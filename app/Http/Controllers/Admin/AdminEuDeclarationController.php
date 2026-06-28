<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\FinalInvoiceReleased;
use App\Models\EuDeclaration;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLog;
use App\Services\CustomerNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminEuDeclarationController extends Controller
{
    // A declaration is overdue when it has been pending for more than this many days.
    private const OVERDUE_DAYS = 14;

    public function index(Request $request): JsonResponse
    {
        $query = EuDeclaration::orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('country')) {
            $query->where('country', $request->country);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->boolean('overdue')) {
            // Only restrict to pending if the caller hasn't already applied a status filter.
            // Without this guard, status=acknowledged&overdue=true would generate
            // WHERE status='acknowledged' AND status='pending' and return 0 rows.
            if (! $request->filled('status')) {
                $query->where('status', 'pending');
            }
            $query->where('created_at', '<=', now()->subDays(self::OVERDUE_DAYS));
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

    /**
     * GET /api/v1/admin/eu-declarations/{id}/download
     *
     * Admin downloads the signed EU entry certificate PDF from the private disk.
     */
    public function download(int $id): BinaryFileResponse|JsonResponse
    {
        $decl = EuDeclaration::findOrFail($id);

        if (! in_array($decl->status, ['signed', 'acknowledged'])) {
            return response()->json(['message' => 'Declaration has not been signed yet.'], 404);
        }

        if (! $decl->pdf_path) {
            Log::warning('Admin EU declaration download: pdf_path is null', [
                'declaration_id' => $decl->id,
                'order_ref'      => $decl->order_ref,
            ]);
            return response()->json(['message' => 'Declaration PDF is not available yet.'], 404);
        }

        $path = storage_path('app/private/' . $decl->pdf_path);

        if (! file_exists($path)) {
            Log::warning('Admin EU declaration download: file missing on disk', [
                'declaration_id' => $decl->id,
                'order_ref'      => $decl->order_ref,
                'pdf_path'       => $decl->pdf_path,
            ]);
            return response()->json(['message' => 'Declaration PDF file was not found.'], 404);
        }

        return response()->download($path, 'EU-Entry-Certificate-' . $decl->order_ref . '.pdf');
    }

    /**
     * POST /api/v1/admin/eu-declarations/{id}/acknowledge
     *
     * Admin marks a signed declaration as acknowledged. Status must be 'signed'.
     */
    public function acknowledge(Request $request, int $id): JsonResponse
    {
        $decl = EuDeclaration::with('invoice')->findOrFail($id);

        if ($decl->status !== 'signed') {
            $msg = $decl->status === 'pending'
                ? 'Declaration has not been signed yet.'
                : 'Declaration is already acknowledged.';
            return response()->json(['message' => $msg], 409);
        }

        $decl->update([
            'status'                  => 'acknowledged',
            'admin_acknowledged_at'   => now(),
            'admin_acknowledged_by'   => $request->user()->id,
        ]);

        // Release the linked invoice and notify the customer.
        // invoice_id may be null on pre-2B-2 declarations; fall back to order_ref lookup.
        $invoice = $decl->invoice ?? Invoice::where('order_ref', $decl->order_ref)->first();

        if ($invoice && ! $invoice->released_at) {
            $invoice->update(['released_at' => now()]);

            Log::info('Invoice released after EU declaration acknowledged', [
                'invoice_number' => $invoice->invoice_number,
                'order_ref'      => $decl->order_ref,
                'declaration_id' => $decl->id,
            ]);

            try {
                Mail::to($decl->customer_email)->send(new FinalInvoiceReleased($decl->fresh(), $invoice));
            } catch (\Throwable $e) {
                Log::error('FinalInvoiceReleased email failed', [
                    'order_ref'      => $decl->order_ref,
                    'declaration_id' => $decl->id,
                    'error'          => $e->getMessage(),
                ]);
            }

            // In-app twin of the released-invoice email (Email = Inbox).
            CustomerNotifier::notifyByEmail(
                $decl->customer_email,
                'document_ready',
                "Invoice {$invoice->invoice_number} is ready",
                'Your final invoice is now available to download in your account.',
                [
                    'severity'     => 'info',
                    'action_url'   => '/account/invoices',
                    'related_type' => 'trade_document',
                    'related_id'   => $invoice->invoice_number,
                    'email_sent'   => true,
                    'metadata'     => ['order_ref' => $decl->order_ref, 'invoice_number' => $invoice->invoice_number],
                ]
            );
        }

        // Audit log on the associated order
        $order = Order::where('ref', $decl->order_ref)->first();
        if ($order) {
            try {
                OrderLog::create([
                    'order_id'         => $order->id,
                    'order_ref'        => $order->ref,
                    'admin_user_id'    => $request->user()->id,
                    'admin_user_email' => $request->user()->email,
                    'action'           => 'declaration_acknowledged',
                    'new_value'        => 'acknowledged',
                    'notes'            => 'EU entry certificate acknowledged. Declaration #' . $decl->id . '.',
                    'ip_address'       => $request->ip(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('OrderLog write failed (declaration acknowledge)', [
                    'order_ref'      => $decl->order_ref,
                    'declaration_id' => $decl->id,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'data'    => $this->formatDetail($decl->fresh()),
            'message' => 'Declaration acknowledged.',
        ]);
    }

    // -------------------------------------------------------------------------
    // Formatters
    // -------------------------------------------------------------------------

    private function formatList(EuDeclaration $d): array
    {
        $daysPending = $d->status === 'pending' && $d->created_at
            ? (int) $d->created_at->diffInDays(now())
            : null;

        return [
            'id'               => $d->id,
            'order_ref'        => $d->order_ref,
            'company_name'     => $d->company_name,
            'customer_email'   => $d->customer_email,
            'country'          => $d->country,
            'vat_number'       => $d->vat_number,
            'status'           => $d->status,
            'days_pending'     => $daysPending,
            'is_overdue'       => $daysPending !== null && $daysPending >= self::OVERDUE_DAYS,
            'last_reminded_at' => $d->last_reminded_at?->toIso8601String(),
            'reminder_count'   => $d->reminder_count,
            'signed_at'        => $d->signed_at?->toIso8601String(),
            'created_at'       => $d->created_at?->toIso8601String(),
        ];
    }

    private function formatDetail(EuDeclaration $d): array
    {
        return [
            'id'                         => $d->id,
            'declaration_id'             => $d->id,
            'order_id'                   => $d->order_id,
            'order_ref'                  => $d->order_ref,
            'customer_id'                => $d->customer_id,
            'invoice_id'                 => $d->invoice_id,
            'invoice_number'             => $d->invoice?->invoice_number,

            // Snapshot — combined and individual address parts
            'company_name'               => $d->company_name,
            'customer_email'             => $d->customer_email,
            'customer_address'           => $d->customer_address,
            'street'                     => $d->street,
            'city'                       => $d->city,
            'postal_code'                => $d->postal_code,
            'country'                    => $d->country,
            'vat_number'                 => $d->vat_number,
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
            'acknowledged_at'            => $d->admin_acknowledged_at?->toIso8601String(),
            'admin_acknowledged_at'      => $d->admin_acknowledged_at?->toIso8601String(),
            'admin_acknowledged_by'      => $d->admin_acknowledged_by,

            'created_at'                 => $d->created_at?->toIso8601String(),
            'updated_at'                 => $d->updated_at?->toIso8601String(),
        ];
    }
}
