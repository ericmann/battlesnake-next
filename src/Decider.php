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
 *               1. LLM if a legal move arrived (subject to the sanity gate)
 *               2. MCTS if at least one rollout completed
 *               3. flood-fill winner (always available)
 *
 * Sanity gate (only active when $safeMovesSpace is provided): if the LLM's
 * pick has dramatically less flood-fill space than the winner — the most
 * common failure mode of the small fallback model, which will cheerfully pick
 * a 1-cell pocket while *claiming* "maximize open space" — the gate discards
 * the LLM result and lets MCTS or flood-fill take over.
 *
 * The class is intentionally injection-friendly: callers pass a built
 * LlmDriver and IncrementalMcts. Production wires CurlMultiLlmDriver +
 * IncrementalMcts; tests can wire a fake LlmDriver and either a real
 * or pre-seeded IncrementalMcts.
 */
final class Decider
{
    /**
     * Sanity-gate threshold: reject the LLM if the winner's flood-fill space
     * is at least this many times the LLM's pick. Tuned to catch the
     * "1-cell-pocket vs 33-cell-room" blunder while still letting the LLM
     * make tight-but-legitimate tactical picks (within ~4× of the winner).
     */
    private const SANITY_GATE_RATIO = 4;

    /**
     * @param list<string>            $safeMoves       Non-empty, sorted by flood-fill score.
     * @param int                     $decisionMs      Hard cap on the decision loop in ms.
     * @param int                     $sleepMicros     Per-iteration usleep. 1ms is the
     *                                                 sweet spot when an LLM is in flight
     *                                                 (curl_multi needs the kernel to
     *                                                 actually deliver bytes). With the
     *                                                 NullLlmDriver, pass 0 to skip the
     *                                                 syscall entirely and run MCTS at
     *                                                 full CPU.
     * @param array<string,int>|null  $safeMovesSpace  Optional map move→flood-fill space,
     *                                                 same key set as $safeMoves. When
     *                                                 present, enables the sanity gate on
     *                                                 LLM picks. Pass `null` (the test
     *                                                 default) to disable the gate.
     */
    public function __construct(
        private readonly LlmDriver        $llm,
        private readonly IncrementalMcts  $mcts,
        private readonly array            $safeMoves,
        private readonly int              $decisionMs = 400,
        private readonly int              $sleepMicros = 1000,
        private readonly ?array           $safeMovesSpace = null,
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
            // gets a chance to deliver TCP segments. When sleepMicros is 0
            // (no LLM in flight) skip the syscall entirely.
            if ($this->sleepMicros > 0) {
                usleep($this->sleepMicros);
            }
        }

        $this->llm->cancel();
        $totalMs = (int) ((hrtime(true) - $t0) / 1_000_000);

        if ($llmResult !== null && $this->isSuicidalLlmPick($llmResult->move, $ffMove)) {
            // The LLM picked a move so cramped that the small fallback model
            // almost certainly hallucinated its own reasoning. Toss the
            // result and let MCTS / flood-fill answer instead.
            $llmResult = null;
        }

        if ($llmResult !== null) {
            return Decision::llm($llmResult, $this->mcts->rolloutCount(), $totalMs);
        }
        $mctsBest = $this->mcts->best();
        if ($mctsBest !== null) {
            return Decision::mcts($mctsBest, $this->mcts->rolloutCount(), $totalMs);
        }
        return Decision::floodFill($ffMove, $totalMs);
    }

    private function isSuicidalLlmPick(string $llmMove, string $ffMove): bool
    {
        if ($this->safeMovesSpace === null) {
            return false; // gate disabled (test default)
        }
        $llmSpace = $this->safeMovesSpace[$llmMove] ?? null;
        $bestSpace = $this->safeMovesSpace[$ffMove]  ?? null;
        if ($llmSpace === null || $bestSpace === null) {
            return false; // unknown shape — don't second-guess
        }
        if ($llmSpace <= 0) {
            // Zero-space pick (head-on-loss fallback returned 0). Always reject
            // if anything else has non-zero room.
            $worthRejecting = $bestSpace > 0;
        } else {
            $worthRejecting = $bestSpace >= $llmSpace * self::SANITY_GATE_RATIO;
        }
        if ($worthRejecting) {
            Logger::warn('llm pick rejected: flood-fill space too tight vs winner', [
                'llm_move'        => $llmMove,
                'llm_space'       => $llmSpace,
                'winner_move'     => $ffMove,
                'winner_space'    => $bestSpace,
                'gate_ratio'      => self::SANITY_GATE_RATIO,
            ]);
            return true;
        }
        return false;
    }
}
