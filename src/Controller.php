<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * The thinnest possible front controller. Every method returns
 * [int $status, array|object $body] which index.php turns into a JSON
 * response.
 *
 * /move pipeline (current):
 *   1. Parse + validate input.                     (sub-millisecond)
 *   2. Compute legal moves via Safety.             (~1 ms)
 *   3. Decider runs MCTS for DECISION_MS:
 *        - LlmDriver is hard-wired to NullLlmDriver
 *        - Each loop iteration is one rollout, no usleep
 *      Picks MCTS best, or flood-fill winner if no rollouts completed.
 *   4. Log + return JSON.                          (sub-millisecond)
 *
 * The OpenRouter / CurlMultiLlmDriver / Prompts / Board classes are still
 * in the codebase so the LLM path can be revived when a faster vendor or
 * a regional endpoint with sub-300ms p50 becomes available. Today the
 * Google Vertex EU endpoint sits at ~500 ms p50 from our deploy region —
 * which already exceeds Battlesnake's 500 ms /move budget, so calling
 * it can only hurt us.
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
            $safeMoves = Safety::legalMoves($me, $state['board']);
        } catch (\Throwable $e) {
            Logger::warn('safety layer threw', ['err' => $e->getMessage()]);
            return [200, ['move' => 'up', 'shout' => 'this is fine']];
        }

        // ----- 3. MCTS-only decision loop ---------------------------------
        // sleepMicros = 0 means "no LLM in flight, burn the budget on
        // rollouts". DECISION_MS sets a hard wall-clock cap (default 150).
        $decision = (new Decider(
            llm:         new NullLlmDriver(),
            mcts:        new IncrementalMcts($state, $safeMoves),
            safeMoves:   $safeMoves,
            decisionMs:  Env::int('DECISION_MS', 150),
            sleepMicros: 0,
        ))->decide();

        // ----- 4. Log + return --------------------------------------------
        Logger::move([
            'game_id'          => $gameId,
            'turn'             => $turn,
            'strategy'         => $decision->strategy,
            'move'             => $decision->move,
            'reasoning'        => $decision->reasoning,
            'safe_moves'       => $safeMoves,
            'mcts_rollouts'    => $decision->mctsRollouts,
            'total_latency_ms' => max($decision->totalLatencyMs, self::elapsedMs($tStart)),
            'own_health'       => (int) ($me['health'] ?? 0),
            'own_length'       => (int) ($me['length'] ?? 0),
        ]);

        $context = Shouts::fromMove(
            $decision->move,
            $state,
            $safeMoves,
            $decision->strategy !== 'llm', // "llm" never set today; preserved for the revival
        );
        $shout = Shouts::pick($context, $turn);

        return [200, ['move' => $decision->move, 'shout' => $shout]];
    }

    private static function elapsedMs(int|float $startNs): int
    {
        return (int) ((hrtime(true) - $startNs) / 1_000_000);
    }
}
