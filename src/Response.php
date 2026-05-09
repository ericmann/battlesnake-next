<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * Completed Transport::race() response.
 *
 * - $label echoes the originating Request's label so logs can attribute the
 *   winning model.
 * - $status is the HTTP status code; 0 means the request errored out before
 *   getting a response (network error, DNS failure, timeout aborting, etc.).
 * - $body is the raw response body. May be empty on errors.
 * - $elapsedMs is how long the request took, measured from race() t0.
 */
final readonly class Response
{
    public function __construct(
        public string $label,
        public int $status,
        public string $body,
        public int $elapsedMs,
    ) {}
}
