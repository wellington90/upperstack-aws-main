<?php

declare(strict_types=1);

namespace App;

use Dotenv\Dotenv;

final class Config
{
    private bool $dotenvLoaded = false;

    public function loadDotenvIfPresent(string $basePath): void
    {
        if ($this->dotenvLoaded) {
            return;
        }

        $envFile = $basePath . '/.env';
        if (is_file($envFile)) {
            Dotenv::createImmutable($basePath)->safeLoad();
        }

        $this->dotenvLoaded = true;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return (string) $value;
        }

        if (array_key_exists($key, $_ENV)) {
            return (string) $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER)) {
            return (string) $_SERVER[$key];
        }

        return $default;
    }

    public function allForPrefix(string $prefix): array
    {
        $result = [];
        foreach ($_ENV as $key => $value) {
            if (str_starts_with((string) $key, $prefix)) {
                $result[(string) $key] = (string) $value;
            }
        }

        ksort($result);

        return $result;
    }
}
