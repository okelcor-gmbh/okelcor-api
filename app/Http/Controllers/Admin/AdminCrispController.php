<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CrispService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile-app proxy to Crisp — the same four operations the existing
 * Next.js admin panel already performs directly against Crisp's REST API.
 * The mobile app can't hold Crisp credentials itself (extractable from a
 * decompiled app bundle), so it talks to these instead.
 *
 *   GET  /api/v1/admin/crisp/conversations
 *   GET  /api/v1/admin/crisp/conversations/{sessionId}/messages
 *   POST /api/v1/admin/crisp/conversations/{sessionId}/reply
 *   POST /api/v1/admin/crisp/conversations/{sessionId}/resolve
 */
class AdminCrispController extends Controller
{
    public function __construct(private CrispService $crisp) {}

    public function index(Request $request): JsonResponse
    {
        return $this->proxy(fn () => $this->crisp->listConversations($request->integer('page', 1)));
    }

    public function messages(string $sessionId): JsonResponse
    {
        return $this->proxy(fn () => $this->crisp->getMessages($sessionId));
    }

    public function reply(Request $request, string $sessionId): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        return $this->proxy(fn () => $this->crisp->sendMessage($sessionId, $data['body']));
    }

    public function resolve(string $sessionId): JsonResponse
    {
        return $this->proxy(function () use ($sessionId) {
            $this->crisp->resolveConversation($sessionId);
            return [];
        });
    }

    private function proxy(\Closure $call): JsonResponse
    {
        if (! $this->crisp->isConfigured()) {
            return response()->json([
                'message' => 'Live chat is not configured yet.',
                'code'    => 'crisp_not_configured',
            ], 503);
        }

        try {
            return response()->json(['data' => $call(), 'message' => 'success']);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Crisp is temporarily unavailable: ' . $e->getMessage(),
                'code'    => 'crisp_error',
            ], 502);
        }
    }
}
