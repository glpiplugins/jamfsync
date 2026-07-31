<?php

declare(strict_types=1);

use Glpi\Plugin\Hooks;
use GlpiPlugin\Jamfsync\Access;
use GlpiPlugin\Jamfsync\Instance;
use GlpiPlugin\Jamfsync\ProfileTab;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/hook.php';

define('PLUGIN_JAMFSYNC_VERSION', '0.5.0');

function plugin_version_jamfsync(): array
{
    return [
        'name'         => __('JAMF Sync', 'jamfsync'),
        'version'      => PLUGIN_JAMFSYNC_VERSION,
        'author'       => 'Roy Brannath',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/glpiplugins/jamfsync',
        'requirements' => [
            'glpi' => [
                'min' => '11.0.0',
                'max' => '11.99.99',
            ],
        ],
    ];
}

function plugin_init_jamfsync(): void
{
    global $PLUGIN_HOOKS;

    plugin_jamfsync_register_autoloader();

    $PLUGIN_HOOKS['csrf_compliant']['jamfsync'] = true;
    $PLUGIN_HOOKS['config_page']['jamfsync'] = 'front/config.form.php';

    Access::ensureProfileRightDefinition();

    Plugin::registerClass(ProfileTab::class, [
        'addtabon' => [Profile::class],
    ]);

    // Registered so the native Search engine can render the instances list
    // (front/config.form.php via Search::showList).
    Plugin::registerClass(Instance::class);

    // Sync runner UI: a Vue component that reuses GLPI's own Vue runtime (window._vue).
    // The bundle is a footer JS module so it loads after core's build/vue/app.js; it
    // no-ops on every page that lacks the #jamfsync-runner-app mount node.
    $PLUGIN_HOOKS['add_css']['jamfsync'][] = 'public/js/dist/jamfsync-vue.css';
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT_MODULE]['jamfsync'][] = 'public/js/dist/jamfsync-vue.js';

    plugin_jamfsync_register_cron();
}

function plugin_jamfsync_check_prerequisites(): bool
{
    return true;
}

function plugin_jamfsync_check_config(): bool
{
    return true;
}

function plugin_jamfsync_install(): bool
{
    $migration = new Migration(PLUGIN_JAMFSYNC_VERSION);
    $appliedVersion = plugin_jamfsync_apply_schema_migrations($migration);
    $migration->executeMigration();

    plugin_jamfsync_migrate_legacy_settings();
    Config::setConfigurationValues('plugin:jamfsync', [
        'schema_version' => $appliedVersion,
    ]);
    Access::ensureProfileRightDefinition();
    plugin_jamfsync_register_cron();

    return true;
}

function plugin_jamfsync_apply_schema_migrations(Migration $migration): string
{
    global $DB;

    $current = (string) Config::getConfigurationValue('plugin:jamfsync', 'schema_version', '0.0.0');

    // Safety net: if the base table is missing (e.g. schema_version left stale by a
    // previous failed/partial uninstall), force a from-scratch run so the table-creating
    // migrations execute again. The CREATE TABLE statements use IF NOT EXISTS, so this is
    // idempotent for tables that do already exist.
    if (!$DB->tableExists('glpi_plugin_jamfsync_instances')) {
        $current = '0.0.0';
    }

    $target = $current;

    foreach (plugin_jamfsync_get_schema_migrations() as $version => $file) {
        if (version_compare($version, PLUGIN_JAMFSYNC_VERSION, '>')) {
            continue;
        }
        if (version_compare($current, $version, '>=')) {
            continue;
        }

        require_once $file;
        $function = 'plugin_jamfsync_migration_' . str_replace('.', '_', $version);
        if (!function_exists($function)) {
            throw new RuntimeException(sprintf('Migration function %s is missing.', $function));
        }

        $function($migration);
        $target = $version;
    }

    if (version_compare($target, PLUGIN_JAMFSYNC_VERSION, '<')) {
        $target = PLUGIN_JAMFSYNC_VERSION;
    }

    return $target;
}

function plugin_jamfsync_get_schema_migrations(): array
{
    return [
        '0.3.0' => __DIR__ . '/install/migrations/0001_0.3.0.php',
        '0.3.7' => __DIR__ . '/install/migrations/0002_0.3.7.php',
        '0.3.8' => __DIR__ . '/install/migrations/0003_0.3.8.php',
        '0.5.0' => __DIR__ . '/install/migrations/0004_0.5.0.php',
    ];
}

