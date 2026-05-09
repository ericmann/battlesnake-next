<?php

declare(strict_types=1);

namespace BattlesnakeAI\Tests\Support;

use BattlesnakeAI\LlmDriver;
use BattlesnakeAI\RaceResult;

/**
 * Test double for LlmDriver. Configure a single canned RaceResult and a
 * "step at which it arrives" — the driver delivers the result on the
 * Nth step() call after start(), simulating the curl_multi delivery
 * latency without any real network I/O.
 */
final class FakeLlmDriver implements LlmDriver
{
    private int $stepsTaken = 0;
    private bool $started = false;
    private bool $cancelled = false;
    private bool $delivered = false;

    public function __construct(
        private readonly ?RaceResult $result = null,
        private readonly int $arrivesOnStep = 1,
    ) {}

    public function start(): void
    {
        $this->started    = true;
        $this->stepsTaken = 0;
        $this->delivered  = false;
    }

    public function step(): ?RaceResult
    {
        if (!$this->started || $this->cancelled || $this->delivered || $this->result === null) {
            return null;
        }
        $this->stepsTaken++;
        if ($this->stepsTaken >= $this->arrivesOnStep) {
            $this->delivered = true;
            return $this->result;
        }
        return null;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function wasCancelled(): bool
    {
        return $this->cancelled;
    }

    public function stepCount(): int
    {
        return $this->stepsTaken;
    }
}
