<?php
/**
 * save.php
 *
 * GitHub Cloud Manager - Save / Commit Endpoint (AJAX / JSON)
 * -----------------------------------------------------------------
 * Handles every "write" operation that isn't a file upload or a deletion:
 *
 *   - create_repo    : create a new GitHub repository
 *   - create_folder  : create a folder (via a .gitkeep placeholder commit)
 *   - save_file      : commit edited content to an existing text file
 *   - rename_item    : rename/move a file, or an entire folder (recursively)
 *   - create_branch  : create a new branch from an existing one
 *
 * Every action here results in one or more real commits pushed to GitHub
 * through the Contents API (GitHubApi::putFile / renameFile / createFolder).
 * All requests must be POST and carry a valid CSRF token.
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

switch ($action) {
    case 'create_repo':
        handle_create_repo($api);
        break;
    case 'create_folder':
        handle_create_folder($api);
        break;
    case 'save_file':
        handle_save_file($api);
        break;
    case 'rename_item':
        handle_rename_item($api);
        break;
    case 'create_branch':
        handle_create_branch($api);
        break;
    default:
        gcm_json_response(['success' => false, 'message' => 'Unknown save action.'], 400);
}

// =============================================================================
// Handlers
// =============================================================================

function handle_create_repo(GitHubApi $api): void
{
    $name = gcm_clean($_POST['name'] ?? '');
    $description = gcm_clean($_POST['description'] ?? '');
    $private = !empty($_POST['private']);

    if (!gcm_is_valid_repo_segment($name)) {
        gcm_json_response(['success' => false, 'message' => 'Repository name may only contain letters, numbers, dots, dashes, and underscores.'], 400);
    }
    if (mb_strlen($description) > 350) {
        gcm_json_response(['success' => false, 'message' => 'Description is too long (350 characters max).'], 400);
    }

    $result = $api->createRepo($name, $description, $private, true);

    if ($result['success']) {
        gcm_json_response([
            'success' => true,
            'message' => "Repository \"{$name}\" created successfully.",
            'repo' => [
                'name' => $result['data']['name'] ?? $name,
                'owner' => $result['data']['owner']['login'] ?? '',
                'default_branch' => $result['data']['default_branch'] ?? 'main',
            ],
        ]);
    }

    $status = $result['status'] === 422 ? 422 : 500;
    gcm_json_response(['success' => false, 'message' => $result['message']], $status);
}

function handle_create_folder(GitHubApi $api): void
{
    [$owner, $repo, $branch, $basePath, $message] = gcm_read_common_params();

    $folderName = gcm_clean($_POST['folderName'] ?? '');
    $folderName = preg_replace('/[^A-Za-z0-9 _.\-]/', '_', $folderName) ?? '';
    $folderName = trim($folderName, ' /');

    if ($folderName === '') {
        gcm_json_response(['success' => false, 'message' => 'Please provide a folder name.'], 400);
    }

    $targetPath = trim(($basePath !== '' ? $basePath . '/' : '') . $folderName, '/');
    if (!gcm_is_valid_path($targetPath)) {
        gcm_json_response(['success' => false, 'message' => 'Invalid folder path.'], 400);
    }

    $result = $api->createFolder($owner, $repo, $targetPath, $branch, $message !== '' ? $message : "Create folder {$folderName}");

    gcm_json_response([
        'success' => $result['success'],
        'message' => $result['success'] ? "Folder \"{$folderName}\" created." : $result['message'],
    ], $result['success'] ? 200 : 422);
}

function handle_save_file(GitHubApi $api): void
{
    [$owner, $repo, $branch, $path, $message] = gcm_read_common_params();

    $sha = gcm_clean($_POST['sha'] ?? '');
    // Raw file content is sent as-is (not sanitized/HTML-escaped) since it is
    // the literal text content of the file being committed, not HTML output.
    $content = (string)($_POST['content'] ?? '');

    if ($path === '' || $sha === '') {
        gcm_json_response(['success' => false, 'message' => 'Missing file path or revision reference.'], 400);
    }
    if (strlen($content) > MAX_UPLOAD_BYTES) {
        gcm_json_response(['success' => false, 'message' => 'File content exceeds the allowed size.'], 400);
    }

    $result = $api->putFile(
        $owner,
        $repo,
        $path,
        base64_encode($content),
        $message !== '' ? $message : 'Update ' . basename($path),
        $branch,
        $sha
    );

    if ($result['success']) {
        gcm_json_response([
            'success' => true,
            'message' => 'File saved successfully.',
            'newSha' => $result['data']['content']['sha'] ?? '',
        ]);
    }

    if ($result['status'] === 409) {
        gcm_json_response(['success' => false, 'message' => 'This file changed on GitHub since you opened it. Please reload before saving.'], 409);
    }

    gcm_json_response(['success' => false, 'message' => $result['message']], 422);
}

function handle_rename_item(GitHubApi $api): void
{
    [$owner, $repo, $branch, , $message] = gcm_read_common_params();

    $oldPath = trim(gcm_clean($_POST['oldPath'] ?? ''), '/');
    $newName = gcm_clean($_POST['newName'] ?? '');
    $type = gcm_clean($_POST['type'] ?? 'file');

    $newName = preg_replace('/[^A-Za-z0-9 _.\-()\[\]]/', '_', $newName) ?? '';

    if ($oldPath === '' || !gcm_is_valid_path($oldPath) || $newName === '') {
        gcm_json_response(['success' => false, 'message' => 'Invalid rename request.'], 400);
    }

    $segments = explode('/', $oldPath);
    array_pop($segments);
    $segments[] = $newName;
    $newPath = implode('/', $segments);

    if (!gcm_is_valid_path($newPath)) {
        gcm_json_response(['success' => false, 'message' => 'Invalid new name.'], 400);
    }
    if ($newPath === $oldPath) {
        gcm_json_response(['success' => false, 'message' => 'The new name is the same as the current name.'], 400);
    }

    $commitMessage = $message !== '' ? $message : "Rename {$oldPath} to {$newPath}";

    if ($type === 'dir') {
        $result = gcm_rename_folder_recursive($api, $owner, $repo, $oldPath, $newPath, $branch, $commitMessage);
    } else {
        $result = $api->renameFile($owner, $repo, $oldPath, $newPath, $branch, $commitMessage);
    }

    gcm_json_response([
        'success' => $result['success'],
        'message' => $result['success'] ? "Renamed to \"{$newName}\"." : $result['message'],
        'newPath' => $newPath,
    ], $result['success'] ? 200 : 422);
}

function handle_create_branch(GitHubApi $api): void
{
    $owner = gcm_clean($_POST['owner'] ?? '');
    $repo = gcm_clean($_POST['repo'] ?? '');
    $fromBranch = gcm_clean($_POST['fromBranch'] ?? '');
    $newBranch = gcm_clean($_POST['newBranch'] ?? '');

    if (!gcm_is_valid_repo_segment($owner) || !gcm_is_valid_repo_segment($repo)) {
        gcm_json_response(['success' => false, 'message' => 'Invalid repository reference.'], 400);
    }
    if (!gcm_is_valid_branch_name($fromBranch) || !gcm_is_valid_branch_name($newBranch)) {
        gcm_json_response(['success' => false, 'message' => 'Invalid branch name.'], 400);
    }

    $result = $api->createBranch($owner, $repo, $newBranch, $fromBranch);

    gcm_json_response([
        'success' => $result['success'],
        'message' => $result['success'] ? "Branch \"{$newBranch}\" created." : $result['message'],
        'branch' => $newBranch,
    ], $result['success'] ? 200 : 422);
}

// =============================================================================
// Shared helpers
// =============================================================================

/**
 * Read and validate the common owner/repo/branch/path/message parameters.
 * Terminates with a 400 JSON error on failure.
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
 * Recursively list every file (not folder) path under a given directory.
 * Used to support "renaming" a folder, since Git has no native folder
 * rename — every file inside must be individually moved.
 */
