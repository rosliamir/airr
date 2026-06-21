<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * Edition gating helper (M1 / open-core). Keeps all edition logic in one place
 * so the same codebase serves Community / Standard / Enterprise.
 */
class Edition
{
    public static function current(): string
    {
        return (string) Config::get('airr.edition', 'community');
    }

    /** Is the given feature key enabled for the active edition? */
    public static function allows(string $feature): bool
    {
        $editions = Config::get("airr.features.{$feature}");

        return is_array($editions) && in_array(self::current(), $editions, true);
    }

    /** Numeric limit for the active edition (null = unlimited, 0 if unknown). */
    public static function limit(string $key): ?int
    {
        $limits = Config::get('airr.limits.' . self::current(), []);

        return array_key_exists($key, $limits) ? $limits[$key] : 0;
    }

    /** All enabled features for the active edition (handy for the SPA bootstrap). */
    public static function enabledFeatures(): array
    {
        $current = self::current();

        return collect(Config::get('airr.features', []))
            ->filter(fn ($editions) => in_array($current, $editions, true))
            ->keys()
            ->values()
            ->all();
    }
}
