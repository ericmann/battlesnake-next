<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * One-line-of-JSON-per-event logger. Always writes to stdout via echo so
 * Docker captures it directly with no fcntl gymnastics.
 *
 * Why echo and not error_log()? On Alpine + php-fpm + nginx, error_log goes
 * to the worker stderr stream, which Docker conflates with nginx errors and
 * makes parsing painful. Stdout is for events; stderr is for surprises.
 */
final class Logger
{
    /**
     * Emit one structured /move log line.
     *
     * @param array{
     *     game_id?:string,
     *     turn?:int,
     *     model_used?:?string,
     *     model_label?:?string,
     *     move?:string,
     *     reasoning?:string,
     *     safe_moves?:list<string>,
     *     llm_latency_ms?:?int,
     *     total_latency_ms?:int,
     *     fallback_used?:bool,
     *     own_health?:int,
     *     own_length?:int,
     * } $data
     */
    public static function move(array $data): void
    {
        // Order keys for human-readable scanning in `docker logs -f`.
        $line = [
            'ts'                => self::nowIso8601(),
            'event'             => 'move',
            'game_id'           => $data['game_id']          ?? null,
            'turn'              => $data['turn']             ?? null,
            'model_used'        => $data['model_used']       ?? null,
            'model_label'       => $data['model_label']      ?? null,
            'move'              => $data['move']             ?? null,
            'reasoning'         => $data['reasoning']        ?? '',
            'safe_moves'        => $data['safe_moves']       ?? [],
            'llm_latency_ms'    => $data['llm_latency_ms']   ?? null,
            'total_latency_ms'  => $data['total_latency_ms'] ?? null,
            'fallback_used'     => $data['fallback_used']    ?? false,
            'own_health'        => $data['own_health']       ?? null,
            'own_length'        => $data['own_length']       ?? null,
        ];

        echo json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    }

    /**
     * Tag an unexpected exception. Keep these rare — anything we can predict
     * should be handled inline so the game engine never sees a 5xx.
     */
    public static function warn(string $message, array $context = []): void
    {
        $line = [
            'ts'      => self::nowIso8601(),
            'event'   => 'warn',
            'message' => $message,
            'context' => $context,
        ];
        // Warnings go to stderr so they're easy to grep and don't pollute the
        // happy-path move stream.
        fwrite(STDERR, json_encode($line, JSON_UNESCAPED_SLASHES) . "\n");
    }

    private static function nowIso8601(): string
    {
        // ms-precision UTC; matches the example log line in TDD.md §11.
        $micro = microtime(true);
        $sec   = (int) $micro;
        $ms    = (int) round(($micro - $sec) * 1000);
        return gmdate('Y-m-d\TH:i:s', $sec) . sprintf('.%03dZ', $ms);
    }
}
