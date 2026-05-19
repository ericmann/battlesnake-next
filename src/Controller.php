<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * The thinnest possible front controller. Every method returns
 * [int $status, array|object $body] which index.php turns into a JSON
 * response.
 *
 * /move pipeline:
 *   1. Parse + validate input.                     (sub-millisecond)
 *   2. Compute legal moves via Safety.             (~1 ms)
 *   3. Render board ASCII for the LLM.             (~1 ms)
 *   4. Build the LLM driver (or NullLlmDriver
 *      when OPENROUTER_API_KEY is absent).
 *   5. Decider runs ONE loop for DECISION_MS:
 *        - polls LLM curl_multi each pass
 *        - runs an MCTS rollout each pass
 *      Picks LLM > MCTS > flood-fill at the deadline.
 *   6. Log + return JSON.                          (sub-millisecond)
 *
 * The LLM path is pinned to a single provider (default: Groq) via
 * `provider.allow_fallbacks=false` so we never silently fan out to a
 * slow backend like DeepInfra. Tune DECISION_MS in .env to fit the
 * observed venue p95 — see LATENCY_CHECK.php.
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

        $me     = $state['you'];
        $turn   = (int) ($state['turn'] ?? 0);
        $gameId = $state['game']['id'] ?? null;

        // ----- 2. Safety filter -------------------------------------------
        try {
            $safeMovesSpace = Safety::legalMovesWithSpace($me, $state['board']);
        } catch (\Throwable $e) {
            Logger::warn('safety layer threw', ['err' => $e->getMessage()]);
            return [200, ['move' => 'up', 'shout' => 'this is fine']];
        }
        $safeMoves = array_keys($safeMovesSpace);

        // ----- 3. Render board for the LLM --------------------------------
        // Pass the scored map so the prompt shows "down(33), left(33), right(1)"
        // — the small fallback model can otherwise miss the cliff between
        // "legal" and "1-cell pocket".
        $prompt = Board::format($state, $safeMovesSpace);

        // ----- 4. Build the LLM driver (or a no-op when no key) -----------
        $apiKey = Env::str('OPENROUTER_API_KEY');
        $hasKey = $apiKey !== '' && !str_starts_with($apiKey, 'sk-or-...');
        $providerPin = Env::str('OPENROUTER_PROVIDER', 'Groq');
        $llm = $hasKey
            ? new CurlMultiLlmDriver(
                apiKey:         $apiKey,
                primaryModel:   Env::str('PRIMARY_MODEL',   'meta-llama/llama-3.3-70b-instruct'),
                secondaryModel: Env::str('SECONDARY_MODEL', 'meta-llama/llama-3.1-8b-instruct'),
                userPrompt:     $prompt,
                safeMoves:      $safeMoves,
                staggerMs:      Env::int('STAGGER_MS', 50),
                appName:        Env::str('OPENROUTER_APP_NAME', 'battlesnake-next'),
                referer:        Env::str('OPENROUTER_REFERER', 'https://snake.eamann.com'),
                providerPin:    $providerPin !== '' ? $providerPin : null,
            )
            : new NullLlmDriver();

        // ----- 5. Run the unified decision loop ---------------------------
        // sleepMicros=1000 lets the kernel deliver TCP segments while the
        // loop also spins MCTS rollouts. With NullLlmDriver, the Decider
        // would still respect this — but no LLM means we'd rather burn
        // CPU on rollouts, so drop the sleep in that case.
        $decision = (new Decider(
            llm:            $llm,
            mcts:           new IncrementalMcts($state, $safeMoves),
            safeMoves:      $safeMoves,
            decisionMs:     Env::int('DECISION_MS', 450),
            sleepMicros:    $hasKey ? 1000 : 0,
            safeMovesSpace: $safeMovesSpace,
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
