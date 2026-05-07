<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\EuDeclaration;
use App\Models\Invoice;
use App\Models\Order;

class EuDeclarationService
{
    /**
     * Returns true when the order requires an EU intra-community entry certificate.
     *
     * All three conditions must hold:
     *   - is_reverse_charge (already encodes EU + B2B + non-DE)
     *   - tax_treatment = reverse_charge (explicit double-check)
     *   - vat_valid = truthy (VIES validation passed at order time)
     */
    public function shouldRequireForOrder(Order $order): bool
    {
        return $order->is_reverse_charge === true
            && $order->tax_treatment === 'reverse_charge'
            && (bool) $order->vat_valid;
    }

    /**
     * Idempotent — returns the existing declaration if one already exists for this order.
     * If existing but invoice_id not yet set, links it now.
     *
     * Snapshots goods and customer data at creation time.
     * Do NOT call Order relationships inside a DB transaction — load items first.
     */
    public function createForOrder(Order $order, ?Invoice $invoice = null): EuDeclaration
    {
        $existing = EuDeclaration::where('order_id', $order->id)->first();

        if ($existing) {
            if ($invoice && ! $existing->invoice_id) {
                $existing->update(['invoice_id' => $invoice->id]);
            }
            return $existing;
        }

        $order->loadMissing('items');

        // Resolve customer account — needed for company_name
        $customer    = Customer::where('email', $order->customer_email)->first();
        $companyName = $customer?->company_name ?? $order->customer_name;

        // Build goods + quantity descriptions from items
        [$goodsDescription, $quantityDescription] = $this->buildGoodsDescription($order);

        // Snapshot delivery address from order fields
        $customerAddress = implode(', ', array_filter([
            $order->address,
            $order->city,
            $order->postal_code,
            $order->country,
        ])) ?: null;

        return EuDeclaration::create([
            'order_id'             => $order->id,
            'customer_id'          => $customer?->id,
            'invoice_id'           => $invoice?->id,
            'order_ref'            => $order->ref,
            'company_name'         => $companyName,
            'customer_email'       => $order->customer_email,
            'customer_address'     => $customerAddress,
            'vat_number'           => (string) ($order->vat_number ?? ''),
            'country'              => $order->country,
            'goods_description'    => $goodsDescription,
            'quantity_description' => $quantityDescription,
            'status'               => 'pending',
        ]);
    }

    // -------------------------------------------------------------------------

    private function buildGoodsDescription(Order $order): array
    {
        $lines    = [];
        $totalQty = 0;

        foreach ($order->items as $item) {
            $qty      = (int) $item->quantity;
            $totalQty += $qty;
            $desc     = trim(implode(' ', array_filter([
                $item->brand,
                $item->name,
                $item->size,
            ])));
            $lines[] = "{$qty}× {$desc}";
        }

        if (empty($lines)) {
            return ['Tyres', null];
        }

        $goodsDescription    = implode("\n", $lines);
        $quantityDescription = "{$totalQty} pcs — " . implode('; ', $lines);

        // Trim to column limit
        if (strlen($quantityDescription) > 300) {
            $quantityDescription = mb_substr($quantityDescription, 0, 297) . '...';
        }

        return [$goodsDescription, $quantityDescription];
    }
}
