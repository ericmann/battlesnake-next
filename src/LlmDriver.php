<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * Step-by-step LLM driver used by Decider.
 *
 * The Decider runs ONE tight loop that interleaves LLM polling with MCTS
 * rollouts inside a single ~400ms decision window. Anything that drives
 * the LLM must therefore expose the work as a sequence of cheap, non-
 * blocking steps:
 *
 *   start()  — fire any outbound HTTP work, return immediately
 *   step()   — do any non-blocking work; return RaceResult if a usable
 *              move has arrived since the previous step(), else null
 *   cancel() — close any in-flight handles; safe to call multiple times
 *
 * Tests can swap in fakes that simulate timing without the network. The
 * production implementation, CurlMultiLlmDriver, drives curl_multi
 * directly so we never block on a single handle.
 */
interface LlmDriver
{
    public function start(): void;

    /**
     * Returns the first arrived legal-move result, or null if nothing
     * usable has come in yet. Once a non-null is returned, subsequent
     * step() calls should return null (the result has been delivered).
     */
    public function step(): ?RaceResult;

    public function cancel(): void;
}
