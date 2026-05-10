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
    /** @var resource */
    private $buffer;

    protected function setUp(): void
    {
        $this->buffer = fopen('php://memory', 'w+');
        Logger::setStream($this->buffer);
    }

    protected function tearDown(): void
    {
        Logger::resetStream();
        if (is_resource($this->buffer)) {
            fclose($this->buffer);
        }
    }

    private function captured(): string
    {
        rewind($this->buffer);
        return (string) stream_get_contents($this->buffer);
    }

    #[Test]
    public function move_emits_one_line_of_well_formed_json(): void
    {
        Logger::move([
            'game_id'          => 'abc-123',
            'turn'             => 47,
            'strategy'         => 'llm',
            'model_used'       => 'meta-llama/llama-3.3-70b-instruct',
            'model_label'      => 'primary',
            'move'             => 'up',
            'reasoning'        => 'health low, food above',
            'safe_moves'       => ['up', 'right'],
            'llm_latency_ms'   => 312,
            'mcts_rollouts'    => 410,
            'total_latency_ms' => 452,
            'fallback_used'    => false,
            'own_health'       => 42,
            'own_length'       => 9,
        ]);
        $out = $this->captured();

        $this->assertStringEndsWith("\n", $out);
        $this->assertSame(1, substr_count($out, "\n"), 'must be exactly one log line');

        $decoded = json_decode(trim($out), true);
        $this->assertIsArray($decoded);
        $this->assertSame('move',                              $decoded['event']);
        $this->assertSame('abc-123',                           $decoded['game_id']);
        $this->assertSame(47,                                  $decoded['turn']);
        $this->assertSame('llm',                               $decoded['strategy']);
        $this->assertSame('meta-llama/llama-3.3-70b-instruct', $decoded['model_used']);
        $this->assertSame('primary',                           $decoded['model_label']);
        $this->assertSame('up',                                $decoded['move']);
        $this->assertSame(['up', 'right'],                     $decoded['safe_moves']);
        $this->assertSame(312,                                 $decoded['llm_latency_ms']);
        $this->assertSame(410,                                 $decoded['mcts_rollouts']);
        $this->assertSame(452,                                 $decoded['total_latency_ms']);
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
        Logger::move(['move' => 'down']);
        $decoded = json_decode(trim($this->captured()), true);

        $this->assertSame('down', $decoded['move']);
        $this->assertNull($decoded['game_id']);
        $this->assertNull($decoded['model_used']);
        $this->assertNull($decoded['llm_latency_ms']);
        $this->assertSame([], $decoded['safe_moves']);
        $this->assertSame(0, $decoded['mcts_rollouts']);
        $this->assertFalse($decoded['fallback_used']);
    }

    #[Test]
    public function warn_emits_separate_event_type(): void
    {
        Logger::warn('something fishy', ['preview' => 'abc']);
        $decoded = json_decode(trim($this->captured()), true);

        $this->assertSame('warn',          $decoded['event']);
        $this->assertSame('something fishy', $decoded['message']);
        $this->assertSame('abc',           $decoded['context']['preview']);
    }
}
