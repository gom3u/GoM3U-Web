/**
 * assets/js/app.js
 *
 * GitHub Cloud Manager - Application JavaScript
 * ---------------------------------------------------
 * A single global `GCM` namespace, vanilla JS (no build step, no framework).
 * Responsible for:
 *   - Toast notifications
 *   - Mobile sidebar toggle
 *   - Dashboard: repository search, create-repo modal, delete-repo modal
 *   - Explorer: branch switching, file editor (CodeMirror), rename/delete,
 *     new folder, new branch, file uploads, and ZIP upload + extract + push
 *
 * Every write action goes through fetch() calls to save.php / upload.php /
 * delete.php, all of which return JSON. The GitHub token itself is never
 * visible here — only owner/repo/branch/path/csrf values are passed around.
 */

const GCM = (function () {
  'use strict';

  // Picked up once at load time from a hidden csrf_token input already
  // present on the page (e.g. the "create repository" form on the
  // dashboard). Pages with no such input (like explorer.php) set this
  // explicitly via initExplorerPage(config) instead.
  let csrfToken = (document.querySelector('input[name="csrf_token"]') || {}).value || null;

  // -------------------------------------------------------------------
  // Small internal helpers
  // -------------------------------------------------------------------

  function spinner() {
    return '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>';
  }

  function setBusy(btn, busyText) {
    if (!btn) return () => {};
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = spinner() + busyText;
    return function restore() {
      btn.disabled = false;
      btn.innerHTML = original;
    };
  }

  /** POST a FormData payload and safely parse the JSON response. */
  async function postForm(url, formData) {
    try {
      const res = await fetch(url, { method: 'POST', body: formData, credentials: 'same-origin' });
      let data;
      try {
        data = await res.json();
      } catch (parseErr) {
        return { success: false, message: 'Unexpected server response (HTTP ' + res.status + ').' };
      }
      return data;
    } catch (networkErr) {
      return { success: false, message: 'Network error. Please check your connection and try again.' };
    }
  }

  function buildForm(fields) {
    const fd = new FormData();
    Object.keys(fields).forEach((key) => {
      if (fields[key] !== undefined && fields[key] !== null) {
        fd.set(key, fields[key]);
      }
    });
    fd.set('csrf_token', csrfToken || '');
    return fd;
  }

  function qs(id) {
    return document.getElementById(id);
  }

  // -------------------------------------------------------------------
  // Toast notifications
  // -------------------------------------------------------------------

  function toast(message, type) {
    type = type || 'info';
    const container = qs('toastContainer');
    if (!container) {
      // Fallback so nothing is silently lost if the container is missing.
      window.alert(message);
      return;
    }

    const icons = {
      success: 'bi-check-circle-fill text-success',
      danger: 'bi-x-circle-fill text-danger',
      warning: 'bi-exclamation-triangle-fill text-warning',
      info: 'bi-info-circle-fill text-info',
    };

    const wrapper = document.createElement('div');
    wrapper.className = 'toast gcm-toast gcm-toast-' + type;
    wrapper.setAttribute('role', 'alert');
    wrapper.setAttribute('aria-live', 'assertive');
    wrapper.setAttribute('aria-atomic', 'true');

    const flexDiv = document.createElement('div');
    flexDiv.className = 'd-flex';

    const body = document.createElement('div');
    body.className = 'toast-body d-flex align-items-start gap-2';

    const iconEl = document.createElement('i');
    iconEl.className = 'bi ' + (icons[type] || icons.info) + ' mt-1';

    const textEl = document.createElement('span');
    textEl.textContent = message; // textContent, never innerHTML: avoids XSS from any reflected data

    body.appendChild(iconEl);
    body.appendChild(textEl);

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'btn-close btn-close-white me-2 m-auto';
    closeBtn.setAttribute('data-bs-dismiss', 'toast');

    flexDiv.appendChild(body);
    flexDiv.appendChild(closeBtn);
    wrapper.appendChild(flexDiv);
    container.appendChild(wrapper);

    const bsToast = new bootstrap.Toast(wrapper, { delay: 5000 });
    wrapper.addEventListener('hidden.bs.toast', () => wrapper.remove());
    bsToast.show();
  }

  // -------------------------------------------------------------------
  // Mobile sidebar toggle (runs automatically on every page that has it)
  // -------------------------------------------------------------------

  function initSidebarToggle() {
    const toggleBtn = qs('sidebarToggleBtn');
    const sidebar = qs('appSidebar');
    const backdrop = qs('sidebarBackdrop');
    if (!toggleBtn || !sidebar || !backdrop) return;

    function close() {
      sidebar.classList.remove('show');
      backdrop.classList.remove('show');
    }

    toggleBtn.addEventListener('click', () => {
      sidebar.classList.toggle('show');
      backdrop.classList.toggle('show');
    });
    backdrop.addEventListener('click', close);
    sidebar.querySelectorAll('a.nav-link').forEach((link) => link.addEventListener('click', close));
  }

  // -------------------------------------------------------------------
  // Dashboard: repository search
  // -------------------------------------------------------------------

  function initRepoSearch() {
    const input = qs('repoSearchInput');
    if (!input) return;

    const cards = Array.from(document.querySelectorAll('.repo-card-wrap'));
    const countLabel = qs('repoCountLabel');
    const noResults = qs('noResultsMsg');

    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      let visible = 0;
      cards.forEach((card) => {
        const matches = card.dataset.repoName.includes(q) || card.dataset.repoDesc.includes(q);
        card.style.display = matches ? '' : 'none';
        if (matches) visible++;
      });
      if (countLabel) countLabel.textContent = visible + ' repositor' + (visible === 1 ? 'y' : 'ies');
      if (noResults) noResults.classList.toggle('d-none', visible !== 0 || cards.length === 0);
    });
  }

  // -------------------------------------------------------------------
  // Dashboard: create repository
  // -------------------------------------------------------------------

  function initCreateRepo() {
    const form = qs('createRepoForm');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const submitBtn = form.querySelector('button[type="submit"]');
      const restore = setBusy(submitBtn, 'Creating...');

      const fd = new FormData(form);
      fd.set('action', 'create_repo');
      if (!fd.get('private')) fd.set('private', ''); // switch unchecked -> falsy on server

      const data = await postForm('save.php', fd);
      restore();

      if (data.success) {
        toast(data.message || 'Repository created.', 'success');
        const modalEl = qs('createRepoModal');
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        setTimeout(() => window.location.reload(), 700);
      } else {
        toast(data.message || 'Could not create repository.', 'danger');
      }
    });
  }

  // -------------------------------------------------------------------
  // Dashboard: delete repository (with type-to-confirm)
  // -------------------------------------------------------------------

  function initDeleteRepo() {
    const modalEl = qs('deleteRepoModal');
    if (!modalEl) return;

    const nameEl = qs('deleteRepoName');
    const confirmInput = qs('deleteRepoConfirmInput');
    const confirmBtn = qs('confirmDeleteRepoBtn');
    let target = null;

    document.querySelectorAll('.btn-delete-repo').forEach((btn) => {
      btn.addEventListener('click', () => {
        target = { owner: btn.dataset.owner, repo: btn.dataset.repo };
        nameEl.textContent = target.owner + '/' + target.repo;
        confirmInput.value = '';
        confirmBtn.disabled = true;
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
      });
    });

    confirmInput.addEventListener('input', () => {
      confirmBtn.disabled = !(target && confirmInput.value === target.repo);
    });

    confirmBtn.addEventListener('click', async () => {
      if (!target) return;
      const restore = setBusy(confirmBtn, 'Deleting...');

      const fd = buildForm({
        action: 'delete_repo',
        owner: target.owner,
        repo: target.repo,
        confirmName: confirmInput.value,
      });

      const data = await postForm('delete.php', fd);

      if (data.success) {
        toast(data.message || 'Repository deleted.', 'success');
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        setTimeout(() => window.location.reload(), 700);
      } else {
        restore();
        toast(data.message || 'Could not delete repository.', 'danger');
      }
    });
  }

  // -------------------------------------------------------------------
  // Explorer page: everything scoped to one repo/branch/path
  // -------------------------------------------------------------------

  function initExplorerPage(config) {
    csrfToken = config.csrfToken || csrfToken;

    function commitMessage() {
      const input = qs('commitMessageInput');
      return input ? input.value.trim() : '';
    }

    function explorerUrl(overrides) {
      const params = new URLSearchParams({
        owner: config.owner,
        repo: config.repo,
        branch: config.branch,
        path: config.path,
      });
      Object.keys(overrides || {}).forEach((k) => params.set(k, overrides[k]));
      return 'explorer.php?' + params.toString();
    }

    initBranchSwitch(config, explorerUrl);
    initFileEditor(config, commitMessage);
    initDownloadButtons(config);
    initRenameFlow(config, commitMessage, explorerUrl);
    initDeleteFlow(config, commitMessage, explorerUrl);
    initNewFolderFlow(config, commitMessage);
    initCreateBranchFlow(config, explorerUrl);
    initUploadFilesFlow(config, commitMessage);
    initUploadZipFlow(config, commitMessage);
  }

  function initBranchSwitch(config, explorerUrl) {
    const select = qs('branchSelect');
    if (!select) return;
    select.addEventListener('change', () => {
      window.location.href = explorerUrl({ branch: select.value, path: config.path });
    });
  }

  function initFileEditor(config, commitMessage) {
    const textarea = qs('fileEditor');
    const saveBtn = qs('saveFileBtn');
    if (!textarea) return;

    const mode = config.codeMirrorMode && config.codeMirrorMode !== 'null' ? config.codeMirrorMode : null;
    const editor = CodeMirror.fromTextArea(textarea, {
      lineNumbers: true,
      theme: 'dracula',
      mode: mode || undefined,
      viewportMargin: Infinity,
      tabSize: 2,
      indentUnit: 2,
    });

    if (!saveBtn) return;

    saveBtn.addEventListener('click', async () => {
      const restore = setBusy(saveBtn, 'Saving...');
      const fd = buildForm({
        action: 'save_file',
        owner: config.owner,
        repo: config.repo,
        branch: config.branch,
        path: config.filePath,
        sha: config.fileSha,
        content: editor.getValue(),
        message: commitMessage(),
      });

      const data = await postForm('save.php', fd);
      restore();

      if (data.success) {
        config.fileSha = data.newSha || config.fileSha;
        toast('File saved successfully.', 'success');
      } else {
        toast(data.message || 'Could not save file.', 'danger');
      }
    });
  }

  function initDownloadButtons(config) {
    function downloadUrl(path) {
      const params = new URLSearchParams({
        owner: config.owner,
        repo: config.repo,
        branch: config.branch,
        path: path,
      });
      return 'download.php?' + params.toString();
    }

    const singleBtn = qs('downloadFileBtn');
    if (singleBtn) {
      singleBtn.addEventListener('click', () => {
        window.location.href = downloadUrl(singleBtn.dataset.path);
      });
    }

    document.querySelectorAll('.btn-download-item').forEach((btn) => {
      btn.addEventListener('click', () => {
        window.location.href = downloadUrl(btn.dataset.path);
      });
    });
  }

  function initRenameFlow(config, commitMessage, explorerUrl) {
    const modalEl = qs('renameModal');
    if (!modalEl) return;

    const input = qs('renameNewNameInput');
    const confirmBtn = qs('confirmRenameBtn');
    let target = null;

    document.querySelectorAll('.btn-rename-item').forEach((btn) => {
      btn.addEventListener('click', () => {
        target = { path: btn.dataset.path, name: btn.dataset.name, type: btn.dataset.type };
        input.value = target.name;
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
        setTimeout(() => input.focus(), 300);
      });
    });

    confirmBtn.addEventListener('click', async () => {
      if (!target) return;
      const newName = input.value.trim();
      if (!newName) {
        toast('Please enter a name.', 'warning');
        return;
      }
      const restore = setBusy(confirmBtn, 'Renaming...');

      const fd = buildForm({
        action: 'rename_item',
        owner: config.owner,
        repo: config.repo,
        branch: config.branch,
        oldPath: target.path,
        newName: newName,
        type: target.type,
        message: commitMessage(),
      });

      const data = await postForm('save.php', fd);
      restore();

      if (data.success) {
        toast(data.message || 'Renamed successfully.', 'success');
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        const wasCurrentFile = config.isFileView && target.path === config.filePath;
        setTimeout(() => {
          window.location.href = wasCurrentFile
            ? explorerUrl({ path: data.newPath })
            : explorerUrl({ path: config.path });
        }, 500);
      } else {
        toast(data.message || 'Rename failed.', 'danger');
      }
    });
  }

  function initDeleteFlow(config, commitMessage, explorerUrl) {
    const modalEl = qs('deleteItemModal');
    if (!modalEl) return;

    const nameEl = qs('deleteItemName');
    const confirmBtn = qs('confirmDeleteItemBtn');
    let target = null;

    document.querySelectorAll('.btn-delete-item').forEach((btn) => {
      btn.addEventListener('click', () => {
        target = { path: btn.dataset.path, sha: btn.dataset.sha, type: btn.dataset.type, name: btn.dataset.name };
        nameEl.textContent = target.name;
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
      });
    });

    confirmBtn.addEventListener('click', async () => {
      if (!target) return;
      const restore = setBusy(confirmBtn, 'Deleting...');

      const action = target.type === 'dir' ? 'delete_folder' : 'delete_file';
      const fd = buildForm({
        action: action,
        owner: config.owner,
        repo: config.repo,
        branch: config.branch,
        path: target.path,
        sha: target.sha,
        message: commitMessage(),
      });

      const data = await postForm('delete.php', fd);
      restore();

      if (data.success) {
        toast(data.message || 'Deleted successfully.', 'success');
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        const wasCurrentFile = config.isFileView && target.path === config.filePath;
        setTimeout(() => {
          window.location.href = wasCurrentFile ? explorerUrl({ path: config.parentPath }) : explorerUrl({ path: config.path });
        }, 500);
      } else {
        toast(data.message || 'Delete failed.', 'danger');
      }
    });
  }

  function initNewFolderFlow(config, commitMessage) {
    const btn = qs('createFolderBtn');
    if (!btn) return;

    btn.addEventListener('click', async () => {
      const nameInput = qs('newFolderNameInput');
      const folderName = nameInput.value.trim();
      if (!folderName) {
        toast('Please enter a folder name.', 'warning');
        return;
      }
      const restore = setBusy(btn, 'Creating...');

      const fd = buildForm({
        action: 'create_folder',
        owner: config.owner,
        repo: config.repo,
        branch: config.branch,
        path: config.path,
        folderName: folderName,
        message: commitMessage(),
      });

      const data = await postForm('save.php', fd);
      restore();

      if (data.success) {
        toast(data.message || 'Folder created.', 'success');
        bootstrap.Modal.getOrCreateInstance(qs('newFolderModal')).hide();
        setTimeout(() => window.location.reload(), 600);
      } else {
        toast(data.message || 'Could not create folder.', 'danger');
      }
    });
  }

  function initCreateBranchFlow(config, explorerUrl) {
    const btn = qs('createBranchBtn');
    if (!btn) return;

    btn.addEventListener('click', async () => {
      const nameInput = qs('newBranchNameInput');
      const newBranch = nameInput.value.trim();
      if (!newBranch) {
        toast('Please enter a branch name.', 'warning');
        return;
      }
      const restore = setBusy(btn, 'Creating...');

      const fd = buildForm({
        action: 'create_branch',
        owner: config.owner,
        repo: config.repo,
        fromBranch: config.branch,
        newBranch: newBranch,
      });

      const data = await postForm('save.php', fd);
      restore();

      if (data.success) {
        toast(data.message || 'Branch created.', 'success');
        bootstrap.Modal.getOrCreateInstance(qs('createBranchModal')).hide();
        setTimeout(() => {
          window.location.href = explorerUrl({ branch: newBranch, path: '' });
        }, 600);
      } else {
        toast(data.message || 'Could not create branch.', 'danger');
      }
    });
  }

  // -------------------------------------------------------------------
  // File uploads (one request per file, so progress reflects real files)
  // -------------------------------------------------------------------

  function initUploadFilesFlow(config, commitMessage) {
    const btn = qs('startUploadFilesBtn');
    if (!btn) return;

    btn.addEventListener('click', async () => {
      const input = qs('uploadFilesInput');
      const files = Array.from(input.files || []);
      if (files.length === 0) {
        toast('Please choose at least one file.', 'warning');
        return;
      }

      const progressWrap = qs('uploadProgressWrap');
      const progressBar = qs('uploadProgressBar');
      const progressLabel = qs('uploadProgressLabel');
      progressWrap.classList.remove('d-none');

      const restore = setBusy(btn, 'Uploading...');
      let successCount = 0;
      const failures = [];

      for (let i = 0; i < files.length; i++) {
        const file = files[i];
        progressLabel.textContent = 'Uploading ' + (i + 1) + ' of ' + files.length + ': ' + file.name;

        const fd = buildForm({
          action: 'upload_file',
          owner: config.owner,
          repo: config.repo,
          branch: config.branch,
          path: config.path,
          message: commitMessage(),
        });
        fd.set('file', file);

        const data = await postForm('upload.php', fd);
        if (data.success) {
          successCount++;
        } else {
          failures.push(file.name + ': ' + (data.message || 'failed'));
        }

        const pct = Math.round(((i + 1) / files.length) * 100);
        progressBar.style.width = pct + '%';
        progressBar.textContent = pct + '%';
      }

      restore();
      input.value = '';

      if (failures.length === 0) {
        toast('Uploaded ' + successCount + ' file(s) successfully.', 'success');
        bootstrap.Modal.getOrCreateInstance(qs('uploadFilesModal')).hide();
        setTimeout(() => window.location.reload(), 700);
      } else {
        toast(successCount + ' succeeded, ' + failures.length + ' failed: ' + failures.slice(0, 2).join('; '), 'warning');
      }

      progressWrap.classList.add('d-none');
      progressBar.style.width = '0%';
      progressBar.textContent = '0%';
    });
  }

  // -------------------------------------------------------------------
  // ZIP upload: extract server-side, then push each file individually
  // -------------------------------------------------------------------

  function initUploadZipFlow(config, commitMessage) {
    const btn = qs('startUploadZipBtn');
    if (!btn) return;

    let activeToken = null;

    const modalEl = qs('uploadZipModal');
    if (modalEl) {
      modalEl.addEventListener('hidden.bs.modal', () => {
        if (activeToken) {
          const fd = buildForm({ action: 'zip_cancel', token: activeToken });
          // Best-effort cleanup; we don't need to wait for the response.
          postForm('upload.php', fd);
          activeToken = null;
        }
      });
    }

    btn.addEventListener('click', async () => {
      const input = qs('uploadZipInput');
      const file = (input.files || [])[0];
      if (!file) {
        toast('Please choose a ZIP file.', 'warning');
        return;
      }

      const progressWrap = qs('zipProgressWrap');
      const progressBar = qs('zipProgressBar');
      const progressLabel = qs('zipProgressLabel');
      progressWrap.classList.remove('d-none');
      progressLabel.textContent = 'Extracting ZIP on server...';

      const restore = setBusy(btn, 'Extracting...');

      const extractFd = buildForm({
        action: 'zip_extract',
        owner: config.owner,
        repo: config.repo,
        branch: config.branch,
        path: config.path,
        message: commitMessage(),
      });
      extractFd.set('zipfile', file);

      const extractResult = await postForm('upload.php', extractFd);

      if (!extractResult.success) {
        restore();
        toast(extractResult.message || 'Could not extract ZIP file.', 'danger');
        progressWrap.classList.add('d-none');
        return;
      }

      activeToken = extractResult.token;
      const total = extractResult.total;
      toast(extractResult.message || ('Extracted ' + total + ' file(s).'), 'info');

      let done = 0;
      let failures = 0;
      btn.innerHTML = spinner() + 'Pushing files...';

      while (true) {
        const pushFd = buildForm({ action: 'zip_push_next', token: activeToken });
        const pushResult = await postForm('upload.php', pushFd);

        if (!pushResult.success) {
          toast(pushResult.message || 'ZIP push interrupted.', 'danger');
          break;
        }

        done = pushResult.done;
        if (!pushResult.fileSuccess && pushResult.currentFile) {
          failures++;
        }

        const pct = total > 0 ? Math.round((done / total) * 100) : 100;
        progressBar.style.width = pct + '%';
        progressBar.textContent = pct + '%';
        progressLabel.textContent = 'Pushed ' + done + ' of ' + total + (pushResult.currentFile ? ' (' + pushResult.currentFile + ')' : '');

        if (pushResult.finished) {
          break;
        }
      }

      activeToken = null;
      restore();
      input.value = '';

      if (failures === 0) {
        toast('ZIP contents uploaded successfully (' + done + ' file(s)).', 'success');
      } else {
        toast('Uploaded with ' + failures + ' file(s) failing out of ' + total + '.', 'warning');
      }

      bootstrap.Modal.getOrCreateInstance(qs('uploadZipModal')).hide();
      setTimeout(() => window.location.reload(), 800);

      progressWrap.classList.add('d-none');
      progressBar.style.width = '0%';
      progressBar.textContent = '0%';
    });
  }

  // -------------------------------------------------------------------
  // Auto-init universal behaviors as soon as this script runs
  // -------------------------------------------------------------------
  initSidebarToggle();

  // -------------------------------------------------------------------
  // Public API
  // -------------------------------------------------------------------
  return {
    toast: toast,
    initRepoSearch: initRepoSearch,
    initCreateRepo: initCreateRepo,
    initDeleteRepo: initDeleteRepo,
    initExplorerPage: initExplorerPage,
  };
})();
