<?php

/**
 * Load project .env once and read configuration values safely.
 */

if (!function_exists('aidfleet_load_env')) {
    function aidfleet_load_env(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }

        $root = dirname(__DIR__, 2);
        $autoload = $root . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        $envFile = $root . '/.env';
        if (class_exists('Dotenv\Dotenv') && is_readable($envFile)) {
            Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }

        $loaded = true;
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        aidfleet_load_env();

        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($val === false || $val === null) {
            return $default;
        }

        if (is_string($val)) {
            $val = trim($val);
            if ($val === '') {
                return $default;
            }
            $lower = strtolower($val);
            if ($lower === 'true') {
                return true;
            }
            if ($lower === 'false') {
                return false;
            }
        }

        return $val;
    }
}
