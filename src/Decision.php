<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * Outcome of Decider::decide(). Carries everything Controller::move()
 * needs to assemble both the HTTP response and the structured log line.
 *
 * `strategy` is one of:
 *   - 'llm'         — the OpenRouter race produced a usable, legal move
 *   - 'mcts'        — MCTS rollouts were the best signal we had at deadline
 *   - 'flood_fill'  — neither LLM nor MCTS produced anything; fell to the
 *                     pre-computed safeMoves[0] (most-open-space direction)
 */
final readonly class Decision
{
    public function __construct(
        public string  $strategy,
        public string  $move,
        public string  $reasoning,
        public ?string $model,         // null unless strategy='llm'
        public ?string $modelLabel,    // 'primary'|'secondary'|null
        public ?int    $llmLatencyMs,  // null unless strategy='llm'
        public int     $mctsRollouts,
        public int     $totalLatencyMs,
    ) {}

    public static function llm(RaceResult $r, int $rollouts, int $totalMs): self
    {
        return new self(
            strategy:       'llm',
            move:           $r->move,
            reasoning:      $r->reasoning !== '' ? $r->reasoning : 'llm',
            model:          $r->model,
            modelLabel:     $r->label,
            llmLatencyMs:   $r->latencyMs,
            mctsRollouts:   $rollouts,
            totalLatencyMs: $totalMs,
        );
    }

    public static function mcts(string $move, int $rollouts, int $totalMs): self
    {
        return new self(
            strategy:       'mcts',
            move:           $move,
            reasoning:      "mcts ({$rollouts} rollouts)",
            model:          null,
            modelLabel:     null,
            llmLatencyMs:   null,
            mctsRollouts:   $rollouts,
            totalLatencyMs: $totalMs,
        );
    }

    public static function floodFill(string $move, int $totalMs): self
    {
        return new self(
            strategy:       'flood_fill',
            move:           $move,
            reasoning:      'flood-fill winner',
            model:          null,
            modelLabel:     null,
            llmLatencyMs:   null,
            mctsRollouts:   0,
            totalLatencyMs: $totalMs,
        );
    }
}
