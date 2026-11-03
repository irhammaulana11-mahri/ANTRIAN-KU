<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\Employee;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $email = $request->string('email')->toString();
        $user = User::with(['roles', 'employee', 'branch', 'unit'])->where('email', $email)->first();
        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            $this->audit->record('auth.login_failed', 'user', $user?->id, $user?->branch_id, null, null, null, $this->context($request, ['email' => mb_substr($email, 0, 190), 'reason' => $user ? 'bad_password' : 'unknown_user']));

            return response()->json(['message' => 'Email atau password tidak valid.'], 401);
        }
        $employee = Employee::withTrashed()->where('user_id', $user->id)->first();
        if ($employee && ($employee->trashed() || ! $employee->is_active)) {
            $this->audit->record('auth.login_blocked', 'user', $user->id, $user->branch_id, $user->id, null, null, $this->context($request, ['reason' => 'employee_inactive']));

            return response()->json(['message' => 'Akun petugas tidak aktif.'], 403);
        }
        $user->forceFill(['last_login_at' => now()])->save();
        [$token, $expiresAt] = $this->issueToken($user, $request->input('device_name', 'sqms-client'));
        $this->audit->record('auth.login', 'user', $user->id, $user->branch_id, $user->id, null, null, $this->context($request, ['device_name' => $request->input('device_name')]));

        return response()->json(['data' => ['user' => new UserResource($user), 'token' => $token, 'expires_at' => $expiresAt->toIso8601String()], 'message' => 'Login berhasil.']);
    }

    public function me(): UserResource
    {
        return new UserResource(auth()->user()->load(['roles', 'employee', 'branch', 'unit']));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email,'.$user->id],
        ], [
            'name.required' => 'Nama wajib diisi.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah digunakan akun lain.',
        ]);
        $user->update($validated);
        $this->audit->record('auth.profile_updated', 'user', $user->id, $user->branch_id, $user->id, null, null, $this->context($request));

        return response()->json(['data' => new UserResource($user->load(['roles', 'employee', 'branch', 'unit'])), 'message' => 'Profil berhasil diperbarui.']);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'current_password.required' => 'Password saat ini wajib diisi.',
            'password.required' => 'Password baru wajib diisi.',
            'password.min' => 'Password minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
        ]);
        if (! Hash::check($request->string('current_password'), $user->password)) {
            return response()->json(['errors' => ['current_password' => ['Password saat ini tidak sesuai.']]], 422);
        }
        $user->update(['password' => Hash::make($request->string('password'))]);
        $this->audit->record('auth.password_changed', 'user', $user->id, $user->branch_id, $user->id, null, null, $this->context($request));

        return response()->json(['message' => 'Password berhasil diubah.']);
    }

    /** Menukar token yang masih berlaku dengan token baru (masa berlaku diperbarui); token lama dicabut. */
    public function refresh(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();
        abort_unless($current instanceof PersonalAccessToken, 400, 'Token tidak dapat diperpanjang.');
        [$token, $expiresAt] = $this->issueToken($request->user(), $current->name);
        $current->delete();

        return response()->json(['data' => ['token' => $token, 'expires_at' => $expiresAt->toIso8601String()], 'message' => 'Token diperpanjang.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = auth()->user();
        $this->audit->record('auth.logout', 'user', $user->id, $user->branch_id, $user->id, null, null, $this->context($request));
        $user->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logout berhasil.']);
    }

    /** @return array{0: string, 1: \Illuminate\Support\Carbon} */
    private function issueToken(User $user, string $name): array
    {
        $expiresAt = now()->addMinutes((int) config('sanctum.staff_token_minutes', 720));

        return [$user->createToken($name, ['*'], $expiresAt)->plainTextToken, $expiresAt];
    }

    private function context(Request $request, array $extra = []): array
    {
        return array_filter(['ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 200)] + $extra, fn ($value) => $value !== null);
    }
}
