<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Edition;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit) {}

    // FR-M1.1: token-based login (Sanctum personal access token for the SPA/API).
    public function login(Request $request): JsonResponse
    {
        $creds = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $creds['email'])->first();

        if (! $user || ! $user->password || ! Hash::check($creds['password'], $user->password)) {
            $this->audit->logAuth('auth.login_failed', $user?->id);
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $this->ensureActive($user);

        $token = $user->createToken('airr-spa', $user->permissionKeys())->plainTextToken;
        $this->audit->logAuth('auth.login', $user->id);

        return $this->sendOk([
            'token' => $token,
            'user'  => $this->userPayload($user),
        ]);
    }

    // FR-M1.1: self-registration. Account is created PENDING with no role; a
    // project admin must approve and assign access before the user can log in.
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name'          => $data['name'],
            'email'         => $data['email'],
            'password'      => $data['password'], // hashed via model cast
            'status'        => User::STATUS_PENDING,
            'auth_provider' => 'local',
        ]);

        $this->audit->logAuth('auth.register', $user->id);

        return $this->sendCreated([
            'status'  => $user->status,
            'message' => 'Registration received — pending project-admin approval.',
        ]);
    }

    // FR-M1.1: request a reset link. Generic response to avoid account enumeration.
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        // Only local accounts have a password to reset.
        Password::sendResetLink(
            $request->only('email') + ['auth_provider' => 'local']
        );

        $this->audit->logAuth('auth.password_reset_requested');

        return $this->sendOk([
            'message' => 'If the email exists, a reset link has been sent.',
        ]);
    }

    // FR-M1.1: complete the reset using the e-mailed broker token.
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        $this->audit->logAuth('auth.password_reset');

        return $this->sendOk(['message' => 'Password has been reset.']);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->sendOk($this->userPayload($request->user()));
    }

    // FR-M14 — switch the user's current project (the default authoring scope).
    public function setCurrentProject(Request $request): JsonResponse
    {
        $data = $request->validate(['project_id' => 'nullable|integer|exists:projects,id']);
        $user = $request->user();

        if ($data['project_id'] && ! $user->accessibleProjects()->whereKey($data['project_id'])->exists()) {
            return $this->sendError(403, 'FORBIDDEN', 'You do not have access to that project.');
        }
        $user->forceFill(['current_project_id' => $data['project_id'] ?? null])->save();

        return $this->sendOk($this->userPayload($user->fresh()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        $this->audit->logAuth('auth.logout', $request->user()->id);

        return $this->sendOk(['success' => true]);
    }

    // FR-M1.1: begin Google OAuth. Stateless because the SPA holds no session.
    public function redirectGoogle()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    // OAuth callback. New Google users land PENDING (same approval gate as local
    // sign-up); approved/active users are issued a token and bounced to the SPA.
    public function handleGoogleCallback()
    {
        try {
            $g = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            return redirect($this->frontend('/login?error=google'));
        }

        $user = User::where('google_id', $g->getId())
            ->orWhere('email', $g->getEmail())
            ->first();

        if (! $user) {
            $user = User::create([
                'name'          => $g->getName() ?: $g->getNickname() ?: $g->getEmail(),
                'email'         => $g->getEmail(),
                'status'        => User::STATUS_PENDING,
                'auth_provider' => 'google',
                'google_id'     => $g->getId(),
                'avatar_url'    => $g->getAvatar(),
            ]);
            $this->audit->logAuth('auth.register', $user->id);
        } elseif (! $user->google_id) {
            // Link Google to an existing local account.
            $user->forceFill([
                'google_id'  => $g->getId(),
                'avatar_url' => $g->getAvatar(),
            ])->save();
        }

        if (! $user->isActive()) {
            $this->audit->logAuth('auth.login_pending', $user->id);

            return redirect($this->frontend('/register?status=pending'));
        }

        $token = $user->createToken('airr-spa', $user->permissionKeys())->plainTextToken;
        $this->audit->logAuth('auth.login', $user->id);

        return redirect($this->frontend('/login?token=' . urlencode($token)));
    }

    // Reject any non-active account at the login gate (pending / suspended).
    private function ensureActive(User $user): void
    {
        if ($user->isActive()) {
            return;
        }

        $this->audit->logAuth('auth.login_blocked', $user->id);

        $message = $user->status === User::STATUS_PENDING
            ? 'Your account is pending project-admin approval.'
            : 'Your account is not active. Contact your administrator.';

        throw ValidationException::withMessages(['email' => [$message]]);
    }

    private function frontend(string $path): string
    {
        return rtrim(config('app.frontend_url', config('app.url')), '/') . $path;
    }

    private function userPayload(User $user): array
    {
        $user->ensureCurrentProject(); // default a current project on login (FR-M14)
        $projects = $user->accessibleProjects()->withCount('reports')->get(['projects.id', 'code', 'name', 'color']);
        $current = $projects->firstWhere('id', $user->current_project_id);

        return [
            'id'              => $user->id,
            'name'            => $user->name,
            'email'           => $user->email,
            'avatar_url'      => $user->avatar_url,
            'user_type'       => $user->user_type,
            'roles'           => $user->roles()->pluck('slug'),
            'permissions'     => $user->permissionKeys(),
            'clearance'       => $user->clearance(),
            'edition'         => Edition::current(),
            'features'        => Edition::enabledFeatures(),
            'current_project' => $current ? $this->projectRef($current) : null,
            'projects'        => $projects->map(fn ($p) => $this->projectRef($p))->values(),
        ];
    }

    private function projectRef($p): array
    {
        return [
            'id'            => $p->id,
            'code'          => $p->code,
            'name'          => $p->name,
            'color'         => $p->color,
            'reports_count' => $p->reports_count ?? 0,
        ];
    }
}
