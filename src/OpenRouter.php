<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * Dual-model OpenRouter racing client.
 *
 * The trick: don't trust any single LLM with a 500ms deadline. Fire two
 * different models in parallel, take the first response that contains a
 * legal move, drop the loser. Worst-case latency drops from "the slow
 * model's tail latency" to "the *faster* model's tail latency"; tail
 * dropouts on conference WiFi stop being game-enders.
 *
 * Hot path (race): one allocation per Request, one curl_multi loop, no
 * string concatenation, no regex. Everything that can be done at object
 * construction is done at construction.
 */
final readonly class OpenRouter
{
    public const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    public function __construct(
        private string $apiKey,
        private string $primaryModel,
        private string $secondaryModel,
        private Transport $transport,
        private int $timeoutMs = 300,
        private int $staggerMs = 50,
        private string $appName = 'battlesnake-next',
        private string $referer = 'https://snake.eamann.com',
    ) {}

    /**
     * Return the OpenRouter response carrying a legal move, or null if
     * neither model returned anything we can use within the budget.
     *
     * The returned Response is the FIRST handle to come back with a usable
     * move. The losing handle is closed inside the transport.
     *
     * @param string       $userPrompt The board ASCII + metadata block.
     * @param list<string> $safeMoves  Pre-filtered legal moves; LLM picks must
     *                                 be a member.
     */
    public function race(string $userPrompt, array $safeMoves): ?RaceResult
    {
        if ($safeMoves === []) {
            return null;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
            'HTTP-Referer'  => $this->referer,
            'X-Title'       => $this->appName,
        ];

        $primaryBody   = $this->buildBody($this->primaryModel, $userPrompt);
        $secondaryBody = $this->buildBody($this->secondaryModel, $userPrompt);

        $requests = [
            new Request('primary',   self::API_URL, $headers, $primaryBody,   delayMs: 0),
            new Request('secondary', self::API_URL, $headers, $secondaryBody, delayMs: $this->staggerMs),
        ];

        $responses = $this->transport->race($requests, $this->timeoutMs);

        // Walk completions in finish order; take the first one with a parseable,
        // legal move. Anything else (HTTP errors, garbage JSON, illegal move)
        // is silently dropped — caller will fall through to the MCTS fallback.
        foreach ($responses as $resp) {
            $move = $this->parseMove($resp->body, $safeMoves);
            if ($move === null) {
                continue;
            }
            $reasoning = $this->parseReasoning($resp->body);
            $modelUsed = $resp->label === 'primary' ? $this->primaryModel : $this->secondaryModel;
            return new RaceResult(
                move:      $move,
                reasoning: $reasoning,
                model:     $modelUsed,
                label:     $resp->label,
                latencyMs: $resp->elapsedMs,
            );
        }

        return null;
    }

    /**
     * Strict-mode JSON body builder. We ask OpenRouter for a JSON object so
     * neither Gemini nor Haiku wraps the answer in markdown fences (which
     * would force a brittle regex unwrap on the hot path).
     */
    private function buildBody(string $model, string $userPrompt): string
    {
        return (string) json_encode([
            'model'       => $model,
            'temperature' => 0.2, // tight creativity; the prompt does the heavy lifting
            'max_tokens'  => 60,  // move + one short sentence — anything more wastes ms
            'response_format' => ['type' => 'json_object'],
            'messages'    => [
                ['role' => 'system', 'content' => Prompts::SYSTEM],
                ['role' => 'user',   'content' => $userPrompt],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Pull the move out of an OpenRouter response. Returns null if the
     * response is empty/malformed/non-JSON, or if the move isn't in the
     * legal set. Tries the strict path first (JSON content) and falls back
     * to a permissive regex unwrap so a model-of-the-week still works
     * when it sneaks markdown fences in.
     */
    public function parseMove(string $body, array $safeMoves): ?string
    {
        return self::parseMoveStatic($body, $safeMoves);
    }

    /**
     * Static counterpart used by CurlMultiLlmDriver so the Decider doesn't
     * need to allocate an OpenRouter instance just to parse a body.
     *
     * @param list<string> $safeMoves
     */
    public static function parseMoveStatic(string $body, array $safeMoves): ?string
    {
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return null;
        }
        $content = $payload['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || $content === '') {
            return null;
        }

        $obj = self::decodeContentJsonStatic($content);
        if (!is_array($obj)) {
            return null;
        }
        $move = $obj['move'] ?? null;
        if (!is_string($move)) {
            return null;
        }
        $move = strtolower(trim($move));
        if (!in_array($move, ['up', 'down', 'left', 'right'], true)) {
            return null;
        }
        return in_array($move, $safeMoves, true) ? $move : null;
    }

    /**
     * Pulls the human-friendly "reasoning" string for logs. Best-effort —
     * returns empty string if the model didn't include one.
     */
    public function parseReasoning(string $body): string
    {
        return self::parseReasoningStatic($body);
    }

    public static function parseReasoningStatic(string $body): string
    {
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return '';
        }
        $content = $payload['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            return '';
        }
        $obj = self::decodeContentJsonStatic($content);
        if (is_array($obj) && isset($obj['reasoning']) && is_string($obj['reasoning'])) {
            return mb_substr($obj['reasoning'], 0, 120);
        }
        return '';
    }

    /**
     * Decode the LLM's "content" field. Per the prompt it's pure JSON, but
     * be permissive: strip ```json fences if a stubborn model adds them.
     */
    public static function decodeContentJsonStatic(string $content): mixed
    {
        $trim = trim($content);
        if (str_starts_with($trim, '```')) {
            // Strip an optional ```json or ``` opening fence and trailing ```.
            $trim = preg_replace('/^```(?:json)?\s*/i', '', $trim) ?? $trim;
            $trim = preg_replace('/```\s*$/', '', $trim) ?? $trim;
            $trim = trim($trim);
        }
        $obj = json_decode($trim, true);
        if (is_array($obj)) {
            return $obj;
        }
        // Last-ditch: yank out the first { ... } block. Some chatty models
        // prepend a sentence even after being told not to.
        if (preg_match('/\{[^{}]*\}/s', $trim, $m) === 1) {
            $obj = json_decode($m[0], true);
            if (is_array($obj)) {
                return $obj;
            }
        }
        return null;
    }
}
