<?php

namespace Tests\Unit;

use App\Support\WidgetTheme;
use PHPUnit\Framework\TestCase;

class WidgetThemeTest extends TestCase
{
    public function test_every_preset_has_readable_foregrounds_and_distinct_brand_colors(): void
    {
        foreach (WidgetTheme::presets() as $preset => $palette) {
            $resolved = WidgetTheme::resolve(['preset' => $preset]);

            $this->assertGreaterThanOrEqual(
                4.5,
                WidgetTheme::colorContrastRatio($resolved['primary'], $resolved['primary_foreground']),
                "{$preset} primary text is not readable.",
            );
            $this->assertGreaterThanOrEqual(
                4.5,
                WidgetTheme::colorContrastRatio($resolved['accent'], $resolved['accent_foreground']),
                "{$preset} accent text is not readable.",
            );
            $this->assertGreaterThanOrEqual(
                3,
                WidgetTheme::colorContrastRatio($palette['primary'], $palette['accent']),
                "{$preset} brand colors are too similar.",
            );
        }
    }

    public function test_custom_colors_are_normalized_and_unsafe_or_low_contrast_values_fail_closed(): void
    {
        $this->assertSame([
            'preset' => 'custom',
            'primary' => '#123456',
            'accent' => '#ABCDEF',
            'primary_foreground' => '#FFFFFF',
            'accent_foreground' => '#000000',
        ], WidgetTheme::resolve([
            'preset' => 'CUSTOM',
            'primary' => '#123456',
            'accent' => '#abcdef',
        ]));

        foreach ([
            ['primary' => '#123456;}', 'accent' => '#ABCDEF'],
            ['primary' => '#111111', 'accent' => '#121212'],
            ['primary' => '#123456', 'accent' => null],
        ] as $palette) {
            $resolved = WidgetTheme::resolve(['preset' => 'custom'] + $palette);

            $this->assertSame('forest', $resolved['preset']);
            $this->assertSame('#163F33', $resolved['primary']);
            $this->assertSame('#D9FF72', $resolved['accent']);
        }

        $this->assertSame('forest', WidgetTheme::resolve('not-an-array')['preset']);
    }
}
