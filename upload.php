<?php
/**
 * upload.php
 *
 * GitHub Cloud Manager - Upload Endpoint (AJAX / JSON)
 * --------------------------------------------------------
 * Handles two families of uploads, both driven by assets/js/app.js:
 *
 *   1. Regular file uploads ("upload_file")
 *      The client calls this endpoint ONCE PER FILE (even for multi-file
 *      selections) so the UI can show real per-file progress. Each call
 *      pushes one file straight to GitHub via the Contents API.
 *
 *   2. ZIP upload + extract + push ("zip_extract", "zip_push_next", "zip_cancel")
 *      The ZIP is extracted to a temporary server-side directory first.
 *      The client then calls "zip_push_next" repeatedly (once per extracted
 *      file) to push each one to GitHub individually, which is what powers
 *      the ZIP Manager's progress bar. "zip_cancel" cleans up early exits.
 *
 * All responses are JSON via gcm_json_response(). All state-changing
 * requests require a valid CSRF token (checked by gcm_require_csrf()).
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/github.php';

if (!gcm_is_logged_in()) {
    gcm_json_response(['success' => false, 'message' => 'You must be logged in.'], 401);
}

gcm_require_csrf();

$action = gcm_clean($_POST['action'] ?? '');
$api = new GitHubApi(gcm_get_token());

// Opportunistically clean up any of this session's ZIP temp directories
// older than one hour, so shared-hosting disk quotas don't fill up over time.
gcm_cleanup_stale_zip_sessions();

switch ($action) {
    case 'upload_file':
        handle_upload_file($api);
        break;
    case 'zip_extract':
        handle_zip_extract($api);
        break;
    case 'zip_push_next':
        handle_zip_push_next($api);
        break;
    case 'zip_cancel':
        handle_zip_cancel();
        break;
    default:
        gcm_json_response(['success' => false, 'message' => 'Unknown upload action.'], 400);
}

// =============================================================================
// Handlers
// =============================================================================

/**
 * Upload (create or overwrite) a single file at the target repo path.
 */
function handle_upload_file(GitHubApi $api): void
{
    [$owner, $repo, $branch, $path, $message] = gcm_read_common_params();

    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        gcm_json_response(['success' => false, 'message' => 'No file was received.'], 400);
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        gcm_json_response(['success' => false, 'message' => 'Upload error code: ' . $file['error']], 400);
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        gcm_json_response(['success' => false, 'message' => 'File exceeds the ' . gcm_human_bytes(MAX_UPLOAD_BYTES) . ' upload limit.'], 400);
    }

    $originalName = basename(str_replace('\\', '/', $file['name']));
    $safeName = gcm_sanitize_filename($originalName);
    if ($safeName === '') {
        gcm_json_response(['success' => false, 'message' => 'Invalid file name.'], 400);
    }
    if (gcm_is_blocked_extension($safeName)) {
        gcm_json_response(['success' => false, 'message' => 'This file type is not allowed: .' . pathinfo($safeName, PATHINFO_EXTENSION)], 400);
    }

    $targetPath = ($path !== '' ? $path . '/' : '') . $safeName;

    $content = file_get_contents($file['tmp_name']);
    if ($content === false) {
        gcm_json_response(['success' => false, 'message' => 'Could not read the uploaded file.'], 500);
    }

    $result = gcm_push_single_file($api, $owner, $repo, $targetPath, $content, $branch, $message !== '' ? $message : "Upload {$safeName}");

    gcm_json_response([
        'success' => $result['success'],
        'message' => $result['success'] ? "Uploaded {$safeName}" : $result['message'],
        'fileName' => $safeName,
        'path' => $targetPath,
    ], $result['success'] ? 200 : 422);
}

/**
 * Accept a ZIP file, extract it into a private temp directory, and return
 * a manifest of files to push plus a session token for subsequent
 * zip_push_next calls.
 */
