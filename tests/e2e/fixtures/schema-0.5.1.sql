-- jamfsync 0.5.1 schema (plugin tables only, no data): dumped from a GLPI 11 install,
-- date columns reset to DATETIME as created by 0.5.1. Used by tests/e2e/run.sh.

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `glpi_plugin_jamfsync_instances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `glpi_plugin_jamfsync_instances` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `jamf_base_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `jamf_api_username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `jamf_api_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `jamf_api_key_is_encrypted` tinyint NOT NULL DEFAULT '0',
  `entities_id` int unsigned NOT NULL DEFAULT '0',
  `pull_devices` tinyint(1) NOT NULL DEFAULT '1',
  `pull_apps` tinyint(1) NOT NULL DEFAULT '1',
  `name_source` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'device_name',
  `verify_tls` tinyint(1) NOT NULL DEFAULT '1',
  `request_timeout` int unsigned NOT NULL DEFAULT '30',
  `page_size` int unsigned NOT NULL DEFAULT '200',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_success_at` datetime DEFAULT NULL,
  `last_cursor` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `date_creation` datetime DEFAULT NULL,
  `date_mod` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_entity` (`entities_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `glpi_plugin_jamfsync_syncruns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `glpi_plugin_jamfsync_syncruns` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `jamf_instances_id` int unsigned NOT NULL DEFAULT '0',
  `instance_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `entities_id` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success',
  `mode` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `message` text COLLATE utf8mb4_unicode_ci,
  `devices_count` int unsigned NOT NULL DEFAULT '0',
  `apps_count` int unsigned NOT NULL DEFAULT '0',
  `processed_count` int unsigned NOT NULL DEFAULT '0',
  `changed_count` int unsigned NOT NULL DEFAULT '0',
  `is_full` tinyint(1) NOT NULL DEFAULT '0',
  `cursor_start` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `cursor_end` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `date_creation` datetime DEFAULT NULL,
  `date_mod` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_instance` (`jamf_instances_id`),
  KEY `idx_entity` (`entities_id`),
  KEY `idx_date_creation` (`date_creation`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `glpi_plugin_jamfsync_devicemaps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `glpi_plugin_jamfsync_devicemaps` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `jamf_instances_id` int unsigned NOT NULL DEFAULT '0',
  `jamf_device_id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `jamf_udid` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `jamf_serial` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `computers_id` int unsigned NOT NULL DEFAULT '0',
  `entities_id` int unsigned NOT NULL DEFAULT '0',
  `device_signature` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `apps_signature` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `last_seen_at` datetime DEFAULT NULL,
  `last_checkin_at` datetime DEFAULT NULL,
  `date_creation` datetime DEFAULT NULL,
  `date_mod` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_instance_device` (`jamf_instances_id`,`jamf_device_id`),
  KEY `idx_instance_udid` (`jamf_instances_id`,`jamf_udid`),
  KEY `idx_instance_serial` (`jamf_instances_id`,`jamf_serial`),
  KEY `idx_computer` (`computers_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `glpi_plugin_jamfsync_runchanges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `glpi_plugin_jamfsync_runchanges` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `syncruns_id` int unsigned NOT NULL DEFAULT '0',
  `jamf_instances_id` int unsigned NOT NULL DEFAULT '0',
  `change_type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'device',
  `jamf_device_id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `computers_id` int unsigned NOT NULL DEFAULT '0',
  `computer_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `object_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `details` text COLLATE utf8mb4_unicode_ci,
  `before_json` longtext COLLATE utf8mb4_unicode_ci,
  `after_json` longtext COLLATE utf8mb4_unicode_ci,
  `impacted_devices` longtext COLLATE utf8mb4_unicode_ci,
  `date_creation` datetime DEFAULT NULL,
  `date_mod` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_syncrun` (`syncruns_id`),
  KEY `idx_syncrun_type` (`syncruns_id`,`change_type`),
  KEY `idx_instance` (`jamf_instances_id`),
  KEY `idx_computer` (`computers_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `glpi_plugin_jamfsync_syncjobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `glpi_plugin_jamfsync_syncjobs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `mode` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `is_full` tinyint(1) NOT NULL DEFAULT '0',
  `instance_id` int unsigned NOT NULL DEFAULT '0',
  `instance_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `total_instances` int unsigned NOT NULL DEFAULT '0',
  `processed_instances` int unsigned NOT NULL DEFAULT '0',
  `total_devices` int unsigned NOT NULL DEFAULT '0',
  `processed_devices` int unsigned NOT NULL DEFAULT '0',
  `changed_count` int unsigned NOT NULL DEFAULT '0',
  `apps_count` int unsigned NOT NULL DEFAULT '0',
  `last_run_id` int unsigned NOT NULL DEFAULT '0',
  `error_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `message` text COLLATE utf8mb4_unicode_ci,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `date_creation` datetime DEFAULT NULL,
  `date_mod` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_instance` (`instance_id`),
  KEY `idx_date_creation` (`date_creation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

