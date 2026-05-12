<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminWelcome;
use App\Models\AdminUser;
use App\Services\AdminAuditLogger;
use App\Support\AdminPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminUserController extends Controller
{
    // -------------------------------------------------------------------------
    // Own profile — available to all authenticated admin roles
    // -------------------------------------------------------------------------

    public function profile(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->formatUser($request->user())]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'         => ['sometimes', 'string', 'max:200'],
            'first_name'   => ['sometimes', 'string', 'max:100'],
            'last_name'    => ['sometimes', 'string', 'max:100'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email'        => ['sometimes', 'email', 'max:255', Rule::unique('admin_users', 'email')->ignore($user->id)],
        ]);

        $user->update($validated);

        return response()->json([
            'data'    => $this->formatUser($user->fresh()),
            'message' => 'Profile updated.',
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'The current password is incorrect.',
                'errors'  => ['current_password' => ['The current password is incorrect.']],
            ], 422);
        }

        $user->update([
            'password'            => $request->password,
            'must_change_password' => false,
        ]);

        // Revoke all other active sessions
        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        return response()->json([
            'data'    => ['user' => $this->formatUser($user->fresh())],
            'message' => 'Password changed successfully.',
        ]);
    }

    // -------------------------------------------------------------------------
    // User management — super_admin only
    // -------------------------------------------------------------------------

    public function index(): JsonResponse
    {
        $users = AdminUser::orderBy('name')->get();

        return response()->json([
            'data'    => $users->map(fn ($u) => $this->formatUser($u)),
            'message' => 'success',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:200'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name'  => ['nullable', 'string', 'max:100'],
            'email'      => ['required', 'email', 'max:255', 'unique:admin_users,email'],
            'role'       => ['required', Rule::in(AdminPermissions::ROLES)],
        ]);

        $temporaryPassword = Str::password(16);

        $user = AdminUser::create([
            ...$validated,
            'password'            => $temporaryPassword,
            'must_change_password' => true,
        ]);

        $emailSent = true;
        try {
            Mail::to($user->email)->send(new AdminWelcome($user, $temporaryPassword));
        } catch (\Throwable $e) {
            $emailSent = false;
            Log::error('Admin welcome email failed for user ' . $user->id . ': ' . $e->getMessage());
        }

        $message = $emailSent
            ? 'Admin user created. A welcome email with login instructions has been sent.'
            : 'Admin user created. Welcome email could not be delivered — use the resend-credentials endpoint to retry.';

        AdminAuditLogger::info('admin_created', "Admin user created: {$user->email} ({$user->role})", $request, $request->user(), [
            'new_admin_id'   => $user->id,
            'new_admin_email' => $user->email,
            'role'           => $user->role,
        ]);

        return response()->json([
            'data'         => $this->formatUser($user),
            'message'      => $message,
            'email_sent'   => $emailSent,
        ], 201);
    }

    public function resendCredentials(int $id): JsonResponse
    {
        $user = AdminUser::findOrFail($id);

        $temporaryPassword = Str::password(16);

        $user->update([
            'password'             => $temporaryPassword,
            'must_change_password' => true,
        ]);

        try {
            Mail::to($user->email)->send(new AdminWelcome($user, $temporaryPassword));
        } catch (\Throwable $e) {
            Log::error('Admin credentials resend failed for user ' . $user->id . ': ' . $e->getMessage());
            return response()->json([
                'message' => 'Credentials reset but email delivery failed. Check mail driver configuration.',
            ], 502);
        }

        return response()->json([
            'message' => 'New temporary credentials sent to ' . $user->email . '.',
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->formatUser(AdminUser::findOrFail($id))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = AdminUser::findOrFail($id);

        $validated = $request->validate([
            'name'         => ['sometimes', 'string', 'max:200'],
            'first_name'   => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name'    => ['sometimes', 'nullable', 'string', 'max:100'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email'        => ['sometimes', 'email', 'max:255', Rule::unique('admin_users', 'email')->ignore($id)],
            'role'         => ['sometimes', Rule::in(AdminPermissions::ROLES)],
            'password'     => ['sometimes', 'confirmed', Password::min(8)->letters()->numbers()],
            'is_active'    => ['sometimes', 'boolean'],
        ]);

        $oldRole = $user->role;
        $user->update($validated);

        if (isset($validated['password'])) {
            $user->tokens()->delete();
        }

        if (isset($validated['role']) && $validated['role'] !== $oldRole) {
            AdminAuditLogger::warning('role_changed', "Admin role changed for {$user->email}", $request, $request->user(), [
                'target_admin_id' => $user->id,
                'old_role'        => $oldRole,
                'new_role'        => $validated['role'],
            ]);
        }

        return response()->json([
            'data'    => $this->formatUser($user->fresh()),
            'message' => 'Admin user updated.',
        ]);
    }

    public function destroy(Request $request, int $id): Response
    {
        if ($request->user()->id === $id) {
            abort(422, 'You cannot delete your own account.');
        }

        $user = AdminUser::findOrFail($id);
        $email = $user->email;
        $role  = $user->role;
        $user->tokens()->delete();
        $user->delete();

        AdminAuditLogger::critical('admin_deleted', "Admin user deleted: {$email} ({$role})", $request, $request->user(), [
            'deleted_admin_id'    => $id,
            'deleted_admin_email' => $email,
            'deleted_admin_role'  => $role,
        ]);

        return response()->noContent();
    }

    // -------------------------------------------------------------------------

    private function formatUser(AdminUser $u): array
    {
        return [
            'id'                  => $u->id,
            'name'                => $u->name,
            'first_name'          => $u->first_name,
            'last_name'           => $u->last_name,
            'display_name'        => $u->display_name,
            'email'               => $u->email,
            'role'                => $u->role,
            'role_label'          => AuthController::roleLabel($u->role),
            'is_active'              => (bool) $u->is_active,
            'must_change_password'   => (bool) $u->must_change_password,
            'two_factor_enabled'     => $u->hasTwoFactorEnabled(),
            'two_factor_enabled_at'  => $u->two_factor_confirmed_at?->toIso8601String(),
            'permissions'            => AdminPermissions::for($u->role),
            'last_login_at'          => $u->last_login_at?->toIso8601String(),
            'created_at'             => $u->created_at?->toIso8601String(),
        ];
    }
}
