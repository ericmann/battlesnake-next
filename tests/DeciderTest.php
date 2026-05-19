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
    public function decision_rejects_llm_pick_that_is_a_dead_end_pocket(): void
    {
        // Mirrors the real-game failure mode: LLM picks 'right' (1-cell pocket)
        // even though 'left' has 30+ cells of room. Sanity gate must trip and
        // fall back to MCTS / flood-fill.
        $state     = $this->state();
        $safeMoves = ['left', 'up', 'right'];      // sorted desc by space
        $space     = ['left' => 30, 'up' => 25, 'right' => 1];
        $llm       = new FakeLlmDriver(
            result: new RaceResult('right', 'maximize open space', 'fake', 'secondary', 50),
            arrivesOnStep: 1,
        );

        $decision = (new Decider(
            llm:            $llm,
            mcts:           new IncrementalMcts($state, $safeMoves),
            safeMoves:      $safeMoves,
            decisionMs:     50,
            sleepMicros:    100,
            safeMovesSpace: $space,
        ))->decide();

        $this->assertNotSame('right',  $decision->move,     'right is the 1-cell trap; gate must veto it');
        $this->assertNotSame('llm',    $decision->strategy, 'gate trip must demote strategy to MCTS or flood-fill');
        $this->assertContains($decision->strategy, ['mcts', 'flood_fill']);
        $this->assertContains($decision->move, $safeMoves);
    }

    #[Test]
    public function decision_accepts_llm_pick_within_sanity_gate_ratio(): void
    {
        // LLM picks the second-best option (within 4× of the winner) — gate
        // should leave it alone. We don't want the gate to muzzle every
        // tactical deviation from the flood-fill winner.
        $state     = $this->state();
        $safeMoves = ['left', 'up', 'right'];
        $space     = ['left' => 30, 'up' => 12, 'right' => 1];
        $llm       = new FakeLlmDriver(
            result: new RaceResult('up', 'cuts off enemy line', 'fake', 'primary', 50),
            arrivesOnStep: 1,
        );

        $decision = (new Decider(
            llm:            $llm,
            mcts:           new IncrementalMcts($state, $safeMoves),
            safeMoves:      $safeMoves,
            decisionMs:     50,
            sleepMicros:    100,
            safeMovesSpace: $space,
        ))->decide();

        $this->assertSame('llm', $decision->strategy);
        $this->assertSame('up',  $decision->move);
    }

    #[Test]
    public function decision_rejects_llm_pick_that_mcts_strongly_dislikes(): void
    {
        // Game 86b92eaf turn 52: length-4 self at (2,10) walks 'right'
        // toward a length-8 enemy and dies two turns later in a forced
        // head-on. The area-gate ratio between the two legal moves is
        // < 4× so it passes that gate, but MCTS rollouts give 'down' a
        // ~3.6 turns higher mean than 'right'. The MCTS gate must trip.
        $me = [
            'id'     => 'me', 'name' => 'me',
            'health' => 71, 'length' => 4,
            'head'   => ['x' => 2, 'y' => 10],
            'body'   => [
                ['x' => 2, 'y' => 10], ['x' => 1, 'y' => 10],
                ['x' => 1, 'y' => 9],  ['x' => 0, 'y' => 9],
            ],
        ];
        $foe = [
            'id'     => 'foe', 'name' => 'foe',
            'health' => 96, 'length' => 8,
            'head'   => ['x' => 5, 'y' => 9],
            'body'   => [
                ['x' => 5, 'y' => 9], ['x' => 6, 'y' => 9], ['x' => 6, 'y' => 8],
                ['x' => 7, 'y' => 8], ['x' => 8, 'y' => 8], ['x' => 8, 'y' => 7],
                ['x' => 8, 'y' => 6], ['x' => 8, 'y' => 5],
            ],
        ];
        $state = [
            'turn'  => 52,
            'board' => [
                'width' => 11, 'height' => 11,
                'food' => [], 'hazards' => [],
                'snakes' => [$me, $foe],
            ],
            'you' => $me,
        ];

        $safeMoves = ['down', 'right'];
        $space     = ['down' => 41, 'right' => 42]; // < 4× ratio — area gate passes

        $mcts = new IncrementalMcts($state, $safeMoves, depth: 25);
        mt_srand(42);
        for ($i = 0; $i < 300; $i++) {
            $mcts->runOne();
        }
        // Sanity-check the MCTS state before we run the Decider — the test
        // only proves the gate if MCTS actually disagrees by > DELTA.
        $this->assertSame('down', $mcts->best(), 'precondition: MCTS prefers down');

        $llm = new FakeLlmDriver(
            result: new RaceResult('right', 'advance on enemy', 'fake', 'secondary', 50),
            arrivesOnStep: 1,
        );
        $decision = (new Decider(
            llm:            $llm,
            mcts:           $mcts,
            safeMoves:      $safeMoves,
            decisionMs:     5,
            sleepMicros:    100,
            safeMovesSpace: $space,
        ))->decide();

        $this->assertNotSame('right', $decision->move,
            'MCTS rollouts saw right as a trap; gate must override the LLM');
        $this->assertSame('mcts', $decision->strategy,
            'gate trip should fall through to MCTS, which prefers down');
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
