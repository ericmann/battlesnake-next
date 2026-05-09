<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * Outcome of a successful OpenRouter::race(). Carries everything the
 * /move handler needs to emit a response and a structured log line.
 */
final readonly class RaceResult
{
    public function __construct(
        public string $move,
        public string $reasoning,
        public string $model,     // full model id, e.g. "google/gemini-2.0-flash"
        public string $label,     // "primary" or "secondary"
        public int    $latencyMs, // race() t0 → response complete
    ) {}
}
