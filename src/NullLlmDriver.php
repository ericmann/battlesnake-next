<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * No-op LlmDriver. Used when OPENROUTER_API_KEY is absent — the snake
 * runs purely on MCTS + flood-fill, and the Decider's loop spends every
 * tick on rollouts.
 *
 * Also useful as a unit-test default when the test doesn't care about
 * the LLM path.
 */
final class NullLlmDriver implements LlmDriver
{
    public function start(): void {}
    public function step(): ?RaceResult { return null; }
    public function cancel(): void {}
}
