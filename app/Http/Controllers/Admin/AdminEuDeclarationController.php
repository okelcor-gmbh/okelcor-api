<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EuDeclaration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminEuDeclarationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EuDeclaration::orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($sub) use ($q) {
                $sub->where('order_ref', 'like', "%{$q}%")
                    ->orWhere('company_name', 'like', "%{$q}%")
                    ->orWhere('customer_email', 'like', "%{$q}%")
                    ->orWhere('vat_number', 'like', "%{$q}%");
            });
        }

        $perPage   = min((int) $request->input('per_page', 25), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data'    => $paginated->map(fn ($d) => $this->formatList($d))->values(),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
            'message' => 'success',
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $decl = EuDeclaration::with(['order', 'invoice'])->findOrFail($id);

        return response()->json([
            'data'    => $this->formatDetail($decl),
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // Formatters
    // -------------------------------------------------------------------------

    private function formatList(EuDeclaration $d): array
    {
        return [
            'id'             => $d->id,
            'order_ref'      => $d->order_ref,
            'company_name'   => $d->company_name,
            'customer_email' => $d->customer_email,
            'country'        => $d->country,
            'vat_number'     => $d->vat_number,
            'status'         => $d->status,
            'signed_at'      => $d->signed_at?->toIso8601String(),
            'created_at'     => $d->created_at?->toIso8601String(),
        ];
    }

    private function formatDetail(EuDeclaration $d): array
    {
        return [
            'id'                         => $d->id,
            'order_id'                   => $d->order_id,
            'order_ref'                  => $d->order_ref,
            'customer_id'                => $d->customer_id,
            'invoice_id'                 => $d->invoice_id,
            'invoice_number'             => $d->invoice?->invoice_number,

            // Snapshot
            'company_name'               => $d->company_name,
            'customer_email'             => $d->customer_email,
            'customer_address'           => $d->customer_address,
            'vat_number'                 => $d->vat_number,
            'country'                    => $d->country,
            'goods_description'          => $d->goods_description,
            'quantity_description'       => $d->quantity_description,

            // Signing fields (null until customer submits)
            'member_state_of_entry'      => $d->member_state_of_entry,
            'place_of_entry'             => $d->place_of_entry,
            'month_year_received'        => $d->month_year_received,
            'self_transported'           => $d->self_transported,
            'month_year_transport_ended' => $d->month_year_transport_ended,
            'representative_name'        => $d->representative_name,
            'representative_title'       => $d->representative_title,
            'signed_name'                => $d->signed_name,
            'accepted_terms'             => $d->accepted_terms,
            'issue_date'                 => $d->issue_date?->toDateString(),
            'signed_at'                  => $d->signed_at?->toIso8601String(),

            // Files
            'has_signature'              => (bool) $d->signature_path,
            'has_pdf'                    => (bool) $d->pdf_path,

            // Workflow
            'status'                     => $d->status,
            'admin_acknowledged_at'      => $d->admin_acknowledged_at?->toIso8601String(),

            'created_at'                 => $d->created_at?->toIso8601String(),
            'updated_at'                 => $d->updated_at?->toIso8601String(),
        ];
    }
}
