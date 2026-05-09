<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * Pluggable HTTP transport for OpenRouter::race().
 *
 * The production implementation drives curl_multi for true parallel calls.
 * Tests can swap in a fake that simulates timing, body content, and failure
 * modes without touching the network — see tests/OpenRouterTest.php.
 *
 * The contract is intentionally minimal: a single method that takes two
 * "ticket" requests with delays, races them with a hard timeout, and
 * returns a list of completed responses in the order they finished. The
 * caller decides which response (if any) yields a usable move.
 */
interface Transport
{
    /**
     * Race a list of HTTP POST requests in parallel.
     *
     * Each request in $requests is a Request value object. The transport
     * should fire the first request immediately, fire each subsequent
     * request after its $delayMs has elapsed since t0, poll for completions
     * until either every request has finished or $totalTimeoutMs is reached,
     * and then close all outstanding handles.
     *
     * Returns the completed responses in the order they finished. Each
     * Response carries its source request label (for logging) and the
     * elapsed milliseconds from t0 to completion.
     *
     * @param list<Request> $requests
     * @return list<Response>
     */
    public function race(array $requests, int $totalTimeoutMs): array;
}
