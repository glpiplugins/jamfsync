<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$coreVendor = dirname($pluginRoot) . '/glpi-core/vendor/autoload.php';
if (is_file($coreVendor)) {
    require_once $coreVendor;
}

if (!defined('GLPI_CONFIG_DIR')) {
    define('GLPI_CONFIG_DIR', sys_get_temp_dir() . '/jamfsync-tests-config');
}

if (!is_dir(GLPI_CONFIG_DIR)) {
    mkdir(GLPI_CONFIG_DIR, 0o777, true);
}

$keyFile = GLPI_CONFIG_DIR . '/glpicrypt.key';
if (!is_file($keyFile)) {
    file_put_contents($keyFile, random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
}

spl_autoload_register(static function (string $class) use ($pluginRoot): void {
    $prefix = 'GlpiPlugin\\Jamfsync\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = $pluginRoot . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

if (!class_exists('GLPIKey')) {
    class GLPIKey
    {
        public function encrypt(string $string, ?string $key = null): string
        {
            return 'enc:' . base64_encode($string);
        }

        public function decrypt(?string $string, ?string $key = null): ?string
        {
            if ($string === null || $string === '') {
                return $string;
            }
            if (!str_starts_with($string, 'enc:')) {
                return $string;
            }
            return base64_decode(substr($string, 4)) ?: '';
        }
    }
}
