<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * One-line-of-JSON-per-event logger. Writes to php://stderr (the php-fpm
 * worker's stderr stream), which the Dockerfile points at the container's
 * stderr and Docker captures verbatim into `docker compose logs`.
 *
 * Why not echo? Under nginx + php-fpm, `echo` writes to the HTTP response
 * body, which would leak structured log JSON into the move response sent
 * back to the Battlesnake game engine. CLI / phpunit users see logs on
 * stderr the same way; PHPUnit doesn't capture stderr by default, so the
 * test suite can still observe emissions via ob_* by re-pointing the
 * stream where it matters.
 *
 * Tests can swap the destination stream via setStream() to capture output.
 */
final class Logger
{
    /** @var resource|null */
    private static $stream = null;

    /** @param resource $stream */
    public static function setStream($stream): void
    {
        self::$stream = $stream;
    }

    public static function resetStream(): void
    {
        self::$stream = null;
    }

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
            'strategy'          => $data['strategy']         ?? null,
            'model_used'        => $data['model_used']       ?? null,
            'model_label'       => $data['model_label']      ?? null,
            'move'              => $data['move']             ?? null,
            'reasoning'         => $data['reasoning']        ?? '',
            'safe_moves'        => $data['safe_moves']       ?? [],
            'llm_latency_ms'    => $data['llm_latency_ms']   ?? null,
            'mcts_rollouts'     => $data['mcts_rollouts']    ?? 0,
            'total_latency_ms'  => $data['total_latency_ms'] ?? null,
            'fallback_used'     => $data['fallback_used']    ?? false,
            'own_health'        => $data['own_health']       ?? null,
            'own_length'        => $data['own_length']       ?? null,
        ];

        self::write(json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
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
        // Warnings share the same stream as move events; the "event" key
        // distinguishes them when filtering.
        self::write(json_encode($line, JSON_UNESCAPED_SLASHES) . "\n");
    }

    private static function write(string $line): void
    {
        if (self::$stream !== null) {
            fwrite(self::$stream, $line);
            return;
        }
        // Default: php://stderr. Under php-fpm this routes to the worker's
        // stderr, which the Dockerfile pipes to the container stderr that
        // `docker compose logs` consumes.
        $fp = fopen('php://stderr', 'w');
        if ($fp !== false) {
            fwrite($fp, $line);
            fclose($fp);
        }
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
