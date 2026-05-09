<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * The thinnest possible front controller. Every method returns
 * [int $status, array|object $body] which index.php turns into a JSON
 * response.
 *
 * The /move handler is the hot path and runs everything inside one
 * deterministic time window:
 *
 *   1. Parse + validate input.                     (sub-millisecond)
 *   2. Compute legal moves via Safety.             (~1 ms)
 *   3. Format the ASCII board for the LLM.         (~1 ms)
 *   4. Build the LLM driver (or NullLlmDriver).    (sub-ms)
 *   5. Decider runs ONE loop for DECISION_MS:
 *      polls LLM curl_multi each pass, runs an MCTS rollout each pass,
 *      and at the deadline picks LLM > MCTS > flood-fill.
 *   6. Log + return JSON.                          (sub-millisecond)
 *
 * The total wall-clock cost is ~DECISION_MS + ~5 ms of overhead. Tune
 * DECISION_MS in .env to fit the observed venue p95 (see LATENCY_CHECK.php).
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
        $tStart = hrtime(true);

        // ----- 1. Parse + validate ----------------------------------------
        $state = json_decode($rawBody, true);
        if (!is_array($state)
            || !isset($state['board'], $state['you'])
            || !is_array($state['board'])
            || !is_array($state['you'])
        ) {
            // Malformed payload — never let the engine see a 5xx.
            Logger::warn('malformed payload, defaulting to up', [
                'preview' => mb_substr($rawBody, 0, 200),
            ]);
            return [200, ['move' => 'up']];
        }

        $board  = $state['board'];
        $me     = $state['you'];
        $turn   = (int) ($state['turn'] ?? 0);
        $gameId = $state['game']['id'] ?? null;

        // ----- 2. Safety filter -------------------------------------------
        try {
            $safeMoves = Safety::legalMoves($me, $board);
        } catch (\Throwable $e) {
            Logger::warn('safety layer threw', ['err' => $e->getMessage()]);
            return [200, ['move' => 'up', 'shout' => 'this is fine']];
        }

        // ----- 3. Render board for the LLM --------------------------------
        $prompt = Board::format($state, $safeMoves);

        // ----- 4. Build the LLM driver (or a no-op when no key) -----------
        $apiKey = Env::str('OPENROUTER_API_KEY');
        $hasKey = $apiKey !== '' && !str_starts_with($apiKey, 'sk-or-...');
        $llm    = $hasKey
            ? new CurlMultiLlmDriver(
                apiKey:         $apiKey,
                primaryModel:   Env::str('PRIMARY_MODEL',   'google/gemini-2.5-flash-lite'),
                secondaryModel: Env::str('SECONDARY_MODEL', 'google/gemini-2.0-flash-lite-001'),
                userPrompt:     $prompt,
                safeMoves:      $safeMoves,
                staggerMs:      Env::int('STAGGER_MS', 50),
                appName:        Env::str('OPENROUTER_APP_NAME', 'battlesnake-next'),
                referer:        Env::str('OPENROUTER_REFERER', 'https://snake.eamann.com'),
            )
            : new NullLlmDriver();

        // ----- 5. Run the unified decision loop ---------------------------
        $decision = (new Decider(
            llm:        $llm,
            mcts:       new IncrementalMcts($state, $safeMoves),
            safeMoves:  $safeMoves,
            decisionMs: Env::int('DECISION_MS', 400),
        ))->decide();

        // ----- 6. Log + return --------------------------------------------
        Logger::move([
            'game_id'          => $gameId,
            'turn'             => $turn,
            'strategy'         => $decision->strategy,
            'model_used'       => $decision->model,
            'model_label'      => $decision->modelLabel,
            'move'             => $decision->move,
            'reasoning'        => $decision->reasoning,
            'safe_moves'       => $safeMoves,
            'llm_latency_ms'   => $decision->llmLatencyMs,
            'mcts_rollouts'    => $decision->mctsRollouts,
            'total_latency_ms' => max($decision->totalLatencyMs, self::elapsedMs($tStart)),
            'fallback_used'    => $decision->strategy !== 'llm',
            'own_health'       => (int) ($me['health'] ?? 0),
            'own_length'       => (int) ($me['length'] ?? 0),
        ]);

        $context = Shouts::fromMove(
            $decision->move,
            $state,
            $safeMoves,
            $decision->strategy !== 'llm',
        );
        $shout = Shouts::pick($context, $turn);

        return [200, ['move' => $decision->move, 'shout' => $shout]];
    }

    private static function elapsedMs(int|float $startNs): int
    {
        return (int) ((hrtime(true) - $startNs) / 1_000_000);
    }
}
