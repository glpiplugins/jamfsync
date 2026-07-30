<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync;

use CommonDBTM;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Jamfsync\Api\JamfClient;
use Plugin;
use Session;

/**
 * CommonDBTM itemtype for the JAMF instances table.
 *
 * Renders both the native Search list (front/config.form.php) and the native item
 * form (front/instance.form.php) through GLPI's standard components. Create/update/delete
 * go through the standard CommonDBTM lifecycle; API-key encryption and base-URL
 * normalisation happen in prepareInputForAdd()/prepareInputForUpdate().
 */
class Instance extends CommonDBTM
{
    // Keep in sync with Access::RIGHT ('plugin_jamfsync_config').
    public static $rightname = 'plugin_jamfsync_config';

    public static function getTypeName($nb = 0): string
    {
        return _n('JAMF instance', 'JAMF instances', $nb, 'jamfsync');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_jamfsync_instances';
    }

    public static function getIcon(): string
    {
        return 'ti ti-brand-apple';
    }

    public static function getFormURL($full = true): string
    {
        return Plugin::getWebDir('jamfsync', $full) . '/front/instance.form.php';
    }

    public static function getSearchURL($full = true): string
    {
        // The instances list lives on the plugin config page, not a dedicated search page.
        return Plugin::getWebDir('jamfsync', $full) . '/front/config.form.php';
    }

    // The plugin exposes a single right ('plugin_jamfsync_config'): READ = view,
    // UPDATE = manage. Map the standard CRUD checks onto it so the native list/form
    // (add / save / delete) work without introducing extra right bits.
    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return (bool) Session::haveRight(self::$rightname, UPDATE);
    }

    public static function canUpdate(): bool
    {
        return (bool) Session::haveRight(self::$rightname, UPDATE);
    }

    public static function canPurge(): bool
    {
        return (bool) Session::haveRight(self::$rightname, UPDATE);
    }

    public function post_getEmpty()
    {
        $this->fields['entities_id'] = $_SESSION['glpiactive_entity'] ?? 0;
        $this->fields['pull_devices'] = 1;
        $this->fields['pull_apps'] = 1;
        $this->fields['verify_tls'] = 1;
        $this->fields['is_active'] = 1;
        $this->fields['name_source'] = 'device_name';
        $this->fields['request_timeout'] = 30;
        $this->fields['page_size'] = 200;
    }

    public function showForm($ID, array $options = [])
    {
        if ((int) $ID > 0) {
            $this->getFromDB((int) $ID);
        } else {
            $this->getEmpty();
        }

        TemplateRenderer::getInstance()->display('@jamfsync/instance_form.html.twig', [
            'item' => $this,
            'params' => $options,
        ]);

        return true;
    }

    public function prepareInputForAdd($input)
    {
        return $this->prepareInput($input, true);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareInput($input, false);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function prepareInput(array $input, bool $isNew)
    {
        if (isset($input['name'])) {
            $input['name'] = trim((string) $input['name']);
        }
        if (isset($input['jamf_base_url'])) {
            $input['jamf_base_url'] = JamfClient::normalizeBaseUrl((string) $input['jamf_base_url']);
        }
        if (isset($input['jamf_api_username'])) {
            $input['jamf_api_username'] = trim((string) $input['jamf_api_username']);
        }
        if (isset($input['name_source'])) {
            $input['name_source'] = strtolower(trim((string) $input['name_source'])) === 'asset_tag'
                ? 'asset_tag'
                : 'device_name';
        }
        foreach (['pull_devices', 'pull_apps', 'verify_tls', 'is_active'] as $flag) {
            if (isset($input[$flag])) {
                $input[$flag] = (int) $input[$flag] > 0 ? 1 : 0;
            }
        }
        if (isset($input['request_timeout'])) {
            $input['request_timeout'] = max(5, min(120, (int) $input['request_timeout']));
        }
        if (isset($input['page_size'])) {
            $input['page_size'] = max(50, min(1000, (int) $input['page_size']));
        }

        if ($isNew) {
            $required = [
                'name' => __('Instance name is required.', 'jamfsync'),
                'jamf_base_url' => __('JAMF base URL is required.', 'jamfsync'),
                'jamf_api_username' => __('JAMF credentials are required.', 'jamfsync'),
            ];
            foreach ($required as $field => $message) {
                if (trim((string) ($input[$field] ?? '')) === '') {
                    Session::addMessageAfterRedirect($message, false, ERROR);
                    return false;
                }
            }
        } elseif (isset($input['name']) && $input['name'] === '') {
            Session::addMessageAfterRedirect(__('Instance name is required.', 'jamfsync'), false, ERROR);
            return false;
        }

        // Encrypt the API key when provided; keep the stored key when left blank on update.
        $apiKey = trim((string) ($input['jamf_api_key'] ?? ''));
        if ($apiKey !== '') {
            $encrypted = (new \GLPIKey())->encrypt($apiKey);
            if ($encrypted === '') {
                Session::addMessageAfterRedirect(__('Unable to encrypt JAMF API key.', 'jamfsync'), false, ERROR);
                return false;
            }
            $input['jamf_api_key'] = $encrypted;
            $input['jamf_api_key_is_encrypted'] = 1;
        } else {
            unset($input['jamf_api_key'], $input['jamf_api_key_is_encrypted']);
            if ($isNew) {
                Session::addMessageAfterRedirect(__('JAMF credentials are required.', 'jamfsync'), false, ERROR);
                return false;
            }
        }

        return $input;
    }

    public function rawSearchOptions(): array
    {
        $table = self::getTable();

        return [
            [
                'id' => 'common',
                'name' => __('JAMF Sync', 'jamfsync'),
            ],
            [
                'id' => 1,
                'table' => $table,
                'field' => 'name',
                'name' => __('Name', 'jamfsync'),
                'datatype' => 'itemlink',
                'massiveaction' => false,
            ],
            [
                'id' => 2,
                'table' => $table,
                'field' => 'jamf_base_url',
                'name' => __('Base URL', 'jamfsync'),
                'datatype' => 'string',
            ],
            [
                'id' => 80,
                'table' => 'glpi_entities',
                'field' => 'completename',
                'linkfield' => 'entities_id',
                'name' => __('Entity'),
                'datatype' => 'dropdown',
            ],
            [
                'id' => 4,
                'table' => $table,
                'field' => 'name_source',
                'name' => __('Name source', 'jamfsync'),
                'datatype' => 'specific',
                'searchtype' => ['equals', 'notequals'],
            ],
            [
                'id' => 5,
                'table' => $table,
                'field' => 'is_active',
                'name' => __('Active', 'jamfsync'),
                'datatype' => 'bool',
            ],
            [
                'id' => 6,
                'table' => $table,
                'field' => 'last_success_at',
                'name' => __('Last success', 'jamfsync'),
                'datatype' => 'datetime',
            ],
            [
                'id' => 7,
                'table' => $table,
                'field' => 'request_timeout',
                'name' => __('Request timeout (sec)', 'jamfsync'),
                'datatype' => 'number',
            ],
            [
                'id' => 8,
                'table' => $table,
                'field' => 'page_size',
                'name' => __('Page size', 'jamfsync'),
                'datatype' => 'number',
            ],
            [
                'id' => 3,
                'table' => $table,
                'field' => 'id',
                'name' => __('ID'),
                'datatype' => 'number',
                'massiveaction' => false,
            ],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        if ($field === 'name_source') {
            return ((string) ($values['name_source'] ?? '')) === 'asset_tag'
                ? __('JAMF asset tag', 'jamfsync')
                : __('JAMF device name', 'jamfsync');
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }
}
