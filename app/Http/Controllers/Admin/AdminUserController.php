<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminWelcome;
use App\Models\AdminUser;
use App\Services\AdminAuditLogger;
use App\Services\RichEmailHtmlSanitizer;
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

    /**
     * PUT /admin/profile/signature
     *
     * The signature is pasted in exactly as it appears in Outlook (rich
     * formatting + an inline logo image). Sanitized + inline images
     * extracted to storage by RichEmailHtmlSanitizer before being saved —
     * the raw pasted HTML is never persisted as-is.
     */
    public function updateSignature(Request $request, RichEmailHtmlSanitizer $sanitizer): JsonResponse
    {
        $data = $request->validate([
            // Generous cap on the RAW paste (pre-sanitize, pre-image-extraction)
            // — Outlook's verbose inline-style markup plus a base64 logo can run
            // large before anything has gone wrong.
            'signature_html' => ['nullable', 'string', 'max:204800'],
        ]);

        $user = $request->user();
        $raw  = $data['signature_html'] ?? '';

        try {
            $clean = $raw === '' ? '' : $sanitizer->sanitize($raw, "signatures/{$user->id}");
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $user->update(['email_signature' => $clean ?: null]);

        return response()->json([
            'data'    => ['email_signature' => $user->email_signature],
            'message' => 'Signature updated.',
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'confirmed', Password::defaults()],
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
            // What the person does, as distinct from what they may open. Free
            // text and optional: the role is a permission set and describes
            // nobody's job — two order managers and the person running
            // operations all hold `admin` because all three need customers,
            // campaigns and quote requests.
            'job_title'  => ['nullable', 'string', 'max:60'],
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
            'job_title'    => ['sometimes', 'nullable', 'string', 'max:60'],
            'password'     => ['sometimes', 'confirmed', Password::defaults()],
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
            // Always present, falling back to a tidied role so nothing renders
            // blank. `job_title_set` says which of the two you are looking at,
            // so the panel can prompt for a real one rather than silently
            // passing a permission off as a job description.
            'job_title'           => $u->jobTitle(),
            'job_title_set'       => $u->hasJobTitle(),
            'is_active'              => (bool) $u->is_active,
            'must_change_password'   => (bool) $u->must_change_password,
            'two_factor_enabled'     => $u->hasTwoFactorEnabled(),
            'two_factor_enabled_at'  => $u->two_factor_confirmed_at?->toIso8601String(),
            'permissions'            => $u->effectivePermissions(),
            // The override halves, so the editor can show "from role" vs
            // "added/removed for this person" instead of one opaque list.
            'permission_grants'      => array_values((array) ($u->permission_grants ?? [])),
            'permission_revokes'     => array_values((array) ($u->permission_revokes ?? [])),
            'has_permission_overrides' => $u->hasPermissionOverrides(),
            'last_login_at'          => $u->last_login_at?->toIso8601String(),
            'created_at'             => $u->created_at?->toIso8601String(),
            'email_signature'        => $u->email_signature,
        ];
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/permissions/catalog — admins.manage
    //
    // Everything the permission editor needs to render: every permission key,
    // the roles holding it by default, and the full role list. The catalog is
    // code, not data — it changes only on deploy — so the panel never has to
    // hardcode a permission name again.
    // -------------------------------------------------------------------------
    public function permissionsCatalog(): JsonResponse
    {
        $permissions = [];
        foreach (AdminPermissions::MAP as $key => $roles) {
            $permissions[] = [
                'key'   => $key,
                // "orders.signoff_finance" → group "orders"
                'group' => explode('.', $key)[0],
                'roles' => array_values($roles),
            ];
        }

        return response()->json([
            'data' => [
                'permissions' => $permissions,
                'roles'       => AdminPermissions::ROLES,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/admin/users/{id}/permissions — admins.manage
    //
    // Replaces the target's override sets wholesale (send the full lists each
    // time — no diff protocol to get wrong). Sending two empty arrays returns
    // the person to exactly their role.
    // -------------------------------------------------------------------------
    public function updatePermissions(Request $request, int $id): JsonResponse
    {
        $known = array_keys(AdminPermissions::MAP);

        $data = $request->validate([
            'grants'    => ['present', 'array'],
            'grants.*'  => ['string', \Illuminate\Validation\Rule::in($known)],
            'revokes'   => ['present', 'array'],
            'revokes.*' => ['string', \Illuminate\Validation\Rule::in($known)],
        ]);

        $user = AdminUser::findOrFail($id);

        if ($user->role === 'super_admin') {
            return response()->json([
                'message' => 'super_admin access is fixed and cannot be overridden — the role is the break-glass account.',
                'code'    => 'super_admin_immutable',
            ], 422);
        }

        $grants  = array_values(array_unique($data['grants']));
        $revokes = array_values(array_unique($data['revokes']));

        if ($overlap = array_intersect($grants, $revokes)) {
            return response()->json([
                'message' => 'A permission cannot be both granted and revoked: ' . implode(', ', $overlap),
                'code'    => 'grant_revoke_conflict',
            ], 422);
        }

        // Store only real overrides: a grant the role already holds, or a
        // revoke of something the role never had, is noise that would show
        // every such user as "customized".
        $base    = AdminPermissions::for($user->role);
        $grants  = array_values(array_diff($grants, $base));
        $revokes = array_values(array_intersect($revokes, $base));

        $user->forceFill([
            'permission_grants'  => $grants,
            'permission_revokes' => $revokes,
        ])->save();

        AdminAuditLogger::warning('admin_permissions_changed', "Permission overrides changed for {$user->email} ({$user->role})", $request, $request->user(), [
            'target_admin_id' => $user->id,
            'grants'          => $grants,
            'revokes'         => $revokes,
        ]);

        return response()->json([
            'data'    => $this->formatUser($user->fresh()),
            'message' => ($grants === [] && $revokes === [])
                ? "{$user->name} is back to the standard {$user->role} access."
                : "Permissions updated for {$user->name}.",
        ]);
    }
}
