<?php
/**
 * config.php
 *
 * GitHub Cloud Manager - Core Configuration
 * ------------------------------------------
 * This file MUST be included at the top of every page (via require_once).
 * It is responsible for:
 *   - Starting a secure PHP session
 *   - Defining global application constants
 *   - Providing CSRF protection helpers
 *   - Providing input sanitization helpers
 *   - Providing authentication guard helpers
 *   - Providing a small JSON response helper for AJAX endpoints
 *
 * SECURITY NOTE:
 * The GitHub Personal Access Token (PAT) is NEVER written to disk, NEVER
 * logged, and NEVER echoed into HTML/JS. It lives only in $_SESSION for the
 * lifetime of the browser session (or until logout.php is called).
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Error reporting (safe defaults for production hosting)
// ---------------------------------------------------------------------------
// On shared hosts like InfinityFree you generally want errors logged, not
// displayed, so secrets/tokens never leak into a rendered page.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ---------------------------------------------------------------------------
// Secure session configuration
// ---------------------------------------------------------------------------
// These ini_set calls must run BEFORE session_start(). Some shared hosts
// restrict session.save_path changes, so we only set flags that are safe
// almost everywhere.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    // Only force a secure cookie if the request is actually over HTTPS.
    // Forcing this unconditionally would break plain-HTTP local testing.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }

    session_name('gcm_session');
    session_start();
}

// Regenerate the session ID once per session to help mitigate session
// fixation, without breaking AJAX calls that happen in quick succession.
if (empty($_SESSION['_initiated'])) {
    session_regenerate_id(true);
    $_SESSION['_initiated'] = true;
}

// ---------------------------------------------------------------------------
// Application constants
// ---------------------------------------------------------------------------
define('APP_NAME', 'GitHub Cloud Manager');
define('APP_VERSION', '1.0.0');
define('GITHUB_API_BASE', 'https://api.github.com');
define('GITHUB_API_VERSION', '2022-11-28');

// Maximum upload size accepted by upload.php, in bytes (25 MB). GitHub's
// Contents API itself only accepts files up to 100 MB (base64 encoded,
// so effectively less), but we keep this conservative for shared hosting
// PHP upload limits.
define('MAX_UPLOAD_BYTES', 25 * 1024 * 1024);

// Allowed file extensions for uploads (extend as needed). Empty extension
// list check is handled separately for "no extension" files like LICENSE.
define('BLOCKED_EXTENSIONS', ['php', 'php3', 'php4', 'php5', 'phtml', 'exe', 'sh', 'bat', 'cmd']);

// ---------------------------------------------------------------------------
// CSRF protection
// ---------------------------------------------------------------------------

/**
 * Return the current CSRF token, generating one if it doesn't exist yet.
 */
function gcm_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output a hidden CSRF input field for use inside <form> elements.
 */
function gcm_csrf_field(): string
{
    $token = htmlspecialchars(gcm_csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Validate a submitted CSRF token using a timing-safe comparison.
 * Accepts the token from POST body or an X-CSRF-Token header (for AJAX/fetch).
 */
function gcm_verify_csrf(): bool
{
    $submitted = $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    if (empty($_SESSION['csrf_token']) || empty($submitted)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], (string)$submitted);
}

/**
 * Halt execution with a 403 JSON response if CSRF validation fails.
 * Call this at the top of any state-changing endpoint (POST/DELETE actions).
 */
function gcm_require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return; // Only enforce on POST requests.
    }
    if (!gcm_verify_csrf()) {
        gcm_json_response(['success' => false, 'message' => 'Invalid or missing CSRF token.'], 403);
    }
}

// ---------------------------------------------------------------------------
// Input sanitization helpers
// ---------------------------------------------------------------------------

/**
 * Sanitize a plain scalar string for safe storage/use (trims + strips
 * control characters). Does NOT HTML-encode; use gcm_e() at output time.
 */
function gcm_clean(?string $value): string
{
    if ($value === null) {
        return '';
    }
    $value = trim($value);
    // Strip null bytes and other control characters except tab/newline.
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
    return $value;
}

/**
 * HTML-escape a string for safe output (XSS protection). Short alias
 * used throughout the view templates.
 */
function gcm_e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Validate that a repository path segment (owner or repo name) only
 * contains characters GitHub itself allows.
 */
function gcm_is_valid_repo_segment(string $value): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $value);
}

/**
 * Validate a branch name against a conservative safe pattern.
 * (Git branch names allow more, but we restrict to keep things safe/simple.)
 */
function gcm_is_valid_branch_name(string $value): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_.\-\/]{1,200}$/', $value) && strpos($value, '..') === false;
}

/**
 * Validate/normalize a file or folder path within a repository. Rejects
 * path traversal attempts and leading slashes.
 */
function gcm_is_valid_path(string $value): bool
{
    if ($value === '') {
        return true; // root
    }
    if (strpos($value, '..') !== false) {
        return false;
    }
    if (str_starts_with($value, '/') || str_contains($value, "\0")) {
        return false;
    }
    return (bool)preg_match('#^[A-Za-z0-9 _.\-/()\[\]]{1,1024}$#', $value);
}

/**
 * Check an uploaded filename's extension against the blocklist.
 */
function gcm_is_blocked_extension(string $filename): bool
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, BLOCKED_EXTENSIONS, true);
}

// ---------------------------------------------------------------------------
// Authentication helpers
// ---------------------------------------------------------------------------

/**
 * Whether the current visitor has an authenticated GitHub session.
 */
function gcm_is_logged_in(): bool
{
    return !empty($_SESSION['gh_token']) && !empty($_SESSION['gh_user']);
}

/**
 * Redirect to the login page if the user isn't authenticated.
 * Call at the top of any protected page.
 */
function gcm_require_login(): void
{
    if (!gcm_is_logged_in()) {
        header('Location: index.php?error=' . urlencode('Please log in to continue.'));
        exit;
    }
}

/**
 * Retrieve the token for the current session. Returns null if not logged in.
 * This is the ONLY place the raw token should be read from for API calls.
 */
function gcm_get_token(): ?string
{
    return $_SESSION['gh_token'] ?? null;
}

// ---------------------------------------------------------------------------
// JSON response helper (for AJAX endpoints: upload.php, save.php, delete.php)
// ---------------------------------------------------------------------------

/**
 * Send a JSON response and terminate the script.
 *
 * @param array $data       Data to encode as JSON.
 * @param int   $statusCode HTTP status code to send.
 */
function gcm_json_response(array $data, int $statusCode = 200): void
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------------
// Basic security headers (applied to every page that includes config.php)
// ---------------------------------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("X-XSS-Protection: 1; mode=block");
}