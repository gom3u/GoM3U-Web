<?php
/**
 * api/github.php
 *
 * GitHub Cloud Manager - GitHub REST API Client
 * -----------------------------------------------
 * A small, dependency-free wrapper around the GitHub REST API (v2022-11-28)
 * built on cURL. Every network call funnels through GitHubApi::request(),
 * which centralizes auth headers, error handling, and JSON decoding.
 *
 * This file defines ONE class: GitHubApi. No output is produced here and no
 * session access happens here — callers must pass in the token explicitly,
 * keeping this class reusable/testable and keeping the token flow explicit.
 */

declare(strict_types=1);

class GitHubApi
{
    private string $token;
    private string $baseUrl;
    private int $timeoutSeconds;

    /** @var array<string,mixed> Rate limit info from the most recent response. */
    public array $lastRateLimit = [];

    public function __construct(string $token, string $baseUrl = GITHUB_API_BASE, int $timeoutSeconds = 20)
    {
        $this->token = $token;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeoutSeconds = $timeoutSeconds;
    }

    // -------------------------------------------------------------------
    // Core request handling
    // -------------------------------------------------------------------

    /**
     * Perform an HTTP request against the GitHub API.
     *
     * @param string     $method   HTTP verb (GET, POST, PUT, PATCH, DELETE).
     * @param string     $endpoint Path beginning with '/', e.g. '/user'.
     * @param array|null $body     Associative array to be JSON-encoded, or null.
     * @param array      $query    Associative array of query-string params.
     *
     * @return array{success:bool,status:int,data:mixed,message:string}
     */
    public function request(string $method, string $endpoint, ?array $body = null, array $query = []): array
    {
        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'status' => 0,
                'data' => null,
                'message' => 'The cURL PHP extension is not available on this server.',
            ];
        }

        $url = $this->baseUrl . $endpoint;
        if (!empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: ' . GITHUB_API_VERSION,
            'User-Agent: GitHub-Cloud-Manager-PHP-App',
        ];

        $ch = curl_init($url);
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return [
                'success' => false,
                'status' => 0,
                'data' => null,
                'message' => 'Network error contacting GitHub: ' . $error,
            ];
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $rawBody = substr($raw, $headerSize);

        $this->captureRateLimit($rawHeaders);

        $decoded = null;
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $decoded = $rawBody; // non-JSON body (e.g. raw file content)
            }
        }

        $success = $status >= 200 && $status < 300;
        $message = $success ? 'OK' : $this->extractErrorMessage($decoded, $status);

        return [
            'success' => $success,
            'status' => $status,
            'data' => $decoded,
            'message' => $message,
        ];
    }

    private function captureRateLimit(string $rawHeaders): void
    {
        foreach (['x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset'] as $key) {
            if (preg_match('/' . preg_quote($key, '/') . ':\s*(\d+)/i', $rawHeaders, $m)) {
                $this->lastRateLimit[$key] = (int)$m[1];
            }
        }
    }

    private function extractErrorMessage($decoded, int $status): string
    {
        if (is_array($decoded) && !empty($decoded['message'])) {
            $msg = (string)$decoded['message'];
            if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
                $extra = array_map(function ($e) {
                    return is_array($e) ? ($e['message'] ?? json_encode($e)) : (string)$e;
                }, $decoded['errors']);
                $msg .= ' (' . implode('; ', $extra) . ')';
            }
            return $msg;
        }
        return 'GitHub API request failed with HTTP status ' . $status . '.';
    }

    // -------------------------------------------------------------------
    // Authentication / user
    // -------------------------------------------------------------------

    /** Validate the token by fetching the authenticated user. */
    public function validateToken(): array
    {
        return $this->request('GET', '/user');
    }

    public function getUser(): array
    {
        return $this->request('GET', '/user');
    }

    // -------------------------------------------------------------------
    // Repositories
    // -------------------------------------------------------------------

    /** List repositories for the authenticated user (paginated). */
    public function listRepos(int $page = 1, int $perPage = 100): array
    {
        return $this->request('GET', '/user/repos', null, [
            'page' => $page,
            'per_page' => $perPage,
            'sort' => 'updated',
            'affiliation' => 'owner,collaborator,organization_member',
        ]);
    }

    public function getRepo(string $owner, string $repo): array
    {
        return $this->request('GET', "/repos/{$owner}/{$repo}");
    }

    public function createRepo(string $name, string $description, bool $private, bool $autoInit = true): array
    {
        return $this->request('POST', '/user/repos', [
            'name' => $name,
            'description' => $description,
            'private' => $private,
            'auto_init' => $autoInit,
        ]);
    }

    public function deleteRepo(string $owner, string $repo): array
    {
        return $this->request('DELETE', "/repos/{$owner}/{$repo}");
    }

    // -------------------------------------------------------------------
    // Branches
    // -------------------------------------------------------------------

    public function listBranches(string $owner, string $repo): array
    {
        return $this->request('GET', "/repos/{$owner}/{$repo}/branches", null, ['per_page' => 100]);
    }

    public function getBranch(string $owner, string $repo, string $branch): array
    {
        return $this->request('GET', "/repos/{$owner}/{$repo}/branches/{$branch}");
    }

    /**
     * Create a new branch from an existing base branch by copying its ref.
     */
    public function createBranch(string $owner, string $repo, string $newBranch, string $fromBranch): array
    {
        $baseRef = $this->request('GET', "/repos/{$owner}/{$repo}/git/ref/heads/{$fromBranch}");
        if (!$baseRef['success']) {
            return $baseRef;
        }
        $sha = $baseRef['data']['object']['sha'] ?? null;
        if (!$sha) {
            return ['success' => false, 'status' => 500, 'data' => null, 'message' => 'Could not resolve base branch SHA.'];
        }
        return $this->request('POST', "/repos/{$owner}/{$repo}/git/refs", [
            'ref' => 'refs/heads/' . $newBranch,
            'sha' => $sha,
        ]);
    }

    // -------------------------------------------------------------------
    // Contents (files & folders)
    // -------------------------------------------------------------------

    /**
     * Get the contents of a path. Returns an array (directory listing) or
     * an associative array (single file) inside 'data', matching GitHub's API.
     */
    public function getContents(string $owner, string $repo, string $path, string $ref = ''): array
    {
        $query = $ref !== '' ? ['ref' => $ref] : [];
        $path = ltrim($path, '/');
        $endpoint = "/repos/{$owner}/{$repo}/contents" . ($path !== '' ? '/' . $path : '');
        return $this->request('GET', $endpoint, null, $query);
    }

    /**
     * Create a new file or update an existing one.
     * Pass $sha when updating an existing file (required by the GitHub API).
     */
    public function putFile(
        string $owner,
        string $repo,
        string $path,
        string $base64Content,
        string $message,
        string $branch,
        ?string $sha = null
    ): array {
        $path = ltrim($path, '/');
        $body = [
            'message' => $message,
            'content' => $base64Content,
            'branch' => $branch,
        ];
        if ($sha !== null) {
            $body['sha'] = $sha;
        }
        return $this->request('PUT', "/repos/{$owner}/{$repo}/contents/{$path}", $body);
    }

    /** Delete a file. Requires the file's current SHA. */
    public function deleteFile(
        string $owner,
        string $repo,
        string $path,
        string $message,
        string $sha,
        string $branch
    ): array {
        $path = ltrim($path, '/');
        // DELETE requests with a JSON body need the body sent via request();
        // GitHub supports this via the standard request path.
        return $this->requestWithBody('DELETE', "/repos/{$owner}/{$repo}/contents/{$path}", [
            'message' => $message,
            'sha' => $sha,
            'branch' => $branch,
        ]);
    }

    /**
     * Some HTTP clients strip bodies from DELETE; cURL does not, but we
     * expose a distinct method name for clarity at call sites.
     */
    private function requestWithBody(string $method, string $endpoint, array $body): array
    {
        return $this->request($method, $endpoint, $body);
    }

    /**
     * Create an empty "folder" by placing a .gitkeep placeholder file,
     * since Git has no concept of empty directories.
     */
    public function createFolder(string $owner, string $repo, string $path, string $branch, string $message = 'Create folder'): array
    {
        $path = trim($path, '/') . '/.gitkeep';
        return $this->putFile($owner, $repo, $path, base64_encode(''), $message, $branch);
    }

    /**
     * Rename or move a file: fetches current content, writes it to the new
     * path, then deletes the old path. Not atomic (GitHub's Contents API has
     * no native rename), but safe: the delete only runs after a successful
     * create, so a failure never loses data.
     */
    public function renameFile(
        string $owner,
        string $repo,
        string $oldPath,
        string $newPath,
        string $branch,
        string $message = 'Rename file'
    ): array {
        $current = $this->getContents($owner, $repo, $oldPath, $branch);
        if (!$current['success'] || !isset($current['data']['content'])) {
            return [
                'success' => false,
                'status' => $current['status'] ?? 500,
                'data' => null,
                'message' => 'Could not read source file for rename: ' . $current['message'],
            ];
        }

        $content = $current['data']['content']; // already base64, may contain newlines
        $create = $this->putFile($owner, $repo, $newPath, $content, $message, $branch);
        if (!$create['success']) {
            return $create;
        }

        $delete = $this->deleteFile($owner, $repo, $oldPath, $message, $current['data']['sha'], $branch);
        if (!$delete['success']) {
            return [
                'success' => false,
                'status' => $delete['status'],
                'data' => $create['data'],
                'message' => 'New file created but old file could not be removed: ' . $delete['message'],
            ];
        }

        return $create;
    }

    // -------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------

    /** Search the authenticated user's repositories by name/description. */
    public function searchUserRepos(string $username, string $queryText, int $page = 1, int $perPage = 50): array
    {
        $q = trim($queryText) . ' user:' . $username;
        return $this->request('GET', '/search/repositories', null, [
            'q' => $q,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }
}
