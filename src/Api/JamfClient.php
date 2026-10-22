<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Api;

use Closure;
use Glpi\Toolbox\HttpClient;
use GlpiPlugin\Jamfsync\Instance;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class JamfClient
{
    /** Attempts per request: the first one plus up to three retries. */
    private const MAX_ATTEMPTS = 4;

    /** Rate limiting and transient server/gateway errors; anything else fails at once. */
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    private const MAX_BACKOFF = 30;
    private const MAX_RETRY_AFTER = 60;

    private string $baseUrl;
    private string $apiUsername;
    private string $apiKey;
    private bool $verifyTls;
    private int $timeout;
    private int $retries = 0;

    /** @var Closure(string, string, array<string, mixed>): array{status: int, body: string, retry_after: string} */
    private Closure $transport;

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param array<string, mixed> $settings Instance row (jamf_base_url, jamf_api_username, jamf_api_key, verify_tls, request_timeout).
     * @param (Closure(string, string, array<string, mixed>): array{status: int, body: string, retry_after: string})|null $transport
     *        Performs one HTTP request; defaults to GLPI's HttpClient (replaced in tests).
     * @param (Closure(int): void)|null $sleep Waits between retries (replaced in tests).
     */
    public function __construct(array $settings, ?Closure $transport = null, ?Closure $sleep = null)
    {
        $this->baseUrl = self::normalizeBaseUrl((string) ($settings['jamf_base_url'] ?? ''));
        $this->apiUsername = (string) ($settings['jamf_api_username'] ?? $settings['jamf_client_id'] ?? '');
        $this->apiKey = (string) ($settings['jamf_api_key'] ?? $settings['jamf_client_secret'] ?? '');
        $this->verifyTls = ((int) ($settings['verify_tls'] ?? 1)) === 1;
        $this->timeout = max(5, (int) ($settings['request_timeout'] ?? 30));
        $this->transport = $transport ?? self::glpiTransport(...);
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Number of requests retried so far (HTTP 429/5xx), for the run log.
     */
    public function getRetryCount(): int
    {
        return $this->retries;
    }

    /**
     * Cheap check of URL and credentials: one device page with a single entry.
     *
     * @return array{devices: int|null} Total device count when JAMF reports it.
     */
    public function testConnection(): array
    {
        $payload = $this->get('/api/devices?limit=1&page=1');
        $total = $payload['count'] ?? $payload['total'] ?? $payload['pagination']['total'] ?? null;

        return ['devices' => is_numeric($total) ? (int) $total : null];
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
        return $this->request('GET', $path);
    }

    private function request(string $method, string $path): array
    {
        if ($this->baseUrl === '' || $this->apiUsername === '' || $this->apiKey === '') {
            throw new RuntimeException('JAMF credentials are incomplete.');
        }

        $url = $this->baseUrl . $path;
        for ($attempt = 1; ; $attempt++) {
            $response = ($this->transport)($method, $url, $this->buildRequestOptions());
            $status = $response['status'];
            if ($status >= 200 && $status < 300) {
                break;
            }

            if (!in_array($status, self::RETRYABLE_STATUSES, true) || $attempt >= self::MAX_ATTEMPTS) {
                throw new RuntimeException(sprintf(
                    'JAMF request failed with HTTP %d%s.',
                    $status,
                    $attempt > 1 ? sprintf(' after %d attempts', $attempt) : ''
                ));
            }

            $this->retries++;
            ($this->sleep)(self::retryDelay($attempt, $response['retry_after']));
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('JAMF response could not be decoded as JSON.');
        }

        return $decoded;
    }

    /**
     * Seconds to wait before retry number $attempt: the server's Retry-After (seconds or
     * HTTP date) when present, otherwise exponential backoff (2, 4, 8 s); capped either way.
     */
    private static function retryDelay(int $attempt, string $retryAfter, ?int $now = null): int
    {
        $retryAfter = trim($retryAfter);
        if ($retryAfter !== '') {
            if (ctype_digit($retryAfter)) {
                $delay = (int) $retryAfter;
            } else {
                $timestamp = strtotime($retryAfter);
                $delay = $timestamp === false ? -1 : $timestamp - ($now ?? time());
            }
            if ($delay >= 0) {
                return min($delay, self::MAX_RETRY_AFTER);
            }
        }

        return min(2 ** $attempt, self::MAX_BACKOFF);
    }

    /**
     * One request through GLPI's HttpClient. The Instance itemtype is the context: GLPI
     * applies its proxy settings and refuses private-network targets unless the context is
     * listed in GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS.
     *
     * @param array<string, mixed> $options
     * @return array{status: int, body: string, retry_after: string}
     */
    private static function glpiTransport(string $method, string $url, array $options): array
    {
        try {
            try {
                $response = (new HttpClient(Instance::class))->request($method, $url, $options);
            } catch (HttpExceptionInterface $exception) {
                // GLPI's client throws for 3xx/4xx/5xx; the status decides about retries.
                $response = $exception->getResponse();
            }

            return [
                'status' => $response->getStatusCode(),
                'body' => $response->getContent(false),
                'retry_after' => (string) ($response->getHeaders(false)['retry-after'][0] ?? ''),
            ];
        } catch (TransportExceptionInterface $exception) {
            throw new RuntimeException('JAMF request failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Symfony HttpClient options for a JAMF School API call.
     *
     * @return array<string, mixed>
     */
    private function buildRequestOptions(): array
    {
        return [
            'auth_basic' => [$this->apiUsername, $this->apiKey],
            'headers' => [
                'Accept' => 'application/json',
                'X-Server-Protocol-Version' => '1',
            ],
            // request_timeout bounds the whole request, as CURLOPT_TIMEOUT did before.
            'timeout' => (float) $this->timeout,
            'max_duration' => (float) $this->timeout,
            'verify_peer' => $this->verifyTls,
            'verify_host' => $this->verifyTls,
            // Do not follow redirects: a 3xx is reported as an error, as before.
            'max_redirects' => 0,
        ];
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
