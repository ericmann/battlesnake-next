<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * Production Transport: drives PHP's built-in curl_multi to fire two HTTP
 * POSTs in true parallel, with a per-request stagger and a hard wall-clock
 * timeout shared across the batch.
 *
 * Design notes:
 * - Polls every 5ms via curl_multi_select(); cheap and tight enough that
 *   we can hit a 300ms total budget without overshooting.
 * - When the *first* request completes successfully, we return immediately
 *   and let the destructor reap remaining handles. The OpenRouter caller
 *   only needs the winner; the loser's wasted bandwidth costs nothing
 *   compared to the round-trip we just saved.
 * - Network errors (DNS, connect, abort) come back as Response with
 *   status=0 and empty body, so the caller can treat them uniformly with
 *   parseable HTTP errors.
 */
final class CurlMultiTransport implements Transport
{
    /** @param int $pollIntervalMs how aggressively to wake and re-poll */
    public function __construct(private readonly int $pollIntervalMs = 5)
    {
    }

    public function race(array $requests, int $totalTimeoutMs): array
    {
        if ($requests === []) {
            return [];
        }

        $mh = curl_multi_init();
        $t0 = hrtime(true);

        // Pending requests: those not yet added because their delayMs hasn't elapsed.
        // Keyed by spl_object_id so we can match handles back to their label.
        $pending = $requests;
        usort($pending, static fn(Request $a, Request $b): int => $a->delayMs <=> $b->delayMs);

        $handles = []; // map: (int) handle resource id => ['handle' => ch, 'request' => Request]
        $completed = [];

        // Add zero-delay requests immediately.
        while ($pending !== [] && $pending[0]->delayMs === 0) {
            $req = array_shift($pending);
            $ch  = $this->buildHandle($req);
            curl_multi_add_handle($mh, $ch);
            $handles[(int) $ch] = ['handle' => $ch, 'request' => $req];
        }

        $deadlineNs = $t0 + $totalTimeoutMs * 1_000_000;

        do {
            // Add any pending requests whose delay has elapsed.
            $elapsedMs = (int) ((hrtime(true) - $t0) / 1_000_000);
            while ($pending !== [] && $pending[0]->delayMs <= $elapsedMs) {
                $req = array_shift($pending);
                $ch  = $this->buildHandle($req);
                curl_multi_add_handle($mh, $ch);
                $handles[(int) $ch] = ['handle' => $ch, 'request' => $req];
            }

            $status = curl_multi_exec($mh, $stillRunning);
            if ($status !== CURLM_OK) {
                break;
            }

            // Drain any completed handles.
            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch = $info['handle'];
                $key = (int) $ch;
                if (!isset($handles[$key])) {
                    continue;
                }
                $req = $handles[$key]['request'];
                $body = (string) curl_multi_getcontent($ch);
                $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $elapsed = (int) ((hrtime(true) - $t0) / 1_000_000);

                $completed[] = new Response(
                    label:     $req->label,
                    status:    $httpStatus,
                    body:      $body,
                    elapsedMs: $elapsed,
                );

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($handles[$key]);
            }

            if ($completed !== []) {
                // OpenRouter::race() only consumes the first completion,
                // so we can short-circuit as soon as something arrives.
                break;
            }

            // Out of time?
            if (hrtime(true) >= $deadlineNs) {
                break;
            }

            // Wait for activity, but never longer than our remaining budget.
            $remainingMs = max(1, (int) (($deadlineNs - hrtime(true)) / 1_000_000));
            $waitS = min($this->pollIntervalMs, $remainingMs) / 1000;
            curl_multi_select($mh, $waitS);
        } while ($stillRunning > 0 || $pending !== []);

        // Cleanup any still-open handles (timeout or short-circuit).
        foreach ($handles as ['handle' => $ch]) {
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $completed;
    }

    private function buildHandle(Request $req): \CurlHandle
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $req->url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $req->body,
            CURLOPT_RETURNTRANSFER => true,
            // Connect quickly or give up — we have a strict budget. The
            // overall wall-clock bound is enforced by race()'s loop, so the
            // per-handle timeout just needs to be loose enough to never
            // truncate a body that's actively arriving.
            CURLOPT_CONNECTTIMEOUT_MS => 250,
            CURLOPT_TIMEOUT_MS        => 5000,
            CURLOPT_HTTPHEADER     => array_map(
                static fn(string $k, string $v): string => "$k: $v",
                array_keys($req->headers),
                array_values($req->headers),
            ),
            // TLS sanity.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        return $ch;
    }
}
