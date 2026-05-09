<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * The thinnest possible front controller. Every method returns
 * [int $status, array|object $body] which index.php turns into a JSON
 * response.
 *
 * The /move handler is the hot path. Its budget is ruthless:
 *   1. Parse + validate input.            (sub-millisecond)
 *   2. Compute legal moves via Safety.    (~1 ms even on dense boards)
 *   3. Format the ASCII board.            (~1 ms)
 *   4. Race two OpenRouter models.        (~120-280 ms typical)
 *   5. If race returned null → MCTS.      (capped at 100 ms)
 *   6. Log + return JSON.                 (sub-millisecond)
 *
 * Every step has a known upper bound. The total wall-clock is also enforced
 * by the LLM_TIMEOUT_MS configured on the OpenRouter client; if the race
 * times out, MCTS still runs, but we must finish within the engine's 500ms
 * budget. We aim for 450 to leave network slack.
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
            // Malformed payload — never let the engine see a 5xx. "up" is
            // arbitrary but legal-looking; the next /move call will recover.
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
        if ($safeMoves === []) {
            // Should never happen — legalMoves always returns >= 1 element —
            // but if it did, default to "up" with a shout. Snake is dead.
            Logger::move([
                'game_id' => $gameId, 'turn' => $turn,
                'move' => 'up', 'safe_moves' => [],
                'fallback_used' => true,
                'total_latency_ms' => self::elapsedMs($tStart),
                'own_health' => (int) ($me['health'] ?? 0),
                'own_length' => (int) ($me['length'] ?? 0),
            ]);
            return [200, ['move' => 'up', 'shout' => 'this is fine']];
        }

        // ----- 3. Render board for the LLM --------------------------------
        $prompt = Board::format($state, $safeMoves);

        // ----- 4. Race the LLM models -------------------------------------
        $raceResult   = null;
        $raceLatency  = null;
        $apiKey       = Env::str('OPENROUTER_API_KEY');
        if ($apiKey !== '' && $apiKey !== 'sk-or-...') {
            try {
                $client = new OpenRouter(
                    apiKey:         $apiKey,
                    primaryModel:   Env::str('PRIMARY_MODEL',   'google/gemini-2.5-flash'),
                    secondaryModel: Env::str('SECONDARY_MODEL', 'anthropic/claude-haiku-4.5'),
                    transport:      new CurlMultiTransport(),
                    timeoutMs:      Env::int('LLM_TIMEOUT_MS', 300),
                    staggerMs:      Env::int('STAGGER_MS', 50),
                    appName:        Env::str('OPENROUTER_APP_NAME', 'battlesnake-next'),
                    referer:        Env::str('OPENROUTER_REFERER', 'https://snake.eamann.com'),
                );
                $raceResult  = $client->race($prompt, $safeMoves);
                $raceLatency = $raceResult?->latencyMs;
            } catch (\Throwable $e) {
                Logger::warn('openrouter race threw', ['err' => $e->getMessage()]);
                $raceResult = null;
            }
        }

        // ----- 5. Decide --------------------------------------------------
        if ($raceResult !== null) {
            $move          = $raceResult->move;
            $reasoning     = $raceResult->reasoning;
            $modelUsed     = $raceResult->model;
            $modelLabel    = $raceResult->label;
            $fallbackUsed  = false;
        } else {
            // LLM unavailable, timed out, or returned an illegal move.
            //
            // Order of fallback preference:
            //   - If we have meaningful budget left (>120ms) AND multiple
            //     legal moves, run MCTS to pick the best survival rollout.
            //   - Otherwise, lean on the flood-fill winner (safeMoves[0]).
            //     It's already the most-open-space direction — a perfectly
            //     reasonable next move and zero additional latency.
            $remainingMs = max(0, 450 - self::elapsedMs($tStart));
            if ($remainingMs >= 120 && count($safeMoves) > 1) {
                $move      = Safety::mctsMove($state, $safeMoves, budgetMs: min(100, $remainingMs - 20));
                $reasoning = 'mcts fallback';
            } else {
                $move      = $safeMoves[0];
                $reasoning = 'flood-fill fallback';
            }
            $modelUsed    = null;
            $modelLabel   = null;
            $fallbackUsed = true;
        }

        // ----- 6. Log + return --------------------------------------------
        Logger::move([
            'game_id'          => $gameId,
            'turn'             => $turn,
            'model_used'       => $modelUsed,
            'model_label'      => $modelLabel,
            'move'             => $move,
            'reasoning'        => $reasoning,
            'safe_moves'       => $safeMoves,
            'llm_latency_ms'   => $raceLatency,
            'total_latency_ms' => self::elapsedMs($tStart),
            'fallback_used'    => $fallbackUsed,
            'own_health'       => (int) ($me['health'] ?? 0),
            'own_length'       => (int) ($me['length'] ?? 0),
        ]);

        $context = Shouts::fromMove($move, $state, $safeMoves, $fallbackUsed);
        $shout   = Shouts::pick($context, $turn);

        return [200, ['move' => $move, 'shout' => $shout]];
    }

    private static function elapsedMs(int|float $startNs): int
    {
        return (int) ((hrtime(true) - $startNs) / 1_000_000);
    }
}
