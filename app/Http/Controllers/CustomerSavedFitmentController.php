<?php

namespace App\Http\Controllers;

use App\Models\SavedFitment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer-portal "My Garage" — a saved size/brand profile a repeat B2B
 * buyer can reuse instead of re-entering search filters every visit. Plain
 * CRUD, scoped strictly to the logged-in customer's own rows.
 *
 *   GET    /api/v1/auth/customer/saved-fitments
 *   POST   /api/v1/auth/customer/saved-fitments
 *   DELETE /api/v1/auth/customer/saved-fitments/{id}
 */
class CustomerSavedFitmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $fitments = SavedFitment::where('customer_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data'    => $fitments->map(fn ($f) => $this->format($f))->values(),
            'message' => 'success',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'size'  => ['required', 'string', 'max:50'],
            'brand' => ['nullable', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $fitment = SavedFitment::create([
            'customer_id' => $request->user()->id,
            'size'        => $data['size'],
            'brand'       => $data['brand'] ?? null,
            'label'       => $data['label'] ?? null,
        ]);

        return response()->json([
            'data'    => $this->format($fitment),
            'message' => 'Fitment saved.',
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $fitment = SavedFitment::where('customer_id', $request->user()->id)->findOrFail($id);
        $fitment->delete();

        return response()->json(['message' => 'Fitment removed.']);
    }

    private function format(SavedFitment $f): array
    {
        return [
            'id'         => $f->id,
            'size'       => $f->size,
            'brand'      => $f->brand,
            'label'      => $f->label,
            'created_at' => $f->created_at?->toIso8601String(),
        ];
    }
}
