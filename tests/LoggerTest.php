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
            'strategy'         => 'mcts',
            'move'             => 'up',
            'reasoning'        => 'mcts (3120 rollouts)',
            'safe_moves'       => ['up', 'right'],
            'mcts_rollouts'    => 3120,
            'total_latency_ms' => 152,
            'own_health'       => 42,
            'own_length'       => 9,
        ]);
        $out = $this->captured();

        $this->assertStringEndsWith("\n", $out);
        $this->assertSame(1, substr_count($out, "\n"), 'must be exactly one log line');

        $decoded = json_decode(trim($out), true);
        $this->assertIsArray($decoded);
        $this->assertSame('move',                   $decoded['event']);
        $this->assertSame('abc-123',                $decoded['game_id']);
        $this->assertSame(47,                       $decoded['turn']);
        $this->assertSame('mcts',                   $decoded['strategy']);
        $this->assertSame('up',                     $decoded['move']);
        $this->assertSame(['up', 'right'],          $decoded['safe_moves']);
        $this->assertSame(3120,                     $decoded['mcts_rollouts']);
        $this->assertSame(152,                      $decoded['total_latency_ms']);

        // LLM-specific fields must not leak back in.
        $this->assertArrayNotHasKey('model_used',     $decoded);
        $this->assertArrayNotHasKey('model_label',    $decoded);
        $this->assertArrayNotHasKey('llm_latency_ms', $decoded);
        $this->assertArrayNotHasKey('fallback_used',  $decoded);

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
        $this->assertSame([], $decoded['safe_moves']);
        $this->assertSame(0, $decoded['mcts_rollouts']);
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
