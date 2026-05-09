<?php

declare(strict_types=1);

namespace BattlesnakeAI\Tests;

use BattlesnakeAI\OpenRouter;
use BattlesnakeAI\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenRouter::class)]
final class OpenRouterTest extends TestCase
{
    private function client(FakeTransport $transport, int $timeoutMs = 300, int $staggerMs = 50): OpenRouter
    {
        return new OpenRouter(
            apiKey:        'sk-or-test',
            primaryModel:  'google/gemini-2.0-flash',
            secondaryModel:'anthropic/claude-haiku-4-5',
            transport:     $transport,
            timeoutMs:     $timeoutMs,
            staggerMs:     $staggerMs,
        );
    }

    /** Build the OpenRouter-style chat completion envelope around an inner JSON. */
    private function envelope(string $innerJson): string
    {
        return (string) json_encode([
            'choices' => [
                ['message' => ['content' => $innerJson]],
            ],
        ]);
    }

    #[Test]
    public function primary_winner_is_returned_with_correct_label_and_model(): void
    {
        $transport = new FakeTransport();
        $transport->reply('primary',   200, $this->envelope('{"move":"up","reasoning":"open"}'),  120);
        $transport->reply('secondary', 200, $this->envelope('{"move":"left","reasoning":"x"}'),   200);

        $result = $this->client($transport)->race('BOARD', ['up', 'right']);

        $this->assertNotNull($result);
        $this->assertSame('up',                          $result->move);
        $this->assertSame('open',                        $result->reasoning);
        $this->assertSame('primary',                     $result->label);
        $this->assertSame('google/gemini-2.0-flash',     $result->model);
        $this->assertSame(120,                           $result->latencyMs);
    }

    #[Test]
    public function secondary_winner_is_returned_when_primary_is_slower(): void
    {
        $transport = new FakeTransport();
        $transport->reply('primary',   200, $this->envelope('{"move":"up","reasoning":"slow"}'),  290);
        $transport->reply('secondary', 200, $this->envelope('{"move":"right","reasoning":"q"}'),  140);

        $result = $this->client($transport)->race('BOARD', ['up', 'right']);

        $this->assertNotNull($result);
        $this->assertSame('right',                         $result->move);
        $this->assertSame('secondary',                     $result->label);
        $this->assertSame('anthropic/claude-haiku-4-5',    $result->model);
    }

    #[Test]
    public function returns_null_when_both_handles_time_out(): void
    {
        $transport = new FakeTransport();
        $transport->reply('primary',   200, $this->envelope('{"move":"up"}'),    9999);
        $transport->reply('secondary', 200, $this->envelope('{"move":"right"}'), 9999);

        $result = $this->client($transport, timeoutMs: 300)->race('BOARD', ['up', 'right']);

        $this->assertNull($result);
    }

    #[Test]
    public function returns_null_when_winner_picks_an_illegal_move(): void
    {
        $transport = new FakeTransport();
        // Winner picks "down" but the safety filter only allows up/right.
        $transport->reply('primary',   200, $this->envelope('{"move":"down","reasoning":"oops"}'), 100);
        // Secondary times out.
        $transport->reply('secondary', 200, $this->envelope('{"move":"left"}'), 9999);

        $result = $this->client($transport, timeoutMs: 300)->race('BOARD', ['up', 'right']);

        // Primary returned an illegal move; we drop it and have nothing else.
        $this->assertNull($result);
    }

    #[Test]
    public function returns_null_when_response_is_unparseable_garbage(): void
    {
        $transport = new FakeTransport();
        $transport->reply('primary',   200, '<html>500 server error</html>', 100);
        $transport->reply('secondary', 200, '',                              9999);

        $result = $this->client($transport)->race('BOARD', ['up', 'right']);

        $this->assertNull($result);
    }

    #[Test]
    public function tolerates_markdown_fences_in_content(): void
    {
        $transport = new FakeTransport();
        $stubborn = "```json\n{\"move\":\"left\",\"reasoning\":\"fenced\"}\n```";
        $transport->reply('primary', 200, $this->envelope($stubborn), 80);

        $result = $this->client($transport)->race('BOARD', ['left']);

        $this->assertNotNull($result);
        $this->assertSame('left',  $result->move);
        $this->assertSame('fenced', $result->reasoning);
    }

    #[Test]
    public function falls_back_to_secondary_when_primary_is_unparseable(): void
    {
        $transport = new FakeTransport();
        // Primary completes first but with garbage; secondary completes with valid JSON.
        $transport->reply('primary',   200, 'not even json',                          50);
        $transport->reply('secondary', 200, $this->envelope('{"move":"up","reasoning":"ok"}'), 200);

        // The current FakeTransport short-circuits on the first completion to mirror
        // the production transport, so this test asserts that *if* both responses are
        // available, the OpenRouter client picks the first parseable one. To exercise
        // that path, we let both arrive — extend FakeTransport for a multi-completion
        // race below.
        $multi = new class extends FakeTransport {
            public function race(array $requests, int $totalTimeoutMs): array
            {
                $out = [];
                foreach ($requests as $req) {
                    if (!isset($this->canned[$req->label])) continue;
                    [$status, $body, $elapsed] = $this->canned[$req->label];
                    if ($elapsed > $totalTimeoutMs) continue;
                    $out[] = [
                        'response' => new \BattlesnakeAI\Response($req->label, $status, $body, $elapsed),
                        'elapsed'  => $elapsed,
                    ];
                }
                usort($out, static fn(array $a, array $b): int => $a['elapsed'] <=> $b['elapsed']);
                return array_map(static fn(array $r): \BattlesnakeAI\Response => $r['response'], $out);
            }
        };
        $multi->reply('primary',   200, 'not even json',                                50);
        $multi->reply('secondary', 200, $this->envelope('{"move":"up","reasoning":"ok"}'), 200);

        $result = $this->client($multi)->race('BOARD', ['up']);

        $this->assertNotNull($result);
        $this->assertSame('up',        $result->move);
        $this->assertSame('secondary', $result->label);
    }

    #[Test]
    public function staggers_secondary_request_by_configured_offset(): void
    {
        $transport = new FakeTransport();
        $transport->reply('primary', 200, $this->envelope('{"move":"up"}'), 100);

        $this->client($transport, staggerMs: 75)->race('BOARD', ['up']);

        $this->assertCount(2, $transport->sent);
        $this->assertSame('primary',   $transport->sent[0]['label']);
        $this->assertSame(0,           $transport->sent[0]['delayMs']);
        $this->assertSame('secondary', $transport->sent[1]['label']);
        $this->assertSame(75,          $transport->sent[1]['delayMs']);
    }

    #[Test]
    public function move_value_is_normalized_to_lowercase(): void
    {
        $transport = new FakeTransport();
        $transport->reply('primary', 200, $this->envelope('{"move":"UP","reasoning":"caps"}'), 80);

        $result = $this->client($transport)->race('BOARD', ['up']);

        $this->assertNotNull($result);
        $this->assertSame('up', $result->move);
    }
}
