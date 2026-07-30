<?php
/**
 * logout.php
 *
 * GitHub Cloud Manager - Logout
 * -----------------------------------
 * Destroys the current session entirely, which is the only place the
 * GitHub token is stored. There is nothing else to clean up: no token is
 * ever written to a database, file, or cookie, so destroying the session
 * fully revokes the app's access until the user logs in again.
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Explicitly wipe the token from memory first, in case any error occurs
// further down before session_destroy() completes.
unset($_SESSION['gh_token'], $_SESSION['gh_user'], $_SESSION['zip_sessions'], $_SESSION['csrf_token']);

// Clear all remaining session data.
$_SESSION = [];

// Remove the session cookie itself, if the client sent one.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]
    );
}

// Destroy the server-side session record.
session_destroy();

header('Location: index.php?error=' . urlencode('You have been signed out.'));
exit;