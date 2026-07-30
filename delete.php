<?php
/**
 * delete.php
 *
 * GitHub Cloud Manager - Delete Endpoint (AJAX / JSON)
 * ---------------------------------------------------------
 * Handles all destructive operations:
 *
 *   - delete_repo   : permanently delete an entire GitHub repository
 *   - delete_file   : delete a single file (commits a delete to the branch)
 *   - delete_folder : recursively delete every file beneath a folder path
 *                     (Git has no folder object, so a folder "disappears"
 *                     once its last file is removed)
 *
 * The client-side confirmation dialogs (typed-name confirmation for repo
 * deletion, a plain confirm modal for files/folders) are handled in
 * assets/js/app.js BEFORE this endpoint is ever called. This endpoint still
 * validates everything independently — it never trusts that confirmation
 * happened client-side.
 *
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
    case 'delete_repo':
        handle_delete_repo($api);
        break;
    case 'delete_file':
        handle_delete_file($api);
        break;
    case 'delete_folder':
        handle_delete_folder($api);
        break;
    default:
        gcm_json_response(['success' => false, 'message' => 'Unknown delete action.'], 400);
}

// =============================================================================
// Handlers
// =============================================================================

function handle_delete_repo(GitHubApi $api): void
{
    $owner = gcm_clean($_POST['owner'] ?? '');
    $repo = gcm_clean($_POST['repo'] ?? '');
    $confirmName = gcm_clean($_POST['confirmName'] ?? '');

    if (!gcm_is_valid_repo_segment($owner) || !gcm_is_valid_repo_segment($repo)) {
        gcm_json_response(['success' => false, 'message' => 'Invalid repository reference.'], 400);
    }

    // Server-side re-validation of the "type the repo name to confirm" step.
    if ($confirmName !== $repo) {
        gcm_json_response(['success' => false, 'message' => 'Repository name confirmation did not match.'], 400);
    }

    $result = $api->deleteRepo($owner, $repo);

    if ($result['success']) {
        gcm_json_response(['success' => true, 'message' => "Repository \"{$repo}\" was permanently deleted."]);
    }

    if ($result['status'] === 403) {
        gcm_json_response(['success' => false, 'message' => 'Your token does not have permission to delete this repository (requires the "delete_repo" scope).'], 403);
    }

    gcm_json_response(['success' => false, 'message' => $result['message']], 422);
}

function handle_delete_file(GitHubApi $api): void
{
    [$owner, $repo, $branch, $path, $message] = gcm_read_common_params();

    $sha = gcm_clean($_POST['sha'] ?? '');
    if ($path === '' || $sha === '') {
        gcm_json_response(['success' => false, 'message' => 'Missing file path or revision reference.'], 400);
    }

    $result = $api->deleteFile($owner, $repo, $path, $message !== '' ? $message : 'Delete ' . basename($path), $sha, $branch);

    if ($result['success']) {
        gcm_json_response(['success' => true, 'message' => 'File deleted successfully.']);
    }

    if ($result['status'] === 409) {
        gcm_json_response(['success' => false, 'message' => 'This file changed on GitHub since the page loaded. Please refresh and try again.'], 409);
    }

    gcm_json_response(['success' => false, 'message' => $result['message']], 422);
}

function handle_delete_folder(GitHubApi $api): void
{
    [$owner, $repo, $branch, $path, $message] = gcm_read_common_params();

    if ($path === '') {
        gcm_json_response(['success' => false, 'message' => 'Refusing to delete the repository root. Delete the repository instead if that is your intent.'], 400);
    }

    $files = gcm_list_all_files_for_deletion($api, $owner, $repo, $path, $branch);

    if (empty($files)) {
        gcm_json_response(['success' => false, 'message' => 'Folder is already empty or could not be read.'], 404);
    }

    $commitMessage = $message !== '' ? $message : 'Delete folder ' . basename($path);
    $deleted = 0;
    $failures = [];

    foreach ($files as $file) {
        $result = $api->deleteFile($owner, $repo, $file['path'], $commitMessage, $file['sha'], $branch);
        if ($result['success']) {
            $deleted++;
        } else {
            $failures[] = $file['path'] . ': ' . $result['message'];
        }
    }

    if (empty($failures)) {
        gcm_json_response(['success' => true, 'message' => "Folder deleted ({$deleted} file(s) removed)."]);
    }

    gcm_json_response([
        'success' => false,
        'message' => "Deleted {$deleted} of " . count($files) . " file(s). Some failed: " . implode('; ', array_slice($failures, 0, 3)),
    ], 207);
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
    if ($path === '' || !gcm_is_valid_path($path)) {
        gcm_json_response(['success' => false, 'message' => 'Invalid target path.'], 400);
    }

    return [$owner, $repo, $branch, $path, $message];
}

/**
 * Recursively list every file (path + sha) beneath a given folder path,
 * needed so each one can be individually deleted via the Contents API.
 */
function gcm_list_all_files_for_deletion(GitHubApi $api, string $owner, string $repo, string $path, string $branch): array
{
    $files = [];
    $result = $api->getContents($owner, $repo, $path, $branch);
    if (!$result['success'] || !is_array($result['data'])) {
        return $files;
    }

    // If GitHub returned a single file object, $path pointed at a file, not a folder.
    if (isset($result['data']['type']) && $result['data']['type'] === 'file') {
        return [['path' => $result['data']['path'], 'sha' => $result['data']['sha']]];
    }

    foreach ($result['data'] as $entry) {
        if (($entry['type'] ?? '') === 'file') {
            $files[] = ['path' => $entry['path'], 'sha' => $entry['sha']];
        } elseif (($entry['type'] ?? '') === 'dir') {
            $files = array_merge($files, gcm_list_all_files_for_deletion($api, $owner, $repo, $entry['path'], $branch));
        }
    }

    return $files;
}