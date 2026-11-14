<?php

namespace GeoEngine\Agent;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use GeoEngine\Agent\Models\Setting;
use GeoEngine\Agent\Version;

/**
 * HTTP client for the GEO Engine platform agent API.
 *
 * Endpoints (all under {api_url}/api/v1/cms):
 *   POST /agent/register      project_api_key + site_url + cms_type → agent_token
 *   POST /agent/ping          heartbeat + metadata (agent_version, crawler_hits)
 *   GET  /tasks/pending       Bearer agent_token → [{id, task_type, payload, attempt}]
 *   POST /tasks/{id}/result   {success, message, error, result_data, attempt}
 *   POST /agent/submit-pages  bulk page scan results
 *   GET  /agent/package       self_update archive (Bearer agent_token)
 */
class AgentClient
{
    private Client $http;
    private string $apiUrl;

    public function __construct(?string $apiUrl = null)
    {
        $this->apiUrl = rtrim($apiUrl ?: config('geoengine.api_url', 'https://geoengine.pro'), '/');
        $this->http = new Client([
            'base_uri' => $this->apiUrl . '/api/v1/cms/',
            'timeout' => (float) config('geoengine.request_timeout', 60),
            'connect_timeout' => 15,
            'http_errors' => false,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    public static function agentToken(): string
    {
        $token = (string) config('geoengine.agent_token', '');
        if ($token !== '') {
            return $token;
        }
        try {
            return (string) (Setting::get('agent_token') ?? '');
        } catch (\Throwable) {
            return ''; // DB not migrated yet
        }
    }

    public static function agentId(): ?int
    {
        $id = config('geoengine.agent_id');
        if ($id) {
            return (int) $id;
        }
        try {
            $v = Setting::get('agent_id');
            return $v ? (int) $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function configured(): bool
    {
        return self::agentToken() !== '' || (string) config('geoengine.project_key', '') !== '';
    }

    private function authHeaders(): array
    {
        $token = self::agentToken();
        return $token !== '' ? ['Authorization' => 'Bearer ' . $token] : [];
    }

    /**
     * Register (or re-register) the agent. Returns [agent_id, agent_token].
     * Persists credentials into geoengine_settings + .env guidance.
     */
    public function register(): array
    {
        $siteUrl = rtrim((string) config('geoengine.site_url'), '/');
        $projectKey = (string) config('geoengine.project_key', '');
        if ($projectKey === '') {
            throw new \RuntimeException('GEO_ENGINE_PROJECT_KEY is not configured');
        }
        if ($siteUrl === '') {
            throw new \RuntimeException('GEO_ENGINE_SITE_URL / APP_URL is not configured');
        }

        $resp = $this->http->post('agent/register', [
            RequestOptions::JSON => [
                'site_url' => $siteUrl,
                'cms_type' => 'laravel',
                'project_api_key' => $projectKey,
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $status = $resp->getStatusCode();
        $body = json_decode((string) $resp->getBody(), true) ?: [];
        if ($status >= 400) {
            throw new \RuntimeException(
                'Agent registration failed (HTTP ' . $status . '): '
                . ($body['detail'] ?? $body['message'] ?? 'unknown error')
            );
        }

        $agentId = (int) ($body['agent_id'] ?? 0);
        $token = (string) ($body['agent_token'] ?? '');
        if (!$agentId || $token === '') {
            throw new \RuntimeException('Agent registration returned an invalid response');
        }

        Setting::put('agent_id', $agentId);
        Setting::put('agent_token', $token);

        return ['agent_id' => $agentId, 'agent_token' => $token];
    }

    /**
     * Heartbeat. metadata.agent_version drives self_update queueing;
     * crawler_hits feed the AI-crawler analytics.
     */
    public function ping(array $extraMeta = []): bool
    {
        $agentId = self::agentId();
        if (!$agentId) {
            return false;
        }

        $meta = array_merge([
            'agent_version' => Version::VERSION,
            'cms' => 'laravel',
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
        ], $extraMeta);

        try {
            $resp = $this->http->post('agent/ping', [
                RequestOptions::JSON => ['metadata' => $meta],
                'headers' => $this->authHeaders() + ['Content-Type' => 'application/json'],
            ]);
            return $resp->getStatusCode() < 400;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Poll pending tasks. Returns list of {id, task_type, priority, payload, attempt}.
     */
    public function pendingTasks(): array
    {
        $agentId = self::agentId();
        if (!$agentId) {
            return [];
        }

        $resp = $this->http->get('tasks/pending', [
            'query' => ['agent_id' => $agentId],
            'headers' => $this->authHeaders(),
        ]);
        if ($resp->getStatusCode() !== 200) {
            return [];
        }
        $tasks = json_decode((string) $resp->getBody(), true);
        return is_array($tasks) ? $tasks : [];
    }

    /**
     * Report task execution result back to the platform.
     */
    public function reportResult(int $taskId, bool $success, string $message = '', ?string $error = null, ?array $resultData = null, ?int $attempt = null): bool
    {
        try {
            $body = [
                'success' => $success,
                'message' => $message,
                'error' => $error,
                'result_data' => $resultData,
            ];
            if ($attempt !== null) {
                $body['attempt'] = $attempt;
            }
            $resp = $this->http->post('tasks/' . $taskId . '/result', [
                RequestOptions::JSON => $body,
                'headers' => $this->authHeaders() + ['Content-Type' => 'application/json'],
            ]);
            return $resp->getStatusCode() < 400;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Bulk page scan submission (scan_site_structure result).
     */
    public function submitPages(array $pages, ?int $crawlId = null): bool
    {
        $body = ['pages' => $pages];
        if ($crawlId) {
            $body['crawl_id'] = $crawlId;
        }
        try {
            $resp = $this->http->post('agent/submit-pages', [
                RequestOptions::JSON => $body,
                'headers' => $this->authHeaders() + ['Content-Type' => 'application/json'],
            ]);
            return $resp->getStatusCode() < 400;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Download the agent package zip for self_update.
     * Returns [tmp_path, sha256_header, version_header].
     */
    public function downloadPackage(): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'geoengine_pkg_') . '.zip';
        $resp = $this->http->get('agent/package', [
            'headers' => $this->authHeaders(),
            'sink' => $tmp,
        ]);
        if ($resp->getStatusCode() !== 200) {
            @unlink($tmp);
            throw new \RuntimeException('Package download failed (HTTP ' . $resp->getStatusCode() . ')');
        }
        return [
            'path' => $tmp,
            'sha256' => (string) $resp->getHeaderLine('X-GeoEngine-Sha256'),
            'version' => (string) $resp->getHeaderLine('X-GeoEngine-Version'),
        ];
    }

    /**
     * Platform-side helpers agents call for bookkeeping (campaign pubs,
     * geotargeting confirmations live platform-side; kept for parity).
     */
    public function apiPost(string $path, array $body): ?array
    {
        try {
            $resp = $this->http->post($path, [
                RequestOptions::JSON => $body,
                'headers' => $this->authHeaders() + ['Content-Type' => 'application/json'],
            ]);
            $decoded = json_decode((string) $resp->getBody(), true);
            return $resp->getStatusCode() < 400 ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
