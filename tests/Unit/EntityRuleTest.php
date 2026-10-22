<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Tests\Unit;

use GlpiPlugin\Jamfsync\Service\EntityRuleManager;
use GlpiPlugin\Jamfsync\Service\ImportRuleManager;
use GlpiPlugin\Jamfsync\Service\InventoryPayloadBuilder;
use PHPUnit\Framework\TestCase;
use Rule;
use RuleImportAsset;

final class EntityRuleTest extends TestCase
{
    public function testDefaultInventoryTagUsesInstanceId(): void
    {
        self::assertSame('jamfsync-4', InventoryPayloadBuilder::inventoryTag(['id' => 4, 'inventory_tag' => '  ']));
        self::assertSame('GMS-iPads', InventoryPayloadBuilder::inventoryTag(['id' => 4, 'inventory_tag' => ' GMS-iPads ']));
    }

    public function testPayloadCarriesInventoryTag(): void
    {
        $payload = InventoryPayloadBuilder::build(
            ['name' => 'iPad 1', 'serialNumber' => 'S1'],
            ['id' => 2, 'name_source' => 'device_name', 'inventory_tag' => 'school-a'],
            ['device_id' => 'D1', 'udid' => '', 'serial' => 'S1', 'uuid' => '']
        );

        self::assertSame('school-a', $payload->tag);
    }

    public function testImportRulesMatchBySerialWithinTheInstanceTag(): void
    {
        $definitions = ImportRuleManager::definitions(['id' => 5, 'name' => 'GMS', 'inventory_tag' => 'gms']);

        self::assertCount(2, $definitions);
        [$link, $import] = $definitions;
        self::assertSame(['jamfsync-5-import-link', 'jamfsync-5-import-new'], ImportRuleManager::uuids(5));
        self::assertSame('jamfsync-5-import-link', $link['rule']['uuid']);
        self::assertSame('RuleImportAsset', $link['rule']['sub_type']);
        self::assertSame('JAMF Sync: GMS – update by serial', $link['rule']['name']);

        $scope = [
            ['criteria' => 'tag', 'condition' => Rule::PATTERN_IS, 'pattern' => 'gms'],
            ['criteria' => 'itemtype', 'condition' => Rule::PATTERN_IS, 'pattern' => 'Computer'],
        ];
        self::assertSame(array_merge($scope, [
            ['criteria' => 'serial', 'condition' => Rule::PATTERN_FIND, 'pattern' => '1'],
            ['criteria' => 'serial', 'condition' => Rule::PATTERN_EXISTS, 'pattern' => '1'],
        ]), $link['criteria']);
        self::assertSame(array_merge($scope, [
            ['criteria' => 'serial', 'condition' => Rule::PATTERN_EXISTS, 'pattern' => '1'],
        ]), $import['criteria']);

        $action = [['action_type' => 'assign', 'field' => '_inventory', 'value' => (string) RuleImportAsset::RULE_ACTION_LINK_OR_IMPORT]];
        self::assertSame($action, $link['actions']);
        self::assertSame($action, $import['actions']);
    }

    public function testRuleMapsTagToTargetEntity(): void
    {
        $definition = EntityRuleManager::definition([
            'id' => 3,
            'name' => ' Gemeinschaftsschule ',
            'inventory_tag' => '',
            'entities_id' => 12,
        ]);

        self::assertSame('RuleImportEntity', $definition['rule']['sub_type']);
        self::assertSame('JAMF Sync: Gemeinschaftsschule', $definition['rule']['name']);
        self::assertSame(1, $definition['rule']['is_active']);
        self::assertSame([['criteria' => 'tag', 'condition' => Rule::PATTERN_IS, 'pattern' => 'jamfsync-3']], $definition['criteria']);
        self::assertSame([['action_type' => 'assign', 'field' => 'entities_id', 'value' => '12']], $definition['actions']);
    }
}
