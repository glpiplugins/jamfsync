<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Service;

use Rule;
use RuleAction;
use RuleCriteria;
use RuleImportEntity;

/**
 * Keeps one GLPI rule "Rules for assigning an item to an entity" per JAMF instance:
 * inventory tag of the instance -> target entity of the instance.
 *
 * The rule is created at the top of the rule list (it only matches the instance's own
 * tag, so it cannot capture other devices) and stays visible and editable in GLPI.
 * Like any entity rule it decides the entity of newly imported computers; existing ones
 * only move when GLPI's automatic entity transfer is configured.
 */
final class EntityRuleManager
{
    /**
     * Create, update or remove the managed rule of an instance.
     *
     * @param array<string, mixed> $instance Instance row.
     * @return int Id of the managed rule, 0 when the instance does not want one.
     */
    public function sync(array $instance): int
    {
        $rulesId = (int) ($instance['rules_id'] ?? 0);
        if ((int) ($instance['manage_entity_rule'] ?? 0) !== 1) {
            $this->remove($rulesId);
            return 0;
        }

        $definition = self::definition($instance);
        $rule = new RuleImportEntity();
        if ($rulesId > 0 && $rule->getFromDB($rulesId)) {
            $rule->update(['id' => $rulesId] + $definition['rule']);
        } else {
            $rulesId = (int) $rule->add($definition['rule'] + ['ranking' => 0]);
            if ($rulesId <= 0) {
                return 0;
            }
        }

        (new RuleCriteria())->deleteByCriteria(['rules_id' => $rulesId]);
        (new RuleAction())->deleteByCriteria(['rules_id' => $rulesId]);
        foreach ($definition['criteria'] as $criteria) {
            (new RuleCriteria())->add(['rules_id' => $rulesId] + $criteria);
        }
        foreach ($definition['actions'] as $action) {
            (new RuleAction())->add(['rules_id' => $rulesId] + $action);
        }

        return $rulesId;
    }

    public function remove(int $rulesId): void
    {
        $rule = new RuleImportEntity();
        if ($rulesId > 0 && $rule->getFromDB($rulesId)) {
            // Purging a rule also removes its criteria and actions.
            $rule->delete(['id' => $rulesId], true);
        }
    }

    /**
     * The managed rule of an instance, as input for Rule/RuleCriteria/RuleAction::add().
     *
     * @param array<string, mixed> $instance
     * @return array{rule: array<string, mixed>, criteria: list<array<string, mixed>>, actions: list<array<string, mixed>>}
     */
    public static function definition(array $instance): array
    {
        return [
            'rule' => [
                'sub_type' => RuleImportEntity::class,
                'name' => sprintf('JAMF Sync: %s', trim((string) ($instance['name'] ?? ''))),
                'match' => 'AND',
                'is_active' => 1,
                'entities_id' => 0,
                'is_recursive' => 1,
                'comment' => __('Managed by the JAMF Sync plugin from the instance settings; changes here are overwritten when the instance is saved.', 'jamfsync'),
            ],
            'criteria' => [[
                'criteria' => 'tag',
                'condition' => Rule::PATTERN_IS,
                'pattern' => InventoryPayloadBuilder::inventoryTag($instance),
            ]],
            'actions' => [[
                'action_type' => 'assign',
                'field' => 'entities_id',
                'value' => (string) (int) ($instance['entities_id'] ?? 0),
            ]],
        ];
    }
}
