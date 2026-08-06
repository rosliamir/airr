<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

// Cross-app SSO hop from KERISI: verifies an HMAC-signed short-lived token
// minted by KERISI (see kerisiv2.0 SsoController@airrLink), finds-or-creates
// the matching AIRR user by email, and hands off a Sanctum token to the SPA —
// mirrors AuthController::handleGoogleCallback()'s token-in-query-string pattern.
class SsoController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function consume(Request $request): RedirectResponse
    {
        $token = (string) $request->query('token', '');
        $secret = (string) config('services.kerisi_sso.secret');

        $payload = $secret !== '' ? $this->verify($token, $secret) : null;
        if ($payload === null) {
            return redirect($this->frontend('/login?error=sso'));
        }

        $email = (string) $payload['email'];
        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'name'          => $payload['name'] ?? $email,
                'email'         => $email,
                'status'        => User::STATUS_ACTIVE,
                'auth_provider' => 'kerisi_sso',
                'password'      => bcrypt(bin2hex(random_bytes(16))),
            ]);
            if ($role = Role::where('name', 'Business Analyst')->first()) {
                $user->roles()->sync([$role->id]);
            }
            $this->audit->logAuth('auth.register', $user->id);
        }

        if (! $user->isActive()) {
            $this->audit->logAuth('auth.login_pending', $user->id);

            return redirect($this->frontend('/login?error=sso_inactive'));
        }

        $accessToken = $user->createToken('airr-sso', $user->permissionKeys())->plainTextToken;
        $this->audit->logAuth('auth.login', $user->id);

        $query = ['token' => $accessToken];
        // Only accept an internal SPA path (leading '/', no scheme) to prevent an open redirect.
        $path = (string) ($payload['path'] ?? '');
        if ($path !== '' && str_starts_with($path, '/') && ! str_contains($path, '://')) {
            $query['redirect'] = $path;
        }

        return redirect($this->frontend('/login?' . http_build_query($query)));
    }

    /**
     * @return array{email: string, name: ?string, path: ?string}|null
     */
    private function verify(string $token, string $secret): ?array
    {
        [$payloadB64, $sig] = array_pad(explode('.', $token, 2), 2, '');
        if ($payloadB64 === '' || $sig === '') {
            return null;
        }

        $expected = hash_hmac('sha256', $payloadB64, $secret);
        if (! hash_equals($expected, $sig)) {
            return null;
        }

        $decoded = base64_decode(strtr($payloadB64, '-_', '+/'), true);
        $payload = $decoded !== false ? json_decode($decoded, true) : null;

        if (! is_array($payload) || empty($payload['email']) || empty($payload['exp'])) {
            return null;
        }

        if ((int) $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    private function frontend(string $path): string
    {
        return rtrim(config('app.frontend_url', config('app.url')), '/') . $path;
    }
}
