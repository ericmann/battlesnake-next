<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * One outbound HTTP POST in a Transport::race() batch.
 *
 * - $label is a free-form name used in logs ("primary", "secondary").
 * - $delayMs is how long after t0 the transport should wait before firing
 *   this request. Set to 0 for the first one; STAGGER_MS for the second.
 * - $headers and $body are sent as-is. The transport sets nothing extra.
 */
final readonly class Request
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        public string $label,
        public string $url,
        public array $headers,
        public string $body,
        public int $delayMs = 0,
    ) {}
}
