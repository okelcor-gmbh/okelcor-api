<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Device registration for the admin/ops companion mobile app's push
 * notifications (see FRONTEND_NOTE_admin-mobile-app.md, ExpoPushService).
 *
 *   POST   /api/v1/admin/push-tokens   { token, platform }
 *   DELETE /api/v1/admin/push-tokens   { token }
 *
 * Upserts on `token`, not `admin_id` — a device belongs to whoever most
 * recently logged into the app on it (handles a shared/reissued device
 * cleanly: the old admin stops receiving pushes there the moment a
 * different admin registers the same token).
 */
class AdminPushTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'    => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(['ios', 'android'])],
        ]);

        AdminPushToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'admin_id'     => $request->user()->id,
                'platform'     => $data['platform'],
                'last_seen_at' => now(),
            ]
        );

        return response()->json(['message' => 'Push token registered.'], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        AdminPushToken::where('token', $data['token'])
            ->where('admin_id', $request->user()->id)
            ->delete();

        return response()->json(['message' => 'Push token removed.']);
    }
}
