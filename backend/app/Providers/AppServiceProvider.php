<?php

namespace App\Providers;

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
        // Bind the active AI provider (FR-M15.6). Resolved from config/ai.php so
        // editions can swap the engine without touching call sites.
        $this->app->singleton(AiProvider::class, function () {
            $key = (string) config('ai.provider', 'ollama');
            $cfg = config("ai.providers.{$key}");
            if (! is_array($cfg) || empty($cfg['driver'])) {
                throw new InvalidArgumentException("AI provider [{$key}] is not configured in config/ai.php.");
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
