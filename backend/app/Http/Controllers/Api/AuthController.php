<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Edition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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

        if (! $user || ! Hash::check($creds['password'], $user->password)) {
            $this->audit->logAuth('auth.login_failed', $user?->id);
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $token = $user->createToken('airr-spa', $user->permissionKeys())->plainTextToken;
        $this->audit->logAuth('auth.login', $user->id);

        return $this->sendOk([
            'token' => $token,
            'user'  => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->sendOk($this->userPayload($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        $this->audit->logAuth('auth.logout', $request->user()->id);

        return $this->sendOk(['success' => true]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'roles'       => $user->roles()->pluck('slug'),
            'permissions' => $user->permissionKeys(),
            'clearance'   => $user->clearance(),
            'edition'     => Edition::current(),
            'features'    => Edition::enabledFeatures(),
        ];
    }
}
