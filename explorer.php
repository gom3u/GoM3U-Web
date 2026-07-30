<?php
/**
 * explorer.php
 *
 * GitHub Cloud Manager - Repository Explorer
 * ---------------------------------------------
 * Combines four features around a single selected repository + branch + path:
 *   1. Repository Explorer - breadcrumb folder/file browsing
 *   2. File Manager        - upload / create folder / rename / delete / edit / download
 *   3. ZIP Manager         - upload + extract + push a ZIP's contents (UI here, logic in upload.php)
 *   4. Git Operations      - branch switcher, commit message, create branch
 *
 * All state-changing actions are performed via fetch() calls from
 * assets/js/app.js to save.php / upload.php / delete.php, which return JSON.
 * This page itself only ever performs read (GET-style) calls to GitHub.
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/github.php';

gcm_require_login();

// ---------------------------------------------------------------------------
// Read & validate query parameters
// ---------------------------------------------------------------------------
$owner = gcm_clean($_GET['owner'] ?? '');
$repo = gcm_clean($_GET['repo'] ?? '');
$branch = gcm_clean($_GET['branch'] ?? '');
$path = gcm_clean($_GET['path'] ?? '');

if (!gcm_is_valid_repo_segment($owner) || !gcm_is_valid_repo_segment($repo)) {
    header('Location: dashboard.php?error=' . urlencode('Invalid repository reference.'));
    exit;
}
if ($path !== '' && !gcm_is_valid_path($path)) {
    header('Location: dashboard.php?error=' . urlencode('Invalid file path.'));
    exit;
}
$path = trim($path, '/');

$api = new GitHubApi(gcm_get_token());
$user = $_SESSION['gh_user'];

// Resolve repo (also validates access + gets default branch).
$repoInfo = $api->getRepo($owner, $repo);
if (!$repoInfo['success']) {
    header('Location: dashboard.php?error=' . urlencode('Repository not found or inaccessible: ' . $repoInfo['message']));
    exit;
}
$defaultBranch = (string)($repoInfo['data']['default_branch'] ?? 'main');
if ($branch === '' || !gcm_is_valid_branch_name($branch)) {
    $branch = $defaultBranch;
}

// Branch list for the switcher.
$branchesResult = $api->listBranches($owner, $repo);
$branches = $branchesResult['success'] && is_array($branchesResult['data']) ? $branchesResult['data'] : [];

// Fetch the current path's contents.
$contentsResult = $api->getContents($owner, $repo, $path, $branch);
$isFileView = false;
$items = [];
$fileMeta = null;
$contentsError = '';

if ($contentsResult['success']) {
    $data = $contentsResult['data'];
    if (is_array($data) && isset($data['type']) && $data['type'] === 'file') {
        $isFileView = true;
        $fileMeta = $data;
    } elseif (is_array($data)) {
        $items = $data;
        // Folders first, then alphabetical, case-insensitive.
        usort($items, function ($a, $b) {
            $aDir = ($a['type'] ?? '') === 'dir';
            $bDir = ($b['type'] ?? '') === 'dir';
            if ($aDir !== $bDir) {
                return $aDir ? -1 : 1;
            }
            return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });
    }
} else {
    if ($contentsResult['status'] === 404 && $path !== '') {
        $contentsError = 'This path is empty or does not exist on the "' . $branch . '" branch.';
    } else {
        $contentsError = $contentsResult['message'];
    }
}

// ---------------------------------------------------------------------------
// Helpers local to this view
// ---------------------------------------------------------------------------

/** Build a breadcrumb trail of [label, path] pairs from a repo-relative path. */
function gcm_breadcrumbs(string $path): array
{
    if ($path === '') {
        return [];
    }
    $parts = explode('/', $path);
    $trail = [];
    $accum = [];
    foreach ($parts as $part) {
        $accum[] = $part;
        $trail[] = [$part, implode('/', $accum)];
    }
    return $trail;
}

/** Human-readable file size. */
function gcm_filesize(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB'];
    $value = $bytes / 1024;
    foreach ($units as $unit) {
        if ($value < 1024) {
            return round($value, 1) . ' ' . $unit;
        }
        $value /= 1024;
    }
    return round($value, 1) . ' TB';
}

