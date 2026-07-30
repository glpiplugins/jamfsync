<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Api;

use RuntimeException;

class JamfClient
{
    private string $baseUrl;
    private string $apiUsername;
    private string $apiKey;
    private bool $verifyTls;
    private int $timeout;

    public function __construct(array $settings)
    {
        $this->baseUrl = self::normalizeBaseUrl((string) ($settings['jamf_base_url'] ?? ''));
        $this->apiUsername = (string) ($settings['jamf_api_username'] ?? $settings['jamf_client_id'] ?? '');
        $this->apiKey = (string) ($settings['jamf_api_key'] ?? $settings['jamf_client_secret'] ?? '');
        $this->verifyTls = ((int) ($settings['verify_tls'] ?? 1)) === 1;
        $this->timeout = max(5, (int) ($settings['request_timeout'] ?? 30));
    }

    /**
     * Normalize the configured base URL. The client always appends "/api/..." itself,
     * so a base URL that already ends in "/api" (a common copy/paste mistake from the
     * JAMF School API base) would produce a doubled ".../api/api/..." path and HTTP 404.
     * Strip trailing slashes and a trailing "/api" so both forms work.
     */
    public static function normalizeBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $url = preg_replace('#/api$#i', '', $url) ?? $url;

        return rtrim($url, '/');
    }

    public function pullDevicesPaged(bool $includeApps = false, int $pageSize = 200): array
    {
        $pageSize = max(50, min(1000, $pageSize));
        $page = 1;
        $all = [];
        $pagesFetched = 0;
        $lastPageFingerprint = '';

        while (true) {
            $path = '/api/devices?limit=' . $pageSize . '&page=' . $page;
            if ($includeApps) {
                $path .= '&includeApps=true';
            }

            $payload = $this->get($path);
            $devices = $this->extractDevices($payload);
            $count = count($devices);
            $pagesFetched++;

            $pageFingerprint = hash('sha256', json_encode([
                'count' => $count,
                'first' => $devices[0] ?? null,
                'last' => $devices[$count - 1] ?? null,
            ]));
            if ($page > 1 && $pageFingerprint === $lastPageFingerprint) {
                break;
            }
            $lastPageFingerprint = $pageFingerprint;

            foreach ($devices as $device) {
                if (is_array($device)) {
                    $all[] = $device;
                }
            }

            $hasNext = $this->hasNextPage($payload, $page, $count, $pageSize);
            if (!$hasNext) {
                break;
            }

            $page++;

            // Safety break against broken/ignored pagination parameters.
            if ($pagesFetched >= 1000) {
                break;
            }
        }

        return [
            'devices' => $all,
            'cursor_end' => 'page:' . $pagesFetched,
            'pages_fetched' => $pagesFetched,
        ];
    }

    private function get(string $path): array
    {
        $response = $this->request('GET', $path);

        if (!is_array($response)) {
            throw new RuntimeException('JAMF response is not valid JSON.');
        }

        return $response;
    }

    private function request(string $method, string $path, array $overrides = []): array
    {
        if ($this->baseUrl === '' || $this->apiUsername === '' || $this->apiKey === '') {
            throw new RuntimeException('JAMF credentials are incomplete.');
        }

        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for JAMF request.');
        }

        $headers = [
            'Accept: application/json',
            'X-Server-Protocol-Version: 1',
            'Authorization: Basic ' . base64_encode($this->apiUsername . ':' . $this->apiKey),
        ];

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_HTTPHEADER => $headers,
        ];

        foreach ($overrides as $key => $value) {
            $options[$key] = $value;
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('JAMF request failed: ' . $curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('JAMF request failed with HTTP ' . $httpCode . '.');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('JAMF response could not be decoded as JSON.');
        }

        return $decoded;
    }

    private function extractDevices(array $payload): array
    {
        if (isset($payload['devices']) && is_array($payload['devices'])) {
            return $payload['devices'];
        }

        if (isset($payload['results']) && is_array($payload['results'])) {
            return $payload['results'];
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        return [];
    }

    private function hasNextPage(array $payload, int $page, int $count, int $pageSize): bool
    {
        if (isset($payload['pagination']) && is_array($payload['pagination'])) {
            $pagination = $payload['pagination'];
            if (isset($pagination['totalPages'])) {
                return $page < (int) $pagination['totalPages'];
            }
            if (isset($pagination['pages'])) {
                return $page < (int) $pagination['pages'];
            }
            if (isset($pagination['next']) && $pagination['next']) {
                return true;
            }
        }

        if (isset($payload['totalPages'])) {
            return $page < (int) $payload['totalPages'];
        }

        if (isset($payload['pages'])) {
            return $page < (int) $payload['pages'];
        }

        // Fallback: assume next page exists only when full page was returned.
        return $count >= $pageSize;
    }
}
