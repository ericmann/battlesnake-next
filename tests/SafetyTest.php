<?php

declare(strict_types=1);

namespace BattlesnakeAI\Tests;

use BattlesnakeAI\Safety;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Safety::class)]
final class SafetyTest extends TestCase
{
    /**
     * Helper: build a minimal /move-style payload around a single snake.
     * Body order is head-first, tail-last.
     */
    private function snake(string $id, array $body, int $health = 100): array
    {
        return [
            'id'     => $id,
            'health' => $health,
            'body'   => $body,
            'head'   => $body[0],
            'length' => count($body),
        ];
    }

    private function board(array $snakes, int $w = 11, int $h = 11, array $food = [], array $hazards = []): array
    {
        return [
            'width'   => $w,
            'height'  => $h,
            'snakes'  => $snakes,
            'food'    => $food,
            'hazards' => $hazards,
        ];
    }

    // ---- legalMoves --------------------------------------------------------

    #[Test]
    public function legalMoves_excludes_walls(): void
    {
        // Head at (0,0) = bottom-left corner; only up & right are legal.
        $me = $this->snake('me', [
            ['x' => 0, 'y' => 0],
            ['x' => 1, 'y' => 0],
            ['x' => 2, 'y' => 0],
        ]);
        $board = $this->board([$me]);

        $legal = Safety::legalMoves($me, $board);

        // 'down' and 'left' both walk off the board; 'right' walks into our
        // own body. Only 'up' is legal.
        $this->assertSame(['up'], $legal);
    }

    #[Test]
    public function legalMoves_excludes_own_body(): void
    {
        // Snake curled so 'up' walks into its own neck.
        $me = $this->snake('me', [
            ['x' => 5, 'y' => 5],
            ['x' => 5, 'y' => 6],
            ['x' => 5, 'y' => 7],
        ]);
        $legal = Safety::legalMoves($me, $this->board([$me]));

        $this->assertNotContains('up', $legal);
        // down/left/right are all open.
        $this->assertContains('down', $legal);
        $this->assertContains('left', $legal);
        $this->assertContains('right', $legal);
    }

    #[Test]
    public function legalMoves_excludes_enemy_body(): void
    {
        $me = $this->snake('me', [
            ['x' => 5, 'y' => 5],
            ['x' => 4, 'y' => 5],
        ]);
        $enemy = $this->snake('foe', [
            ['x' => 6, 'y' => 5], // directly to my right
            ['x' => 7, 'y' => 5],
            ['x' => 8, 'y' => 5],
        ]);
        // Enemy head at 6,5 = right of me, equal length (2 vs 3 — actually
        // enemy is longer). So 'right' is also a head-on loss against a
        // longer snake — doubly excluded. Also blocked is moving into the
        // enemy head cell as a body.
        $legal = Safety::legalMoves($me, $this->board([$me, $enemy]));
        $this->assertNotContains('right', $legal);
    }

    #[Test]
    public function legalMoves_avoids_head_on_with_equal_or_larger(): void
    {
        // Two snakes facing each other. Both length 3.
        $me = $this->snake('me', [
            ['x' => 5, 'y' => 5],
            ['x' => 5, 'y' => 4],
            ['x' => 5, 'y' => 3],
        ]);
        $enemy = $this->snake('foe', [
            ['x' => 5, 'y' => 7], // two cells above me
            ['x' => 5, 'y' => 8],
            ['x' => 5, 'y' => 9],
        ]);
        // If I move 'up', my head goes to (5,6) and the enemy's possible move
        // 'down' lands them at (5,6) too — head-on with equal length = avoid.
        $legal = Safety::legalMoves($me, $this->board([$me, $enemy]));
        $this->assertNotContains('up', $legal);
    }

    #[Test]
    public function legalMoves_pursues_smaller_head_to_head(): void
    {
        // I'm length 5; enemy is length 2. If I move up to (5,6), they
        // *might* move down to (5,6) — but they're shorter, so I win.
        // legalMoves should keep 'up' in the candidate list.
        $me = $this->snake('me', [
            ['x' => 5, 'y' => 5],
            ['x' => 5, 'y' => 4],
            ['x' => 5, 'y' => 3],
            ['x' => 4, 'y' => 3],
            ['x' => 3, 'y' => 3],
        ]);
        $enemy = $this->snake('foe', [
            ['x' => 5, 'y' => 7],
            ['x' => 5, 'y' => 8],
        ]);

        $legal = Safety::legalMoves($me, $this->board([$me, $enemy]));
        $this->assertContains('up', $legal);
    }

