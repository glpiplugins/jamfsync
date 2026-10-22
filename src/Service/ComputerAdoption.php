<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Service;

use Agent;
use AgentType;

/**
 * Takeover of computers that already exist in GLPI without an agent of the JAMF device:
 * iPads created by another plugin or a data import, or synced by the JAMF instance of
 * another school (device changed schools).
 *
 * The managed import rule "update by serial" only finds computers whose agent has the
 * device's id and tag (ImportRuleManager). Registering that agent for the one existing
 * computer with the device's serial number makes GLPI's native inventory update it
 * instead of creating a duplicate. A serial number held by several computers is left
 * alone and reported, so that no device is attached to the wrong record.
 */
final class ComputerAdoption
{
    public const SYNCED = 'synced';
    public const ADOPT = 'adopt';
    public const AMBIGUOUS = 'ambiguous';
    public const NEW = 'new';
    public const NO_SERIAL = 'no_serial';

    /**
     * What a sync would do with the device, without changing anything.
     *
     * @return array{state: string, computers: list<array<string, mixed>>}
     */
    public function classify(string $deviceid, string $serial): array
    {
        global $DB;

        if (countElementsInTable(Agent::getTable(), ['deviceid' => $deviceid]) > 0) {
            return ['state' => self::SYNCED, 'computers' => []];
        }
        if ($serial === '') {
            return ['state' => self::NO_SERIAL, 'computers' => []];
        }

        $computers = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'serial', 'entities_id'],
            'FROM' => 'glpi_computers',
            'WHERE' => ['serial' => $serial, 'is_deleted' => 0, 'is_template' => 0],
            'ORDER' => ['id ASC'],
        ]);
        foreach ($iterator as $row) {
            $computers[] = $row;
        }

        $state = match (count($computers)) {
            0 => self::NEW,
            1 => self::ADOPT,
            default => self::AMBIGUOUS,
        };

        return ['state' => $state, 'computers' => $computers];
    }

    /**
     * Prepare the inventory submission of a device: register its agent for the existing
     * computer when there is exactly one with its serial number.
     *
     * @return array{state: string, computers_id: int} ADOPT means "agent registered for computers_id".
     */
    public function prepare(string $deviceid, string $tag, string $serial): array
    {
        $classified = $this->classify($deviceid, $serial);
        if ($classified['state'] !== self::ADOPT) {
            return ['state' => $classified['state'], 'computers_id' => 0];
        }

        $computer = $classified['computers'][0];
        $added = (new Agent())->add([
            'deviceid' => $deviceid,
            'name' => $deviceid,
            'itemtype' => 'Computer',
            'items_id' => (int) $computer['id'],
            'entities_id' => (int) $computer['entities_id'],
            'tag' => $tag,
            'agenttypes_id' => $this->coreAgentTypeId(),
            'useragent' => 'JAMFSync-Plugin',
            'last_contact' => date('Y-m-d H:i:s'),
        ]);

        return $added
            ? ['state' => self::ADOPT, 'computers_id' => (int) $computer['id']]
            : ['state' => self::NEW, 'computers_id' => 0];
    }

    private function coreAgentTypeId(): int
    {
        $type = new AgentType();
        if (!$type->getFromDBByCrit(['name' => 'Core'])) {
            $type->add(['name' => 'Core']);
        }

        return (int) $type->getID();
    }
}
