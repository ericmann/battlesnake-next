<?php

declare(strict_types=1);

namespace BattlesnakeAI\Tests;

use BattlesnakeAI\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Logger::class)]
final class LoggerTest extends TestCase
{
    #[Test]
    public function move_emits_one_line_of_well_formed_json(): void
    {
        ob_start();
        Logger::move([
            'game_id'          => 'abc-123',
            'turn'             => 47,
            'model_used'       => 'google/gemini-2.0-flash',
            'model_label'      => 'primary',
            'move'             => 'up',
            'reasoning'        => 'food at (3,8)',
            'safe_moves'       => ['up', 'right'],
            'llm_latency_ms'   => 187,
            'total_latency_ms' => 203,
            'fallback_used'    => false,
            'own_health'       => 42,
            'own_length'       => 9,
        ]);
        $out = (string) ob_get_clean();

        $this->assertStringEndsWith("\n", $out);
        $this->assertSame(1, substr_count($out, "\n"), 'must be exactly one log line');

        $decoded = json_decode(trim($out), true);
        $this->assertIsArray($decoded);
        $this->assertSame('move',                     $decoded['event']);
        $this->assertSame('abc-123',                  $decoded['game_id']);
        $this->assertSame(47,                         $decoded['turn']);
        $this->assertSame('google/gemini-2.0-flash',  $decoded['model_used']);
        $this->assertSame('up',                       $decoded['move']);
        $this->assertSame(['up', 'right'],            $decoded['safe_moves']);
        $this->assertFalse($decoded['fallback_used']);

        // ts must be ISO8601-ish UTC.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $decoded['ts'],
        );
    }

    #[Test]
    public function move_defaults_unspecified_fields(): void
    {
        ob_start();
        Logger::move(['move' => 'down']);
        $out = (string) ob_get_clean();

        $decoded = json_decode(trim($out), true);
        $this->assertSame('down', $decoded['move']);
        $this->assertNull($decoded['game_id']);
        $this->assertNull($decoded['llm_latency_ms']);
        $this->assertSame([], $decoded['safe_moves']);
        $this->assertFalse($decoded['fallback_used']);
    }
}