function handle_zip_extract(GitHubApi $api): void
{
    [$owner, $repo, $branch, $path, $message] = gcm_read_common_params();

    if (!class_exists('ZipArchive')) {
        gcm_json_response(['success' => false, 'message' => 'The PHP ZipArchive extension is not available on this server.'], 500);
    }

    if (empty($_FILES['zipfile']) || ($_FILES['zipfile']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        gcm_json_response(['success' => false, 'message' => 'No ZIP file was received.'], 400);
    }

    $zipFile = $_FILES['zipfile'];
    if ($zipFile['error'] !== UPLOAD_ERR_OK) {
        gcm_json_response(['success' => false, 'message' => 'Upload error code: ' . $zipFile['error']], 400);
    }
    if ($zipFile['size'] > MAX_UPLOAD_BYTES) {
        gcm_json_response(['success' => false, 'message' => 'ZIP file exceeds the ' . gcm_human_bytes(MAX_UPLOAD_BYTES) . ' upload limit.'], 400);
    }
    if (strtolower(pathinfo($zipFile['name'], PATHINFO_EXTENSION)) !== 'zip') {
        gcm_json_response(['success' => false, 'message' => 'Please upload a .zip file.'], 400);
    }

    $token = bin2hex(random_bytes(16));
    $tempDir = sys_get_temp_dir() . '/gcm_zip_' . session_id() . '_' . $token;

    if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
        gcm_json_response(['success' => false, 'message' => 'Could not create a temporary extraction directory.'], 500);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFile['tmp_name']) !== true) {
        gcm_rrmdir($tempDir);
        gcm_json_response(['success' => false, 'message' => 'Could not open the ZIP file. It may be corrupt.'], 400);
    }

    if ($zip->numFiles > 2000) {
        $zip->close();
        gcm_rrmdir($tempDir);
        gcm_json_response(['success' => false, 'message' => 'ZIP file contains too many entries (limit: 2000).'], 400);
    }

    $manifest = [];
    $skipped = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        if ($entryName === false) {
            continue;
        }
        // Skip directory entries, junk metadata, and hidden macOS resource forks.
        if (str_ends_with($entryName, '/') || str_starts_with($entryName, '__MACOSX/') || str_contains($entryName, '.DS_Store')) {
            continue;
        }
        // Guard against zip-slip path traversal.
        if (str_contains($entryName, '..') || str_starts_with($entryName, '/')) {
            $skipped[] = $entryName;
            continue;
        }
        $stat = $zip->statIndex($i);
        if ($stat !== false && $stat['size'] > MAX_UPLOAD_BYTES) {
            $skipped[] = $entryName . ' (too large)';
            continue;
        }

        $extractedPath = $tempDir . '/entry_' . $i;
        $data = $zip->getFromIndex($i);
        if ($data === false) {
            $skipped[] = $entryName;
            continue;
        }
        if (file_put_contents($extractedPath, $data) === false) {
            $skipped[] = $entryName;
            continue;
        }

        $manifest[] = [
            'index' => $i,
            'relativePath' => gcm_clean_zip_entry_name($entryName),
            'localFile' => $extractedPath,
        ];
    }
    $zip->close();

    if (empty($manifest)) {
        gcm_rrmdir($tempDir);
        gcm_json_response(['success' => false, 'message' => 'No usable files were found inside the ZIP.'], 400);
    }

    $_SESSION['zip_sessions'][$token] = [
        'dir' => $tempDir,
        'owner' => $owner,
        'repo' => $repo,
        'branch' => $branch,
        'basePath' => $path,
        'message' => $message !== '' ? $message : 'Upload extracted ZIP contents',
        'manifest' => $manifest,
        'cursor' => 0,
        'created' => time(),
    ];

    gcm_json_response([
        'success' => true,
        'token' => $token,
        'total' => count($manifest),
        'skipped' => $skipped,
        'message' => 'Extracted ' . count($manifest) . ' file(s).' . (!empty($skipped) ? ' ' . count($skipped) . ' entr' . (count($skipped) === 1 ? 'y' : 'ies') . ' skipped.' : ''),
    ]);
}

/**
 * Push the next file from a previously extracted ZIP session to GitHub.
 * The client calls this in a loop until finished=true, updating a progress
 * bar based on done/total after each call.
 */
function handle_zip_push_next(GitHubApi $api): void
{
    $token = gcm_clean($_POST['token'] ?? '');
    if ($token === '' || empty($_SESSION['zip_sessions'][$token])) {
        gcm_json_response(['success' => false, 'message' => 'Unknown or expired ZIP session.'], 400);
    }

    $session = &$_SESSION['zip_sessions'][$token];
    $manifest = $session['manifest'];
    $total = count($manifest);
    $cursor = $session['cursor'];

    if ($cursor >= $total) {
        gcm_cleanup_zip_session($token);
        gcm_json_response(['success' => true, 'finished' => true, 'done' => $total, 'total' => $total, 'message' => 'All files pushed.']);
    }

    $entry = $manifest[$cursor];
    $relativePath = $entry['relativePath'];
    $localFile = $entry['localFile'];

    $targetPath = trim(($session['basePath'] !== '' ? $session['basePath'] . '/' : '') . $relativePath, '/');

    $result = ['success' => false, 'message' => 'Entry skipped (blocked file type).'];
    if (!gcm_is_blocked_extension($relativePath) && is_file($localFile)) {
        $content = file_get_contents($localFile);
        if ($content !== false) {
            $result = gcm_push_single_file(
                $api,
                $session['owner'],
                $session['repo'],
                $targetPath,
                $content,
                $session['branch'],
                $session['message']
            );
        } else {
            $result = ['success' => false, 'message' => 'Could not read extracted file.'];
        }
    }

    $session['cursor']++;
    $done = $session['cursor'];
    $finished = $done >= $total;

    $response = [
        'success' => true, // the push loop itself continues even if one file failed
        'fileSuccess' => $result['success'],
        'fileMessage' => $result['success'] ? '' : $result['message'],
        'currentFile' => $relativePath,
        'done' => $done,
        'total' => $total,
        'finished' => $finished,
    ];

    if ($finished) {
        gcm_cleanup_zip_session($token);
    }

    gcm_json_response($response);
}

