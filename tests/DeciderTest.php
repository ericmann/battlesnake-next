<?php

declare(strict_types=1);

namespace BattlesnakeAI\Tests;

use BattlesnakeAI\Decider;
use BattlesnakeAI\IncrementalMcts;
use BattlesnakeAI\NullLlmDriver;
use BattlesnakeAI\RaceResult;
use BattlesnakeAI\Tests\Support\FakeLlmDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Decider::class)]
final class DeciderTest extends TestCase
{
    private function state(): array
    {
        $me = [
            'id'     => 'me',
            'name'   => 'me',
            'health' => 80,
            'length' => 3,
            'head'   => ['x' => 5, 'y' => 5],
            'body'   => [
                ['x' => 5, 'y' => 5],
                ['x' => 5, 'y' => 4],
                ['x' => 5, 'y' => 3],
            ],
        ];
        return [
            'turn'  => 0,
            'board' => [
                'width'   => 11,
                'height'  => 11,
                'food'    => [],
                'hazards' => [],
                'snakes'  => [$me],
            ],
            'you' => $me,
        ];
    }

    #[Test]
    public function decision_picks_llm_when_driver_returns_a_result(): void
    {
        $state     = $this->state();
        $safeMoves = ['up', 'left', 'right'];
        $llm       = new FakeLlmDriver(
            result: new RaceResult('left', 'cuts off enemy', 'fake-model', 'primary', 50),
            arrivesOnStep: 2,
        );
        $decision = (new Decider(
            llm:        $llm,
            mcts:       new IncrementalMcts($state, $safeMoves),
            safeMoves:  $safeMoves,
            decisionMs: 60,
            sleepMicros: 100,
        ))->decide();

        $this->assertSame('llm',          $decision->strategy);
        $this->assertSame('left',         $decision->move);
        $this->assertSame('fake-model',   $decision->model);
        $this->assertSame('primary',      $decision->modelLabel);
        $this->assertSame(50,             $decision->llmLatencyMs);
        $this->assertTrue($llm->wasCancelled(), 'driver must be cancelled at deadline');
    }

    #[Test]
    public function decision_falls_back_to_mcts_when_llm_returns_nothing(): void
    {
        $state     = $this->state();
        $safeMoves = ['up', 'left', 'right'];
        $decision  = (new Decider(
            llm:        new NullLlmDriver(),
            mcts:       new IncrementalMcts($state, $safeMoves),
            safeMoves:  $safeMoves,
            decisionMs: 60,
            sleepMicros: 100,
        ))->decide();

        $this->assertSame('mcts',     $decision->strategy);
        $this->assertContains($decision->move, $safeMoves);
        $this->assertGreaterThan(0,   $decision->mctsRollouts);
        $this->assertNull($decision->model);
    }

    #[Test]
    public function decision_falls_back_to_flood_fill_when_only_one_safe_move(): void
    {
        // Snake jammed in a corner — single legal move.
        $me = [
            'id'     => 'me',
            'name'   => 'me',
            'health' => 80,
            'length' => 3,
            'head'   => ['x' => 0, 'y' => 0],
            'body'   => [
                ['x' => 0, 'y' => 0],
                ['x' => 1, 'y' => 0],
                ['x' => 2, 'y' => 0],
            ],
        ];
        $state = [
            'turn'  => 0,
            'board' => [
                'width' => 11, 'height' => 11,
                'food' => [], 'hazards' => [],
                'snakes' => [$me],
            ],
            'you' => $me,
        ];
        $safeMoves = ['up'];

        $decision = (new Decider(
            llm:        new NullLlmDriver(),
            mcts:       new IncrementalMcts($state, $safeMoves),
            safeMoves:  $safeMoves,
            decisionMs: 30,
            sleepMicros: 100,
        ))->decide();

        // Single-option path: skip MCTS rollouts; result is the flood-fill winner.
        $this->assertSame('flood_fill', $decision->strategy);
        $this->assertSame('up',         $decision->move);
        $this->assertSame(0,            $decision->mctsRollouts);
    }

    #[Test]
    public function decision_respects_deadline_within_reasonable_slack(): void
    {
        $state     = $this->state();
        $safeMoves = ['up', 'left', 'right'];
        $start = hrtime(true);

        (new Decider(
            llm:        new NullLlmDriver(),
            mcts:       new IncrementalMcts($state, $safeMoves),
            safeMoves:  $safeMoves,
            decisionMs: 50,
            sleepMicros: 1000,
        ))->decide();

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $this->assertLessThan(120, $elapsedMs, "decider overshot deadline: {$elapsedMs}ms");
    }
}
