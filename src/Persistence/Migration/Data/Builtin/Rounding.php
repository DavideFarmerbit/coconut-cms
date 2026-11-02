<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

use RoundingMode;

/** How a number losing places is rounded: what NumberToNumber takes. */
enum Rounding: string
{
    /** To the nearest; a half away from zero: 2.5 to 3, -2.5 to -3. */
    case HalfUp = 'half-up';

    /** To the nearest; a half to the even neighbour: 2.5 to 2, 3.5 to 4. */
    case HalfEven = 'half-even';

    /** Toward zero: 2.9 to 2, -2.9 to -2. */
    case Down = 'down';

    /** Away from zero: 2.1 to 3, -2.1 to -3. */
    case Up = 'up';

    /** PHP's own mode, for round(). */
    public function mode(): RoundingMode {
        return match ($this) {
            self::HalfUp => RoundingMode::HalfAwayFromZero,
            self::HalfEven => RoundingMode::HalfEven,
            self::Down => RoundingMode::TowardsZero,
            self::Up => RoundingMode::AwayFromZero,
        };
    }
}