/** Cancel an in-progress ZIP session and remove its temp directory. */
function handle_zip_cancel(): void
{
    $token = gcm_clean($_POST['token'] ?? '');
    if ($token !== '' && !empty($_SESSION['zip_sessions'][$token])) {
        gcm_cleanup_zip_session($token);
    }
    gcm_json_response(['success' => true, 'message' => 'ZIP session cancelled.']);
}

// =============================================================================
// Shared helpers
// =============================================================================

/**
 * Read and validate the common owner/repo/branch/path/message parameters
 * shared by both upload flows. Terminates with a 400 JSON error on failure.
 *
 * @return array{0:string,1:string,2:string,3:string,4:string}
 */
function gcm_read_common_params(): array
{
    $owner = gcm_clean($_POST['owner'] ?? '');
    $repo = gcm_clean($_POST['repo'] ?? '');
    $branch = gcm_clean($_POST['branch'] ?? '');
    $path = trim(gcm_clean($_POST['path'] ?? ''), '/');
    $message = gcm_clean($_POST['message'] ?? '');

    if (!gcm_is_valid_repo_segment($owner) || !gcm_is_valid_repo_segment($repo)) {
        gcm_json_response(['success' => false, 'message' => 'Invalid repository reference.'], 400);
    }
    if (!gcm_is_valid_branch_name($branch)) {
        gcm_json_response(['success' => false, 'message' => 'Invalid branch name.'], 400);
    }
    if ($path !== '' && !gcm_is_valid_path($path)) {
        gcm_json_response(['success' => false, 'message' => 'Invalid target path.'], 400);
    }

    return [$owner, $repo, $branch, $path, $message];
}

/**
 * Create or update a single file in the repo. Automatically fetches the
 * existing SHA (if the file already exists) so overwrites work correctly.
 */
function gcm_push_single_file(GitHubApi $api, string $owner, string $repo, string $targetPath, string $rawContent, string $branch, string $message): array
{
    $existing = $api->getContents($owner, $repo, $targetPath, $branch);
    $sha = null;
    if ($existing['success'] && is_array($existing['data']) && isset($existing['data']['sha']) && ($existing['data']['type'] ?? '') === 'file') {
        $sha = $existing['data']['sha'];
    }

    $base64 = base64_encode($rawContent);
    $result = $api->putFile($owner, $repo, $targetPath, $base64, $message, $branch, $sha);

    return [
        'success' => $result['success'],
        'message' => $result['success'] ? 'OK' : $result['message'],
    ];
}

/** Sanitize an uploaded file's name: strip path parts and dangerous characters. */
function gcm_sanitize_filename(string $name): string
{
    $name = trim(basename($name));
    $name = preg_replace('/[^A-Za-z0-9 _.\-()\[\]]/', '_', $name) ?? '';
    $name = ltrim($name, '.'); // avoid hidden dotfile surprises like ".."
    return substr($name, 0, 255);
}

/** Normalize a ZIP entry's internal path into a safe repo-relative path. */
function gcm_clean_zip_entry_name(string $entryName): string
{
    $parts = explode('/', $entryName);
    $clean = array_map(function ($part) {
        $part = preg_replace('/[^A-Za-z0-9 _.\-()\[\]]/', '_', $part) ?? '';
        return $part;
    }, $parts);
    return implode('/', array_filter($clean, fn($p) => $p !== '' && $p !== '.'));
}

function gcm_human_bytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
    return round($bytes / 1024, 1) . ' KB';
}

/** Recursively delete a directory and its contents. */
function gcm_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $dir . '/' . $item;
        if (is_dir($full)) {
            gcm_rrmdir($full);
        } else {
            @unlink($full);
        }
    }
    @rmdir($dir);
}

/** Remove a specific ZIP session's temp directory and session record. */
function gcm_cleanup_zip_session(string $token): void
{
    if (!empty($_SESSION['zip_sessions'][$token]['dir'])) {
        gcm_rrmdir($_SESSION['zip_sessions'][$token]['dir']);
    }
    unset($_SESSION['zip_sessions'][$token]);
}

/** Remove any ZIP session temp directories older than one hour. */
function gcm_cleanup_stale_zip_sessions(): void
{
    if (empty($_SESSION['zip_sessions']) || !is_array($_SESSION['zip_sessions'])) {
        return;
    }
    foreach ($_SESSION['zip_sessions'] as $token => $session) {
        if (!is_array($session) || empty($session['created']) || (time() - (int)$session['created']) > 3600) {
            if (!empty($session['dir'])) {
                gcm_rrmdir($session['dir']);
            }
            unset($_SESSION['zip_sessions'][$token]);
        }
    }
}