    #[Test]
    public function legalMoves_treats_just_eaten_tail_as_blocked(): void
    {
        // Enemy has duplicated tail (just ate); their tail does NOT vacate.
        $tailCell = ['x' => 7, 'y' => 5];
        $enemy = $this->snake('foe', [
            ['x' => 9, 'y' => 5],
            ['x' => 8, 'y' => 5],
            $tailCell,
            $tailCell, // duplicated → just ate
        ]);
        // Position me so my only "good" move is into their tail cell.
        $me = $this->snake('me', [
            ['x' => 6, 'y' => 5], // tail of theirs is to my right
            ['x' => 6, 'y' => 4],
        ]);
        $legal = Safety::legalMoves($me, $this->board([$me, $enemy]));
        $this->assertNotContains('right', $legal); // tail won't vacate
    }

    #[Test]
    public function legalMoves_allows_vacating_tail(): void
    {
        // Same setup but tail is NOT duplicated → vacates next turn.
        $enemy = $this->snake('foe', [
            ['x' => 9, 'y' => 5],
            ['x' => 8, 'y' => 5],
            ['x' => 7, 'y' => 5],
        ]);
        $me = $this->snake('me', [
            ['x' => 6, 'y' => 5],
            ['x' => 6, 'y' => 4],
        ]);
        $legal = Safety::legalMoves($me, $this->board([$me, $enemy]));
        $this->assertContains('right', $legal);
    }

    #[Test]
    public function legalMoves_sorts_by_flood_fill_descending(): void
    {
        // Long snake with body running along bottom row pinning me near corner.
        // Going 'up' from (1,1) opens a huge cavity; going 'down' from (1,1)
        // hits the wall at (1,0)? No — (1,0) is open.
        //
        // Build a wall of body to the right: enemy at (3, 0..10). That makes
        // 'right' from (1,1) → (2,1) which still flood-fills the whole left
        // half (~22 cells) but 'down' to (1,0) and then trapped between body
        // and wall (~3 cells reachable). 'right' should outrank 'down'.
        $me = $this->snake('me', [
            ['x' => 1, 'y' => 1],
            ['x' => 0, 'y' => 1],
            ['x' => 0, 'y' => 0],
        ]);
        $wallSegments = [];
        for ($y = 10; $y >= 0; $y--) {
            $wallSegments[] = ['x' => 3, 'y' => $y];
        }
        $enemy = $this->snake('wall', $wallSegments, health: 100);

        $legal = Safety::legalMoves($me, $this->board([$me, $enemy]));

        $this->assertGreaterThanOrEqual(2, count($legal), 'expected multiple legal moves');
        // The flood fill from 'right' (the open left half) should be
        // bigger than from 'down' (the corner pocket).
        $this->assertSame('up', $legal[0], 'most-open-space move should rank first');
    }

    #[Test]
    public function legalMoves_returns_least_bad_when_all_fatal(): void
    {
        // Snake boxed into a corner with no legal escape.
        // (0,0) head; (1,0), (0,1) both occupied by enemy; left/down off board.
        $me = $this->snake('me', [
            ['x' => 0, 'y' => 0],
            ['x' => 0, 'y' => 1], // wait — that puts our body at (0,1) too
        ]);
        // Let me redo: head at (0,0), body to the right along y=0.
        $me = $this->snake('me', [
            ['x' => 0, 'y' => 0],
            ['x' => 1, 'y' => 0],
            ['x' => 2, 'y' => 0],
        ]);
        // Enemy occupies (0,1) blocking 'up'.
        $enemy = $this->snake('foe', [
            ['x' => 0, 'y' => 1],
            ['x' => 0, 'y' => 2],
            ['x' => 0, 'y' => 3],
        ]);
        $legal = Safety::legalMoves($me, $this->board([$me, $enemy]));

        $this->assertNotEmpty($legal, 'must always return at least one move');
        $this->assertContains($legal[0], ['up', 'down', 'left', 'right']);
    }

    // ---- floodFill --------------------------------------------------------

    #[Test]
    public function floodFill_counts_full_board_when_unblocked(): void
    {
        $count = Safety::floodFill(['x' => 0, 'y' => 0], [], 11, 11);
        $this->assertSame(11 * 11, $count);
    }

    #[Test]
    public function floodFill_partitions_by_blocked_cells(): void
    {
        // Build a vertical wall at x=5 splitting an 11×11 board in half.
        $blocked = [];
        for ($y = 0; $y < 11; $y++) {
            $blocked["5,$y"] = true;
        }
        // Left side: x ∈ [0,4] = 5 cols × 11 rows = 55.
        $left = Safety::floodFill(['x' => 0, 'y' => 0], $blocked, 11, 11);
        $this->assertSame(55, $left);
        // Right side: x ∈ [6,10] = 5 cols × 11 rows = 55.
        $right = Safety::floodFill(['x' => 10, 'y' => 0], $blocked, 11, 11);
        $this->assertSame(55, $right);
    }

