<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Tests\Unit;

use GlpiPlugin\Jamfsync\InstanceRepository;
use PHPUnit\Framework\TestCase;

final class InstanceRepositoryTest extends TestCase
{
    private FakeDbForInstanceRepository $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new FakeDbForInstanceRepository();
        $this->db->tables['glpi_plugin_jamfsync_instances'] = [];

        global $DB;
        $DB = $this->db;

        $_SESSION['glpi_currenttime'] = '2026-02-26 10:00:00';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['glpi_currenttime']);
        parent::tearDown();
    }

    public function testRotateApiKeyEncryptionEncryptsExistingPlainValues(): void
    {
        $this->db->tables['glpi_plugin_jamfsync_instances'][] = [
            'id' => 9,
            'name' => 'Tenant',
            'jamf_base_url' => 'https://tenant.example.org',
            'jamf_api_username' => 'user',
            'jamf_api_key' => 'plain-key',
            'jamf_api_key_is_encrypted' => 0,
            'entities_id' => 0,
            'pull_devices' => 1,
            'pull_apps' => 1,
            'name_source' => 'device_name',
            'verify_tls' => 1,
            'request_timeout' => 30,
            'page_size' => 200,
            'is_active' => 1,
            'date_creation' => '2026-01-01 00:00:00',
            'date_mod' => '2026-01-01 00:00:00',
        ];

        $repository = new InstanceRepository();
        $updated = $repository->rotateApiKeyEncryption();
        self::assertSame(1, $updated);

        $row = $this->db->tables['glpi_plugin_jamfsync_instances'][0];
        self::assertSame(1, $row['jamf_api_key_is_encrypted']);
        self::assertNotSame('plain-key', $row['jamf_api_key']);
        self::assertSame('plain-key', (new \GLPIKey())->decrypt((string) $row['jamf_api_key']));
    }
}

final class FakeDbForInstanceRepository
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $tables = [];

    private int $insertId = 0;

    public function tableExists(string $table): bool
    {
        return array_key_exists($table, $this->tables);
    }

    public function request(array $query): \ArrayIterator
    {
        $table = (string) ($query['FROM'] ?? '');
        $rows = $this->tables[$table] ?? [];

        if (isset($query['WHERE']) && is_array($query['WHERE'])) {
            $where = $query['WHERE'];
            $rows = array_values(array_filter($rows, static function (array $row) use ($where): bool {
                foreach ($where as $field => $expected) {
                    if (!array_key_exists((string) $field, $row) || (string) $row[(string) $field] !== (string) $expected) {
                        return false;
                    }
                }
                return true;
            }));
        }

        if (isset($query['LIMIT'])) {
            $rows = array_slice($rows, 0, (int) $query['LIMIT']);
        }

        return new \ArrayIterator($rows);
    }

    public function insert(string $table, array $payload): void
    {
        if (!$this->tableExists($table)) {
            $this->tables[$table] = [];
        }

        $this->insertId++;
        $payload['id'] = $this->insertId;
        $this->tables[$table][] = $payload;
    }

    public function insertId(): int
    {
        return $this->insertId;
    }

    public function update(string $table, array $payload, array $where): void
    {
        $rows = $this->tables[$table] ?? [];
        foreach ($rows as $idx => $row) {
            $match = true;
            foreach ($where as $field => $value) {
                if (!isset($row[(string) $field]) || (string) $row[(string) $field] !== (string) $value) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                $this->tables[$table][$idx] = array_merge($row, $payload);
            }
        }
    }

    public function delete(string $table, array $where): void
    {
        $rows = $this->tables[$table] ?? [];
        $this->tables[$table] = array_values(array_filter($rows, static function (array $row) use ($where): bool {
            foreach ($where as $field => $value) {
                if (isset($row[(string) $field]) && (string) $row[(string) $field] === (string) $value) {
                    return false;
                }
            }
            return true;
        }));
    }
}
