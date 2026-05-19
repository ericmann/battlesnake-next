<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * Renders a Battlesnake game state into a compact ASCII string the LLM
 * can reason about directly.
 *
 * Coordinate system: Battlesnake uses (0,0) = bottom-left, +y = up. We
 * keep that convention in the metadata block, but flip the y-axis when
 * drawing the grid so row 0 in the printed output is the *top* of the
 * board (highest y). That matches how a human would sketch the board on
 * paper and dramatically improves the LLM's spatial reasoning — the model
 * sees "up" in the description aligning with "up" on the page.
 *
 * Legend (kept identical to Prompts::SYSTEM):
 *   H   own head
 *   B   own body
 *   T   own tail (vacates next turn unless we just ate)
 *   e+  enemy head — shorter than us (head-on kill is in our favor)
 *   e=  enemy head — equal length (head-on is mutual death; avoid)
 *   e-  enemy head — longer than us (head-on is fatal; avoid)
 *   s   enemy body
 *   t   enemy tail (vacates next turn unless they just ate)
 *   F   food
 *   X   hazard (royale sauce)
 *   .   empty
 *
 * Cells are joined by single spaces so each column is two characters wide
 * — needed to fit the e+/e=/e- enemy-head annotations without the grid
 * shifting columns mid-row.
 */
final class Board
{
    /**
     * @param array $state     The full Battlesnake /move payload.
     * @param array $safeMoves Optional pre-computed legal-moves data. Two
     *                         accepted shapes:
     *                           - list<string>        — direction names only
     *                           - array<string,int>   — direction → flood-fill
     *                                                   space (sorted desc)
     *                         The scored form gets the magnitudes spliced into
     *                         the prompt so the LLM can see how cramped each
     *                         option is. The plain-list form is kept for tests
     *                         and ad-hoc callers.
     */
    public static function format(array $state, array $safeMoves = []): string
    {
        $board  = $state['board'];
        $me     = $state['you'];
        $turn   = (int) ($state['turn'] ?? 0);
        $width  = (int) $board['width'];
        $height = (int) $board['height'];

        $grid = self::drawGrid($board, $me, $width, $height);
        $meta = self::drawMetadata($state, $turn, $safeMoves);

        return $grid . "\n\n" . $meta;
    }

    // ---------------------------------------------------------------------

