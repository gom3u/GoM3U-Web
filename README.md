# GitHub Cloud Manager

A production-ready, self-hosted web application for managing your GitHub
repositories from the browser — profile dashboard, a full file explorer,
file/folder create-rename-delete-download, a text/code editor with syntax
highlighting, ZIP upload & extraction, and Git operations (branches, commits)
— all built with plain PHP 8, Bootstrap 5, and vanilla JavaScript. No build
step, no Composer dependencies, no database required.

---

## 1. Features

**Authentication**
- Sign in with a GitHub Personal Access Token (PAT).
- The token is validated live against the GitHub API and stored **only**
  in the PHP session — never written to disk, a database, a cookie, or
  exposed in HTML/JavaScript.

**Dashboard**
- Profile summary (avatar, name, bio, followers/following, star count).
- Searchable grid of every repository you own or collaborate on.
- Repository name, description, star count, primary language, and last
  updated date.
- Create new repositories, delete repositories (type-to-confirm dialog).

**Repository Explorer / File Manager**
- Breadcrumb-based folder and file browsing.
- Upload single or multiple files with a live progress bar.
- Upload a `.zip` archive — it's extracted on the server and every file
  inside is pushed to the repository individually, with a live progress bar.
- Create folders, rename files/folders, delete files/folders (recursive),
  download files.
- Built-in code editor (CodeMirror) with syntax highlighting for
  JavaScript, PHP, Python, HTML, CSS, Markdown, YAML, SQL, shell, and more.

**Git Operations**
- Branch switcher, create new branches from any existing branch.
- A persistent commit-message field used by every write action.
- Every file change is committed and pushed immediately through the
  GitHub REST API (there is no local staging — GitHub's Contents API
  commits directly to the branch you're viewing).

**UI**
- Dark, GitHub-inspired theme (Bootstrap 5 `data-bs-theme="dark"`).
- Responsive layout with a collapsible off-canvas sidebar on mobile.
- Toast notifications, progress bars, and confirmation dialogs throughout.

**Security**
- CSRF tokens on every state-changing request.
- All inputs sanitized and validated (paths, branch names, repo names,
  filenames) before being sent to GitHub.
- Upload validation: size limits, blocked dangerous extensions
  (`.php`, `.exe`, `.sh`, etc.), ZIP entry validation (path traversal /
  zip-slip protection, entry count limits).
- Strict session cookie flags (`HttpOnly`, `SameSite=Lax`, `Secure` when
  served over HTTPS) and standard security response headers.
- The token is never echoed into any HTML page or JavaScript variable.

---

## 2. Tech stack

| Layer      | Technology                                   |
|------------|-----------------------------------------------|
| Backend    | PHP 8.0+ (no Composer packages required)      |
| Frontend   | Bootstrap 5.3, Bootstrap Icons, vanilla JS    |
| Editor     | CodeMirror 5 (loaded from a CDN)              |
| API        | GitHub REST API (`api.github.com`, v2022-11-28) |

Bootstrap, Bootstrap Icons, and CodeMirror are loaded from `cdnjs.cloudflare.com`
in the page `<head>` — no local copies or npm install needed.

---

## 3. Requirements

- **PHP 8.0 or newer**
- PHP extensions:
  - `curl` — required (all GitHub API calls)
  - `json` — required (virtually always bundled with PHP)
  - `zip` (ZipArchive) — required only for the ZIP Manager feature
  - `fileinfo` — recommended (used to detect MIME types on download; the
    app still works without it, falling back to a generic binary type)
  - `mbstring` — recommended (used for safe text-encoding checks)
- Outbound HTTPS access to `api.github.com` on port 443 (some locked-down
  shared hosts block outbound connections — see the InfinityFree notes below).
- Write access to the system temp directory (`sys_get_temp_dir()`), used
  briefly to extract ZIP uploads before they're pushed to GitHub.

---

## 4. Installation

1. **Upload the files.** Copy the entire `github-cloud-manager/` folder to
   your web server's public document root (e.g. `public_html/`,
   `htdocs/`, or a subfolder of it).

2. **Check folder permissions.** No folder inside the project needs to be
   writable — all "storage" happens in the PHP session and a system temp
   directory that PHP manages itself. If your host uses `open_basedir`
   restrictions, make sure `sys_get_temp_dir()` is inside the allowed path
   (this is true on almost all shared hosts by default).

