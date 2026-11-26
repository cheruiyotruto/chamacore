<?php

namespace App\Support;

use App\Models\Group;
use App\Models\GroupSetting;

/**
 * Resolves the optional, group-wide appearance overrides used by the Blade
 * layouts. Everything has a safe default so a missing or stale setting can
 * never break a page.
 */
class Appearance
{
    public const BORDERS = [
        'slate' => ['label' => 'Slate (default)', 'color' => '#cbd5e1'],
        'black' => ['label' => 'Black', 'color' => '#0f172a'],
        'blue' => ['label' => 'Blue', 'color' => '#2563eb'],
        'green' => ['label' => 'Green', 'color' => '#16a34a'],
    ];

    public const BACKGROUNDS = [
        'slate' => ['label' => 'Slate', 'color' => '#f8fafc'],
        'white' => ['label' => 'White', 'color' => '#ffffff'],
        'blue' => ['label' => 'Blue tint', 'color' => '#eff6ff'],
        'green' => ['label' => 'Green tint', 'color' => '#ecfdf5'],
        'sand' => ['label' => 'Warm sand', 'color' => '#fdf8f3'],
        'lavender' => ['label' => 'Lavender', 'color' => '#f5f3ff'],
    ];

    public const ACCENTS = [
        'green' => [
            'label' => 'Green',
            'scale' => [
                50 => '#ecfdf5', 100 => '#d1fae5', 200 => '#a7f3d0', 300 => '#6ee7b7',
                400 => '#34d399', 500 => '#10b981', 600 => '#059669', 700 => '#047857',
                800 => '#065f46', 900 => '#064e3b', 950 => '#022c22',
            ],
        ],
        'blue' => [
            'label' => 'Blue',
            'scale' => [
                50 => '#eff6ff', 100 => '#dbeafe', 200 => '#bfdbfe', 300 => '#93c5fd',
                400 => '#60a5fa', 500 => '#3b82f6', 600 => '#2563eb', 700 => '#1d4ed8',
                800 => '#1e40af', 900 => '#1e3a8a', 950 => '#172554',
            ],
        ],
        'indigo' => [
            'label' => 'Indigo',
            'scale' => [
                50 => '#eef2ff', 100 => '#e0e7ff', 200 => '#c7d2fe', 300 => '#a5b4fc',
                400 => '#818cf8', 500 => '#6366f1', 600 => '#4f46e5', 700 => '#4338ca',
                800 => '#3730a3', 900 => '#312e81', 950 => '#1e1b4b',
            ],
        ],
        'teal' => [
            'label' => 'Teal',
            'scale' => [
                50 => '#f0fdfa', 100 => '#ccfbf1', 200 => '#99f6e4', 300 => '#5eead4',
                400 => '#2dd4bf', 500 => '#14b8a6', 600 => '#0d9488', 700 => '#0f766e',
                800 => '#115e59', 900 => '#134e4a', 950 => '#042f2e',
            ],
        ],
        'rose' => [
            'label' => 'Rose',
            'scale' => [
                50 => '#fff1f2', 100 => '#ffe4e6', 200 => '#fecdd3', 300 => '#fda4af',
                400 => '#fb7185', 500 => '#f43f5e', 600 => '#e11d48', 700 => '#be123c',
                800 => '#9f1239', 900 => '#881337', 950 => '#4c0519',
            ],
        ],
        'black' => [
            'label' => 'Black',
            'scale' => [
                50 => '#f8fafc', 100 => '#f1f5f9', 200 => '#e2e8f0', 300 => '#cbd5e1',
                400 => '#94a3b8', 500 => '#64748b', 600 => '#475569', 700 => '#334155',
                800 => '#1e293b', 900 => '#0f172a', 950 => '#020617',
            ],
        ],
    ];

    public const KEYS = ['theme_border_color', 'theme_accent_color', 'theme_background_color'];

    /**
     * @return array<string, mixed>
     */
    public static function for(?Group $group = null): array
    {
        $values = [
            'theme_border_color' => 'slate',
            'theme_accent_color' => 'green',
            'theme_background_color' => 'slate',
        ];

        if ($group) {
            $stored = GroupSetting::query()
                ->whereIn('key', self::KEYS)
                ->get(['key', 'value'])
                ->mapWithKeys(fn (GroupSetting $setting) => [$setting->key => $setting->value]);

            foreach (self::KEYS as $key) {
                $value = $stored->get($key);

                if (is_string($value) && $value !== '') {
                    $values[$key] = $value;
                }
            }
        }

        $borderKey = array_key_exists($values['theme_border_color'], self::BORDERS)
            ? $values['theme_border_color']
            : 'slate';
        $backgroundKey = array_key_exists($values['theme_background_color'], self::BACKGROUNDS)
            ? $values['theme_background_color']
            : 'slate';
        $accentKey = array_key_exists($values['theme_accent_color'], self::ACCENTS)
            ? $values['theme_accent_color']
            : 'green';

        return [
            'border_key' => $borderKey,
            'border_color' => self::BORDERS[$borderKey]['color'],
            'background_key' => $backgroundKey,
            'background_color' => self::BACKGROUNDS[$backgroundKey]['color'],
            'accent_key' => $accentKey,
            'accent_color' => self::ACCENTS[$accentKey]['scale'][600],
            'accent' => self::ACCENTS[$accentKey]['scale'],
        ];
    }
}
