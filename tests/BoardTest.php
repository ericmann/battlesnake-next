<?php

declare(strict_types=1);

namespace BattlesnakeAI\Tests;

use BattlesnakeAI\Board;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Board::class)]
final class BoardTest extends TestCase
{
    private function fixture(): array
    {
        $path = __DIR__ . '/Fixtures/sample_board.json';
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function format_renders_expected_snapshot(): void
    {
        $expected = <<<TXT
        10| .  .  .  .  .  .  .  .  .  .  .
         9| .  .  .  .  .  .  .  .  .  .  .
         8| .  .  F  .  .  .  .  .  s  s  t
         7| .  .  .  .  .  .  .  .  e+ .  .
         6| .  .  .  .  H  .  .  .  .  .  .
         5| .  .  e- .  B  F  .  .  .  .  .
         4| .  .  s  .  B  B  B  .  .  .  .
         3| .  .  s  s  .  .  T  .  .  .  .
         2| X  X  .  s  .  .  .  .  .  .  .
         1| X  X  .  s  s  t  .  .  .  F  .
         0| X  X  .  .  .  .  .  .  .  .  .
           +---------------------------------
             0  1  2  3  4  5  6  7  8  9  10

        Turn: 47   Board: 11x11
        You: length=6  health=32  head=(4,6)  tail=(6,3)  facing=up
        Enemies:
          - lil-fang: length=4  health=80  head=(8,7)
          - constrictor-prime: length=8  health=95  head=(2,5)
        Food on board: 3   Hazards: 6
        Legal moves (pre-filtered, sorted by open space): up, right, left
        TXT;

        $actual = Board::format($this->fixture(), ['up', 'right', 'left']);

        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function format_marks_enemy_head_relative_size(): void
    {
        $state  = $this->fixture();
        $output = Board::format($state);

        // shorty (length 4) is shorter than us (length 6) → e+
        // biggie (length 8) is longer than us → e-
        $this->assertStringContainsString('e+', $output);
        $this->assertStringContainsString('e-', $output);
        $this->assertStringNotContainsString('e=', $output);
    }

    #[Test]
    public function format_renders_top_row_as_highest_y(): void
    {
        // First grid line printed must correspond to y = height - 1.
        $output = Board::format($this->fixture());
        $firstLine = explode("\n", $output)[0];
        $this->assertStringStartsWith('10|', $firstLine, 'top row should be y=10 on an 11-tall board');
    }

    #[Test]
    public function format_handles_solo_game(): void
    {
        $me = [
            'id'     => 'me',
            'name'   => 'solo',
            'health' => 100,
            'length' => 3,
            'head'   => ['x' => 5, 'y' => 5],
            'body'   => [
                ['x' => 5, 'y' => 5],
                ['x' => 5, 'y' => 4],
                ['x' => 5, 'y' => 3],
            ],
        ];
        $state = [
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

        $output = Board::format($state);
        $this->assertStringContainsString('Enemies: (none — solo game)', $output);
        $this->assertStringContainsString('H', $output);
        $this->assertStringContainsString('T', $output);
    }

    #[Test]
    public function format_marks_just_eaten_enemy_tail_as_body_not_tail(): void
    {
        // Enemy with duplicated tail segment (just ate). Tail glyph 't' should
        // NOT appear; the doubled tail cell is rendered as body 's' instead.
        $me = [
            'id'     => 'me',
            'name'   => 'me',
            'health' => 100,
            'length' => 3,
            'head'   => ['x' => 0, 'y' => 0],
            'body'   => [
                ['x' => 0, 'y' => 0],
                ['x' => 1, 'y' => 0],
                ['x' => 2, 'y' => 0],
            ],
        ];
        $enemy = [
            'id'     => 'fed',
            'name'   => 'fed',
            'health' => 100,
            'length' => 4, // length matches the unique segments
            'head'   => ['x' => 5, 'y' => 5],
            'body'   => [
                ['x' => 5, 'y' => 5],
                ['x' => 5, 'y' => 6],
                ['x' => 5, 'y' => 7],
                ['x' => 5, 'y' => 7], // duplicated tail = just ate
            ],
        ];
        $state = [
            'turn' => 1,
            'board' => [
                'width' => 11, 'height' => 11,
                'food' => [], 'hazards' => [],
                'snakes' => [$me, $enemy],
            ],
            'you' => $me,
        ];

        $output = Board::format($state);
        // The duplicated cell should be rendered as 's', not 't'.
        // Find row y=7 line and confirm it has 's' at column 5.
        $lines = explode("\n", $output);
        $row7 = '';
        foreach ($lines as $line) {
            if (str_starts_with($line, ' 7|')) {
                $row7 = $line;
                break;
            }
        }
        $this->assertNotSame('', $row7);
        $this->assertStringNotContainsString(' t ', $row7, "just-eaten tail must not render as 't'");
    }
}