3. **Confirm PHP version & extensions.** Most control panels (cPanel,
   Plesk, InfinityFree's client area) let you pick the PHP version and see
   enabled extensions. Select PHP 8.0+ and confirm `curl` (and ideally
   `zip`) are enabled.

4. **Visit `index.php`** in your browser (e.g.
   `https://yourdomain.com/github-cloud-manager/`).

5. **Generate a GitHub Personal Access Token:**
   - Go to **GitHub → Settings → Developer settings → Personal access
     tokens**.
   - Create a **classic token** (simplest option) with at least the
     `repo` scope (full control of private/public repositories).
   - If you also want to **delete repositories** from the dashboard, the
     classic token additionally needs the `delete_repo` scope.
   - Fine-grained tokens work too — grant "Contents", "Administration",
     and "Metadata" repository permissions (read & write) for the
     repositories you want to manage.
   - Copy the generated token (you'll only see it once).

6. **Paste the token into the login page** and sign in. That's it —
   nothing further to configure.

---

## 5. Notes for InfinityFree and similar free shared hosts

InfinityFree and comparable free hosts are usually fine for this app, with
a few things worth knowing:

- **Outbound connections**: some free hosts historically restricted
  outbound cURL requests to arbitrary domains. GitHub Cloud Manager only
  needs to reach `api.github.com` over HTTPS (port 443). If login fails
  with a "Network error contacting GitHub" message, ask your host to
  confirm outbound HTTPS to that domain is permitted.
- **`upload_max_filesize` / `post_max_size`**: shared hosts often cap
  these fairly low (e.g. 10–20 MB) in `php.ini`, which you usually can't
  edit directly. The app's own internal cap (`MAX_UPLOAD_BYTES` in
  `config.php`) is 25 MB; lower it if your host's PHP limits are smaller,
  so users get a clear in-app error instead of a silent PHP-level failure.
- **`ZipArchive` availability**: the `zip` PHP extension isn't always
  enabled by default on every free tier. If it's missing, every feature
  *except* ZIP upload/extraction still works fully — the app detects this
  and returns a clear error only when someone tries to use the ZIP Manager.
- **Sessions**: some free hosts use shared session storage with short
  garbage-collection windows. If you notice sessions (and therefore your
  logged-in state) expiring sooner than expected, this is a host-level
  `session.gc_maxlifetime` setting, not an app bug.
- **HTTPS**: strongly recommended. `config.php` automatically marks the
  session cookie `Secure` only when it detects HTTPS, so the app still
  works over plain HTTP for local testing, but you should only use a real
  GitHub token over HTTPS in production.

---

## 6. Project structure

```
github-cloud-manager/
├── index.php            # Login page (PAT entry + validation)
├── dashboard.php         # Profile + repository grid + search
├── explorer.php           # Folder/file browser, editor, Git operations UI
├── upload.php            # AJAX: file uploads + ZIP extract/push
├── save.php              # AJAX: create repo/folder/branch, save file, rename
├── delete.php             # AJAX: delete repo/file/folder
├── download.php           # Streams a repo file back to the browser
├── logout.php             # Destroys the session (and the token with it)
├── config.php             # Session setup, CSRF, sanitization, auth helpers
├── api/
│   └── github.php        # GitHubApi class — all GitHub REST API calls
├── assets/
│   ├── css/style.css     # Dark theme, responsive app shell, components
│   ├── js/app.js         # All client-side behavior (vanilla JS)
│   └── icons/            # App icon(s)
└── README.md
```

---

## 7. How writes actually work (important context)

GitHub's Contents API has no concept of a local staging area — every file
create/update/delete call is an **immediate commit and push** to the
branch you specify. This app's "commit message" field is therefore used
as the message for whichever action you take next (upload, save, delete,
rename), rather than batching multiple file changes into a single commit.
Renaming or deleting a *folder* is likewise implemented as multiple
individual per-file commits (Git itself has no folder object — a folder
simply stops existing once its last file is gone).

---

## 8. Security summary

- The GitHub token lives only in `$_SESSION` for the duration of your
  browser session (or until you click **Sign Out**, which destroys the
  session outright).
- Every POST request that changes data requires a valid CSRF token.
- All user-supplied paths, branch names, and filenames are validated
  against strict allow-list patterns before being used in any API call.
- Uploaded filenames are sanitized and dangerous extensions are blocked.
- ZIP extraction guards against path traversal ("zip-slip"), oversized
  entries, and archives with excessive numbers of files.
- Output is HTML-escaped everywhere user or GitHub-sourced text is
  rendered, to prevent stored/reflected XSS.

---

## 9. Disclaimer

This project is an independent tool that uses the GitHub REST API. It is
not affiliated with, endorsed by, or sponsored by GitHub, Inc. "GitHub" is
a trademark of GitHub, Inc.
