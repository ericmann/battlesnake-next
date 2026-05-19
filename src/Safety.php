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
     * Thin wrapper around legalMovesWithSpace(): callers that only need the
     * direction names get a list; callers that need the magnitudes (Decider's
     * sanity gate, Board's prompt renderer) can call the scored variant.
     *
     * @param array $me    The "you" object from the Battlesnake payload.
     * @param array $board The "board" object from the Battlesnake payload.
     * @return list<string> A non-empty subset of {up, down, left, right}.
     */
    public static function legalMoves(array $me, array $board): array
    {
        return array_keys(self::legalMovesWithSpace($me, $board));
    }

    /**
     * Same legal-move computation as legalMoves() but returns the per-move
     * area-control score alongside the direction name.
     *
     * Score is the number of cells my head reaches strictly before any enemy
     * head in a multi-source BFS (see areaControl). In a solo game this is
     * identical to the older floodFill-based score.
     *
     * **Ordering** of the returned map is by a *combined* score that adds a
     * food-seeking bonus on top of the raw area, weighted by hunger and
     * length deficit:
     *
     *   - hunger weight ramps from 0 at health ≥ 60 to 1 at health 0
     *   - length-deficit weight ramps from 0 when we're ≥2 longer than the
     *     longest enemy to 1 when we're 5 behind
     *   - the weights are combined as max() so a healthy-but-tiny snake
     *     still seeks growth and a long-but-starving snake still seeks food
     *   - bonus contribution per food = weight × FOOD_BONUS / (dist + 1),
     *     summed for fast falloff (closer food matters disproportionately)
     *
     * The map's **values** stay the raw area-control score so the Decider
     * sanity gate continues to detect 1-cell pockets correctly — only the
     * ranking order is food-adjusted.
     *
     * @return array<string,int> Map of direction → raw area-control score,
     *                           sorted descending by combined ranking.
     *                           Guaranteed non-empty. When every move is
     *                           fatal, the single entry has score 0.
     */
    public static function legalMovesWithSpace(array $me, array $board): array
    {
        $width  = (int) $board['width'];
        $height = (int) $board['height'];
        $head   = $me['head'];
        $myLen  = (int) $me['length'];
        $myId   = $me['id'] ?? null;
        $myHealth = (int) ($me['health'] ?? 100);

        // Build the occupancy map for "next turn": a set of cells we cannot
        // safely enter. Every snake's body except the tail is blocked. The
        // tail vacates next turn unless that snake just ate this turn (length
        // != tail-segment-count means the tail just grew — careful).
        $blocked = [];
        $enemies = [];
        $maxEnemyLen = 0;
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
            if (($snake['id'] ?? null) !== $myId) {
                $enemies[] = [
                    'x'      => (int) $snake['head']['x'],
                    'y'      => (int) $snake['head']['y'],
                    'length' => (int) $snake['length'],
                ];
                $maxEnemyLen = max($maxEnemyLen, (int) $snake['length']);
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

            $candidates[$dir] = self::areaControl(
                ['x' => $nx, 'y' => $ny],
                $myLen,
                $enemies,
                $blocked,
                $width,
                $height
            );
        }

        if ($candidates === []) {
            // Every direction is a wall, body, or head-on loss. Return the
            // least-bad option (head-on coin flip > guaranteed death). If even
            // that doesn't exist, default to "up" — we're dead either way.
            return [($fallback ?? 'up') => 0];
        }

        // Detect "loiter mode": every legal move leads to a region too
        // tight to outrun. When this fires, food becomes dangerous (eating
        // grows us further into the trap) and the right play is to follow
        // our own tail — it vacates every turn, keeping a cyclic loop of
        // breathable space open. Threshold of 1.5× length is the soft point
        // where the body barely fits, with enough room to maneuver.
        $maxArea = max($candidates);
        $loiterThreshold = (int) ceil($myLen * 1.5);
        $loiterMode = $maxArea < $loiterThreshold;

        $combined = $candidates; // default: combined = raw area

        if ($loiterMode) {
            // Loiter: rank by tail proximity (closer to tail = higher), with
            // raw area dominating so we never pick a 1-cell pocket just
            // because it sits next to the tail.
            $body = $me['body'];
            $tail = $body[count($body) - 1];
            $tailDist = self::multiSourceBfsDistances(
                [['x' => (int) $tail['x'], 'y' => (int) $tail['y']]],
                $blocked,
                $width,
                $height,
            );
            $maxFallbackDist = $width + $height;
            foreach ($candidates as $dir => $area) {
                [$dx, $dy] = self::DIRECTIONS[$dir];
                $nx = (int) $head['x'] + $dx;
                $ny = (int) $head['y'] + $dy;
                $d = $tailDist[self::key($nx, $ny)] ?? $maxFallbackDist;
                // 0.5 per distance unit keeps area as the primary signal
                // (so a 1-cell pocket beside the tail still loses to a
                // wide path two cells from the tail) while still breaking
                // ties on tail proximity.
                $combined[$dir] = $area - $d * 0.5;
            }
        } else {
            // Normal mode: optional food bonus, weighted by hunger and
            // length deficit (the two conditions where eating is worth
            // the body growth).
            $hungerWeight = max(0.0, (60.0 - $myHealth) / 60.0);
            $lengthDeficitWeight = max(0.0, ($maxEnemyLen + 2 - $myLen) / 5.0);
            $foodWeight = min(1.0, max($hungerWeight, $lengthDeficitWeight));

            $foodCells = $board['food'] ?? [];
            if ($foodWeight > 0.0 && $foodCells !== []) {
                $foodDistance = self::multiSourceBfsDistances($foodCells, $blocked, $width, $height);
                foreach ($candidates as $dir => $area) {
                    [$dx, $dy] = self::DIRECTIONS[$dir];
                    $nx = (int) $head['x'] + $dx;
                    $ny = (int) $head['y'] + $dy;
                    $dist = $foodDistance[self::key($nx, $ny)] ?? null;
                    if ($dist === null) {
                        continue; // food unreachable from this direction
                    }
                    // Bonus shrinks fast with distance — a food at the next
                    // cell matters far more than one 10 cells away.
                    $bonus = $foodWeight * (self::FOOD_BONUS / ($dist + 1.0));
                    $combined[$dir] = $area + $bonus;
                }
            }
        }

        // Sort by the combined score. Then return the raw-area map in that
        // order — that way Board renders raw cells (which the LLM
        // interprets correctly) and the Decider's sanity gate keeps its
        // pocket-detection ratio.
        arsort($combined);
        $result = [];
        foreach (array_keys($combined) as $dir) {
            $result[$dir] = $candidates[$dir];
        }
        return $result;
    }

    /**
     * Maximum food contribution to the ranking when both food weight and
     * proximity are saturated. Tuned so a candidate next to food while
     * starving overcomes a ~15-cell area difference, but a healthy snake
     * with low foodWeight sees ~0 bonus.
     */
    private const FOOD_BONUS = 30.0;

    /**
     * Multi-source BFS from every food cell, returning the distance from
     * the nearest food to every reachable cell on the board. Used by the
     * ranker to score food proximity per candidate.
     *
     * @param list<array{x:int,y:int}> $foodCells
     * @param array<string,bool>       $blocked   Same shape as floodFill/areaControl.
     * @return array<string,int>                   Cell key → BFS distance.
     */
    private static function multiSourceBfsDistances(array $foodCells, array $blocked, int $width, int $height): array
    {
        $dist = [];
        $frontier = [];
        foreach ($foodCells as $f) {
            $fx = (int) $f['x'];
            $fy = (int) $f['y'];
            if ($fx < 0 || $fx >= $width || $fy < 0 || $fy >= $height) {
                continue;
            }
            $k = self::key($fx, $fy);
            // Food cells are technically standalone — they sit on otherwise
            // open cells, but if the engine put one inside a body cell
            // (royale spawn quirks) we still seed the BFS from there. The
            // BFS won't escape into blocked neighbours, so dirty starts are
            // self-healing.
            if (isset($dist[$k])) {
                continue;
            }
            $dist[$k] = 0;
            $frontier[] = [$fx, $fy];
        }
        $d = 0;
        while ($frontier !== []) {
            $next = [];
            foreach ($frontier as [$x, $y]) {
                foreach (self::DIRECTIONS as [$dx, $dy]) {
                    $nx = $x + $dx;
                    $ny = $y + $dy;
                    if ($nx < 0 || $nx >= $width || $ny < 0 || $ny >= $height) {
                        continue;
                    }
                    $k = self::key($nx, $ny);
                    if (isset($dist[$k]) || isset($blocked[$k])) {
                        continue;
                    }
                    $dist[$k] = $d + 1;
                    $next[] = [$nx, $ny];
                }
            }
            $frontier = $next;
            $d++;
        }
        return $dist;
    }

    /**
     * Voronoi area control from a hypothetical landing cell.
     *
     * Multi-source BFS: start from $origin (my head's next position) and
     * every enemy head simultaneously, advance one step at a time. A cell
     * is claimed by whoever reaches it first; on simultaneous arrival,
     * the longer snake wins and equal-length ties go to no one. Returns
     * the count of cells claimed by me (including the origin itself).
     *
     * In a solo game (no enemies), this collapses to floodFill(): only one
     * frontier exists, so every reachable cell is mine.
     *
     * The score generalises both halves of "smarter snake":
     *   - Self-preservation: my area shrinks if I'm walking into a region
     *     an enemy can reach first, even if it's still a large region by
     *     raw cell count.
     *   - Aggression: a move that cuts an enemy off from open space shows
     *     up as a jump in my area / drop in theirs — same operation from
     *     opposite ends.
     *
     * @param array{x:int,y:int}                                       $origin   My hypothetical next head cell.
     * @param int                                                      $myLength My current length.
     * @param list<array{x:int,y:int,length:int}>                      $enemies  Enemy heads + lengths.
     * @param array<string,bool>                                       $blocked  Body-occupancy map (tails already
     *                                                                            vacated by the caller — same map
     *                                                                            shape floodFill consumes).
     */
    public static function areaControl(
        array $origin,
        int $myLength,
        array $enemies,
        array $blocked,
        int $width,
        int $height
    ): int {
        $ox = (int) $origin['x'];
        $oy = (int) $origin['y'];
        if ($ox < 0 || $ox >= $width || $oy < 0 || $oy >= $height) {
            return 0;
        }
        $originKey = self::key($ox, $oy);
        if (isset($blocked[$originKey])) {
            return 0;
        }

        // Owner indices: 0 = me, 1..n = enemies (in input order). Lengths
        // are looked up by the same index.
        $lengths = [$myLength];
        $claimed = [$originKey => 0];
        $frontiers = [0 => [[$ox, $oy]]];

        foreach ($enemies as $i => $e) {
            $idx = $i + 1;
            $lengths[] = (int) $e['length'];
            $ex = (int) $e['x'];
            $ey = (int) $e['y'];
            $eKey = self::key($ex, $ey);
            // Always seed enemy heads, even when they're in $blocked — the
            // caller's blocked map includes head cells as body segments, but
            // for area-control purposes a head IS the BFS source. Marking the
            // cell as claimed prevents anyone else from racing into it.
            $claimed[$eKey] = $idx;
            $frontiers[$idx] = [[$ex, $ey]];
        }

        $myCount = 1; // origin counts toward my area

        // Level-synchronous BFS: at each step, all owners expand their
        // frontiers in parallel, then we resolve any cell that multiple
        // owners reached at the same distance.
        while (true) {
            $proposed = []; // cellKey => list<ownerIdx> trying to claim this level
            $any = false;
            foreach ($frontiers as $owner => $cells) {
                if ($cells === []) {
                    continue;
                }
                $any = true;
                foreach ($cells as [$x, $y]) {
                    foreach (self::DIRECTIONS as [$dx, $dy]) {
                        $nx = $x + $dx;
                        $ny = $y + $dy;
                        if ($nx < 0 || $nx >= $width || $ny < 0 || $ny >= $height) {
                            continue;
                        }
                        $k = self::key($nx, $ny);
                        if (isset($blocked[$k]) || isset($claimed[$k])) {
                            continue;
                        }
                        $proposed[$k][$owner] = [$nx, $ny];
                    }
                }
                $frontiers[$owner] = []; // emptied; refilled below
            }
            if (!$any) {
                break;
            }

            foreach ($proposed as $k => $bidders) {
                if (count($bidders) === 1) {
                    $owner = array_key_first($bidders);
                    $claimed[$k] = $owner;
                    $frontiers[$owner][] = $bidders[$owner];
                    if ($owner === 0) {
                        $myCount++;
                    }
                    continue;
                }
                // Multi-owner contention: longest wins; tie → neutral.
                $maxLen = -1;
                $winner = null;
                foreach ($bidders as $owner => $_) {
                    $len = $lengths[$owner];
                    if ($len > $maxLen) {
                        $maxLen = $len;
                        $winner = $owner;
                    } elseif ($len === $maxLen) {
                        $winner = null;
                    }
                }
                if ($winner === null) {
                    // Neutral cell — block it so no one expands through it.
                    $claimed[$k] = -1;
                    continue;
                }
                $claimed[$k] = $winner;
                $frontiers[$winner][] = $bidders[$winner];
                if ($winner === 0) {
                    $myCount++;
                }
            }
        }

        return $myCount;
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
     * Public entry point for one MCTS rollout — used by IncrementalMcts.
     *
     * Pulls $width / $height / enemy occupancy out of $state once per call
     * (fast enough that recomputing it is cheaper than threading a
     * pre-built context through every call).
     */
    public static function singleRollout(array $state, string $rootMove, int $depth = 20): float
    {
        $me     = $state['you'];
        $board  = $state['board'];
        $width  = (int) $board['width'];
        $height = (int) $board['height'];
        return self::rollout($me, $board, $width, $height, $rootMove, $depth);
    }

    /**
     * Enemy-aware random rollout with health/food/hazard tracking. Returns
     * a score where higher = better.
     *
     * Each turn every still-alive snake (mine + enemies) picks a legal move
     * (mine random with predictive head-on filtering; enemies 50/50 chase-
     * me / random). Health decays by 1 per turn, +15 inside a hazard cell.
     * Landing on food restores health to 100, grows the snake by one
     * segment (tail does not pop that turn), and removes the food from the
     * board. Hitting zero health kills the snake.
     *
     * After moves resolve, killshot detection (area < length) and head-on
     * collisions (longer wins, equal both die) finalise the turn. Score is
     * turns survived from the root move forward plus a kill bonus for each
     * enemy that died (no-escape, starvation, head-on loss, or killshot).
     *
     * Modelling food + health is what lets MCTS see "if I take this
     * direction I will starve before reaching food" — without it, rollouts
     * happily run to depth even while the real snake would die of
     * health=0. Modelling enemies as movers (the change from the previous
     * commit) is what lets it see multi-turn traps.
     */
    private static function rollout(array $me, array $board, int $width, int $height, string $rootMove, int $depth): float
    {
        $myId = $me['id'] ?? null;
        $mySnake = [
            'body'   => array_map(static fn(array $c): array => [(int) $c['x'], (int) $c['y']], $me['body']),
            'length' => (int) $me['length'],
            'health' => (int) ($me['health'] ?? 100),
        ];

        /** @var list<array{body:list<array{0:int,1:int}>,length:int,health:int,id:string}> */
        $enemies = [];
        foreach ($board['snakes'] as $snake) {
            if (($snake['id'] ?? null) === $myId) {
                continue;
            }
            $enemies[] = [
                'body'   => array_map(static fn(array $c): array => [(int) $c['x'], (int) $c['y']], $snake['body']),
                'length' => (int) $snake['length'],
                'health' => (int) ($snake['health'] ?? 100),
                'id'     => (string) ($snake['id'] ?? spl_object_hash((object) $snake)),
            ];
        }

        // Mutable sets for food + hazards. Food is consumed during the
        // rollout; hazards persist for the whole window.
        $food = [];
        foreach ($board['food'] ?? [] as $f) {
            $food[self::key((int) $f['x'], (int) $f['y'])] = true;
        }
        $hazards = [];
        foreach ($board['hazards'] ?? [] as $h) {
            $hazards[self::key((int) $h['x'], (int) $h['y'])] = true;
        }

        // Apply the root move first. Use a static occupancy here — enemies
        // haven't moved yet relative to the engine's current frame.
        $staticEnemyOcc = self::occupancyOfAll($enemies, includeTails: false);
        if (!self::stepSnake($mySnake, $rootMove, $width, $height, $staticEnemyOcc, $food, $hazards)) {
            return 0.0;
        }

        $survived = 1;
        $kills    = 0;
        $killBonus = 10.0; // a kill is worth ten turns of survival

        for ($t = 1; $t < $depth; $t++) {
            // 1. Enemies pick moves based on the pre-move state. Policy is a
            //    50/50 mix of "chase me" (pick the legal move that minimises
            //    Manhattan distance to my head) and "random legal" — pure
            //    random doesn't apply enough pressure to surface multi-turn
            //    traps; pure chase is too pessimistic and would have us flee
            //    every encounter. The mix gives MCTS enough adversarial
            //    signal to mark "advancing toward a longer snake" as
            //    statistically dangerous without making every game
            //    feel like the enemy is psychic.
            //
            //    Use mt_rand instead of random_int — rollouts don't need
            //    cryptographic randomness and random_int dominates the
            //    per-call cost.
            $myHead = $mySnake['body'][0];
            $myOcc = self::occupancyOfSelf($mySnake['body'], includeTail: false);
            $newEnemies = [];
            foreach ($enemies as $i => $e) {
                $otherEnemyOcc = self::occupancyOfAll($enemies, includeTails: false, except: $i);
                $combined = $myOcc + $otherEnemyOcc;
                $legal = self::legalSelfMoves($e['body'], $width, $height, $combined);
                if ($legal === []) {
                    $kills++; // enemy has nowhere to go → dies trapped
                    continue;
                }
                if (mt_rand(0, 1) === 0) {
                    // Chase: pick the legal move that minimises Manhattan
                    // distance to my head. Tie-break by iteration order
                    // (stable, doesn't matter which one).
                    $pick = $legal[0];
                    $bestDist = PHP_INT_MAX;
                    foreach ($legal as $dir) {
                        [$dx, $dy] = self::DIRECTIONS[$dir];
                        $nx = $e['body'][0][0] + $dx;
                        $ny = $e['body'][0][1] + $dy;
                        $d = abs($nx - $myHead[0]) + abs($ny - $myHead[1]);
                        if ($d < $bestDist) {
                            $bestDist = $d;
                            $pick = $dir;
                        }
                    }
                } else {
                    $pick = $legal[mt_rand(0, count($legal) - 1)];
                }
                if (!self::stepSnake($e, $pick, $width, $height, $combined, $food, $hazards)) {
                    $kills++; // illegal move OR starvation → dies
                    continue;
                }

                // Killshot detection: after the enemy moves, flood-fill from
                // their new head. If their reachable area is smaller than
                // their length, they can't fit their own body and are
                // imminently dead — credit the kill now and drop them from
                // the rollout.
                $eHead = ['x' => $e['body'][0][0], 'y' => $e['body'][0][1]];
                $blockedForE = $combined;
                $eSegs = count($e['body']);
                for ($j = 1; $j < $eSegs - 1; $j++) {
                    $blockedForE[self::key($e['body'][$j][0], $e['body'][$j][1])] = true;
                }
                $area = self::floodFill($eHead, $blockedForE, $width, $height);
                if ($area < $e['length']) {
                    $kills++;
                    continue;
                }
                $newEnemies[] = $e;
            }
            $enemies = $newEnemies;

            // 2. I pick my move from legal options against the *updated*
            //    enemy occupancy. Head-on with a longer enemy looks like a
            //    blocked cell here (enemy head is in occupancy + longer);
            //    head-on with a shorter enemy is allowed and resolved below.
            [$myLegal, $headOnTargets] = self::legalSelfMovesWithHeadOnTargets(
                $mySnake['body'],
                $mySnake['length'],
                $enemies,
                $width,
                $height,
            );
            if ($myLegal === []) {
                break;
            }
            $pick = $myLegal[mt_rand(0, count($myLegal) - 1)];

            // 3. Step me. Need to compute the destination before stepping
            //    because stepSnake will mutate the body.
            [$dx, $dy] = self::DIRECTIONS[$pick];
            $nx = $mySnake['body'][0][0] + $dx;
            $ny = $mySnake['body'][0][1] + $dy;
            $allEnemyOcc = self::occupancyOfAll($enemies, includeTails: false);
            if (!self::stepSnake($mySnake, $pick, $width, $height, $allEnemyOcc, $food, $hazards)) {
                break; // I died (collision, head-on, or starvation)
            }

            $targetKey = self::key($nx, $ny);
            if (isset($headOnTargets[$targetKey])) {
                // We body-checked a strictly-shorter enemy head.
                $kills++;
                $enemies = array_values(array_filter(
                    $enemies,
                    static fn(array $e): bool => !($e['body'][0][0] === $nx && $e['body'][0][1] === $ny),
                ));
            }

            // 4. Any enemy whose head now equals mine post-move triggers a
            //    head-on. Resolution: longer wins; equal length kills both.
            //    (Enemy heads moved in step 1, mine in step 3 — same turn.)
            $iDied = false;
            $newEnemies = [];
            foreach ($enemies as $e) {
                if ($e['body'][0][0] === $mySnake['body'][0][0] && $e['body'][0][1] === $mySnake['body'][0][1]) {
                    if ($e['length'] >= $mySnake['length']) {
                        $iDied = true;
                    }
                    if ($e['length'] <= $mySnake['length']) {
                        $kills++;
                        continue; // they die
                    }
                }
                $newEnemies[] = $e;
            }
            $enemies = $newEnemies;
            if ($iDied) {
                break;
            }
            $survived++;
        }

        return (float) $survived + $kills * $killBonus;
    }

    /**
     * Mutates $snake in place: applies one move, updates body, health, and
     * length, consuming food / taking hazard damage as appropriate. Returns
     * false if the snake died — walls, body collisions, or health hitting
     * zero are all reported as failure.
     *
     * @param array{body:list<array{0:int,1:int}>,length:int,health:int} $snake
     * @param array<string,bool>                                          $blocked  Other snakes' bodies (minus their tails).
     * @param array<string,bool>                                          $food     Mutable set: this snake removes any food it eats.
     * @param array<string,bool>                                          $hazards  Static set of hazard cells.
     */
    private static function stepSnake(
        array &$snake,
        string $move,
        int $width,
        int $height,
        array $blocked,
        array &$food,
        array $hazards
    ): bool {
        [$dx, $dy] = self::DIRECTIONS[$move];
        [$hx, $hy] = $snake['body'][0];
        $nx = $hx + $dx;
        $ny = $hy + $dy;

        if ($nx < 0 || $nx >= $width || $ny < 0 || $ny >= $height) {
            return false;
        }
        $newKey = self::key($nx, $ny);
        if (isset($blocked[$newKey])) {
            return false;
        }

        $ate = isset($food[$newKey]);
        if ($ate) {
            // Eat: remove food, restore health, grow by one (tail does not
            // pop this turn — body just gets a new head).
            unset($food[$newKey]);
            array_unshift($snake['body'], [$nx, $ny]);
            $snake['length']++;
            $snake['health'] = 100;
            return true;
        }

        // Normal step: pop tail, prepend head, decay health.
        $tail = array_pop($snake['body']);
        // Body-collision check (excluding the tail we just popped, since it
        // vacates the same turn).
        foreach ($snake['body'] as [$bx, $by]) {
            if ($bx === $nx && $by === $ny) {
                $snake['body'][] = $tail; // restore for caller correctness
                return false;
            }
        }
        array_unshift($snake['body'], [$nx, $ny]);
        $snake['health']--;
        if (isset($hazards[$newKey])) {
            $snake['health'] -= 15;
        }
        if ($snake['health'] <= 0) {
            return false; // starved
        }
        return true;
    }

    /**
     * Legal moves for a snake whose body is $body. "Legal" means walls and
     * blocked cells; head-on logic is the caller's responsibility.
     *
     * @return list<string>
     */
    private static function legalSelfMoves(array $body, int $width, int $height, array $blocked): array
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
            if (isset($bodyKeys[$k]) || isset($blocked[$k])) {
                continue;
            }
            $out[] = $dir;
        }
        return $out;
    }

    /**
     * Variant of legalSelfMoves used by the enemy-aware rollout for *my* turn.
     * Returns the legal direction list AND a map of "destination key →
     * shorter-enemy-head-we'd-kill-by-going-there", so the caller can
     * credit a kill when it chooses one of those moves.
     *
     * Head-on logic mirrors the production legalMoves filter
     * (Safety::headToHeadLoss): a cell is excluded if any enemy of equal-or-
     * greater length *could* move into it next turn (not just if it equals
     * their current head). Without this predictive check, the rollout sees
     * a snake walking adjacent to a longer enemy as "safe", then dies one
     * turn later when the enemy boxes us in — exactly the game-86b92eaf
     * pattern we're trying to detect.
     *
     * @return array{0:list<string>,1:array<string,bool>}
     */
    private static function legalSelfMovesWithHeadOnTargets(
        array $myBody,
        int $myLength,
        array $enemies,
        int $width,
        int $height
    ): array {
        $bodyKeys = [];
        $stopAt   = count($myBody) - 1;
        for ($i = 0; $i < $stopAt; $i++) {
            $bodyKeys[self::key($myBody[$i][0], $myBody[$i][1])] = true;
        }

        // Enemy non-head occupancy (head handled via the predictive check).
        $enemyNonHeads = [];
        foreach ($enemies as $e) {
            $segs = count($e['body']);
            if ($segs === 0) {
                continue;
            }
            $tailStop = $segs - 1;
            for ($i = 1; $i < $tailStop; $i++) {
                $enemyNonHeads[self::key($e['body'][$i][0], $e['body'][$i][1])] = true;
            }
        }

        [$hx, $hy] = $myBody[0];
        $legal = [];
        $killTargets = [];
        foreach (self::DIRECTIONS as $dir => [$dx, $dy]) {
            $nx = $hx + $dx;
            $ny = $hy + $dy;
            if ($nx < 0 || $nx >= $width || $ny < 0 || $ny >= $height) {
                continue;
            }
            $k = self::key($nx, $ny);
            if (isset($bodyKeys[$k]) || isset($enemyNonHeads[$k])) {
                continue;
            }

            // Predictive head-on: for each enemy, check whether any cell
            // adjacent to their current head equals our candidate. Equal-or-
            // longer enemies block; strictly shorter become kill targets.
            $blockedByHeadOn = false;
            $kill = false;
            foreach ($enemies as $e) {
                $eHx = $e['body'][0][0];
                $eHy = $e['body'][0][1];
                foreach (self::DIRECTIONS as [$edx, $edy]) {
                    if ($eHx + $edx !== $nx || $eHy + $edy !== $ny) {
                        continue;
                    }
                    if ($e['length'] >= $myLength) {
                        $blockedByHeadOn = true;
                        break 2;
                    }
                    $kill = true;
                }
            }
            if ($blockedByHeadOn) {
                continue;
            }
            if ($kill) {
                $killTargets[$k] = true;
            }
            $legal[] = $dir;
        }
        return [$legal, $killTargets];
    }

    /**
     * Occupancy of "all enemies" body cells. Optionally exclude one enemy
     * (used when computing the obstacle set for that enemy's own legal-move
     * check) and optionally include tails (which normally vacate).
     *
     * @param list<array{body:list<array{0:int,1:int}>,length:int,id:string}> $enemies
     * @return array<string,bool>
     */
    private static function occupancyOfAll(array $enemies, bool $includeTails, ?int $except = null): array
    {
        $occ = [];
        foreach ($enemies as $i => $e) {
            if ($except !== null && $i === $except) {
                continue;
            }
            $segs = count($e['body']);
            $stopAt = $includeTails ? $segs : $segs - 1;
            for ($j = 0; $j < $stopAt; $j++) {
                $occ[self::key($e['body'][$j][0], $e['body'][$j][1])] = true;
            }
        }
        return $occ;
    }

    /**
     * Occupancy of my own body — same shape as occupancyOfAll, used to
     * build the "everything that's currently a snake" obstacle set for an
     * enemy's legal-move computation.
     *
     * @return array<string,bool>
     */
    private static function occupancyOfSelf(array $myBody, bool $includeTail): array
    {
        $occ = [];
        $segs = count($myBody);
        $stopAt = $includeTail ? $segs : $segs - 1;
        for ($i = 0; $i < $stopAt; $i++) {
            $occ[self::key($myBody[$i][0], $myBody[$i][1])] = true;
        }
        return $occ;
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
