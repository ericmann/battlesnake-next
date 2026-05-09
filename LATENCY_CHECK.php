#!/usr/bin/env php
<?php

/*
 * LATENCY_CHECK.php — tournament-day venue probe.
 *
 * Fires 20 sequential OpenRouter requests using PRIMARY_MODEL with a
 * deliberately tiny prompt ("Board: . Move:") and reports p50 / p95 /
 * max round-trip latency in milliseconds. Run this from the venue
 * network *before* the tournament to decide whether to lower
 * LLM_TIMEOUT_MS in your .env file.
 *
 * The minimal prompt isolates network + model TTFT from prompt-size
 * effects. Real /move calls send ~1200-1500 tokens of board + system
 * prompt and will run noticeably slower; treat these numbers as a
 * lower bound, then add ~150-300ms for production prompts.
 *
 * Usage:
 *   php LATENCY_CHECK.php
 *
 * Reads from .env in the same way the live app does. Requires only
 * ext-curl and ext-json. No Composer install needed.
 *
 * Exit code 0 always — this is a diagnostic, not a CI gate.
 */

declare(strict_types=1);

const REQUEST_COUNT = 20;
const PROMPT        = 'Board: . Move:';
const URL           = 'https://openrouter.ai/api/v1/chat/completions';

if (!extension_loaded('curl')) {
    fwrite(STDERR, "ext-curl is required for LATENCY_CHECK.php\n");
    exit(1);
}

$env = loadDotenv(__DIR__ . '/.env');

$apiKey  = $env['OPENROUTER_API_KEY'] ?? getenv('OPENROUTER_API_KEY') ?: '';
$model   = $env['PRIMARY_MODEL']      ?? getenv('PRIMARY_MODEL')      ?: 'google/gemini-2.5-flash-lite';
$referer = $env['OPENROUTER_REFERER'] ?? getenv('OPENROUTER_REFERER') ?: 'https://snake.eamann.com';
$appName = $env['OPENROUTER_APP_NAME']?? getenv('OPENROUTER_APP_NAME')?: 'battlesnake-next';

if ($apiKey === '' || str_starts_with($apiKey, 'sk-or-...')) {
    fwrite(STDERR, "OPENROUTER_API_KEY not set in .env (or still has placeholder value).\n");
    exit(1);
}

fwrite(STDOUT, "Probing $model via OpenRouter — " . REQUEST_COUNT . " sequential requests.\n");
fwrite(STDOUT, "(Tiny prompt — real /move calls add ~150-300 ms for the board state.)\n\n");

$latencies = [];
$failures  = 0;
$bodyTpl   = json_encode([
    'model'         => $model,
    'temperature'   => 0.2,
    'max_tokens'    => 8,
    'response_format' => ['type' => 'json_object'],
    'messages'      => [
        ['role' => 'user', 'content' => PROMPT . ' Reply {"move":"up"}.'],
    ],
], JSON_UNESCAPED_SLASHES);

for ($i = 1; $i <= REQUEST_COUNT; $i++) {
    $ch = curl_init(URL);
    curl_setopt_array($ch, [
        CURLOPT_POST              => true,
        CURLOPT_POSTFIELDS        => $bodyTpl,
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_TIMEOUT_MS        => 8000,
        CURLOPT_HTTPHEADER        => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: ' . $referer,
            'X-Title: ' . $appName,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $t0   = hrtime(true);
    $body = curl_exec($ch);
    $ms   = (int) ((hrtime(true) - $t0) / 1_000_000);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $http >= 400) {
        $failures++;
        $note = $err !== '' ? " err=$err" : '';
        fwrite(STDOUT, sprintf("  %2d/%2d  FAIL  http=%-3d  elapsed=%4d ms%s\n",
            $i, REQUEST_COUNT, $http, $ms, $note));
        continue;
    }
    $latencies[] = $ms;
    fwrite(STDOUT, sprintf("  %2d/%2d  ok    http=%-3d  elapsed=%4d ms\n",
        $i, REQUEST_COUNT, $http, $ms));
}

fwrite(STDOUT, "\n");

if ($latencies === []) {
    fwrite(STDOUT, "All $failures requests failed. Check OPENROUTER_API_KEY and connectivity.\n");
    exit(0);
}

sort($latencies);
$count = count($latencies);
$p50   = $latencies[(int) floor(0.50 * ($count - 1))];
$p95   = $latencies[(int) floor(0.95 * ($count - 1))];
$max   = $latencies[$count - 1];
$min   = $latencies[0];
$mean  = (int) round(array_sum($latencies) / $count);

fwrite(STDOUT, "================ Summary ================\n");
fwrite(STDOUT, "Model:     $model\n");
fwrite(STDOUT, "Successes: $count / " . REQUEST_COUNT . "\n");
fwrite(STDOUT, "Failures:  $failures\n");
fwrite(STDOUT, "Min:       {$min} ms\n");
fwrite(STDOUT, "Mean:      {$mean} ms\n");
fwrite(STDOUT, "p50:       {$p50} ms\n");
fwrite(STDOUT, "p95:       {$p95} ms\n");
fwrite(STDOUT, "Max:       {$max} ms\n");
fwrite(STDOUT, "==========================================\n\n");

$budget    = 500;
$prodSlack = 200; // expected extra TTFT from full board prompt
$rec       = max(150, $p95 + $prodSlack);
fwrite(STDOUT, sprintf(
    "Recommended LLM_TIMEOUT_MS for tournament use:  %d ms\n",
    min($budget - 50, $rec),
));
fwrite(STDOUT, "  (clamped to leave 50 ms for the rest of the pipeline)\n");
fwrite(STDOUT, "  Heuristic: max(150, observed p95 + ~200 ms prompt overhead),\n");
fwrite(STDOUT, "  capped at the Battlesnake budget of {$budget} ms minus margin.\n");

exit(0);

// ---------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------

/**
 * Tiny .env parser. Mirrors how the app reads config:
 * - one KEY=VALUE per line
 * - comments (#...) and blank lines ignored
 * - no shell expansion, no exports
 *
 * Returns an associative array. Missing file → empty array.
 */
function loadDotenv(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $out   = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $k = trim(substr($line, 0, $eq));
        $v = trim(substr($line, $eq + 1));
        // Strip optional surrounding quotes.
        if ($v !== '' && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $out[$k] = $v;
    }
    return $out;
}
