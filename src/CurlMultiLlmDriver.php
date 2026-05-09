<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * Drives the dual-model OpenRouter race via curl_multi, interleaved into
 * Decider's main loop.
 *
 * Differences from the older CurlMultiTransport / OpenRouter::race():
 *
 * - This class is *step-driven*. Every call to step() drains any completed
 *   handles, promotes staggered handles whose delay has elapsed, and
 *   returns a RaceResult on the first parseable, legal answer.
 * - It never blocks. The curl_multi_select sleep that the older transport
 *   used has been replaced with a single curl_multi_exec poke per step;
 *   the Decider's loop body governs the cadence (typically ~1ms).
 * - It does not early-terminate the second handle on the first arrival —
 *   if the primary returns garbage, the secondary's body is parsed when
 *   it arrives. The Decider, not this class, owns the deadline.
 */
final class CurlMultiLlmDriver implements LlmDriver
{
    private \CurlMultiHandle $mh;
    /** @var array<int, array{ch: \CurlHandle, label: string}> */
    private array $handles = [];
    /** @var list<array{ch: \CurlHandle, label: string, addAtMs: int}> */
    private array $pending = [];
    private int $t0 = 0;
    private bool $delivered = false;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $primaryModel,
        private readonly string $secondaryModel,
        private readonly string $userPrompt,
        private readonly array $safeMoves,
        private readonly int $staggerMs = 50,
        private readonly string $appName = 'battlesnake-next',
        private readonly string $referer = 'https://snake.eamann.com',
    ) {}

    public function start(): void
    {
        $this->t0 = (int) (hrtime(true) / 1_000_000);
        $this->mh = curl_multi_init();

        $primary = $this->buildHandle($this->primaryModel);
        curl_multi_add_handle($this->mh, $primary);
        $this->handles[(int) $primary] = ['ch' => $primary, 'label' => 'primary'];

        $secondary = $this->buildHandle($this->secondaryModel);
        $this->pending[] = [
            'ch'      => $secondary,
            'label'   => 'secondary',
            'addAtMs' => $this->t0 + $this->staggerMs,
        ];
    }

    public function step(): ?RaceResult
    {
        if ($this->delivered) {
            return null;
        }

        // Promote any pending handles whose stagger has elapsed.
        $nowMs = (int) (hrtime(true) / 1_000_000);
        foreach ($this->pending as $i => $p) {
            if ($p['addAtMs'] <= $nowMs) {
                curl_multi_add_handle($this->mh, $p['ch']);
                $this->handles[(int) $p['ch']] = ['ch' => $p['ch'], 'label' => $p['label']];
                unset($this->pending[$i]);
            }
        }

        // Drive curl_multi forward (non-blocking).
        curl_multi_exec($this->mh, $stillRunning);

        // Drain any completed handles.
        while (($info = curl_multi_info_read($this->mh)) !== false) {
            $ch  = $info['handle'];
            $key = (int) $ch;
            if (!isset($this->handles[$key])) {
                continue;
            }
            $label   = $this->handles[$key]['label'];
            $body    = (string) curl_multi_getcontent($ch);
            $elapsed = (int) (hrtime(true) / 1_000_000) - $this->t0;
            curl_multi_remove_handle($this->mh, $ch);
            curl_close($ch);
            unset($this->handles[$key]);

            $move = OpenRouter::parseMoveStatic($body, $this->safeMoves);
            if ($move === null) {
                continue; // try the other handle when it arrives
            }
            $reasoning = OpenRouter::parseReasoningStatic($body);
            $modelId   = $label === 'primary' ? $this->primaryModel : $this->secondaryModel;
            $this->delivered = true;
            return new RaceResult(
                move:      $move,
                reasoning: $reasoning,
                model:     $modelId,
                label:     $label,
                latencyMs: $elapsed,
            );
        }

        return null;
    }

    public function cancel(): void
    {
        foreach ($this->handles as ['ch' => $ch]) {
            curl_multi_remove_handle($this->mh, $ch);
            curl_close($ch);
        }
        $this->handles = [];
        foreach ($this->pending as $p) {
            curl_close($p['ch']);
        }
        $this->pending = [];
        if (isset($this->mh)) {
            curl_multi_close($this->mh);
            unset($this->mh);
        }
    }

    private function buildHandle(string $model): \CurlHandle
    {
        $body = (string) json_encode([
            'model'           => $model,
            'temperature'     => 0.2,
            'max_tokens'      => 60,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => Prompts::SYSTEM],
                ['role' => 'user',   'content' => $this->userPrompt],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL               => OpenRouter::API_URL,
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => $body,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CONNECTTIMEOUT_MS => 250,
            CURLOPT_TIMEOUT_MS        => 5000, // overall window owned by Decider
            CURLOPT_HTTPHEADER        => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'HTTP-Referer: ' . $this->referer,
                'X-Title: ' . $this->appName,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        return $ch;
    }
}
