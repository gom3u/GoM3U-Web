<?php
/**
 * index.php
 *
 * GitHub Cloud Manager - Login Page
 * ------------------------------------
 * Accepts a GitHub Personal Access Token (PAT), validates it against the
 * GitHub API, and — on success — stores the token and basic profile info
 * in the PHP session ONLY (never in a cookie, never on disk, never in a
 * database). If the token is invalid, an error is shown and nothing is
 * persisted.
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/github.php';

// Already logged in? Go straight to the dashboard.
if (gcm_is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$errorMessage = '';
$notice = gcm_clean($_GET['error'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!gcm_verify_csrf()) {
        $errorMessage = 'Your session expired. Please try again.';
    } else {
        $token = gcm_clean($_POST['token'] ?? '');

        if ($token === '') {
            $errorMessage = 'Please enter a GitHub Personal Access Token.';
        } elseif (!preg_match('/^(ghp_|github_pat_|gho_|ghu_|ghs_|ghr_)[A-Za-z0-9_]{10,}$/', $token)) {
            $errorMessage = 'That doesn\'t look like a valid GitHub token format. Expected a token starting with "ghp_" or "github_pat_".';
        } else {
            $api = new GitHubApi($token);
            $result = $api->validateToken();

            if ($result['success'] && is_array($result['data'])) {
                // Store only what we need in the session. The token itself
                // never touches HTML, JS, cookies, or logs.
                $_SESSION['gh_token'] = $token;
                $_SESSION['gh_user'] = [
                    'login' => $result['data']['login'] ?? '',
                    'name' => $result['data']['name'] ?? ($result['data']['login'] ?? ''),
                    'avatar_url' => $result['data']['avatar_url'] ?? '',
                    'html_url' => $result['data']['html_url'] ?? '',
                    'public_repos' => $result['data']['public_repos'] ?? 0,
                    'followers' => $result['data']['followers'] ?? 0,
                    'following' => $result['data']['following'] ?? 0,
                    'bio' => $result['data']['bio'] ?? '',
                ];
                session_regenerate_id(true);
                header('Location: dashboard.php');
                exit;
            }

            if ($result['status'] === 401) {
                $errorMessage = 'Invalid or expired token. Please double-check your Personal Access Token.';
            } elseif ($result['status'] === 0) {
                $errorMessage = $result['message'];
            } else {
                $errorMessage = 'Could not validate token: ' . $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In · <?= gcm_e(APP_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="assets/icons/logo.svg">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="login-body d-flex align-items-center">
<div class="container">
  <div class="row justify-content-center">
    <div class="col-12 col-sm-10 col-md-7 col-lg-5">

      <div class="text-center mb-4">
        <span class="login-logo"><i class="bi bi-github"></i></span>
        <h1 class="h4 mt-3 mb-1 fw-semibold"><?= gcm_e(APP_NAME) ?></h1>
        <p class="text-secondary small mb-0">Manage your GitHub repositories from the cloud</p>
      </div>

      <div class="card gcm-card shadow-lg border-0">
        <div class="card-body p-4 p-md-5">

          <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-danger d-flex align-items-start gap-2 small" role="alert">
              <i class="bi bi-exclamation-triangle-fill mt-1"></i>
              <div><?= gcm_e($errorMessage) ?></div>
            </div>
          <?php elseif ($notice !== ''): ?>
            <div class="alert alert-info d-flex align-items-start gap-2 small" role="alert">
              <i class="bi bi-info-circle-fill mt-1"></i>
              <div><?= gcm_e($notice) ?></div>
            </div>
          <?php endif; ?>

          <form method="post" action="index.php" autocomplete="off" novalidate>
            <?= gcm_csrf_field() ?>

            <label for="token" class="form-label small text-secondary text-uppercase fw-semibold">
              Personal Access Token
            </label>
            <div class="input-group input-group-lg mb-2">
              <span class="input-group-text bg-body-tertiary border-secondary-subtle">
                <i class="bi bi-key-fill"></i>
              </span>
              <input
                type="password"
                class="form-control border-secondary-subtle"
                id="token"
                name="token"
                placeholder="ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                required
                autocomplete="off"
                spellcheck="false"
              >
              <button class="btn btn-outline-secondary" type="button" id="toggleTokenBtn" tabindex="-1" title="Show/hide token">
                <i class="bi bi-eye" id="toggleTokenIcon"></i>
              </button>
            </div>
            <div class="form-text mb-4">
              <i class="bi bi-shield-lock me-1"></i>
              Your token is kept only in your server session. It is never saved to a database or file.
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-100" id="loginBtn">
              <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
            </button>
          </form>

        </div>
      </div>

      <div class="card gcm-card border-0 mt-3">
        <div class="card-body p-3 p-md-4">
          <h2 class="h6 fw-semibold mb-2"><i class="bi bi-question-circle me-1"></i> How to get a token</h2>
          <ol class="small text-secondary mb-0 ps-3">
            <li>Go to GitHub → Settings → Developer settings → Personal access tokens.</li>
            <li>Generate a new token (classic or fine-grained) with <code>repo</code> scope.</li>
            <li>Copy the token and paste it above. It is only used for this session.</li>
          </ol>
        </div>
      </div>

      <p class="text-center text-secondary small mt-4 mb-0">
        <?= gcm_e(APP_NAME) ?> v<?= gcm_e(APP_VERSION) ?> &middot; Not affiliated with GitHub, Inc.
      </p>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script>
  // Simple show/hide toggle for the token field. No token data is logged
  // or transmitted anywhere by this script — it only flips the input type.
  document.getElementById('toggleTokenBtn').addEventListener('click', function () {
    const input = document.getElementById('token');
    const icon = document.getElementById('toggleTokenIcon');
    const isPassword = input.getAttribute('type') === 'password';
    input.setAttribute('type', isPassword ? 'text' : 'password');
    icon.classList.toggle('bi-eye');
    icon.classList.toggle('bi-eye-slash');
  });

  document.querySelector('form').addEventListener('submit', function () {
    const btn = document.getElementById('loginBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Verifying token...';
  });
</script>
</body>
</html>