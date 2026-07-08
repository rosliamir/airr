<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\Ai\AiProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind the active AI provider (FR-M15.6). Defaults from config/ai.php,
        // but an admin can switch provider (e.g. ollama -> openai/claude) at
        // runtime via Settings > AI/Models — that choice (and each hosted
        // provider's own base_url/api_key) is stored in the `settings` table
        // and takes precedence here. Resolved lazily (singleton factory only
        // runs on first use), so it's safe to touch the DB here.
        $this->app->singleton(AiProvider::class, function () {
            $stored = Setting::get('ai', []);
            $key = (string) ($stored['provider'] ?? config('ai.provider', 'ollama'));
            $cfg = config("ai.providers.{$key}");
            if (! is_array($cfg) || empty($cfg['driver'])) {
                throw new InvalidArgumentException("AI provider [{$key}] is not configured in config/ai.php.");
            }

            if (in_array($key, ['openai', 'claude'], true)) {
                return new $cfg['driver'](
                    (string) ($stored["{$key}_base_url"] ?? $cfg['base_url'] ?? ''),
                    (string) ($stored["{$key}_api_key"] ?? $cfg['api_key'] ?? ''),
                    (int) ($cfg['timeout'] ?? 120),
                );
            }

            return new $cfg['driver'](
                (string) ($cfg['url'] ?? ''),
                (int) ($cfg['timeout'] ?? 120),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // API-only app: the password-reset link points at the SPA, not a
        // server-rendered route. The SPA posts token+email to /auth/reset-password.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $base = rtrim(config('app.frontend_url', config('app.url')), '/');

            return $base . '/reset-password?token=' . $token
                . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
        });
    }
}
