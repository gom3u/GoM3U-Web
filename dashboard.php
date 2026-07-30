<?php
/**
 * dashboard.php
 *
 * GitHub Cloud Manager - Dashboard
 * -----------------------------------
 * Shows the authenticated user's profile summary and a searchable grid of
 * their repositories (name, description, stars, primary language, and last
 * updated date). Also hosts the "Create repository" and "Delete repository"
 * modals, both of which submit via fetch() to save.php / delete.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/github.php';

gcm_require_login();

$api = new GitHubApi(gcm_get_token());
$user = $_SESSION['gh_user'];

// Fetch repositories across a few pages (GitHub caps per_page at 100).
// 5 pages = up to 500 repos, which comfortably covers the vast majority
// of individual accounts while keeping load time reasonable.
$repos = [];
$fetchError = '';
for ($page = 1; $page <= 5; $page++) {
    $result = $api->listRepos($page, 100);
    if (!$result['success']) {
        if ($page === 1) {
            $fetchError = $result['message'];
        }
        break;
    }
    $batch = is_array($result['data']) ? $result['data'] : [];
    $repos = array_merge($repos, $batch);
    if (count($batch) < 100) {
        break; // last page reached
    }
}

$totalStars = 0;
foreach ($repos as $r) {
    $totalStars += (int)($r['stargazers_count'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard · <?= gcm_e(APP_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="assets/icons/logo.svg">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="app-shell">

  <?php include __DIR__ . '/partials-topbar.php'; ?>

  <div class="app-body">
    <?php include __DIR__ . '/partials-sidebar.php'; ?>

    <main class="app-main">
      <div class="container-fluid py-4 px-3 px-md-4">

        <?php if ($fetchError !== ''): ?>
          <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            Could not load repositories: <?= gcm_e($fetchError) ?>
          </div>
        <?php endif; ?>

        <!-- Profile summary -->
        <div class="card gcm-card border-0 mb-4">
          <div class="card-body p-4">
            <div class="row align-items-center g-4">
              <div class="col-auto">
                <img src="<?= gcm_e($user['avatar_url']) ?>" alt="Avatar" class="rounded-circle profile-avatar" width="88" height="88">
              </div>
              <div class="col">
                <h1 class="h4 mb-1 fw-semibold"><?= gcm_e($user['name'] ?: $user['login']) ?></h1>
                <p class="text-secondary mb-2">
                  <i class="bi bi-person-badge me-1"></i>@<?= gcm_e($user['login']) ?>
                  <a href="<?= gcm_e($user['html_url']) ?>" target="_blank" rel="noopener" class="ms-2 small">
                    <i class="bi bi-box-arrow-up-right"></i> View on GitHub
                  </a>
                </p>
                <?php if (!empty($user['bio'])): ?>
                  <p class="mb-2 small"><?= gcm_e($user['bio']) ?></p>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-3 small text-secondary">
                  <span><i class="bi bi-journal-code me-1"></i><strong class="text-body"><?= count($repos) ?></strong> repositories</span>
                  <span><i class="bi bi-star-fill me-1 text-warning"></i><strong class="text-body"><?= $totalStars ?></strong> total stars</span>
                  <span><i class="bi bi-people-fill me-1"></i><strong class="text-body"><?= (int)$user['followers'] ?></strong> followers</span>
                  <span><i class="bi bi-person-plus-fill me-1"></i><strong class="text-body"><?= (int)$user['following'] ?></strong> following</span>
                </div>
              </div>
              <div class="col-12 col-md-auto">
                <button type="button" class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#createRepoModal">
                  <i class="bi bi-plus-lg me-1"></i> New Repository
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Search + toolbar -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
          <div class="input-group flex-grow-1" style="max-width: 420px;">
            <span class="input-group-text bg-body-tertiary border-secondary-subtle"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control border-secondary-subtle" id="repoSearchInput" placeholder="Search repositories...">
          </div>
          <span class="text-secondary small ms-auto" id="repoCountLabel"><?= count($repos) ?> repositories</span>
        </div>

        <!-- Repository grid -->
        <div class="row g-3" id="repoGrid">
          <?php foreach ($repos as $repo):
              $name = (string)($repo['name'] ?? '');
              $owner = (string)($repo['owner']['login'] ?? $user['login']);
              $desc = (string)($repo['description'] ?? '');
              $stars = (int)($repo['stargazers_count'] ?? 0);
              $forks = (int)($repo['forks_count'] ?? 0);
              $lang = (string)($repo['language'] ?? '');
              $updated = (string)($repo['updated_at'] ?? '');
              $isPrivate = !empty($repo['private']);
              $defaultBranch = (string)($repo['default_branch'] ?? 'main');
              $updatedFormatted = $updated ? date('M j, Y', strtotime($updated)) : '—';
          ?>
          <div class="col-12 col-sm-6 col-lg-4 repo-card-wrap"
               data-repo-name="<?= gcm_e(strtolower($name)) ?>"
               data-repo-desc="<?= gcm_e(strtolower($desc)) ?>">
            <div class="card gcm-card repo-card h-100 border-0">
              <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start mb-2">
                  <h2 class="h6 mb-0 text-truncate me-2">
                    <i class="bi <?= $isPrivate ? 'bi-lock-fill text-warning' : 'bi-book' ?> me-1"></i>
                    <a href="explorer.php?owner=<?= urlencode($owner) ?>&repo=<?= urlencode($name) ?>&branch=<?= urlencode($defaultBranch) ?>"
                       class="stretched-link-title text-decoration-none">
                      <?= gcm_e($name) ?>
                    </a>
                  </h2>
                  <span class="badge <?= $isPrivate ? 'text-bg-warning' : 'text-bg-secondary' ?> flex-shrink-0">
                    <?= $isPrivate ? 'Private' : 'Public' ?>
                  </span>
                </div>
                <p class="text-secondary small flex-grow-1 mb-3">
                  <?= $desc !== '' ? gcm_e($desc) : '<span class="fst-italic">No description</span>' ?>
                </p>
                <div class="d-flex flex-wrap align-items-center gap-3 small text-secondary mb-3">
                  <?php if ($lang !== ''): ?>
                    <span><span class="lang-dot" data-lang="<?= gcm_e($lang) ?>"></span> <?= gcm_e($lang) ?></span>
                  <?php endif; ?>
                  <span><i class="bi bi-star me-1"></i><?= $stars ?></span>
                  <span><i class="bi bi-diagram-2 me-1"></i><?= $forks ?></span>
                  <span><i class="bi bi-clock-history me-1"></i><?= gcm_e($updatedFormatted) ?></span>
                </div>
                <div class="d-flex gap-2">
                  <a href="explorer.php?owner=<?= urlencode($owner) ?>&repo=<?= urlencode($name) ?>&branch=<?= urlencode($defaultBranch) ?>"
                     class="btn btn-sm btn-outline-primary flex-grow-1">
                    <i class="bi bi-folder2-open me-1"></i> Explore
                  </a>
                  <button type="button" class="btn btn-sm btn-outline-danger btn-delete-repo"
                          data-owner="<?= gcm_e($owner) ?>" data-repo="<?= gcm_e($name) ?>"
                          title="Delete repository">
                    <i class="bi bi-trash3"></i>
                  </button>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>

          <?php if (empty($repos) && $fetchError === ''): ?>
          <div class="col-12">
            <div class="text-center py-5 text-secondary">
              <i class="bi bi-inboxes display-4 d-block mb-3"></i>
              No repositories found. Create your first one above.
            </div>
          </div>
          <?php endif; ?>
        </div>

        <div class="text-center py-5 text-secondary d-none" id="noResultsMsg">
          <i class="bi bi-search display-4 d-block mb-3"></i>
          No repositories match your search.
        </div>

      </div>
    </main>
  </div>
</div>

<!-- Create Repository Modal -->
<div class="modal fade" id="createRepoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="createRepoForm">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i> New Repository</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= gcm_e(gcm_csrf_token()) ?>">
          <div class="mb-3">
            <label class="form-label small">Repository name</label>
            <input type="text" class="form-control" name="name" pattern="[A-Za-z0-9_.\-]+" maxlength="100" required placeholder="my-new-project">
          </div>
          <div class="mb-3">
            <label class="form-label small">Description (optional)</label>
            <textarea class="form-control" name="description" rows="2" maxlength="350"></textarea>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="repoPrivate" name="private" value="1" checked>
            <label class="form-check-label small" for="repoPrivate">Private repository</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i> Create
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Repository Confirmation Modal -->
<div class="modal fade" id="deleteRepoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-danger-subtle">
      <div class="modal-header">
        <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> Delete Repository</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>This will permanently delete <strong id="deleteRepoName"></strong>. This action <strong>cannot be undone</strong>.</p>
        <label class="form-label small">Type the repository name to confirm:</label>
        <input type="text" class="form-control" id="deleteRepoConfirmInput" autocomplete="off">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteRepoBtn" disabled>
          <i class="bi bi-trash3 me-1"></i> Delete Forever
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Toast container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
<script>
  GCM.initRepoSearch();
  GCM.initCreateRepo();
  GCM.initDeleteRepo();
</script>
</body>
</html>