<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Tests\Unit;

use GlpiPlugin\Jamfsync\Api\JamfClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class JamfClientPaginationTest extends TestCase
{
    public function testExtractDevicesSupportsKnownPayloadShapes(): void
    {
        $client = new JamfClient([
            'jamf_base_url' => 'https://example.invalid',
            'jamf_api_username' => 'user',
            'jamf_api_key' => 'key',
        ]);

        self::assertSame([['id' => 1]], $this->callPrivate($client, 'extractDevices', [['devices' => [['id' => 1]]] ]));
        self::assertSame([['id' => 2]], $this->callPrivate($client, 'extractDevices', [['results' => [['id' => 2]]] ]));
        self::assertSame([['id' => 3]], $this->callPrivate($client, 'extractDevices', [['data' => [['id' => 3]]] ]));
        self::assertSame([['id' => 4]], $this->callPrivate($client, 'extractDevices', [[['id' => 4]]]));
        self::assertSame([], $this->callPrivate($client, 'extractDevices', [['unexpected' => true]]));
    }

    public function testHasNextPageUsesPaginationThenFallback(): void
    {
        $client = new JamfClient([
            'jamf_base_url' => 'https://example.invalid',
            'jamf_api_username' => 'user',
            'jamf_api_key' => 'key',
        ]);

        self::assertTrue($this->callPrivate($client, 'hasNextPage', [['pagination' => ['totalPages' => 3]], 1, 200, 200]));
        self::assertFalse($this->callPrivate($client, 'hasNextPage', [['pagination' => ['totalPages' => 2]], 2, 200, 200]));

        self::assertTrue($this->callPrivate($client, 'hasNextPage', [['totalPages' => 5], 4, 200, 200]));
        self::assertFalse($this->callPrivate($client, 'hasNextPage', [['pages' => 4], 4, 200, 200]));

        self::assertTrue($this->callPrivate($client, 'hasNextPage', [[], 1, 200, 200]));
        self::assertFalse($this->callPrivate($client, 'hasNextPage', [[], 1, 199, 200]));
    }

    public function testBuildRequestOptionsMapsInstanceSettings(): void
    {
        $client = new JamfClient([
            'jamf_base_url' => 'https://example.invalid',
            'jamf_api_username' => 'network-id',
            'jamf_api_key' => 'secret',
            'verify_tls' => 1,
            'request_timeout' => 45,
        ]);

        self::assertSame([
            'auth_basic' => ['network-id', 'secret'],
            'headers' => [
                'Accept' => 'application/json',
                'X-Server-Protocol-Version' => '1',
            ],
            'timeout' => 45.0,
            'max_duration' => 45.0,
            'verify_peer' => true,
            'verify_host' => true,
            'max_redirects' => 0,
        ], $this->callPrivate($client, 'buildRequestOptions', []));
    }

    public function testBuildRequestOptionsDisablesTlsChecksAndClampsTimeout(): void
    {
        $client = new JamfClient([
            'jamf_base_url' => 'https://example.invalid',
            'jamf_api_username' => 'network-id',
            'jamf_api_key' => 'secret',
            'verify_tls' => 0,
            'request_timeout' => 1,
        ]);

        $options = $this->callPrivate($client, 'buildRequestOptions', []);

        self::assertFalse($options['verify_peer']);
        self::assertFalse($options['verify_host']);
        self::assertSame(5.0, $options['timeout']);
        self::assertSame(5.0, $options['max_duration']);
    }

    private function callPrivate(object $object, string $method, array $args)
    {
        $reflection = new ReflectionClass($object);
        $instanceMethod = $reflection->getMethod($method);

        return $instanceMethod->invokeArgs($object, $args);
    }
}
