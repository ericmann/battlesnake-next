<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * MCTS that runs one rollout per call and keeps a running tally of the
 * mean survival score per root move. Designed to be invoked from inside
 * Decider's main loop so we do useful work during LLM idle time instead
 * of sleeping.
 *
 * Selection heuristic for the next root move to roll out: pick whichever
 * legal move has the fewest rollouts so far. That gives every move at
 * least one early sample while still allowing the most-promising-looking
 * move to be re-tested if budget permits.
 */
final class IncrementalMcts
{
    /** @var array<string, float> totals[move] = sum of survival turns */
    private array $totals;
    /** @var array<string, int> counts[move] = rollout count */
    private array $counts;

    /**
     * @param array        $state     The full Battlesnake /move payload.
     * @param list<string> $safeMoves Pre-filtered legal moves; never empty.
     * @param int          $depth     Per-rollout horizon (turns).
     */
    public function __construct(
        private readonly array $state,
        private readonly array $safeMoves,
        private readonly int $depth = 20,
    ) {
        $this->totals = array_fill_keys($safeMoves, 0.0);
        $this->counts = array_fill_keys($safeMoves, 0);
    }

    /**
     * Run exactly one rollout. Cheap; safe to call hundreds of times
     * inside the Decider loop.
     */
    public function runOne(): void
    {
        if ($this->safeMoves === []) {
            return;
        }
        // Round-robin by lowest count first so every move gets sampled.
        $least = $this->safeMoves[0];
        $leastCount = $this->counts[$least];
        foreach ($this->safeMoves as $m) {
            if ($this->counts[$m] < $leastCount) {
                $least = $m;
                $leastCount = $this->counts[$m];
            }
        }

        $score = Safety::singleRollout($this->state, $least, $this->depth);
        $this->totals[$least] += $score;
        $this->counts[$least]++;
    }

    /**
     * Best move by mean rollout score, or null if no rollouts have run.
     */
    public function best(): ?string
    {
        $bestMove = null;
        $bestScore = -1.0;
        foreach ($this->safeMoves as $m) {
            if ($this->counts[$m] === 0) {
                continue;
            }
            $mean = $this->totals[$m] / $this->counts[$m];
            if ($mean > $bestScore) {
                $bestScore = $mean;
                $bestMove  = $m;
            }
        }
        return $bestMove;
    }

    public function rolloutCount(): int
    {
        return array_sum($this->counts);
    }
}