/** Bootstrap Icon class for a directory entry, based on type + extension. */
function gcm_file_icon(string $type, string $name): string
{
    if ($type === 'dir') {
        return 'bi-folder-fill text-warning';
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = [
        'php' => 'bi-filetype-php', 'js' => 'bi-filetype-js', 'ts' => 'bi-filetype-tsx',
        'json' => 'bi-filetype-json', 'html' => 'bi-filetype-html', 'htm' => 'bi-filetype-html',
        'css' => 'bi-filetype-css', 'scss' => 'bi-filetype-scss', 'md' => 'bi-filetype-md',
        'py' => 'bi-filetype-py', 'java' => 'bi-filetype-java', 'yml' => 'bi-filetype-yml',
        'yaml' => 'bi-filetype-yml', 'xml' => 'bi-filetype-xml', 'sql' => 'bi-filetype-sql',
        'png' => 'bi-filetype-png', 'jpg' => 'bi-filetype-jpg', 'jpeg' => 'bi-filetype-jpg',
        'gif' => 'bi-filetype-gif', 'svg' => 'bi-filetype-svg', 'pdf' => 'bi-filetype-pdf',
        'zip' => 'bi-file-earmark-zip', 'txt' => 'bi-filetype-txt', 'sh' => 'bi-terminal',
        'gitignore' => 'bi-git', 'lock' => 'bi-file-earmark-lock',
    ];
    return $map[$ext] ?? 'bi-file-earmark-text';
}

/** Whether decoded file content appears to be text (safe to view/edit inline). */
function gcm_looks_like_text(string $data): bool
{
    if ($data === '') {
        return true;
    }
    if (str_contains($data, "\0")) {
        return false;
    }
    $sample = substr($data, 0, 8000);
    return mb_check_encoding($sample, 'UTF-8') || mb_check_encoding($sample, 'ISO-8859-1');
}

/** Map a file extension to a CodeMirror mode name for syntax highlighting. */
function gcm_codemirror_mode(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $map = [
        'js' => 'javascript', 'jsx' => 'javascript', 'ts' => 'javascript', 'tsx' => 'javascript',
        'json' => 'javascript', 'php' => 'application/x-httpd-php', 'html' => 'htmlmixed', 'htm' => 'htmlmixed',
        'xml' => 'xml', 'svg' => 'xml', 'css' => 'css', 'scss' => 'css', 'md' => 'markdown',
        'markdown' => 'markdown', 'py' => 'python', 'java' => 'text/x-java', 'c' => 'text/x-csrc',
        'h' => 'text/x-csrc', 'cpp' => 'text/x-c++src', 'cs' => 'text/x-csharp', 'sh' => 'shell',
        'bash' => 'shell', 'yml' => 'yaml', 'yaml' => 'yaml', 'sql' => 'sql',
    ];
    return $map[$ext] ?? 'null';
}

$breadcrumbs = gcm_breadcrumbs($isFileView ? (string)($fileMeta['path'] ?? $path) : $path);
$parentPath = '';
if ($path !== '') {
    $segments = explode('/', $path);
    array_pop($segments);
    $parentPath = implode('/', $segments);
}

$decodedContent = '';
$isTextFile = false;
$fileTooLarge = false;
if ($isFileView && $fileMeta) {
    if (isset($fileMeta['content']) && ($fileMeta['encoding'] ?? '') === 'base64') {
        $decodedContent = base64_decode(str_replace("\n", '', $fileMeta['content'])) ?: '';
        $isTextFile = gcm_looks_like_text($decodedContent);
    } else {
        $fileTooLarge = true;
    }
}

$pageConfig = [
    'owner' => $owner,
    'repo' => $repo,
    'branch' => $branch,
    'path' => $path,
    'parentPath' => $parentPath,
    'csrfToken' => gcm_csrf_token(),
    'isFileView' => $isFileView,
    'filePath' => $isFileView ? ($fileMeta['path'] ?? '') : '',
    'fileSha' => $isFileView ? ($fileMeta['sha'] ?? '') : '',
];
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= gcm_e($repo) ?> · <?= gcm_e(APP_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="assets/icons/logo.svg">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/dracula.min.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="app-shell">

  <!-- Top navigation bar -->
  <nav class="navbar app-topbar px-3">
    <button class="btn btn-icon d-lg-none me-2" id="sidebarToggleBtn" aria-label="Toggle sidebar">
      <i class="bi bi-list fs-4"></i>
    </button>
    <a class="navbar-brand d-flex align-items-center gap-2 mb-0" href="dashboard.php">
      <i class="bi bi-github fs-4"></i>
      <span class="fw-semibold d-none d-sm-inline"><?= gcm_e(APP_NAME) ?></span>
    </a>
    <div class="ms-auto dropdown">
      <button class="btn btn-icon d-flex align-items-center gap-2 dropdown-toggle" type="button" data-bs-toggle="dropdown">
        <img src="<?= gcm_e($user['avatar_url']) ?>" alt="" class="rounded-circle" width="30" height="30">
        <span class="d-none d-md-inline small"><?= gcm_e($user['login']) ?></span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= gcm_e($user['html_url']) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-2"></i>View GitHub Profile</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
      </ul>
    </div>
  </nav>

  <div class="app-body">
    <!-- Sidebar navigation -->
    <aside class="app-sidebar" id="appSidebar">
      <nav class="nav flex-column p-2">
        <a class="nav-link" href="dashboard.php">
          <i class="bi bi-arrow-left me-2"></i> Back to Dashboard
        </a>
        <hr class="text-secondary opacity-25">
        <div class="px-2 small text-secondary text-uppercase fw-semibold mb-1">Current Repository</div>
        <div class="px-2 mb-2 text-truncate fw-semibold">
          <i class="bi bi-book me-1"></i><?= gcm_e($owner) ?>/<?= gcm_e($repo) ?>
        </div>

        <label class="px-2 small text-secondary mb-1">Branch</label>
        <select class="form-select form-select-sm mx-2 mb-3" style="width: calc(100% - 1rem);" id="branchSelect">
          <?php foreach ($branches as $b): $bn = (string)($b['name'] ?? ''); ?>
            <option value="<?= gcm_e($bn) ?>" <?= $bn === $branch ? 'selected' : '' ?>><?= gcm_e($bn) ?></option>
          <?php endforeach; ?>
          <?php if (empty($branches)): ?>
            <option value="<?= gcm_e($branch) ?>" selected><?= gcm_e($branch) ?></option>
          <?php endif; ?>
        </select>

        <button type="button" class="nav-link text-start btn btn-link" data-bs-toggle="modal" data-bs-target="#createBranchModal">
          <i class="bi bi-signpost-split me-2"></i> New Branch
        </button>
        <button type="button" class="nav-link text-start btn btn-link" data-bs-toggle="modal" data-bs-target="#newFolderModal">
          <i class="bi bi-folder-plus me-2"></i> New Folder
        </button>
        <button type="button" class="nav-link text-start btn btn-link" data-bs-toggle="modal" data-bs-target="#uploadFilesModal">
          <i class="bi bi-upload me-2"></i> Upload Files
        </button>
        <button type="button" class="nav-link text-start btn btn-link" data-bs-toggle="modal" data-bs-target="#uploadZipModal">
          <i class="bi bi-file-zip me-2"></i> Upload ZIP
        </button>

        <hr class="text-secondary opacity-25">
        <a class="nav-link text-danger" href="logout.php">
          <i class="bi bi-box-arrow-right me-2"></i> Sign Out
        </a>
      </nav>
    </aside>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="app-main">
      <div class="container-fluid py-4 px-3 px-md-4">

        <!-- Commit message bar (used by all write actions on this page) -->
        <div class="card gcm-card border-0 mb-3">
          <div class="card-body py-2 px-3">
            <div class="row g-2 align-items-center">
              <div class="col-auto text-secondary small"><i class="bi bi-chat-left-text me-1"></i> Commit message</div>
              <div class="col">
                <input type="text" class="form-control form-control-sm" id="commitMessageInput"
                       placeholder="Describe your change (used for uploads, edits, deletes...)" maxlength="500">
              </div>
              <div class="col-auto text-secondary small d-none d-md-block">
                <i class="bi bi-git me-1"></i> Branch: <span class="fw-semibold text-body"><?= gcm_e($branch) ?></span>
              </div>
            </div>
          </div>
        </div>

        <!-- Breadcrumbs -->
        <nav aria-label="breadcrumb" class="mb-3">
          <ol class="breadcrumb gcm-breadcrumb mb-0 flex-wrap">
            <li class="breadcrumb-item">
              <a href="explorer.php?owner=<?= urlencode($owner) ?>&repo=<?= urlencode($repo) ?>&branch=<?= urlencode($branch) ?>">
                <i class="bi bi-house-door-fill me-1"></i><?= gcm_e($repo) ?>
              </a>
            </li>
            <?php foreach ($breadcrumbs as $i => [$label, $crumbPath]): ?>
              <?php if ($i === count($breadcrumbs) - 1): ?>
                <li class="breadcrumb-item active" aria-current="page"><?= gcm_e($label) ?></li>
              <?php else: ?>
                <li class="breadcrumb-item">
                  <a href="explorer.php?owner=<?= urlencode($owner) ?>&repo=<?= urlencode($repo) ?>&branch=<?= urlencode($branch) ?>&path=<?= urlencode($crumbPath) ?>">
                    <?= gcm_e($label) ?>
                  </a>
                </li>
              <?php endif; ?>
            <?php endforeach; ?>
          </ol>
        </nav>

        <?php if ($contentsError !== ''): ?>
          <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i> <?= gcm_e($contentsError) ?></div>
        <?php endif; ?>

        <?php if ($isFileView && $fileMeta): ?>
          <!-- ============================ FILE VIEW / EDIT ============================ -->
          <div class="card gcm-card border-0">
            <div class="card-header d-flex flex-wrap align-items-center gap-2 bg-transparent">
              <i class="bi <?= gcm_file_icon('file', (string)$fileMeta['name']) ?> fs-5"></i>
              <span class="fw-semibold text-truncate"><?= gcm_e((string)$fileMeta['name']) ?></span>
              <span class="text-secondary small">(<?= gcm_filesize((int)($fileMeta['size'] ?? 0)) ?>)</span>
              <div class="ms-auto d-flex gap-2 flex-wrap">
                <?php if ($parentPath !== '' || $path !== ''): ?>
                <a class="btn btn-sm btn-outline-secondary"
                   href="explorer.php?owner=<?= urlencode($owner) ?>&repo=<?= urlencode($repo) ?>&branch=<?= urlencode($branch) ?>&path=<?= urlencode($parentPath) ?>">
                  <i class="bi bi-arrow-left me-1"></i> Back
                </a>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-primary" id="downloadFileBtn"
                        data-path="<?= gcm_e((string)$fileMeta['path']) ?>">
                  <i class="bi bi-download me-1"></i> Download
                </button>
                <button class="btn btn-sm btn-outline-warning btn-rename-item"
                        data-path="<?= gcm_e((string)$fileMeta['path']) ?>" data-name="<?= gcm_e((string)$fileMeta['name']) ?>" data-type="file">
                  <i class="bi bi-pencil-square me-1"></i> Rename
                </button>
                <button class="btn btn-sm btn-outline-danger btn-delete-item"
                        data-path="<?= gcm_e((string)$fileMeta['path']) ?>" data-sha="<?= gcm_e((string)$fileMeta['sha']) ?>" data-type="file" data-name="<?= gcm_e((string)$fileMeta['name']) ?>">
                  <i class="bi bi-trash3 me-1"></i> Delete
                </button>
                <?php if ($isTextFile): ?>
                <button class="btn btn-sm btn-success" id="saveFileBtn">
                  <i class="bi bi-check-lg me-1"></i> Save Changes
                </button>
                <?php endif; ?>
              </div>
            </div>
            <div class="card-body p-0">
              <?php if ($fileTooLarge): ?>
                <div class="p-4 text-secondary text-center">
                  <i class="bi bi-file-earmark-lock display-5 d-block mb-2"></i>
                  This file is too large to preview inline. Please use Download instead.
                </div>
              <?php elseif (!$isTextFile): ?>
                <?php if (in_array(strtolower(pathinfo((string)$fileMeta['name'], PATHINFO_EXTENSION)), ['png','jpg','jpeg','gif','svg','webp'], true)): ?>
                  <div class="p-4 text-center bg-body-tertiary">
                    <img src="data:image;base64,<?= $fileMeta['content'] ?>" class="img-fluid rounded" style="max-height:70vh;" alt="<?= gcm_e((string)$fileMeta['name']) ?>">
                  </div>
                <?php else: ?>
                  <div class="p-4 text-secondary text-center">
                    <i class="bi bi-file-earmark-binary display-5 d-block mb-2"></i>
                    This appears to be a binary file and can't be edited here. Please use Download.
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <textarea id="fileEditor"><?= gcm_e($decodedContent) ?></textarea>
              <?php endif; ?>
            </div>
          </div>

        <?php else: ?>
          <!-- ============================ DIRECTORY LISTING ============================ -->
          <div class="card gcm-card border-0">
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0 gcm-file-table">
                <thead>
                  <tr class="text-secondary small text-uppercase">
                    <th>Name</th>
                    <th class="d-none d-md-table-cell">Size</th>
                    <th class="text-end pe-3">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($path !== ''): ?>
                  <tr>
                    <td colspan="3">
                      <a class="text-decoration-none d-inline-flex align-items-center gap-2"
                         href="explorer.php?owner=<?= urlencode($owner) ?>&repo=<?= urlencode($repo) ?>&branch=<?= urlencode($branch) ?>&path=<?= urlencode($parentPath) ?>">
                        <i class="bi bi-arrow-90deg-up"></i> <span class="text-secondary">.. (parent folder)</span>
                      </a>
                    </td>
                  </tr>
                  <?php endif; ?>

                  <?php foreach ($items as $item):
                    $itemName = (string)($item['name'] ?? '');
                    $itemPath = (string)($item['path'] ?? '');
                    $itemType = (string)($item['type'] ?? 'file');
                    $itemSize = (int)($item['size'] ?? 0);
                    $itemSha = (string)($item['sha'] ?? '');
                  ?>
                  <tr>
                    <td>
                      <?php if ($itemType === 'dir'): ?>
                        <a class="text-decoration-none d-inline-flex align-items-center gap-2"
                           href="explorer.php?owner=<?= urlencode($owner) ?>&repo=<?= urlencode($repo) ?>&branch=<?= urlencode($branch) ?>&path=<?= urlencode($itemPath) ?>">
                          <i class="bi <?= gcm_file_icon('dir', $itemName) ?>"></i> <?= gcm_e($itemName) ?>
                        </a>
                      <?php else: ?>
                        <a class="text-decoration-none d-inline-flex align-items-center gap-2"
                           href="explorer.php?owner=<?= urlencode($owner) ?>&repo=<?= urlencode($repo) ?>&branch=<?= urlencode($branch) ?>&path=<?= urlencode($itemPath) ?>">
                          <i class="bi <?= gcm_file_icon('file', $itemName) ?>"></i> <?= gcm_e($itemName) ?>
                        </a>
                      <?php endif; ?>
                    </td>
                    <td class="d-none d-md-table-cell text-secondary small">
                      <?= $itemType === 'file' ? gcm_filesize($itemSize) : '—' ?>
                    </td>
                    <td class="text-end pe-3">
                      <div class="btn-group btn-group-sm">
                        <?php if ($itemType === 'file'): ?>
                        <button class="btn btn-outline-secondary btn-download-item" data-path="<?= gcm_e($itemPath) ?>" title="Download">
                          <i class="bi bi-download"></i>
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-outline-secondary btn-rename-item"
                                data-path="<?= gcm_e($itemPath) ?>" data-name="<?= gcm_e($itemName) ?>" data-type="<?= gcm_e($itemType) ?>" title="Rename">
                          <i class="bi bi-pencil-square"></i>
                        </button>
                        <button class="btn btn-outline-danger btn-delete-item"
                                data-path="<?= gcm_e($itemPath) ?>" data-sha="<?= gcm_e($itemSha) ?>" data-type="<?= gcm_e($itemType) ?>" data-name="<?= gcm_e($itemName) ?>" title="Delete">
                          <i class="bi bi-trash3"></i>
                        </button>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>

                  <?php if (empty($items) && $path === '' && $contentsError === ''): ?>
                  <tr>
                    <td colspan="3" class="text-center text-secondary py-5">
                      <i class="bi bi-folder2-open display-4 d-block mb-3"></i>
                      This repository is empty. Upload files or create a folder to get started.
                    </td>
                  </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

      </div>
    </main>
  </div>
</div>

<!-- ============================ MODALS ============================ -->

<!-- Upload Files Modal -->
<div class="modal fade" id="uploadFilesModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-upload me-1"></i> Upload Files</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="file" class="form-control mb-3" id="uploadFilesInput" multiple>
        <div class="form-text mb-2">Files upload to: <code><?= gcm_e($path !== '' ? $path : '/') ?></code> on branch <code><?= gcm_e($branch) ?></code></div>
        <div id="uploadProgressWrap" class="d-none">
          <div class="progress mb-2" role="progressbar">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="uploadProgressBar" style="width:0%">0%</div>
          </div>
          <div class="small text-secondary" id="uploadProgressLabel"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="startUploadFilesBtn">
          <i class="bi bi-cloud-upload me-1"></i> Upload
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Upload ZIP Modal -->
<div class="modal fade" id="uploadZipModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-zip me-1"></i> Upload &amp; Extract ZIP</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="file" class="form-control mb-3" id="uploadZipInput" accept=".zip">
        <div class="form-text mb-2">
          The ZIP will be extracted on the server, then every file inside will be pushed to
          <code><?= gcm_e($path !== '' ? $path : '/') ?></code> on branch <code><?= gcm_e($branch) ?></code>.
        </div>
        <div id="zipProgressWrap" class="d-none">
          <div class="progress mb-2" role="progressbar">
            <div class="progress-bar progress-bar-striped progress-bar-animated bg-info" id="zipProgressBar" style="width:0%">0%</div>
          </div>
          <div class="small text-secondary" id="zipProgressLabel"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="startUploadZipBtn">
          <i class="bi bi-cloud-upload me-1"></i> Upload &amp; Extract
        </button>
      </div>
    </div>
  </div>
</div>

<!-- New Folder Modal -->
<div class="modal fade" id="newFolderModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-folder-plus me-1"></i> New Folder</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label small">Folder name</label>
        <input type="text" class="form-control" id="newFolderNameInput" placeholder="assets" maxlength="200">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="createFolderBtn">
          <i class="bi bi-check-lg me-1"></i> Create
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Create Branch Modal -->
<div class="modal fade" id="createBranchModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-signpost-split me-1"></i> New Branch</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label small">New branch name</label>
        <input type="text" class="form-control mb-3" id="newBranchNameInput" placeholder="feature/my-change" maxlength="200">
        <label class="form-label small">Based on</label>
        <input type="text" class="form-control" value="<?= gcm_e($branch) ?>" disabled>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="createBranchBtn">
          <i class="bi bi-check-lg me-1"></i> Create Branch
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Generic Rename Modal -->
<div class="modal fade" id="renameModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-1"></i> Rename</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label small">New name</label>
        <input type="text" class="form-control" id="renameNewNameInput" maxlength="255">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirmRenameBtn">
          <i class="bi bi-check-lg me-1"></i> Rename
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Generic Delete Confirmation Modal -->
<div class="modal fade" id="deleteItemModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-danger-subtle">
      <div class="modal-header">
        <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> Confirm Delete</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0">Are you sure you want to delete <strong id="deleteItemName"></strong>? This cannot be undone.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteItemBtn">
          <i class="bi bi-trash3 me-1"></i> Delete
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Toast container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/clike/clike.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/php/php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/python/python.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/markdown/markdown.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/shell/shell.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/yaml/yaml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/sql/sql.min.js"></script>
<script src="assets/js/app.js"></script>
<script>
  const GCM_CONFIG = <?= json_encode($pageConfig, JSON_UNESCAPED_SLASHES) ?>;
  GCM_CONFIG.codeMirrorMode = <?= json_encode($isFileView ? gcm_codemirror_mode((string)$fileMeta['name']) : 'null') ?>;
  GCM.initExplorerPage(GCM_CONFIG);
</script>
</body>
</html>