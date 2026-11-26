<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * Platform-controlled catalogue of currencies that organisations may select.
 *
 * The full catalogue lives in config/chama.php. Super admins choose which of
 * those currencies are offered in group settings. Only currencies with two
 * decimal places belong in the catalogue because Money stores minor units.
 */
final class CurrencySettings
{
    public const KEY = 'currencies.enabled';

    /**
     * @return array<string, array{name: string, symbol: string}>
     */
    public static function catalogue(): array
    {
        $catalogue = config('chama.currencies', []);

        return is_array($catalogue) ? $catalogue : [];
    }

    /**
     * Codes that were enabled before a super admin changed anything.
     *
     * @return array<int, string>
     */
    public static function defaults(): array
    {
        $defaults = config('chama.default_enabled_currencies', []);

        return self::known(is_array($defaults) ? $defaults : []);
    }

    /**
     * Codes currently offered to organisations.
     *
     * @return array<int, string>
     */
    public static function enabledCodes(): array
    {
        $setting = PlatformSetting::query()->where('key', self::KEY)->first();

        if ($setting === null || ! is_array($setting->value)) {
            return self::defaults();
        }

        return self::known($setting->value);
    }

    public static function isEnabled(string $code): bool
    {
        return in_array(strtoupper($code), self::enabledCodes(), true);
    }

    /**
     * Drop codes that are not in the configured catalogue.
     *
     * @param  array<int, mixed>  $codes
     * @return array<int, string>
     */
    public static function known(array $codes): array
    {
        $catalogue = self::catalogue();
        $known = [];

        foreach ($codes as $code) {
            if (! is_string($code)) {
                continue;
            }

            $code = strtoupper(trim($code));

            if (isset($catalogue[$code]) && ! in_array($code, $known, true)) {
                $known[] = $code;
            }
        }

        return $known;
    }

    /**
     * Labels for the group settings currency select. A group's existing code is
     * kept visible even when it is no longer offered, so its settings remain
     * editable until the organisation switches.
     *
     * @return array<string, string>
     */
    public static function options(?string $current = null): array
    {
        $current = $current === null ? null : strtoupper($current);
        $enabled = self::enabledCodes();
        $wasEnabled = $enabled;

        if ($current !== null && ! in_array($current, $enabled, true) && isset(self::catalogue()[$current])) {
            $enabled[] = $current;
        }

        $options = [];

        foreach ($enabled as $code) {
            $options[$code] = self::label($code)
                .($current === $code && ! in_array($code, $wasEnabled, true) ? ' (current)' : '');
        }

        return $options;
    }

    /**
     * Codes a group settings form may submit.
     *
     * @return array<int, string>
     */
    public static function selectableCodes(?string $current = null): array
    {
        return array_keys(self::options($current));
    }

    public static function label(string $code): string
    {
        $code = strtoupper($code);
        $definition = self::catalogue()[$code] ?? null;

        if ($definition === null) {
            return $code;
        }

        return $code.' — '.$definition['name'].' ('.$definition['symbol'].')';
    }

    /**
     * @param  array<int, string>  $codes
     */
    public static function save(array $codes, int $actorId): void
    {
        PlatformSetting::updateOrCreate(
            ['key' => self::KEY],
            ['value' => self::known($codes), 'updated_by' => $actorId]
        );
    }
}
