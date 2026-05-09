<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * The snake's running commentary.
 *
 * Battlesnake lets each /move response include an optional "shout" string
 * that's broadcast in the game viewer. We use it to keep things on-brand:
 * a half-NatGeo, half-ESPN, half-Australian-naturalist deadpan, narrated
 * as if the snake itself were dictating to a stenographer mid-coil. No
 * gen-Z slang. No emoji. Just observational dryness with a faint sense
 * of menace.
 *
 * Each context bucket holds a short pool of one-liners. pick() resolves
 * a bucket and a deterministic-but-pseudo-random line so the same board
 * doesn't keep emitting the same shout. The selection is salted by turn
 * number so successive turns rotate naturally.
 *
 * Public API is intentionally tiny — Controller::move() decides the bucket
 * from the situation (food eaten, kill opportunity, tight escape) and
 * defers the wording to here.
 */
final class Shouts
{
    /**
     * Shout buckets, indexed by Context. Keep the lines under ~80 chars
     * so the in-game viewer doesn't truncate mid-syllable.
     */
    /** Pools keyed by Context::value. PHP enums can't be const array keys directly. */
    private const POOLS = [
        'hunting' => [
            'Stalking the smaller specimen. Notice the unhurried gait.',
            'Closing the distance. The quarry has not yet noticed.',
            'A patient predator is rarely a hungry one for long.',
            'Crikey. That one is built for being eaten.',
            'The hunt begins not with the strike, but with the angle.',
            'Lining up the killing geometry. Take notes.',
        ],
        'attacking' => [
            'Striking. Do try to keep up.',
            'Head-on. The shorter snake will not be writing memoirs.',
            'A textbook collision in the making. Eight out of ten judges.',
            'You weren\'t using that head, were you?',
            'Making contact. The viewer should now look away.',
        ],
        'eating' => [
            'Refueled. The body remembers.',
            'A well-timed snack. Onward.',
            'Crikey, that hit the spot.',
            'Caloric intake noted. Strategy unchanged.',
            'One pellet, one promotion in length. The math is poetry.',
        ],
        'hungry' => [
            'Health is dipping. Plotting a course to provisions.',
            'The body grows cross. Food, then everything else.',
            'A lean snake is a dead snake. Banking calories.',
            'Reconnaissance suggests sustenance to the north. Adjusting.',
        ],
        'escaping' => [
            'Threading the needle. Shaving off no millimetre to spare.',
            'A sporting little corridor. Through it goes.',
            'An ungainly retreat is still a retreat. Survive first; preen later.',
            'That was tighter than the host\'s necktie. Onward.',
        ],
        'cornering' => [
            'The quarry is running out of board. A shame, really.',
            'Cutting off the only sensible exit. Observe the panic.',
            'Box drawn. Lid pending.',
            'The geometry is now hostile to my opponent. As intended.',
        ],
        'coiling' => [
            'Holding the centre. The board comes to me.',
            'Patience is a strategy disguised as inactivity.',
            'Coiling for tempo. Let the others tire themselves out.',
            'A tidy posture. The viewer may admire it.',
        ],
        'fallback' => [
            'Vibes-based routing. The brain is on a coffee break.',
            'Trusting the reptilian instincts on this one.',
            'When the oracle is silent, follow the open space.',
            'A purely procedural decision. Apologies for the lack of flair.',
        ],
        'cornered' => [
            'Out of options worth dignifying. We do what we can.',
            'A poor hand, played politely.',
            'Fewer choices than I would prefer. Rolling the bones.',
        ],
        'generic' => [
            'Proceeding with characteristic composure.',
            'Onward, then.',
            'A quiet turn. The next one may be louder.',
            'Maintaining form. The crowd remains seated.',
        ],
    ];

    /**
     * Pick a shout for the given context. The result is deterministic for
     * a (context, salt) pair so test snapshots remain stable, but salt is
     * usually the turn number — rotating the line per turn naturally.
     */
    public static function pick(Context $context, int $salt = 0): string
    {
        $pool = self::POOLS[$context->value] ?? self::POOLS[Context::Generic->value];
        return $pool[abs($salt) % count($pool)];
    }

    /**
     * Decide the appropriate Context from the move outcome. The Controller
     * passes everything it knows; we infer the most evocative bucket.
     *
     * Priority order (most evocative wins):
     *   1. Cornered      → only one legal move at all
     *   2. Escaping      → tightest legal move had < own length cells of room
     *   3. Attacking     → the chosen move lands on a smaller enemy's head
     *   4. Eating        → the chosen move lands on a food cell
     *   5. Hunting       → there's a smaller adjacent enemy (e+) on the board
     *   6. Cornering     → an enemy has only one legal move available
     *   7. Hungry        → own health < 35
     *   8. Fallback      → the LLM didn't decide; we used the safety net
     *   9. Generic       → nothing else matched
     */
    public static function fromMove(
        string $chosenMove,
        array $state,
        array $safeMoves,
        bool $fallbackUsed,
    ): Context {
        $me      = $state['you'];
        $board   = $state['board'];
        $head    = $me['head'];
        $myLen   = (int) $me['length'];
        $myHealth = (int) ($me['health'] ?? 100);

        if (count($safeMoves) <= 1) {
            return Context::Cornered;
        }

        // Where will my head be after this move?
        $delta = Safety::DIRECTIONS[$chosenMove] ?? null;
        if ($delta === null) {
            return Context::Generic;
        }
        [$dx, $dy] = $delta;
        $newHead = ['x' => (int) $head['x'] + $dx, 'y' => (int) $head['y'] + $dy];

        // 3. Attacking: landing cell == enemy head AND we're longer.
        foreach ($board['snakes'] as $snake) {
            if (($snake['id'] ?? null) === ($me['id'] ?? null)) {
                continue;
            }
            $eHead = $snake['head'];
            if ((int) $eHead['x'] === $newHead['x']
                && (int) $eHead['y'] === $newHead['y']
                && (int) $snake['length'] < $myLen
            ) {
                return Context::Attacking;
            }
        }

        // 4. Eating: landing cell is food.
        foreach (($board['food'] ?? []) as $food) {
            if ((int) $food['x'] === $newHead['x'] && (int) $food['y'] === $newHead['y']) {
                return Context::Eating;
            }
        }

        // 5. Hunting: a smaller enemy head sits within 2 cells of us.
        foreach ($board['snakes'] as $snake) {
            if (($snake['id'] ?? null) === ($me['id'] ?? null)) {
                continue;
            }
            if ((int) $snake['length'] >= $myLen) {
                continue;
            }
            $dist = abs((int) $snake['head']['x'] - $newHead['x'])
                  + abs((int) $snake['head']['y'] - $newHead['y']);
            if ($dist <= 2) {
                return Context::Hunting;
            }
        }

        // 7. Hungry takes precedence over generic when health is low.
        if ($myHealth < 35) {
            return Context::Hungry;
        }

        // 8. Fallback only if the LLM didn't decide AND nothing more dramatic fit.
        if ($fallbackUsed) {
            return Context::Fallback;
        }

        return Context::Generic;
    }
}