    #[Test]
    public function floodFill_returns_zero_when_origin_blocked(): void
    {
        $count = Safety::floodFill(['x' => 5, 'y' => 5], ['5,5' => true], 11, 11);
        $this->assertSame(0, $count);
    }

    // ---- mctsMove ---------------------------------------------------------

    #[Test]
    public function mctsMove_returns_only_legal_move_when_one_option(): void
    {
        $me = $this->snake('me', [
            ['x' => 0, 'y' => 0],
            ['x' => 1, 'y' => 0],
            ['x' => 2, 'y' => 0],
        ]);
        $state = ['board' => $this->board([$me]), 'you' => $me];

        $move = Safety::mctsMove($state, ['up'], 50);
        $this->assertSame('up', $move);
    }

    #[Test]
    public function mctsMove_picks_a_safe_move_with_room_to_breathe(): void
    {
        // Board with two options: 'up' opens to the wide board, 'down' walks
        // into a 1-cell pocket and dies next turn. MCTS should pick 'up'.
        // Setup: head at (5,1). Wall of enemy body across y=0 except (5,0)
        // is open but immediately surrounded by enemy body at (4,0) and (6,0).
        $me = $this->snake('me', [
            ['x' => 5, 'y' => 1],
            ['x' => 5, 'y' => 2],
        ]);
        $enemy = $this->snake('foe', [
            ['x' => 0, 'y' => 5], // head (far away, irrelevant)
            ['x' => 0, 'y' => 4],
            ['x' => 0, 'y' => 3],
            ['x' => 0, 'y' => 2],
            ['x' => 0, 'y' => 1],
            ['x' => 0, 'y' => 0],
            ['x' => 1, 'y' => 0],
            ['x' => 2, 'y' => 0],
            ['x' => 3, 'y' => 0],
            ['x' => 4, 'y' => 0], // blocks (4,0)
            ['x' => 6, 'y' => 0], // wait this isn't contiguous
        ]);
        // Body must be contiguous; redo enemy as a U-shape.
        $enemy = $this->snake('foe', [
            ['x' => 4, 'y' => 0],
            ['x' => 4, 'y' => 1],
            ['x' => 4, 'y' => 2],
            ['x' => 4, 'y' => 3],
            ['x' => 5, 'y' => 3],
            ['x' => 6, 'y' => 3],
            ['x' => 6, 'y' => 2],
            ['x' => 6, 'y' => 1],
            ['x' => 6, 'y' => 0],
        ]);
        // Now from (5,1), 'down' lands at (5,0) which is a pocket bounded by
        // enemy body at (4,0) and (6,0) and wall at y=-1 — dead-end.
        // 'up' lands at (5,2) which is bounded by enemy body at (4,2), (6,2)
        // and (5,3) — also a dead end. Hmm, both die.
        // Let me redo to make 'up' clearly safer:
        $enemy = $this->snake('foe', [
            ['x' => 4, 'y' => 0],
            ['x' => 4, 'y' => 1],
            ['x' => 6, 'y' => 1],
            ['x' => 6, 'y' => 0],
        ]);
        // Now from (5,1): 'down' → (5,0) is a 1-cell pocket between body at
        // (4,0)/(6,0) and the wall — dies turn 2.
        // 'left' → (4,1) blocked by enemy body. 'right' → (6,1) blocked by
        // enemy body. 'up' → (5,2) opens onto the rest of the board.
        $state = ['board' => $this->board([$me, $enemy]), 'you' => $me];

        // Pre-filter to legal options (legalMoves will give us the right set).
        $legal = Safety::legalMoves($me, $state['board']);
        // Expect 'up' and 'down' to be legal next-step options (left/right
        // hit body), and MCTS should pick 'up' because 'down' is a dead-end.
        $this->assertContains('up', $legal);

        $pick = Safety::mctsMove($state, $legal, 80);
        $this->assertContains($pick, $legal);
        $this->assertSame('up', $pick, 'MCTS should prefer the open direction over the 1-cell pocket');
    }

    #[Test]
    public function mctsMove_returns_quickly_within_budget(): void
    {
        $me = $this->snake('me', [
            ['x' => 5, 'y' => 5],
            ['x' => 5, 'y' => 4],
        ]);
        $state = ['board' => $this->board([$me]), 'you' => $me];

        $start = hrtime(true);
        Safety::mctsMove($state, ['up', 'down', 'left', 'right'], 50);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        // Allow generous slack for CI noise but well under any /move budget.
        $this->assertLessThan(120, $elapsedMs, "MCTS overshot budget: {$elapsedMs}ms");
    }
}