function gcm_list_all_files(GitHubApi $api, string $owner, string $repo, string $path, string $branch): array
{
    $files = [];
    $result = $api->getContents($owner, $repo, $path, $branch);
    if (!$result['success'] || !is_array($result['data'])) {
        return $files;
    }

    // A single-file result would mean $path itself was a file, not a folder.
    if (isset($result['data']['type'])) {
        return $files;
    }

    foreach ($result['data'] as $entry) {
        if (($entry['type'] ?? '') === 'file') {
            $files[] = $entry['path'];
        } elseif (($entry['type'] ?? '') === 'dir') {
            $files = array_merge($files, gcm_list_all_files($api, $owner, $repo, $entry['path'], $branch));
        }
    }

    return $files;
}

/**
 * Rename a folder by moving every file beneath it to the equivalent path
 * under the new folder name. Stops and reports the first failure so
 * partial renames are visible rather than silently incomplete.
 */
function gcm_rename_folder_recursive(GitHubApi $api, string $owner, string $repo, string $oldPath, string $newPath, string $branch, string $message): array
{
    $files = gcm_list_all_files($api, $owner, $repo, $oldPath, $branch);

    if (empty($files)) {
        return ['success' => false, 'message' => 'Folder is empty or could not be read.'];
    }

    $moved = 0;
    foreach ($files as $filePath) {
        $relative = ltrim(substr($filePath, strlen($oldPath)), '/');
        $newFilePath = trim($newPath . '/' . $relative, '/');

        $result = $api->renameFile($owner, $repo, $filePath, $newFilePath, $branch, $message);
        if (!$result['success']) {
            return [
                'success' => false,
                'message' => "Renamed {$moved} of " . count($files) . " file(s) before an error occurred: " . $result['message'],
            ];
        }
        $moved++;
    }

    return ['success' => true, 'message' => "Renamed {$moved} file(s)."];
}