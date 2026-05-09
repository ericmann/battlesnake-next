<?php

declare(strict_types=1);

namespace BattlesnakeAI\Tests\Support;

use BattlesnakeAI\Request;
use BattlesnakeAI\Response;
use BattlesnakeAI\Transport;

/**
 * Test double for the Transport interface.
 *
 * Configure it with a queue of canned responses keyed by request label, plus
 * a simulated elapsed time for each. The race() method honors the same
 * "first response in finish order wins" contract as the real curl_multi
 * transport, but never opens a socket.
 */
class FakeTransport implements Transport
{
    /** @var array<string, array{0:int,1:string,2:int}> label => [status, body, elapsedMs] */
    protected array $canned = [];

    /** @var list<array{label:string, url:string, body:string, delayMs:int}> */
    public array $sent = [];

    public function reply(string $label, int $status, string $body, int $elapsedMs): void
    {
        $this->canned[$label] = [$status, $body, $elapsedMs];
    }

    public function race(array $requests, int $totalTimeoutMs): array
    {
        // Capture for assertion-side inspection.
        foreach ($requests as $req) {
            $this->sent[] = [
                'label'   => $req->label,
                'url'     => $req->url,
                'body'    => $req->body,
                'delayMs' => $req->delayMs,
            ];
        }

        $completions = [];
        foreach ($requests as $req) {
            if (!isset($this->canned[$req->label])) {
                continue; // unmocked request "times out"
            }
            [$status, $body, $elapsed] = $this->canned[$req->label];
            // Filter anything that wouldn't have completed inside the budget.
            if ($elapsed > $totalTimeoutMs) {
                continue;
            }
            $completions[] = [
                'response' => new Response($req->label, $status, $body, $elapsed),
                'elapsed'  => $elapsed,
            ];
        }

        // Mirror the production transport's behavior: short-circuit on the
        // first completion in finish order so the caller only ever sees the
        // winning response.
        usort($completions, static fn(array $a, array $b): int => $a['elapsed'] <=> $b['elapsed']);
        return $completions === [] ? [] : [$completions[0]['response']];
    }
}
