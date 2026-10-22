<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Tests\Unit;

use Closure;
use GlpiPlugin\Jamfsync\Api\JamfClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class JamfClientRetryTest extends TestCase
{
    /** @var list<array{method: string, url: string}> */
    private array $requests = [];

    /** @var list<int> */
    private array $sleeps = [];

    public function testRetriesServerErrorsWithExponentialBackoff(): void
    {
        $client = $this->client([
            ['status' => 503, 'body' => '', 'retry_after' => ''],
            ['status' => 502, 'body' => '', 'retry_after' => ''],
            ['status' => 200, 'body' => '{"devices":[{"id":1}]}', 'retry_after' => ''],
        ]);

        $result = $client->pullDevicesPaged(false, 200);

        self::assertSame([['id' => 1]], $result['devices']);
        self::assertSame(2, $client->getRetryCount());
        self::assertSame([2, 4], $this->sleeps);
        self::assertCount(3, $this->requests);
    }

    public function testHonoursRetryAfterSecondsOnRateLimit(): void
    {
        $client = $this->client([
            ['status' => 429, 'body' => '', 'retry_after' => '7'],
            ['status' => 200, 'body' => '{"devices":[]}', 'retry_after' => ''],
        ]);

        $client->pullDevicesPaged(false, 200);

        self::assertSame([7], $this->sleeps);
    }

    public function testGivesUpAfterFourAttempts(): void
    {
        $client = $this->client(array_fill(0, 4, ['status' => 500, 'body' => '', 'retry_after' => '']));

        try {
            $client->pullDevicesPaged(false, 200);
            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            self::assertSame('JAMF request failed with HTTP 500 after 4 attempts.', $exception->getMessage());
        }
        self::assertSame([2, 4, 8], $this->sleeps);
        self::assertSame(3, $client->getRetryCount());
    }

    public function testClientErrorsFailImmediately(): void
    {
        $client = $this->client([['status' => 401, 'body' => '{"message":"unauthorized"}', 'retry_after' => '']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JAMF request failed with HTTP 401.');
        try {
            $client->pullDevicesPaged(false, 200);
        } finally {
            self::assertSame([], $this->sleeps);
            self::assertCount(1, $this->requests);
        }
    }

    public function testRetryDelay(): void
    {
        $delay = new ReflectionMethod(JamfClient::class, 'retryDelay');
        $now = strtotime('2026-10-22 12:00:00 UTC');

        self::assertSame(2, $delay->invoke(null, 1, ''));
        self::assertSame(8, $delay->invoke(null, 3, ''));
        self::assertSame(30, $delay->invoke(null, 6, ''));
        self::assertSame(15, $delay->invoke(null, 1, '15'));
        self::assertSame(60, $delay->invoke(null, 1, '3600'));
        self::assertSame(20, $delay->invoke(null, 1, 'Thu, 22 Oct 2026 12:00:20 GMT', $now));
        self::assertSame(2, $delay->invoke(null, 1, 'not a date'));
    }

    public function testPaginationFollowsTotalPagesAndRequestsApps(): void
    {
        $client = $this->client([
            ['status' => 200, 'body' => '{"devices":[{"id":1}],"pagination":{"totalPages":2}}', 'retry_after' => ''],
            ['status' => 200, 'body' => '{"devices":[{"id":2}],"pagination":{"totalPages":2}}', 'retry_after' => ''],
        ]);

        $result = $client->pullDevicesPaged(true, 50);

        self::assertSame([['id' => 1], ['id' => 2]], $result['devices']);
        self::assertSame('page:2', $result['cursor_end']);
        self::assertSame('https://example.invalid/api/devices?limit=50&page=1&includeApps=true', $this->requests[0]['url']);
        self::assertSame('https://example.invalid/api/devices?limit=50&page=2&includeApps=true', $this->requests[1]['url']);
    }

    public function testConnectionTestReportsDeviceCount(): void
    {
        $client = $this->client([['status' => 200, 'body' => '{"code":200,"count":321,"devices":[{"id":1}]}', 'retry_after' => '']]);

        self::assertSame(['devices' => 321], $client->testConnection());
        self::assertSame('https://example.invalid/api/devices?limit=1&page=1', $this->requests[0]['url']);
    }

    public function testInvalidJsonIsReported(): void
    {
        $client = $this->client([['status' => 200, 'body' => '<html>', 'retry_after' => '']]);

        $this->expectExceptionMessage('JAMF response could not be decoded as JSON.');
        $client->testConnection();
    }

    /**
     * @param list<array{status: int, body: string, retry_after: string}> $responses
     */
    private function client(array $responses): JamfClient
    {
        $transport = function (string $method, string $url) use (&$responses): array {
            $this->requests[] = ['method' => $method, 'url' => $url];
            $response = array_shift($responses);
            self::assertNotNull($response, 'Unexpected extra request to ' . $url);

            return $response;
        };
        $sleep = function (int $seconds): void {
            $this->sleeps[] = $seconds;
        };

        return new JamfClient([
            'jamf_base_url' => 'https://example.invalid',
            'jamf_api_username' => 'user',
            'jamf_api_key' => 'key',
        ], Closure::fromCallable($transport), Closure::fromCallable($sleep));
    }
}
