<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingContact;
use App\Services\MarketingContactImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMarketingContactController extends Controller
{
    // -------------------------------------------------------------------------
    // POST /api/v1/admin/marketing-contacts/import — marketing.manage
    // -------------------------------------------------------------------------
    public function import(Request $request, MarketingContactImportService $service): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        set_time_limit(300);

        $path = $request->file('file')->getRealPath();

        try {
            $result = $service->import($path);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data'    => $result,
            'message' => "{$result['imported']} contacts imported, {$result['updated']} updated.",
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/marketing-contacts — marketing.manage
    // -------------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'   => ['nullable', 'in:subscribed,unsubscribed,unknown'],
            'company'  => ['nullable', 'string', 'max:150'],
            'country'  => ['nullable', 'string', 'max:100'],
            'search'   => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = MarketingContact::query()->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('company')) {
            $query->where('company', 'like', '%' . $request->company . '%');
        }
        if ($request->filled('country')) {
            $query->where('country', $request->country);
        }
        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('email', 'like', $term)
                  ->orWhere('first_name', 'like', $term)
                  ->orWhere('last_name', 'like', $term)
                  ->orWhere('company', 'like', $term);
            });
        }

        $perPage   = $request->integer('per_page', 50);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/marketing-contacts/stats — marketing.manage
    // -------------------------------------------------------------------------
    public function stats(): JsonResponse
    {
        return response()->json([
            'data' => [
                'total'        => MarketingContact::count(),
                'subscribed'   => MarketingContact::where('status', 'subscribed')->count(),
                'unsubscribed' => MarketingContact::where('status', 'unsubscribed')->count(),
                'unknown'      => MarketingContact::where('status', 'unknown')->count(),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/marketing-contacts/{id} — marketing.manage
    // -------------------------------------------------------------------------
    public function destroy(int $id): JsonResponse
    {
        $contact = MarketingContact::findOrFail($id);
        $contact->delete();

        return response()->json(['message' => 'Contact removed.']);
    }
}
