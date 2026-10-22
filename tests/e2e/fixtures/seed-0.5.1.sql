-- Synthetic jamfsync 0.5.1 state (tests/e2e/run.sh, fictional data).
INSERT INTO glpi_plugins (directory, name, version, state, author, homepage, license)
  VALUES ('jamfsync', 'JAMF Sync', '0.5.1', 1, 'Roy Brannath', 'https://github.com/glpiplugins/jamfsync', 'GPLv3+');
INSERT INTO glpi_configs (context, name, value) VALUES ('plugin:jamfsync', 'schema_version', '0.5.1');
-- Admin customised the hourly pull: every 2 hours, disabled, CLI mode.
INSERT INTO glpi_crontasks (itemtype, name, frequency, param, state, mode, allowmode, hourmin, hourmax, logs_lifetime, comment, date_mod, date_creation)
  VALUES ('PluginJamfsyncSyncTask', 'PullFromJamf', 7200, NULL, 0, 2, 3, 0, 24, 30, 'Pull devices and apps from JAMF', NOW(), NOW());
INSERT INTO glpi_profilerights (profiles_id, name, rights)
  SELECT id, 'plugin_jamfsync_config', IF(id = 4, 3, 0) FROM glpi_profiles;
INSERT INTO glpi_plugin_jamfsync_instances (name, jamf_base_url, jamf_api_username, jamf_api_key, jamf_api_key_is_encrypted, entities_id, pull_devices, pull_apps, name_source, verify_tls, request_timeout, page_size, is_active, last_success_at, last_cursor, date_creation, date_mod)
  VALUES ('Mock JAMF (from 0.5.1)', 'http://MOCK_HOST:8080', 'mock-user', 'mock-key-not-secret', 0, 0, 1, 1, 'device_name', 1, 30, 200, 1, '2026-10-01 08:05:00', 'page:1', '2026-09-01 10:00:00', '2026-10-01 08:05:00');
INSERT INTO glpi_plugin_jamfsync_syncruns (jamf_instances_id, instance_name, entities_id, status, mode, message, devices_count, apps_count, processed_count, changed_count, is_full, cursor_start, cursor_end, started_at, finished_at, date_creation, date_mod)
  VALUES (1, 'Mock JAMF (from 0.5.1)', 0, 'success', 'cron', 'JAMF pull completed.', 2, 3, 2, 0, 0, 'page:1', 'page:1', '2026-10-01 08:00:00', '2026-10-01 08:05:00', '2026-10-01 08:00:00', '2026-10-01 08:05:00');
INSERT INTO glpi_plugin_jamfsync_syncjobs (status, mode, is_full, instance_id, instance_name, message, date_creation, date_mod, started_at)
  VALUES ('running', 'manual', 0, 0, '', 'left over by a killed 0.5.1 web worker', '2026-10-20 09:00:00', '2026-10-20 09:01:00', '2026-10-20 09:00:00'),
         ('queued', 'manual_full', 1, 0, '', 'Job queued', '2026-10-20 09:02:00', '2026-10-20 09:02:00', NULL);
