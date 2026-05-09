<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * Tiny env reader. Docker Compose's `env_file:` injects everything into the
 * php-fpm worker environment, so $_ENV / $_SERVER / getenv() all work. We
 * normalize to a single accessor so callers don't have to care.
 */
final class Env
{
    public static function str(string $key, string $default = ''): string
    {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return is_string($val) && $val !== '' ? $val : $default;
    }

    public static function int(string $key, int $default): int
    {
        $val = self::str($key);
        return $val === '' ? $default : (int) $val;
    }
}
