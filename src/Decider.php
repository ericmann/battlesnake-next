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
 * Two sanity gates run on the LLM's pick before it wins:
 *   1. Area-control gate (only active when $safeMovesSpace is provided):
 *      if the LLM picked a move with dramatically less area than the
 *      winner, it's almost certainly a hallucination (small-model favourite
 *      failure mode: pick a 1-cell pocket while *claiming* "maximize open
 *      space"). Reject.
 *   2. MCTS gate: if MCTS rollouts have sampled both the LLM's pick and
 *      MCTS's own best, and the LLM's pick scores much worse, the rollouts
 *      saw a multi-turn trap the area-control gate can't see. Reject.
 *
 * When either gate trips, the LLM is discarded and MCTS / flood-fill
 * take over.
 *
 * The class is intentionally injection-friendly: callers pass a built
 * LlmDriver and IncrementalMcts. Production wires CurlMultiLlmDriver +
 * IncrementalMcts; tests can wire a fake LlmDriver and either a real
 * or pre-seeded IncrementalMcts.
 */
final class Decider
{
    /**
     * Area-gate threshold: reject the LLM if the winner's flood-fill space
     * is at least this many times the LLM's pick. Tuned to catch the
     * "1-cell-pocket vs 33-cell-room" blunder while still letting the LLM
     * make tight-but-legitimate tactical picks (within ~4× of the winner).
     */
    private const SANITY_GATE_RATIO = 4;

    /**
     * MCTS-gate threshold: reject the LLM if MCTS's best move scores at
     * least this much better than the LLM's pick in mean rollout score,
     * AND both have been sampled enough times to be trustworthy. The unit
     * is "extra survival turns + kill bonuses". 3 is roughly "MCTS rollouts
     * see the LLM's pick dying ~3 turns earlier on average" — meaningful
     * but not so noisy that legitimate tactical picks get overridden.
     */
    private const MCTS_GATE_DELTA = 3.0;

    /**
     * Minimum samples per move before the MCTS gate is willing to act on
     * the comparison. A single rollout has too much variance to base a
     * decision on; require at least this many before trusting the mean.
     */
    private const MCTS_GATE_MIN_SAMPLES = 3;

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
     * @param array<string,bool>      $foodMoves       Optional map of moves whose landing
     *                                                 cell is a food cell. When the LLM
     *                                                 picks one of these, the MCTS gate is
     *                                                 disarmed — MCTS rollouts undervalue
     *                                                 growth, but the food-aware ranker
     *                                                 already weighed whether eating is
     *                                                 worth it. Empty by default.
     * @param array<string,bool>      $headOnLossMoves Optional map of moves whose landing
     *                                                 cell is a head-on loss against an
     *                                                 equal-or-larger enemy. Enables the
     *                                                 head-on-mismatch gate: reject the
     *                                                 LLM when its head-on-vs-safe call
     *                                                 disagrees with the ranker's top.
     */
    public function __construct(
        private readonly LlmDriver        $llm,
        private readonly IncrementalMcts  $mcts,
        private readonly array            $safeMoves,
        private readonly int              $decisionMs = 400,
        private readonly int              $sleepMicros = 1000,
        private readonly ?array           $safeMovesSpace = null,
        private readonly array            $foodMoves = [],
        private readonly array            $headOnLossMoves = [],
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
        if ($llmResult !== null && $this->headOnMismatch($llmResult->move, $ffMove)) {
            // The LLM and the ranker disagree about whether to take a
            // head-on gamble — either the LLM is walking into a longer
            // enemy when a safe option exists, or it's playing safe into
            // a death-trap that the ranker correctly flagged. Either way,
            // trust the ranker.
            $llmResult = null;
        }
        if ($llmResult !== null && $this->mctsStronglyDisagrees($llmResult->move, $ffMove)) {
            // The LLM's pick survived the area check but MCTS rollouts saw
            // a much-better alternative — usually a multi-turn trap the
            // area gate can't see (advance toward longer enemy, etc.).
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

    private function mctsStronglyDisagrees(string $llmMove, string $rankerTop): bool
    {
        if (isset($this->foodMoves[$llmMove])) {
            // The LLM stepped directly onto a food cell. MCTS rollouts
            // systematically undervalue eating — a random-walk rollout
            // with a longer body self-collides more often, so growing
            // looks "dangerous" in the score. The food-aware ranker
            // already weighed hunger and length deficit; trust it.
            return false;
        }
        $mctsBest = $this->mcts->best();
        if ($mctsBest === null || $mctsBest === $llmMove) {
            return false; // no MCTS opinion, or it agrees
        }
        $llmMean  = $this->mcts->meanFor($llmMove);
        $bestMean = $this->mcts->meanFor($mctsBest);
        if ($llmMean === null || $bestMean === null) {
            return false; // one or both untouched
        }
        // Require both moves to have enough samples that the means aren't
        // dominated by one lucky rollout.
        $minSamples = min($this->mcts->countFor($llmMove), $this->mcts->countFor($mctsBest));
        if ($minSamples < self::MCTS_GATE_MIN_SAMPLES) {
            return false;
        }
        if ($bestMean - $llmMean < self::MCTS_GATE_DELTA) {
            return false; // close enough; trust the LLM
        }
        Logger::warn('llm pick rejected: mcts rollouts strongly prefer a different move', [
            'llm_move'    => $llmMove,
            'llm_mean'    => $llmMean,
            'mcts_move'   => $mctsBest,
            'mcts_mean'   => $bestMean,
            'samples_min' => $minSamples,
            'gate_delta'  => self::MCTS_GATE_DELTA,
        ]);
        return true;
    }

    private function headOnMismatch(string $llmMove, string $rankerTop): bool
    {
        if ($this->headOnLossMoves === []) {
            return false; // gate disabled (no head-on candidates this turn)
        }
        $llmIsHeadOn    = isset($this->headOnLossMoves[$llmMove]);
        $rankerIsHeadOn = isset($this->headOnLossMoves[$rankerTop]);
        // Only reject when the LLM gambled on a head-on that the ranker avoided.
        // When the LLM picks safe and the ranker gambled (high-area head-on),
        // the LLM is *correcting* the ranker — let it win.
        if (!$llmIsHeadOn || $rankerIsHeadOn) {
            return false;
        }
        Logger::warn('llm pick rejected: head-on mismatch with ranker', [
            'llm_move'          => $llmMove,
            'llm_is_head_on'    => $llmIsHeadOn,
            'ranker_top'        => $rankerTop,
            'ranker_is_head_on' => $rankerIsHeadOn,
        ]);
        return true;
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
