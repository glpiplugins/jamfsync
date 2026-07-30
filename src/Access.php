<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync;

use ProfileRight;
use Session;

/**
 * Dedicated profile right for the JAMF Sync plugin.
 *
 * Replaces the former reliance on the core `config` right so access can be delegated
 * granularly (READ = view monitoring, UPDATE = configure instances and run syncs).
 */
class Access
{
    public const RIGHT = 'plugin_jamfsync_config';

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHT, READ);
    }

    public static function canConfig(): bool
    {
        return (bool) Session::haveRight(self::RIGHT, UPDATE);
    }

    public static function canRun(): bool
    {
        return (bool) Session::haveRight(self::RIGHT, UPDATE);
    }

    /**
     * Register the right with all profiles (idempotent). On first creation, the right is
     * granted (READ|UPDATE) to every profile that currently holds the core `config`
     * UPDATE right, so existing administrators are not locked out by the migration.
     */
    public static function ensureProfileRightDefinition(): void
    {
        global $DB;

        $exists = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => ['name' => self::RIGHT],
            'LIMIT'  => 1,
        ])->count() > 0;

        if ($exists) {
            return;
        }

        ProfileRight::addProfileRights([self::RIGHT]);
        self::grantToConfigProfiles();
    }

    private static function grantToConfigProfiles(): void
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['profiles_id', 'rights'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => ['name' => 'config'],
        ]);

        foreach ($iterator as $row) {
            if (((int) $row['rights'] & UPDATE) !== UPDATE) {
                continue;
            }

            ProfileRight::updateProfileRights((int) $row['profiles_id'], [
                self::RIGHT => READ | UPDATE,
            ]);
        }
    }

    public static function deleteProfileRightDefinition(): void
    {
        ProfileRight::deleteProfileRights([self::RIGHT]);
    }
}
