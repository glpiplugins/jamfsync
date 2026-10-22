<?php

declare(strict_types=1);

use Glpi\Cache\CacheManager;
use Glpi\Config\DataAndPrivacyConfig;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Jamfsync\Access;
use GlpiPlugin\Jamfsync\Instance;
use GlpiPlugin\Jamfsync\NotificationTargetSyncRun;
use GlpiPlugin\Jamfsync\ProfileTab;
use GlpiPlugin\Jamfsync\Service\EntityRuleManager;
use GlpiPlugin\Jamfsync\Service\ImportRuleManager;
use GlpiPlugin\Jamfsync\Service\RetentionPolicy;
use GlpiPlugin\Jamfsync\SyncRun;
use GlpiPlugin\Jamfsync\SyncTask;
use GlpiPlugin\Jamfsync\Ui\PrivacyTab;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/hook.php';

define('PLUGIN_JAMFSYNC_VERSION', '1.0.0');

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
                'min' => '12.0.0',
                'max' => '12.0.99',
            ],
        ],
    ];
}

function plugin_init_jamfsync(): void
{
    global $PLUGIN_HOOKS;

    // Classes under src/ are autoloaded by GLPI (PSR-4, GlpiPlugin\Jamfsync\).
    // No csrf_compliant hook and no DB work here: GLPI 12 validates CSRF via the
    // Sec-Fetch-Site/Origin headers, and the profile right + cron tasks are registered
    // by plugin_jamfsync_install() only. (Re-registering the cron on every request used
    // to reset its state to "waiting", overriding a disabled or errored task.)
    $PLUGIN_HOOKS['config_page']['jamfsync'] = 'front/config.form.php';

    Plugin::registerClass(ProfileTab::class, [
        'addtabon' => [Profile::class],
    ]);

    // Registered so the native Search engine can render the instances list
    // (front/config.form.php via Search::showList).
    Plugin::registerClass(Instance::class);

    // Item type of the "Scheduled sync failed" notification (Setup > Notifications).
    Plugin::registerClass(SyncRun::class, ['notificationtemplates_types' => true]);

    // Retention periods on Setup > Data and Privacy.
    Plugin::registerClass(PrivacyTab::class, ['addtabon' => [DataAndPrivacyConfig::class]]);

    // Sync runner UI: a Vue component that reuses GLPI's own Vue runtime (window._vue).
    // The bundle is a footer JS module so it loads after core's build/vue/app.js; it
    // no-ops on every page that lacks the #jamfsync-runner-app mount node.
    // Paths are relative to public/ (GLPI serves /plugins/jamfsync/* from there).
    $PLUGIN_HOOKS['add_css']['jamfsync'][] = 'js/dist/jamfsync-vue.css';
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT_MODULE]['jamfsync'][] = 'js/dist/jamfsync-vue.js';
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
    plugin_jamfsync_install_notification();
    RetentionPolicy::installDefaults();
    plugin_jamfsync_sync_import_rules();

    return true;
}

function plugin_jamfsync_apply_schema_migrations(Migration $migration): string
{
    global $DB;

    // getConfigurationValue() has no default parameter; null means "never installed".
    $current = (string) (Config::getConfigurationValue('plugin:jamfsync', 'schema_version') ?? '0.0.0');

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
        '0.7.0' => __DIR__ . '/install/migrations/0005_0.7.0.php',
        '0.8.0' => __DIR__ . '/install/migrations/0006_0.8.0.php',
        '0.9.0' => __DIR__ . '/install/migrations/0007_0.9.0.php',
    ];
}

/**
 * GLPI resets compiled Twig templates on (de)activation, but in production mode not the
 * Symfony route cache: the plugin's controller routes (/plugins/jamfsync/Sync/...) would stay
 * unknown (HTTP 404) when that cache was built while the plugin was inactive.
 */
function plugin_jamfsync_activate(): void
{
    (new CacheManager())->resetAllCaches();
}

function plugin_jamfsync_deactivate(): void
{
    (new CacheManager())->resetAllCaches();
}

