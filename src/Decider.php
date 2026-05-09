<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * Single-window /move decision orchestrator.
 *
 * The flow:
 *
 *   t=0       Pre-compute the flood-fill winner (free; safeMoves[0]).
 *             This is our floor — every Decision has *some* legal move.
 *   t=0       Fire the LLM driver (curl_multi inside).
 *   loop      Until DECISION_MS elapses:
 *               - call llm.step() once (drains any completed handles)
 *               - call mcts.runOne() once (one random rollout)
 *               - usleep ~1 ms so the kernel can actually deliver bytes
 *   t=DEC     llm.cancel(); pick the best result by priority:
 *               1. LLM if a legal move arrived
 *               2. MCTS if at least one rollout completed
 *               3. flood-fill winner (always available)
 *
 * The class is intentionally injection-friendly: callers pass a built
 * LlmDriver and IncrementalMcts. Production wires CurlMultiLlmDriver +
 * IncrementalMcts; tests can wire a fake LlmDriver and either a real
 * or pre-seeded IncrementalMcts.
 */
final class Decider
{
    /**
     * @param list<string> $safeMoves     Non-empty, sorted by flood-fill score.
     * @param int          $decisionMs    Hard cap on the decision loop in ms.
     * @param int          $sleepMicros   Per-iteration usleep. 1ms is the
     *                                    sweet spot for curl_multi; tighter
     *                                    starves the kernel, looser delays
     *                                    arrival detection.
     */
    public function __construct(
        private readonly LlmDriver        $llm,
        private readonly IncrementalMcts  $mcts,
        private readonly array            $safeMoves,
        private readonly int              $decisionMs = 400,
        private readonly int              $sleepMicros = 1000,
    ) {
        if ($safeMoves === []) {
            throw new \InvalidArgumentException('safeMoves must not be empty');
        }
    }

    public function decide(): Decision
    {
        $t0       = hrtime(true);
        $deadline = $t0 + $this->decisionMs * 1_000_000;
        $ffMove   = $this->safeMoves[0];

        $this->llm->start();
        $llmResult = null;

        // If there's only one legal move, MCTS is redundant; still spin
        // briefly to give the LLM a window in case its reasoning beats
        // "the only option" (it won't change the move, but it provides a
        // genuine reason for the log).
        $singleOption = count($this->safeMoves) === 1;

        while (hrtime(true) < $deadline) {
            if ($llmResult === null) {
                $llmResult = $this->llm->step();
            }
            if (!$singleOption) {
                $this->mcts->runOne();
            }

            // Yield. Without this we burn 100% CPU and the kernel never
            // gets a chance to deliver TCP segments.
            usleep($this->sleepMicros);
        }

        $this->llm->cancel();
        $totalMs = (int) ((hrtime(true) - $t0) / 1_000_000);

        if ($llmResult !== null) {
            return Decision::llm($llmResult, $this->mcts->rolloutCount(), $totalMs);
        }
        $mctsBest = $this->mcts->best();
        if ($mctsBest !== null) {
            return Decision::mcts($mctsBest, $this->mcts->rolloutCount(), $totalMs);
        }
        return Decision::floodFill($ffMove, $totalMs);
    }
}