    private static function drawGrid(array $board, array $me, int $width, int $height): string
    {
        // Build a width × height grid pre-filled with empties. Index by [y][x].
        $cells = [];
        for ($y = 0; $y < $height; $y++) {
            $cells[$y] = array_fill(0, $width, '.');
        }

        // Hazards first — anything else painted on top wins. Using "X" matches
        // the legend; the LLM treats hazard cells as "passable but draining".
        foreach ($board['hazards'] ?? [] as $hz) {
            $hx = (int) $hz['x'];
            $hy = (int) $hz['y'];
            if (self::inBounds($hx, $hy, $width, $height)) {
                $cells[$hy][$hx] = 'X';
            }
        }

        // Food on top of empties/hazards (food in hazard zone is rare but
        // happens; food display wins because the LLM cares more about it).
        foreach ($board['food'] ?? [] as $food) {
            $fx = (int) $food['x'];
            $fy = (int) $food['y'];
            if (self::inBounds($fx, $fy, $width, $height)) {
                $cells[$fy][$fx] = 'F';
            }
        }

        $myId  = $me['id'] ?? null;
        $myLen = (int) $me['length'];

        foreach ($board['snakes'] as $snake) {
            $isMe   = ($snake['id'] ?? null) === $myId;
            $body   = $snake['body'];
            $segs   = count($body);
            if ($segs === 0) {
                continue;
            }
            $justAte = $segs >= 2 && $body[$segs - 1] === $body[$segs - 2];

            // Paint body segments first, then overwrite head and tail so
            // their distinct glyphs survive even on length-2 snakes where
            // the head and the second segment can collide visually.
            for ($i = 1; $i < $segs - 1; $i++) {
                $seg = $body[$i];
                $sx  = (int) $seg['x'];
                $sy  = (int) $seg['y'];
                if (self::inBounds($sx, $sy, $width, $height)) {
                    $cells[$sy][$sx] = $isMe ? 'B' : 's';
                }
            }
            // Tail glyph — capital 'T' for us, lowercase 't' for enemies.
            // If they just ate, the "tail" is actually the duplicated body
            // segment; both indices point to the same cell, so painting
            // either gives the right answer.
            $tail = $body[$segs - 1];
            $tx   = (int) $tail['x'];
            $ty   = (int) $tail['y'];
            if (self::inBounds($tx, $ty, $width, $height)) {
                if ($segs === 1) {
                    // Length-1 snake (just spawned): treat the lone segment as
                    // the head; we'll repaint below.
                } else {
                    $cells[$ty][$tx] = $isMe
                        ? ($justAte ? 'B' : 'T')
                        : ($justAte ? 's' : 't');
                }
            }
            // Head glyph — annotated for enemies with relative length.
            $head = $body[0];
            $hx = (int) $head['x'];
            $hy = (int) $head['y'];
            if (self::inBounds($hx, $hy, $width, $height)) {
                if ($isMe) {
                    $cells[$hy][$hx] = 'H';
                } else {
                    $eLen = (int) $snake['length'];
                    $marker = match (true) {
                        $eLen <  $myLen => 'e+', // killable
                        $eLen === $myLen => 'e=', // mutual-death risk
                        default          => 'e-', // they win head-on
                    };
                    $cells[$hy][$hx] = $marker;
                }
            }
        }

        // Render rows top-down (highest y first) so "up" in coordinates
        // aligns with "up" on the page. Pad single-char cells to width 2
        // so the e+/e=/e- two-char enemy heads don't shift columns.
        $lines = [];
        for ($y = $height - 1; $y >= 0; $y--) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $cell = $cells[$y][$x];
                $row[] = strlen($cell) === 1 ? $cell . ' ' : $cell;
            }
            $lines[] = rtrim(sprintf('%2d|', $y) . ' ' . implode(' ', $row));
        }

        // X-axis label row at the bottom for orientation.
        $xLabels = [];
        for ($x = 0; $x < $width; $x++) {
            $xLabels[] = sprintf('%-2d', $x);
        }
        $lines[] = '   +' . str_repeat('---', $width);
        $lines[] = '     ' . implode(' ', $xLabels);

        return implode("\n", $lines);
    }

    private static function drawMetadata(array $state, int $turn, array $safeMoves): string
    {
        $board = $state['board'];
        $me    = $state['you'];
        $myId  = $me['id'] ?? null;
        $head  = $me['head'];
        $tail  = $me['body'][count($me['body']) - 1] ?? $head;

        $facing = self::facing($me['body']);

        $lines = [];
        $lines[] = "Turn: $turn   Board: {$board['width']}x{$board['height']}";
        $lines[] = sprintf(
            'You: length=%d  health=%d  head=(%d,%d)  tail=(%d,%d)  facing=%s',
            (int) $me['length'],
            (int) $me['health'],
            (int) $head['x'],
            (int) $head['y'],
            (int) $tail['x'],
            (int) $tail['y'],
            $facing,
        );

        $enemyLines = [];
        foreach ($board['snakes'] as $snake) {
            if (($snake['id'] ?? null) === $myId) {
                continue;
            }
            $eHead = $snake['head'];
            $name  = $snake['name'] ?? ($snake['id'] ?? '?');
            $enemyLines[] = sprintf(
                '  - %s: length=%d  health=%d  head=(%d,%d)',
                $name,
                (int) $snake['length'],
                (int) $snake['health'],
                (int) $eHead['x'],
                (int) $eHead['y'],
            );
        }
        if ($enemyLines !== []) {
            $lines[] = 'Enemies:';
            foreach ($enemyLines as $line) {
                $lines[] = $line;
            }
        } else {
            $lines[] = 'Enemies: (none — solo game)';
        }

        $foodCount    = count($board['food'] ?? []);
        $hazardCount  = count($board['hazards'] ?? []);
        $lines[] = "Food on board: {$foodCount}   Hazards: {$hazardCount}";

        if ($safeMoves !== []) {
            if (array_is_list($safeMoves)) {
                $lines[] = 'Legal moves (pre-filtered, sorted by open space): '
                    . implode(', ', $safeMoves);
            } else {
                // Scored form: "down(33), left(33), right(1)" — the magnitudes
                // matter, not just the order. A 1-cell pocket and a 30-cell
                // room both come out as "legal", but reading the integers
                // makes the difference impossible to ignore.
                $parts = [];
                foreach ($safeMoves as $move => $space) {
                    $parts[] = "{$move}({$space})";
                }
                $lines[] = 'Legal moves (sorted by reachable-cell count in parens): '
                    . implode(', ', $parts);
            }
        }

        return implode("\n", $lines);
    }

    /** Direction the head is currently moving, derived from head vs neck. */
    private static function facing(array $body): string
    {
        if (count($body) < 2) {
            return 'unknown';
        }
        $head = $body[0];
        $neck = $body[1];
        return match (true) {
            $head['x'] === $neck['x'] && $head['y']  >  $neck['y'] => 'up',
            $head['x'] === $neck['x'] && $head['y']  <  $neck['y'] => 'down',
            $head['y'] === $neck['y'] && $head['x']  >  $neck['x'] => 'right',
            $head['y'] === $neck['y'] && $head['x']  <  $neck['x'] => 'left',
            default                                                => 'unknown',
        };
    }

    private static function inBounds(int $x, int $y, int $w, int $h): bool
    {
        return $x >= 0 && $x < $w && $y >= 0 && $y < $h;
    }
}
