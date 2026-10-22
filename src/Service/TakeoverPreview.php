<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Service;

use Computer;
use Entity;
use GlpiPlugin\Jamfsync\Api\JamfClient;

/**
 * Dry run of the next sync of an instance: which JAMF devices are already synced, which
 * existing GLPI computers would be taken over by serial number (with the name that will
 * replace theirs), which serial numbers are ambiguous and which devices would be new.
 * Reads JAMF and GLPI only; nothing is written.
 */
final class TakeoverPreview
{
    private const LIST_LIMIT = 100;

    public function __construct(private readonly ComputerAdoption $adoption = new ComputerAdoption()) {}

    /**
     * @param array<string, mixed> $instance Instance row with decrypted API key.
     * @return array{total: int, counts: array<string, int>, adopt: list<array<string, mixed>>, ambiguous: list<array<string, mixed>>}
     */
    public function build(array $instance): array
    {
        $devices = (new JamfClient($instance))->pullDevicesPaged(false, (int) ($instance['page_size'] ?? 200))['devices'];
        $counts = array_fill_keys([
            ComputerAdoption::SYNCED,
            ComputerAdoption::ADOPT,
            ComputerAdoption::AMBIGUOUS,
            ComputerAdoption::NEW,
            ComputerAdoption::NO_SERIAL,
        ], 0);
        $adopt = [];
        $ambiguous = [];

        foreach ($devices as $device) {
            $identifiers = InventoryPayloadBuilder::identifiers($device);
            $deviceid = InventoryPayloadBuilder::deviceId($instance, $identifiers);
            $result = $this->adoption->classify($deviceid, $identifiers['serial']);
            $counts[$result['state']]++;

            $jamfName = InventoryPayloadBuilder::resolveComputerNameFromDevice($instance, $device);
            if ($result['state'] === ComputerAdoption::ADOPT && count($adopt) < self::LIST_LIMIT) {
                $computer = $result['computers'][0];
                $adopt[] = [
                    'serial' => $identifiers['serial'],
                    'jamf_name' => $jamfName,
                    'glpi_name' => (string) $computer['name'],
                    'entity' => Entity::getFriendlyNameById((int) $computer['entities_id']),
                    'url' => Computer::getFormURLWithID((int) $computer['id']),
                ];
            } elseif ($result['state'] === ComputerAdoption::AMBIGUOUS && count($ambiguous) < self::LIST_LIMIT) {
                $ambiguous[] = [
                    'serial' => $identifiers['serial'],
                    'jamf_name' => $jamfName,
                    'computers' => array_map(static fn(array $computer): array => [
                        'name' => (string) $computer['name'],
                        'url' => Computer::getFormURLWithID((int) $computer['id']),
                    ], $result['computers']),
                ];
            }
        }

        return ['total' => count($devices), 'counts' => $counts, 'adopt' => $adopt, 'ambiguous' => $ambiguous];
    }
}
