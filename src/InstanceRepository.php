<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync;

use RuntimeException;

class InstanceRepository
{
    private const TABLE = 'glpi_plugin_jamfsync_instances';

    public function getAll(): array
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return [];
        }

        $rows = [];
        $iterator = $DB->request([
            'FROM' => self::TABLE,
            'ORDER' => ['is_active DESC', 'name ASC', 'id ASC'],
        ]);

        foreach ($iterator as $row) {
            $rows[] = $this->normalizeRow($row, false);
        }

        return $rows;
    }

    public function getActive(): array
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return [];
        }

        $rows = [];
        $iterator = $DB->request([
            'FROM' => self::TABLE,
            'WHERE' => [
                'is_active' => 1,
            ],
            'ORDER' => ['name ASC', 'id ASC'],
        ]);

        foreach ($iterator as $row) {
            $rows[] = $this->normalizeRow($row, true);
        }

        return $rows;
    }

    public function getById(int $id, bool $includeSecrets = false): ?array
    {
        global $DB;

        if ($id <= 0 || !$DB->tableExists(self::TABLE)) {
            return null;
        }

        $row = $DB->request([
            'FROM' => self::TABLE,
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ])->current();

        if (!$row) {
            return null;
        }

        return $this->normalizeRow($row, $includeSecrets);
    }

    public function updateCheckpoint(int $id, ?string $lastSuccessAt, string $cursor = ''): void
    {
        global $DB;

        if ($id <= 0 || !$DB->tableExists(self::TABLE)) {
            return;
        }

        $DB->update(self::TABLE, [
            'last_success_at' => $lastSuccessAt,
            'last_cursor' => trim($cursor),
            'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function rotateApiKeyEncryption(): int
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return 0;
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $updated = 0;

        $iterator = $DB->request([
            'FROM' => self::TABLE,
        ]);

        foreach ($iterator as $row) {
            $id = (int) ($row['id'] ?? 0);
            $plain = $this->decryptApiKey($row);
            if ($id <= 0 || $plain === '') {
                continue;
            }

            $DB->update(self::TABLE, [
                'jamf_api_key' => $this->encryptApiKey($plain),
                'jamf_api_key_is_encrypted' => 1,
                'date_mod' => $now,
            ], ['id' => $id]);
            $updated++;
        }

        return $updated;
    }

    private function normalizeRow(array $row, bool $includeSecrets): array
    {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['entities_id'] = (int) ($row['entities_id'] ?? 0);
        $row['pull_devices'] = (int) (($row['pull_devices'] ?? 0) > 0 ? 1 : 0);
        $row['pull_apps'] = (int) (($row['pull_apps'] ?? 0) > 0 ? 1 : 0);
        $row['name_source'] = $this->normalizeNameSource((string) ($row['name_source'] ?? 'device_name'));
        $row['verify_tls'] = (int) (($row['verify_tls'] ?? 0) > 0 ? 1 : 0);
        $row['request_timeout'] = max(5, min(120, (int) ($row['request_timeout'] ?? 30)));
        $row['page_size'] = max(50, min(1000, (int) ($row['page_size'] ?? 200)));
        $row['is_active'] = (int) (($row['is_active'] ?? 0) > 0 ? 1 : 0);
        $row['last_success_at'] = (string) ($row['last_success_at'] ?? '');
        $row['last_cursor'] = trim((string) ($row['last_cursor'] ?? ''));
        $row['jamf_api_key_is_encrypted'] = (int) (($row['jamf_api_key_is_encrypted'] ?? 0) > 0 ? 1 : 0);
        $row['jamf_api_key'] = $includeSecrets ? $this->decryptApiKey($row) : '';

        return $row;
    }

    private function normalizeNameSource(string $value): string
    {
        $value = trim(strtolower($value));
        return $value === 'asset_tag' ? 'asset_tag' : 'device_name';
    }

    private function encryptApiKey(string $plain): string
    {
        $encrypted = (new \GLPIKey())->encrypt(trim($plain));
        if ($encrypted === '') {
            throw new RuntimeException(__('Unable to encrypt JAMF API key.', 'jamfsync'));
        }

        return $encrypted;
    }

    private function decryptApiKey(array $row): string
    {
        $stored = trim((string) ($row['jamf_api_key'] ?? ''));
        if ($stored === '') {
            return '';
        }

        $isEncrypted = (int) (($row['jamf_api_key_is_encrypted'] ?? 0) > 0 ? 1 : 0) === 1;
        if (!$isEncrypted) {
            return $stored;
        }

        return trim((string) (new \GLPIKey())->decrypt($stored));
    }
}
