<?php
/**
 * download.php
 *
 * GitHub Cloud Manager - File Download
 * -----------------------------------------
 * Streams a single repository file back to the browser as an attachment.
 *
 * Unlike the rest of the app, this endpoint talks to GitHub directly with
 * the "raw" media type (Accept: application/vnd.github.raw) instead of
 * going through GitHubApi::request(), because that method always expects
 * (and JSON-decodes) a JSON body. Requesting the raw media type lets us
 * stream files up to 100 MB correctly regardless of size, instead of being
 * limited by the Contents API's ~1 MB inline base64 "content" field.
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

gcm_require_login();

$owner = gcm_clean($_GET['owner'] ?? '');
$repo = gcm_clean($_GET['repo'] ?? '');
$branch = gcm_clean($_GET['branch'] ?? '');
$path = trim(gcm_clean($_GET['path'] ?? ''), '/');

if (!gcm_is_valid_repo_segment($owner) || !gcm_is_valid_repo_segment($repo) || $path === '' || !gcm_is_valid_path($path)) {
    http_response_code(400);
    echo 'Invalid download request.';
    exit;
}
if ($branch !== '' && !gcm_is_valid_branch_name($branch)) {
    http_response_code(400);
    echo 'Invalid branch name.';
    exit;
}

if (!function_exists('curl_init')) {
    http_response_code(500);
    echo 'The cURL PHP extension is not available on this server.';
    exit;
}

$token = gcm_get_token();
$url = GITHUB_API_BASE . '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents/'
    . implode('/', array_map('rawurlencode', explode('/', $path)));
if ($branch !== '') {
    $url .= '?ref=' . rawurlencode($branch);
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Accept: application/vnd.github.raw',
        'X-GitHub-Api-Version: ' . GITHUB_API_VERSION,
        'User-Agent: GitHub-Cloud-Manager-PHP-App',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
]);

$raw = curl_exec($ch);

if ($raw === false) {
    $error = curl_error($ch);
    curl_close($ch);
    http_response_code(502);
    echo 'Network error contacting GitHub: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8');
    exit;
}

$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$body = substr($raw, $headerSize);

if ($status < 200 || $status >= 300) {
    http_response_code($status === 404 ? 404 : 502);
    echo 'Could not download this file (HTTP ' . $status . '). It may not exist on this branch, or it may be a folder.';
    exit;
}

$filename = basename($path);

// Detect an appropriate MIME type from the actual bytes when possible,
// falling back to a generic binary stream if detection is unavailable.
$mimeType = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $detected = finfo_buffer($finfo, $body);
        if ($detected !== false) {
            $mimeType = $detected;
        }
        finfo_close($finfo);
    }
}

// Clear any output buffering that might corrupt the binary stream.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . strlen($body));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');

echo $body;
exit;