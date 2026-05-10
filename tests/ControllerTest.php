<?php

declare(strict_types=1);

namespace BattlesnakeAI\Tests;

use BattlesnakeAI\Controller;
use BattlesnakeAI\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Controller::class)]
final class ControllerTest extends TestCase
{
    /** @var resource */
    private $logBuffer;

    protected function setUp(): void
    {
        // Force the controller into "no LLM available" mode so we don't
        // try to hit OpenRouter from CI / dev machines without a key.
        $_ENV['OPENROUTER_API_KEY'] = '';
        putenv('OPENROUTER_API_KEY=');

        // Keep the Decider's loop tight so the test suite stays fast.
        $_ENV['DECISION_MS'] = '20';
        putenv('DECISION_MS=20');

        $this->logBuffer = fopen('php://memory', 'w+');
        Logger::setStream($this->logBuffer);
    }

    protected function tearDown(): void
    {
        Logger::resetStream();
        if (is_resource($this->logBuffer)) {
            fclose($this->logBuffer);
        }
    }

    private function logs(): string
    {
        rewind($this->logBuffer);
        return (string) stream_get_contents($this->logBuffer);
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__ . '/Fixtures/sample_board.json');
    }

    #[Test]
    public function meta_returns_the_configured_snake_metadata(): void
    {
        [$status, $body] = Controller::meta();

        $this->assertSame(200, $status);
        $this->assertSame('1', $body['apiversion']);
        $this->assertArrayHasKey('color', $body);
        $this->assertArrayHasKey('head',  $body);
        $this->assertArrayHasKey('tail',  $body);
        $this->assertSame('next-3.0', $body['version']);
    }

    #[Test]
    public function start_and_end_return_empty_object(): void
    {
        [$startStatus, $startBody] = Controller::start();
        [$endStatus,   $endBody]   = Controller::end();

        $this->assertSame(200, $startStatus);
        $this->assertSame(200, $endStatus);
        $this->assertInstanceOf(\stdClass::class, $startBody);
        $this->assertInstanceOf(\stdClass::class, $endBody);
    }

    #[Test]
    public function move_returns_a_legal_move_with_a_shout(): void
    {
        [$status, $body] = Controller::move($this->fixture());

        $this->assertSame(200, $status);
        $this->assertContains($body['move'], ['up', 'down', 'left', 'right']);
        $this->assertIsString($body['shout'] ?? null);
        $this->assertNotSame('', $body['shout']);
    }

    #[Test]
    public function move_with_garbage_input_defaults_to_up(): void
    {
        [$status, $body] = Controller::move('this is not json');

        $this->assertSame(200, $status);
        $this->assertSame('up', $body['move']);

        // The malformed payload should also have produced a warn event.
        $this->assertStringContainsString('"event":"warn"', $this->logs());
    }

    #[Test]
    public function move_emits_log_line_with_strategy_and_latency(): void
    {
        Controller::move($this->fixture());

        $line = trim(explode("\n", $this->logs())[0]);
        $log = json_decode($line, true);
        $this->assertIsArray($log);
        $this->assertSame('move',             $log['event']);
        // No API key available in tests → we should land on MCTS or flood-fill.
        $this->assertContains($log['strategy'], ['mcts', 'flood_fill']);
        $this->assertIsInt($log['total_latency_ms']);
        $this->assertNotEmpty($log['safe_moves']);
        // LLM fields are present in the schema but null on the no-key path.
        $this->assertArrayHasKey('model_used',     $log);
        $this->assertArrayHasKey('llm_latency_ms', $log);
        $this->assertArrayHasKey('fallback_used',  $log);
        $this->assertNull($log['model_used']);
        $this->assertTrue($log['fallback_used']);
    }
}