function plugin_jamfsync_uninstall(): bool
{
    global $DB;

    foreach ([
        'glpi_plugin_jamfsync_syncruns',
        'glpi_plugin_jamfsync_instances',
        'glpi_plugin_jamfsync_devicemaps',
        'glpi_plugin_jamfsync_applinks',
        'glpi_plugin_jamfsync_runchanges',
        'glpi_plugin_jamfsync_syncjobs',
    ] as $table) {
        $DB->dropTable($table, true);
    }

    $configNames = array_keys(Config::getConfigurationValues('plugin:jamfsync'));
    if ($configNames !== []) {
        // deleteConfigurationValues() only removes the value names passed to it, so we must
        // enumerate them explicitly — otherwise schema_version lingers and the next install
        // skips the table-creating migrations.
        Config::deleteConfigurationValues('plugin:jamfsync', $configNames);
    }
    CronTask::unregister('PluginJamfsyncSyncTask');

    return true;
}

function plugin_jamfsync_migrate_legacy_settings(): void
{
    global $DB;

    if (!$DB->tableExists('glpi_plugin_jamfsync_instances')) {
        return;
    }

    $existing = $DB->request([
        'FROM' => 'glpi_plugin_jamfsync_instances',
        'LIMIT' => 1,
    ])->current();
    if ($existing) {
        return;
    }

    $legacy = Config::getConfigurationValues('plugin:jamfsync');
    $baseUrl = trim((string) ($legacy['jamf_base_url'] ?? ''));
    if ($baseUrl === '') {
        return;
    }

    $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    $apiKey = trim((string) ($legacy['jamf_api_key'] ?? $legacy['jamf_client_secret'] ?? ''));
    $encryptedApiKey = $apiKey !== '' ? (new GLPIKey())->encrypt($apiKey) : '';

    $DB->insert('glpi_plugin_jamfsync_instances', [
        'name' => __('Default instance', 'jamfsync'),
        'jamf_base_url' => $baseUrl,
        'jamf_api_username' => trim((string) ($legacy['jamf_api_username'] ?? $legacy['jamf_client_id'] ?? '')),
        'jamf_api_key' => $encryptedApiKey,
        'jamf_api_key_is_encrypted' => $encryptedApiKey !== '' ? 1 : 0,
        'entities_id' => (int) ($legacy['entities_id'] ?? 0),
        'pull_devices' => (int) (($legacy['pull_devices'] ?? 1) > 0 ? 1 : 0),
        'pull_apps' => (int) (($legacy['pull_apps'] ?? 1) > 0 ? 1 : 0),
        'name_source' => 'device_name',
        'verify_tls' => (int) (($legacy['verify_tls'] ?? 1) > 0 ? 1 : 0),
        'request_timeout' => max(5, min(120, (int) ($legacy['request_timeout'] ?? 30))),
        'page_size' => 200,
        'is_active' => 1,
        'last_success_at' => null,
        'last_cursor' => '',
        'date_creation' => $now,
        'date_mod' => $now,
    ]);
}

function plugin_jamfsync_register_cron(): void
{
    $frequency = 3600;
    $task = new CronTask();

    if ($task->getFromDBbyName(PluginJamfsyncSyncTask::class, 'PullFromJamf')) {
        $update = [];

        if ((int) $task->fields['frequency'] !== $frequency) {
            $update['frequency'] = $frequency;
        }

        if (($task->fields['state'] ?? '') !== CronTask::STATE_WAITING) {
            $update['state'] = CronTask::STATE_WAITING;
        }

        if ($update !== []) {
            $task->update($update);
        }

        return;
    }

    CronTask::register(
        PluginJamfsyncSyncTask::class,
        'PullFromJamf',
        $frequency,
        [
            'state'   => CronTask::STATE_WAITING,
            'comment' => __('Pull devices and apps from JAMF', 'jamfsync'),
        ]
    );
}

function plugin_jamfsync_register_autoloader(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }

    spl_autoload_register(static function (string $class): void {
        $legacy = [
            'PluginJamfsyncSyncTask' => __DIR__ . '/src/PluginJamfsyncSyncTask.php',
        ];

        if (isset($legacy[$class]) && is_file($legacy[$class])) {
            require_once $legacy[$class];
            return;
        }

        $prefix = 'GlpiPlugin\\Jamfsync\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require_once $path;
        }
    });

    $registered = true;
}
