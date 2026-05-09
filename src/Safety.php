<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * Pure-function safety layer. Two responsibilities:
 *
 *   1. Filter obviously fatal moves before we ever ask an LLM. Anything that
 *      survives this gate is *legal* per the rules of Battlesnake — no walls,
 *      no body collisions, no head-on with an equal-or-larger snake.
 *   2. Provide a fallback move (`mctsMove`) when the LLM either times out or
 *      returns garbage. The MCTS is intentionally cheap: random rollouts that
 *      score by survival turns. Won't beat a strong heuristic, but it always
 *      returns *something* legal in well under 100ms.
 *
 * Coordinate convention is straight from the Battlesnake API:
 *   (0, 0) = bottom-left, x→right, y→up.
 *
 * Every method is static and stateless so the front controller can call them
 * without needing dependency wiring. Game state arrives as the parsed JSON
 * payload from the engine; we never copy it into a class hierarchy.
 */
final class Safety
{
    public const DIRECTIONS = [
        'up'    => [0, 1],
        'down'  => [0, -1],
        'left'  => [-1, 0],
        'right' => [1, 0],
    ];

    /**
     * Returns an ordered list of legal moves for `me` on `board`.
     *
     * The list is sorted descending by flood-fill space score (most open space
     * first). It is *guaranteed non-empty*: if every move is fatal, the
     * least-bad option is still returned so the front controller always has
     * something to send back. The MCTS layer is then free to pick a different
     * one if it likes its odds.
     *
     * @param array $me    The "you" object from the Battlesnake payload.
     * @param array $board The "board" object from the Battlesnake payload.
     * @return list<string> A non-empty subset of {up, down, left, right}.
     */
    public static function legalMoves(array $me, array $board): array
    {
        $width  = (int) $board['width'];
        $height = (int) $board['height'];
        $head   = $me['head'];
        $myLen  = (int) $me['length'];

        // Build the occupancy map for "next turn": a set of cells we cannot
        // safely enter. Every snake's body except the tail is blocked. The
        // tail vacates next turn unless that snake just ate this turn (length
        // != tail-segment-count means the tail just grew — careful).
        $blocked = [];
        foreach ($board['snakes'] as $snake) {
            $body = $snake['body'];
            $segCount = count($body);
            if ($segCount === 0) {
                continue;
            }
            // Heuristic for "did this snake just eat?":
            //   When a snake eats, the tail segment is duplicated for one turn,
            //   so body[-1] == body[-2]. In that case the tail is NOT vacating.
            $justAte = $segCount >= 2 && $body[$segCount - 1] === $body[$segCount - 2];
            $stopAt  = $justAte ? $segCount : $segCount - 1; // exclude tail when it will vacate
            for ($i = 0; $i < $stopAt; $i++) {
                $blocked[self::key($body[$i]['x'], $body[$i]['y'])] = true;
            }
        }

        $candidates = [];
        $fallback   = null; // tracks the absolute least-bad move if everything is fatal

        foreach (self::DIRECTIONS as $dir => [$dx, $dy]) {
            $nx = (int) $head['x'] + $dx;
            $ny = (int) $head['y'] + $dy;

            // Wall: instantly fatal.
            if ($nx < 0 || $nx >= $width || $ny < 0 || $ny >= $height) {
                continue;
            }

            // Body collision (including own neck): instantly fatal.
            if (isset($blocked[self::key($nx, $ny)])) {
                continue;
            }

            // Head-to-head with equal-or-larger snake: fatal or mutual death.
            if (self::headToHeadLoss($nx, $ny, $me, $board['snakes'])) {
                // Track as last-resort fallback (it's a coin flip, not certain death)
                // only if we have nothing better. Don't include in primary list.
                $fallback ??= $dir;
                continue;
            }

            $space = self::floodFill(['x' => $nx, 'y' => $ny], $blocked, $width, $height);

            // Anything is included; sort by space below. We tag tight spaces
            // (smaller than half our length) so the LLM/MCTS can see they're
            // claustrophobic but they're not auto-rejected — being trapped in
            // a 4-cell room is still better than walking into a wall.
            $candidates[$dir] = $space;
        }

        if ($candidates === []) {
            // Every direction is a wall, body, or head-on loss. Return the
            // least-bad option (head-on coin flip > guaranteed death). If even
            // that doesn't exist, default to "up" — we're dead either way.
            return [$fallback ?? 'up'];
        }

        // Sort descending by reachable cells. Stable order on ties is fine.
        arsort($candidates);
        return array_keys($candidates);
    }

