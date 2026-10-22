<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Service;

use Rule;
use RuleAction;
use RuleCollection;
use RuleCriteria;
use RuleImportAsset;
use RuleImportAssetCollection;

/**
 * Keeps two GLPI asset import rules ("Rules for import and link equipments") per JAMF
 * instance, at the top of the list, so its devices are matched by serial number only:
 *  1. "update by serial": inventory tag of the instance, serial already present -> link;
 *  2. "import by serial": inventory tag of the instance, serial set -> create.
 *
 * Without them GLPI's default rule "Computer update (by name)" (ranked before
 * "Computer import (by serial)") merges two iPads that share a name in JAMF into one
 * computer, the second overwriting the serial of the first on every sync.
 *
 * With a "tag" criterion GLPI only finds computers whose agent has the same device id and
 * tag, so rule 1 links a device to its own computer only. Both rules only match devices
 * carrying the instance's tag; devices without serial number fall through to the default
 * rules. Rules are identified by their uuid; an administrator may deactivate them in GLPI
 * (the plugin keeps that state when it updates them).
 */
final class ImportRuleManager
{
    /**
     * @param array<string, mixed> $instance Instance row.
     */
    public function sync(array $instance): void
    {
        $ids = [];
        $created = false;
        foreach (self::definitions($instance) as $definition) {
            $rule = new RuleImportAsset();
            if ($rule->getFromDBByCrit(['uuid' => $definition['rule']['uuid']])) {
                $rulesId = (int) $rule->getID();
                $rule->update(['id' => $rulesId, 'name' => $definition['rule']['name'], 'comment' => $definition['rule']['comment']]);
            } else {
                $rulesId = (int) $rule->add($definition['rule']);
                if ($rulesId <= 0) {
                    continue;
                }
                $created = true;
            }
            $ids[] = $rulesId;

            (new RuleCriteria())->deleteByCriteria(['rules_id' => $rulesId]);
            (new RuleAction())->deleteByCriteria(['rules_id' => $rulesId]);
            foreach ($definition['criteria'] as $criteria) {
                (new RuleCriteria())->add(['rules_id' => $rulesId] + $criteria);
            }
            foreach ($definition['actions'] as $action) {
                (new RuleAction())->add(['rules_id' => $rulesId] + $action);
            }
        }

        // Only on creation, so a later manual reordering in GLPI is kept.
        if ($created) {
            $this->moveToTop($ids);
        }
    }

    /**
     * Put the rules first, in the given order. (A ranking passed to Rule::add() does not move
     * the other rules, so the second rule would tie with GLPI's first default rule.)
     *
     * @param list<int> $ids
     */
    private function moveToTop(array $ids): void
    {
        global $DB;

        $collection = new RuleImportAssetCollection();
        // Last one first: each rule goes before the rule that is currently first.
        foreach (array_reverse($ids) as $id) {
            $first = $DB->request([
                'SELECT' => ['id'],
                'FROM' => RuleImportAsset::getTable(),
                'WHERE' => ['sub_type' => RuleImportAsset::class, 'NOT' => ['id' => $id]],
                'ORDER' => ['ranking ASC', 'id ASC'],
                'LIMIT' => 1,
            ])->current();
            if ($first) {
                $collection->moveRule($id, (int) $first['id'], RuleCollection::MOVE_BEFORE);
            }
        }
    }

    public function remove(int $instanceId): void
    {
        foreach (self::uuids($instanceId) as $uuid) {
            $rule = new RuleImportAsset();
            if ($rule->getFromDBByCrit(['uuid' => $uuid])) {
                // Purging a rule also removes its criteria and actions.
                $rule->delete(['id' => $rule->getID()], true);
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function uuids(int $instanceId): array
    {
        return ["jamfsync-$instanceId-import-link", "jamfsync-$instanceId-import-new"];
    }

    /**
     * The two managed rules of an instance, in ranking order, as input for
     * Rule/RuleCriteria/RuleAction::add().
     *
     * @param array<string, mixed> $instance
     * @return list<array{rule: array<string, mixed>, criteria: list<array<string, mixed>>, actions: list<array<string, mixed>>}>
     */
    public static function definitions(array $instance): array
    {
        $name = trim((string) ($instance['name'] ?? ''));
        [$linkUuid, $importUuid] = self::uuids((int) ($instance['id'] ?? 0));
        $comment = __('Managed by the JAMF Sync plugin: matches the devices of this instance by serial number only, so devices that share a name in JAMF are not merged.', 'jamfsync');
        $scope = [
            ['criteria' => 'tag', 'condition' => Rule::PATTERN_IS, 'pattern' => InventoryPayloadBuilder::inventoryTag($instance)],
            ['criteria' => 'itemtype', 'condition' => Rule::PATTERN_IS, 'pattern' => 'Computer'],
        ];
        $linkOrImport = [[
            'action_type' => 'assign',
            'field' => '_inventory',
            'value' => (string) RuleImportAsset::RULE_ACTION_LINK_OR_IMPORT,
        ]];
        $rule = [
            'sub_type' => RuleImportAsset::class,
            'match' => 'AND',
            'is_active' => 1,
            'entities_id' => 0,
            'is_recursive' => 1,
            'condition' => 0,
            'comment' => $comment,
        ];

        return [
            [
                'rule' => ['uuid' => $linkUuid, 'name' => sprintf('JAMF Sync: %s – update by serial', $name)] + $rule,
                'criteria' => array_merge($scope, [
                    ['criteria' => 'serial', 'condition' => Rule::PATTERN_FIND, 'pattern' => '1'],
                    ['criteria' => 'serial', 'condition' => Rule::PATTERN_EXISTS, 'pattern' => '1'],
                ]),
                'actions' => $linkOrImport,
            ],
            [
                'rule' => ['uuid' => $importUuid, 'name' => sprintf('JAMF Sync: %s – import by serial', $name)] + $rule,
                'criteria' => array_merge($scope, [
                    ['criteria' => 'serial', 'condition' => Rule::PATTERN_EXISTS, 'pattern' => '1'],
                ]),
                'actions' => $linkOrImport,
            ],
        ];
    }
}
