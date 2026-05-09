<?php

declare(strict_types=1);

namespace BattlesnakeAI;

/**
 * Buckets of snake mood, used by Shouts to pick on-brand commentary
 * for the current /move outcome.
 *
 * The order here is purely organizational; selection priority is encoded
 * in Shouts::fromMove().
 */
enum Context: string
{
    case Hunting   = 'hunting';
    case Attacking = 'attacking';
    case Eating    = 'eating';
    case Hungry    = 'hungry';
    case Escaping  = 'escaping';
    case Cornering = 'cornering';
    case Coiling   = 'coiling';
    case Cornered  = 'cornered';
    case Fallback  = 'fallback';
    case Generic   = 'generic';
}
