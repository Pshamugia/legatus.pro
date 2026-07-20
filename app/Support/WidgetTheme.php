<?php

namespace App\Support;

final class WidgetTheme
{
    public const DEFAULT_PRESET = 'forest';

    public const CUSTOM_PRESET = 'custom';

    private const DARK_FOREGROUND = '#000000';

    private const LIGHT_FOREGROUND = '#FFFFFF';

    /**
     * @return array<string, array{label: string, description: string, primary: string, accent: string}>
     */
    public static function presets(): array
    {
        return [
            'forest' => [
                'label' => 'Forest',
                'description' => 'Grounded and trustworthy',
                'primary' => '#163F33',
                'accent' => '#D9FF72',
            ],
            'ocean' => [
                'label' => 'Ocean',
                'description' => 'Clear and modern',
                'primary' => '#164E63',
                'accent' => '#67E8F9',
            ],
            'midnight' => [
                'label' => 'Midnight',
                'description' => 'Premium and composed',
                'primary' => '#1E293B',
                'accent' => '#A5B4FC',
            ],
            'plum' => [
                'label' => 'Plum',
                'description' => 'Warm and distinctive',
                'primary' => '#581C87',
                'accent' => '#F0ABFC',
            ],
            'ember' => [
                'label' => 'Ember',
                'description' => 'Confident and energetic',
                'primary' => '#7C2D12',
                'accent' => '#FDBA74',
            ],
        ];
    }

    /** @return list<string> */
    public static function allowedPresets(): array
    {
        return [...array_keys(self::presets()), self::CUSTOM_PRESET];
    }

    /** @return array{preset: string, primary: string, accent: string, primary_foreground: string, accent_foreground: string} */
    public static function resolve(mixed $theme): array
    {
        $theme = is_array($theme) ? $theme : [];
        $presets = self::presets();
        $preset = is_string($theme['preset'] ?? null) ? strtolower(trim($theme['preset'])) : self::DEFAULT_PRESET;

        if (isset($presets[$preset])) {
            $primary = $presets[$preset]['primary'];
            $accent = $presets[$preset]['accent'];
        } elseif ($preset === self::CUSTOM_PRESET) {
            $primary = self::normalizeHex($theme['primary'] ?? null);
            $accent = self::normalizeHex($theme['accent'] ?? null);

            if ($primary === null || $accent === null || ! self::hasSufficientPairContrast($primary, $accent)) {
                $preset = self::DEFAULT_PRESET;
                $primary = $presets[$preset]['primary'];
                $accent = $presets[$preset]['accent'];
            }
        } else {
            $preset = self::DEFAULT_PRESET;
            $primary = $presets[$preset]['primary'];
            $accent = $presets[$preset]['accent'];
        }

        return [
            'preset' => $preset,
            'primary' => $primary,
            'accent' => $accent,
            'primary_foreground' => self::readableForeground($primary),
            'accent_foreground' => self::readableForeground($accent),
        ];
    }

    /**
     * @return array{preset: string, primary: string, accent: string}
     */
    public static function configured(string $preset, string $primary, string $accent): array
    {
        $resolved = self::resolve([
            'preset' => $preset,
            'primary' => $primary,
            'accent' => $accent,
        ]);

        return [
            'preset' => $resolved['preset'],
            'primary' => $resolved['primary'],
            'accent' => $resolved['accent'],
        ];
    }

    public static function normalizeHex(mixed $color): ?string
    {
        if (! is_string($color) || preg_match('/\A#[0-9A-Fa-f]{6}\z/', $color) !== 1) {
            return null;
        }

        return strtoupper($color);
    }

    public static function readableForeground(string $background): string
    {
        $backgroundLuminance = self::relativeLuminance($background);
        $lightContrast = self::contrastRatio($backgroundLuminance, self::relativeLuminance(self::LIGHT_FOREGROUND));
        $darkContrast = self::contrastRatio($backgroundLuminance, self::relativeLuminance(self::DARK_FOREGROUND));

        return $lightContrast >= $darkContrast ? self::LIGHT_FOREGROUND : self::DARK_FOREGROUND;
    }

    public static function hasSufficientPairContrast(string $primary, string $accent): bool
    {
        return self::colorContrastRatio($primary, $accent) >= 3;
    }

    public static function colorContrastRatio(string $first, string $second): float
    {
        if (self::normalizeHex($first) === null || self::normalizeHex($second) === null) {
            return 1;
        }

        return self::contrastRatio(self::relativeLuminance($first), self::relativeLuminance($second));
    }

    private static function relativeLuminance(string $color): float
    {
        $hex = ltrim(self::normalizeHex($color) ?? '#000000', '#');
        $channels = [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
        $linear = array_map(
            fn (float $channel): float => $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            $channels,
        );

        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
    }

    private static function contrastRatio(float $first, float $second): float
    {
        $lighter = max($first, $second);
        $darker = min($first, $second);

        return ($lighter + 0.05) / ($darker + 0.05);
    }
}