function plugin_jamfsync_uninstall(): bool
{
    global $DB;

    // Managed entity rules first: their ids live in the instances table dropped below.
    if ($DB->tableExists('glpi_plugin_jamfsync_instances') && $DB->fieldExists('glpi_plugin_jamfsync_instances', 'rules_id')) {
        $rules = new EntityRuleManager();
        foreach ($DB->request(['SELECT' => ['rules_id'], 'FROM' => 'glpi_plugin_jamfsync_instances']) as $row) {
            $rules->remove((int) $row['rules_id']);
        }
    }

    // Import rules are found by uuid, so orphans of already deleted instances go as well.
    (new RuleImportAsset())->deleteByCriteria(['uuid' => ['LIKE', 'jamfsync-%-import-%']], true);

    (new Notification())->deleteByCriteria(['itemtype' => SyncRun::class], true);
    (new NotificationTemplate())->deleteByCriteria(['itemtype' => SyncRun::class], true);

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
    // unregister() expects the plugin key; it matches both 'Pluginjamfsync%' and
    // 'GlpiPlugin\jamfsync\%' itemtypes (case-insensitive).
    CronTask::unregister('jamfsync');
    Access::deleteProfileRightDefinition();

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

/**
 * Create the automatic actions when they are missing. Existing tasks are left alone so an
 * administrator's frequency, run mode or "disabled" choice survives plugin upgrades.
 */
function plugin_jamfsync_register_cron(): void
{
    $frequencies = [
        SyncTask::TASK_PULL  => HOUR_TIMESTAMP,
        SyncTask::TASK_QUEUE => MINUTE_TIMESTAMP,
        SyncTask::TASK_PURGE => DAY_TIMESTAMP,
    ];

    foreach ($frequencies as $name => $frequency) {
        CronTask::register(SyncTask::class, $name, $frequency, [
            'state'   => CronTask::STATE_WAITING,
            'comment' => SyncTask::cronInfo($name)['description'] ?? '',
        ]);
    }
}

/**
 * Default notification "JAMF Sync: scheduled sync failed" (to the GLPI administrator
 * address), created once; administrators can change or disable it afterwards.
 */
function plugin_jamfsync_install_notification(): void
{
    if (countElementsInTable(Notification::getTable(), ['itemtype' => SyncRun::class]) > 0) {
        return;
    }

    $templatesId = (int) (new NotificationTemplate())->add([
        'name' => 'JAMF Sync: ' . __('Scheduled sync failed', 'jamfsync'),
        'itemtype' => SyncRun::class,
    ]);
    if ($templatesId <= 0) {
        return;
    }

    (new NotificationTemplateTranslation())->add([
        'notificationtemplates_id' => $templatesId,
        'language' => '',
        'subject' => '##jamfsync.action##: ##jamfsync.instance##',
        'content_text' => "##lang.jamfsync.instance##: ##jamfsync.instance##\n"
            . "##lang.jamfsync.started##: ##jamfsync.started##\n"
            . "##lang.jamfsync.message##: ##jamfsync.message##\n\n"
            . "##jamfsync.url##",
        'content_html' => '<p>##lang.jamfsync.instance##: ##jamfsync.instance##<br>'
            . '##lang.jamfsync.started##: ##jamfsync.started##<br>'
            . '##lang.jamfsync.message##: ##jamfsync.message##</p>'
            . '<p><a href="##jamfsync.url##">##jamfsync.url##</a></p>',
    ]);

    $notificationsId = (int) (new Notification())->add([
        'name' => 'JAMF Sync: ' . __('Scheduled sync failed', 'jamfsync'),
        'entities_id' => 0,
        'is_recursive' => 1,
        'is_active' => 1,
        'itemtype' => SyncRun::class,
        'event' => NotificationTargetSyncRun::EVENT_FAILED,
    ]);
    if ($notificationsId <= 0) {
        return;
    }

    (new Notification_NotificationTemplate())->add([
        'notifications_id' => $notificationsId,
        'mode' => Notification_NotificationTemplate::MODE_MAIL,
        'notificationtemplates_id' => $templatesId,
    ]);
    (new NotificationTarget())->add([
        'notifications_id' => $notificationsId,
        'type' => Notification::USER_TYPE,
        'items_id' => Notification::GLOBAL_ADMINISTRATOR,
    ]);
}

/**
 * Create or refresh the managed asset import rules of every instance (install and upgrade),
 * see ImportRuleManager.
 */
function plugin_jamfsync_sync_import_rules(): void
{
    global $DB;

    if (!$DB->tableExists('glpi_plugin_jamfsync_instances')) {
        return;
    }

    $rules = new ImportRuleManager();
    foreach ($DB->request(['FROM' => 'glpi_plugin_jamfsync_instances']) as $instance) {
        $rules->sync($instance);
    }
}