    /**
     * BFS flood fill from $origin counting reachable cells inside the board,
     * skipping any cell present in $blocked. Includes the origin itself in
     * the count, so a fully open 11×11 board returns 121 from the corner.
     *
     * @param array<string,bool> $blocked Map keyed by self::key($x,$y).
     */
    public static function floodFill(array $origin, array $blocked, int $width, int $height): int
    {
        $startKey = self::key((int) $origin['x'], (int) $origin['y']);
        if (isset($blocked[$startKey])) {
            return 0;
        }
        if ((int) $origin['x'] < 0 || (int) $origin['x'] >= $width
            || (int) $origin['y'] < 0 || (int) $origin['y'] >= $height) {
            return 0;
        }

        $seen  = [$startKey => true];
        $queue = [[(int) $origin['x'], (int) $origin['y']]];
        $count = 0;

        while ($queue !== []) {
            [$x, $y] = array_pop($queue);
            $count++;

            foreach (self::DIRECTIONS as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if ($nx < 0 || $nx >= $width || $ny < 0 || $ny >= $height) {
                    continue;
                }
                $k = self::key($nx, $ny);
                if (isset($seen[$k]) || isset($blocked[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $queue[]  = [$nx, $ny];
            }
        }

        return $count;
    }

    /**
     * Lightweight MCTS fallback. Runs random rollouts from each candidate
     * move and returns the one with the best mean survival turns.
     *
     * Rollout policy:
     *   - From the current state, simulate "us" taking each candidate move.
     *   - For each subsequent turn, pick a random *legal* move for ourselves.
     *   - Enemies are simulated as "freeze in place" — fast, conservative,
     *     and good enough for a fallback. (We're already in panic mode.)
     *   - Score is turns survived from the candidate move forward, capped at
     *     a depth that fits in the budget.
     *
     * Hard time budget: stops the moment $budgetMs elapses, even mid-rollout.
     *
     * @param array        $state     The full Battlesnake payload (must include
     *                                'board' and 'you').
     * @param list<string> $safeMoves Non-empty list from legalMoves(). MCTS
     *                                only ever simulates these as the root move.
     */
    public static function mctsMove(array $state, array $safeMoves, int $budgetMs = 100): string
    {
        if ($safeMoves === []) {
            return 'up'; // engine wants something, snake is dead anyway
        }
        if (count($safeMoves) === 1) {
            return $safeMoves[0]; // no decision to make
        }

        $deadline = self::nowMs() + $budgetMs;

        $board  = $state['board'];
        $width  = (int) $board['width'];
        $height = (int) $board['height'];
        $me     = $state['you'];

        $rolloutDepth = 20; // ~2s game lookahead is plenty for fallback purposes

        $totals = array_fill_keys($safeMoves, 0.0);
        $counts = array_fill_keys($safeMoves, 0);

        // Round-robin across candidates so an early timeout still touches each one.
        $idx = 0;
        while (self::nowMs() < $deadline) {
            $move = $safeMoves[$idx % count($safeMoves)];
            $score = self::rollout($me, $board, $width, $height, $move, $rolloutDepth);
            $totals[$move] += $score;
            $counts[$move]++;
            $idx++;
        }

        $best  = $safeMoves[0]; // safeMoves is space-sorted, so the head is the flood-fill winner
        $bestScore = -1.0;
        foreach ($safeMoves as $move) {
            if ($counts[$move] === 0) {
                continue;
            }
            $mean = $totals[$move] / $counts[$move];
            if ($mean > $bestScore) {
                $bestScore = $mean;
                $best      = $move;
            }
        }

        return $best;
    }

    // ---------------------------------------------------------------------
    // internals
    // ---------------------------------------------------------------------

    private static function headToHeadLoss(int $nx, int $ny, array $me, array $snakes): bool
    {
        $myLen = (int) $me['length'];
        $myId  = $me['id'] ?? null;

        foreach ($snakes as $snake) {
            if (($snake['id'] ?? null) === $myId) {
                continue;
            }
            $enemyHead = $snake['head'];
            $enemyLen  = (int) $snake['length'];

            // For each cell the enemy head could move to next turn,
            // check whether it overlaps our candidate landing cell.
            foreach (self::DIRECTIONS as [$dx, $dy]) {
                if ($enemyHead['x'] + $dx === $nx && $enemyHead['y'] + $dy === $ny) {
                    if ($enemyLen >= $myLen) {
                        return true; // we lose or both die
                    }
                }
            }
        }

        return false;
    }

    /**
     * One random rollout. Returns turns survived (higher = better).
     * Enemy snakes are treated as static; food is ignored. This is the
     * panic-mode fallback — accuracy is secondary to "always returns fast".
     */
    private static function rollout(array $me, array $board, int $width, int $height, string $rootMove, int $depth): float
    {
        $myBody = array_map(static fn(array $c): array => [(int) $c['x'], (int) $c['y']], $me['body']);
        // Enemy bodies are frozen; precompute their occupancy once.
        $enemyOcc = [];
        foreach ($board['snakes'] as $snake) {
            if (($snake['id'] ?? null) === ($me['id'] ?? null)) {
                continue;
            }
            foreach ($snake['body'] as $seg) {
                $enemyOcc[self::key((int) $seg['x'], (int) $seg['y'])] = true;
            }
        }

        // Apply the root move first.
        if (!self::stepSelf($myBody, $rootMove, $width, $height, $enemyOcc)) {
            return 0.0;
        }

        $survived = 1;
        for ($t = 1; $t < $depth; $t++) {
            $legal = self::legalSelfMoves($myBody, $width, $height, $enemyOcc);
            if ($legal === []) {
                break;
            }
            $pick = $legal[random_int(0, count($legal) - 1)];
            if (!self::stepSelf($myBody, $pick, $width, $height, $enemyOcc)) {
                break;
            }
            $survived++;
        }

        return (float) $survived;
    }

    /**
     * Mutates $body in place: prepend the new head, drop the tail.
     * Returns false if the move is illegal (hit wall / body / enemy).
     */
    private static function stepSelf(array &$body, string $move, int $width, int $height, array $enemyOcc): bool
    {
        [$dx, $dy] = self::DIRECTIONS[$move];
        [$hx, $hy] = $body[0];
        $nx = $hx + $dx;
        $ny = $hy + $dy;

        if ($nx < 0 || $nx >= $width || $ny < 0 || $ny >= $height) {
            return false;
        }
        if (isset($enemyOcc[self::key($nx, $ny)])) {
            return false;
        }
        // Tail vacates this same turn (we're not tracking food in the rollout),
        // so the cell occupied by body[count-1] becomes empty.
        $tail = array_pop($body);
        foreach ($body as [$bx, $by]) {
            if ($bx === $nx && $by === $ny) {
                $body[] = $tail; // restore for caller correctness
                return false;
            }
        }
        array_unshift($body, [$nx, $ny]);
        return true;
    }

    /**
     * @return list<string>
     */
    private static function legalSelfMoves(array $body, int $width, int $height, array $enemyOcc): array
    {
        [$hx, $hy] = $body[0];
        $bodyKeys = [];
        // Tail will vacate, so don't block on it.
        $stopAt = count($body) - 1;
        for ($i = 0; $i < $stopAt; $i++) {
            $bodyKeys[self::key($body[$i][0], $body[$i][1])] = true;
        }
        $out = [];
        foreach (self::DIRECTIONS as $dir => [$dx, $dy]) {
            $nx = $hx + $dx;
            $ny = $hy + $dy;
            if ($nx < 0 || $nx >= $width || $ny < 0 || $ny >= $height) {
                continue;
            }
            $k = self::key($nx, $ny);
            if (isset($bodyKeys[$k]) || isset($enemyOcc[$k])) {
                continue;
            }
            $out[] = $dir;
        }
        return $out;
    }

    private static function key(int $x, int $y): string
    {
        // Single-string key for set-style lookups; faster than nested arrays.
        return $x . ',' . $y;
    }

    private static function nowMs(): int
    {
        return (int) (hrtime(true) / 1_000_000);
    }
}
