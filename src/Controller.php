<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * The thinnest possible front controller. Every method returns
 * [int $status, array $body] which index.php turns into a JSON response.
 *
 * The /move handler is wired up in Story 8 — for now it returns "up" so the
 * Battlesnake game engine can register and ping the snake during scaffolding.
 */
final class Controller
{
    public static function meta(): array
    {
        return [200, [
            'apiversion' => '1',
            'author'     => Env::str('SNAKE_AUTHOR', 'eamann'),
            'color'      => Env::str('SNAKE_COLOR', '#1a1a2e'),
            'head'       => Env::str('SNAKE_HEAD', 'tongue'),
            'tail'       => Env::str('SNAKE_TAIL', 'bolt'),
            'version'    => 'next-3.0',
        ]];
    }

    public static function start(): array
    {
        return [200, new \stdClass()]; // game engine wants {}, not []
    }

    public static function end(): array
    {
        return [200, new \stdClass()];
    }

    public static function move(string $rawBody): array
    {
        // Story 8 replaces this body with the full LLM-race pipeline.
        // Until then: return a guaranteed-200 stub so the engine's smoke-test
        // pass can register the snake and we can iterate on internals.
        unset($rawBody);

        return [200, ['move' => 'up']];
    }
}
