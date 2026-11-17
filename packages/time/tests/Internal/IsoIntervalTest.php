<?php

declare(strict_types=1);

namespace Thesis\Time\Internal;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Test;

/**
 * Duration checks only whether there are days, so the number of them is seen here.
 */
#[Test]
final class IsoIntervalTest
{
    #[DataSet(['P2W3D'])]
    #[DataSet(['P3D P2W'], name: 'days of repeated periods add up')]
    public function addsWeeksAsSevenDays(string $string): void
    {
        Assert::same(IsoInterval::parse($string)?->days, 17);
    }

    public function parsesWithoutWarnings(): void
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings) {
            $warnings[] = $message;

            return true;
        });

        try {
            IsoInterval::parse('PT1S');
        } finally {
            restore_error_handler();
        }

        Assert::same($warnings, []);
    }

    public function readsEveryNumberOfCombinedPeriod(): void
    {
        $interval = IsoInterval::parse('P1234-11-29T13:46:58');

        Assert::same(
            actual: [
                $interval?->years,
                $interval?->months,
                $interval?->days,
                $interval?->hours,
                $interval?->minutes,
                $interval?->seconds,
            ],
            expected: [1234, 11, 29, 13, 46, 58],
        );
    }
}
