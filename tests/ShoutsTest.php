<?php

declare(strict_types=1);

namespace BattlesnakeAI\Tests;

use BattlesnakeAI\Context;
use BattlesnakeAI\Shouts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Shouts::class)]
#[CoversClass(Context::class)]
final class ShoutsTest extends TestCase
{
    private function snake(string $id, array $body, int $health = 100): array
    {
        return [
            'id'     => $id,
            'name'   => $id,
            'health' => $health,
            'body'   => $body,
            'head'   => $body[0],
            'length' => count($body),
        ];
    }

    private function state(array $me, array $enemies = [], array $food = [], int $w = 11, int $h = 11): array
    {
        return [
            'turn'  => 1,
            'board' => [
                'width'   => $w,
                'height'  => $h,
                'food'    => $food,
                'hazards' => [],
                'snakes'  => array_merge([$me], $enemies),
            ],
            'you' => $me,
        ];
    }

    #[Test]
    public function pick_returns_a_nonempty_string_for_every_context(): void
    {
        foreach (Context::cases() as $context) {
            $shout = Shouts::pick($context, 0);
            $this->assertIsString($shout);
            $this->assertNotSame('', $shout, "pool for {$context->value} must not be empty");
            $this->assertLessThan(120, strlen($shout), "shout for {$context->value} too long");
        }
    }

    #[Test]
    public function pick_is_deterministic_for_the_same_salt(): void
    {
        $a = Shouts::pick(Context::Hunting, 7);
        $b = Shouts::pick(Context::Hunting, 7);
        $this->assertSame($a, $b);
    }

    #[Test]
    public function pick_rotates_across_salts(): void
    {
        // Drawing many salts should yield more than one distinct line —
        // confirms the salt actually drives the selection.
        $seen = [];
        for ($i = 0; $i < 12; $i++) {
            $seen[Shouts::pick(Context::Hunting, $i)] = true;
        }
        $this->assertGreaterThan(1, count($seen));
    }

    #[Test]
    public function fromMove_picks_attacking_when_landing_on_smaller_enemy_head(): void
    {
        $me = $this->snake('me', [
            ['x' => 5, 'y' => 5],
            ['x' => 5, 'y' => 4],
            ['x' => 5, 'y' => 3],
            ['x' => 5, 'y' => 2],
        ], health: 80);
        $shorty = $this->snake('shorty', [
            ['x' => 5, 'y' => 6],
            ['x' => 5, 'y' => 7],
        ]);
        $state = $this->state($me, [$shorty]);

        $context = Shouts::fromMove('up', $state, ['up', 'left', 'right'], false);
        $this->assertSame(Context::Attacking, $context);
    }

    #[Test]
    public function fromMove_picks_eating_when_landing_on_food(): void
    {
        $me = $this->snake('me', [
            ['x' => 5, 'y' => 5],
            ['x' => 5, 'y' => 4],
        ], health: 80);
        $state = $this->state($me, [], [['x' => 5, 'y' => 6]]);

        $context = Shouts::fromMove('up', $state, ['up', 'left', 'right'], false);
        $this->assertSame(Context::Eating, $context);
    }

    #[Test]
    public function fromMove_picks_hunting_when_smaller_enemy_is_nearby(): void
    {
        $me = $this->snake('me', [
            ['x' => 5, 'y' => 5],
            ['x' => 5, 'y' => 4],
            ['x' => 5, 'y' => 3],
            ['x' => 5, 'y' => 2],
        ], health: 80);
        $shorty = $this->snake('shorty', [
            ['x' => 7, 'y' => 6],
            ['x' => 8, 'y' => 6],
        ]);
        $state = $this->state($me, [$shorty]);

        // Landing at (6,5) — distance to enemy head at (7,6) is 2.
        $context = Shouts::fromMove('right', $state, ['up', 'right'], false);
        $this->assertSame(Context::Hunting, $context);
    }

    #[Test]
    public function fromMove_picks_hungry_when_health_is_low(): void
    {
        $me = $this->snake('me', [
            ['x' => 5, 'y' => 5],
            ['x' => 5, 'y' => 4],
        ], health: 20);
        $state = $this->state($me, [], []);

        $context = Shouts::fromMove('up', $state, ['up', 'left', 'right'], false);
        $this->assertSame(Context::Hungry, $context);
    }

    #[Test]
    public function fromMove_picks_cornered_when_only_one_legal_move(): void
    {
        $me = $this->snake('me', [
            ['x' => 0, 'y' => 0],
            ['x' => 1, 'y' => 0],
        ], health: 80);
        $state = $this->state($me);

        $context = Shouts::fromMove('up', $state, ['up'], false);
        $this->assertSame(Context::Cornered, $context);
    }

    #[Test]
    public function fromMove_picks_fallback_when_llm_failed_and_nothing_else_fits(): void
    {
        $me = $this->snake('me', [
            ['x' => 5, 'y' => 5],
            ['x' => 5, 'y' => 4],
        ], health: 90);
        $state = $this->state($me, [], []);

        $context = Shouts::fromMove('up', $state, ['up', 'left', 'right'], true);
        $this->assertSame(Context::Fallback, $context);
    }

    #[Test]
    public function fromMove_picks_generic_when_nothing_special_is_happening(): void
    {
        $me = $this->snake('me', [
            ['x' => 5, 'y' => 5],
            ['x' => 5, 'y' => 4],
        ], health: 90);
        $state = $this->state($me, [], []);

        $context = Shouts::fromMove('up', $state, ['up', 'left', 'right'], false);
        $this->assertSame(Context::Generic, $context);
    }
}
