<?php

namespace App\Services;

use App\Models\EbayOrderSyncLog;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EbayOrderSyncService
{
    public function __construct(private EbaySellingService $ebay) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Sync all orders modified within the last $days days.
     * Returns ['imported', 'updated', 'failed', 'skipped'].
     */
    public function syncRecent(int $days = 30): array
    {
        $stats = ['imported' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];

        try {
            $ebayOrders = $this->ebay->fetchOrders(now()->subDays($days));
        } catch (\Throwable $e) {
            Log::error('eBay order sync aborted — could not fetch orders', ['error' => $e->getMessage()]);
            EbayOrderSyncLog::create([
                'action'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            $stats['failed']  = 1;
            $stats['errors'][] = $e->getMessage();
            return $stats;
        }

        foreach ($ebayOrders as $ebayOrder) {
            $result = $this->processOne($ebayOrder);
            if (in_array($result, ['imported', 'updated', 'failed', 'skipped'], true)) {
                $stats[$result]++;
            }
            if ($result === 'failed') {
                $stats['errors'][] = $ebayOrder['orderId'] ?? 'unknown';
            }
        }

        return $stats;
    }

    /**
     * Sync a single eBay order by its orderId.
     * Returns ['imported', 'updated', 'failed', 'skipped'].
     */
    public function syncOne(string $ebayOrderId): array
    {
        $stats = ['imported' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];

        try {
            $ebayOrder = $this->ebay->fetchOrder($ebayOrderId);
        } catch (\Throwable $e) {
            Log::error('eBay single order fetch failed', [
                'ebay_order_id' => $ebayOrderId,
                'error'         => $e->getMessage(),
            ]);
            EbayOrderSyncLog::create([
                'ebay_order_id' => $ebayOrderId,
                'action'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            $stats['failed']   = 1;
            $stats['errors'][] = $e->getMessage();
            return $stats;
        }

        $result = $this->processOne($ebayOrder);
        $stats[$result]++;
        return $stats;
    }

    // -------------------------------------------------------------------------
    // Core processing
    // -------------------------------------------------------------------------

    private function processOne(array $ebayOrder): string
    {
        $ebayOrderId = $ebayOrder['orderId'] ?? null;

        if (! $ebayOrderId) {
            Log::warning('eBay order sync: skipping record with no orderId');
            EbayOrderSyncLog::create(['action' => 'skipped', 'status' => 'no_order_id']);
            return 'skipped';
        }

        try {
            $existing = Order::where('ebay_order_id', $ebayOrderId)->first();

            if ($existing) {
                $this->updateOrder($existing, $ebayOrder);
                return 'updated';
            }

            $this->importOrder($ebayOrder);
            return 'imported';
        } catch (\Throwable $e) {
            Log::error('eBay order sync: processing failed', [
                'ebay_order_id' => $ebayOrderId,
                'error'         => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);
            EbayOrderSyncLog::create([
                'ebay_order_id' => $ebayOrderId,
                'action'        => 'failed',
                'error_message' => $e->getMessage(),
                'payload_summary' => $this->buildPayloadSummary($ebayOrder),
            ]);
            return 'failed';
        }
    }

    // -------------------------------------------------------------------------
    // Import new order
    // -------------------------------------------------------------------------

    private function importOrder(array $eb): void
    {
        $ebayOrderId       = $eb['orderId'];
        $paymentStatus     = $this->mapPaymentStatus($eb['orderPaymentStatus'] ?? '');
        $orderStatus       = $this->mapOrderStatus($eb['orderFulfillmentStatus'] ?? '', $eb);
        $buyer             = $this->extractBuyer($eb);
        $shipping          = $this->extractShipping($eb);
        $lineItems         = $eb['lineItems'] ?? [];
        $total             = (float) ($eb['pricingSummary']['total']['value'] ?? 0);
        $deliveryCost      = (float) ($eb['pricingSummary']['deliveryCost']['value'] ?? 0);
        $subtotal          = round($total - $deliveryCost, 2);

        $order = DB::transaction(function () use (
            $eb, $ebayOrderId, $paymentStatus, $orderStatus,
            $buyer, $shipping, $lineItems, $total, $deliveryCost, $subtotal
        ) {
            $order = Order::create([
                'ref'                      => $this->generateRef(),
                'source'                   => 'ebay',
                'ebay_order_id'            => $ebayOrderId,
                'ebay_order_status'        => $eb['orderFulfillmentStatus'] ?? null,
                'ebay_payment_status'      => $eb['orderPaymentStatus'] ?? null,
                'ebay_fulfillment_status'  => $eb['orderFulfillmentStatus'] ?? null,
                'ebay_buyer_username'      => $buyer['username'],
                'ebay_last_synced_at'      => now(),
                'ebay_raw_summary'         => $this->buildPayloadSummary($eb),
                'customer_name'            => $buyer['full_name'] ?? $buyer['username'] ?? 'eBay Buyer',
                'customer_email'           => $buyer['email'],
                'customer_phone'           => null,
                'address'                  => $shipping['address_line1'],
                'city'                     => $shipping['city'],
                'postal_code'              => $shipping['postal_code'],
                'country'                  => $shipping['country_code'],
                'payment_method'           => 'ebay',
                'payment_status'           => $paymentStatus,
                'status'                   => $orderStatus,
                'subtotal'                 => $subtotal,
                'delivery_cost'            => $deliveryCost,
                'total'                    => $total,
                'mode'                     => 'manual',
            ]);

            foreach ($lineItems as $item) {
                $unitPrice = (float) ($item['lineItemCost']['value'] ?? 0);
                $quantity  = (int) ($item['quantity'] ?? 1);

                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => null,
                    'sku'        => $item['sku'] ?? $item['lineItemId'] ?? '',
                    'brand'      => '',
                    'name'       => $item['title'] ?? $item['legacyVariationName'] ?? 'eBay item',
                    'size'       => '',
                    'unit_price' => $unitPrice,
                    'quantity'   => $quantity,
                    'line_total' => round($unitPrice * $quantity, 2),
                ]);
            }

            return $order;
        });

        if (in_array($orderStatus, ['shipped', 'delivered'], true)) {
            $this->enrichCarrierFromEbay($order, $ebayOrderId);
        }

        EbayOrderSyncLog::create([
            'ebay_order_id'   => $ebayOrderId,
            'order_id'        => $order->id,
            'action'          => 'imported',
            'status'          => $paymentStatus,
            'payload_summary' => $this->buildPayloadSummary($eb),
        ]);

        Log::info('eBay order imported', [
            'ebay_order_id' => $ebayOrderId,
            'order_ref'     => $order->ref,
        ]);
    }

    // -------------------------------------------------------------------------
    // Update existing order
    // -------------------------------------------------------------------------

    private function updateOrder(Order $order, array $eb): void
    {
        $paymentStatus = $this->mapPaymentStatus($eb['orderPaymentStatus'] ?? '');
        $orderStatus   = $this->mapOrderStatus($eb['orderFulfillmentStatus'] ?? '', $eb);

        // Safety: only advance payment_status to paid — never downgrade a confirmed paid order
        $updatedPaymentStatus = $order->payment_status === 'paid'
            ? 'paid'
            : $paymentStatus;

        // Safety: do not override if already shipped/delivered
        $protectedStatuses  = ['shipped', 'delivered'];
        $updatedOrderStatus = in_array($order->status, $protectedStatuses, true)
            ? $order->status
            : $orderStatus;

        $order->update([
            'ebay_order_status'       => $eb['orderFulfillmentStatus'] ?? null,
            'ebay_payment_status'     => $eb['orderPaymentStatus'] ?? null,
            'ebay_fulfillment_status' => $eb['orderFulfillmentStatus'] ?? null,
            'ebay_last_synced_at'     => now(),
            'ebay_raw_summary'        => $this->buildPayloadSummary($eb),
            'payment_status'          => $updatedPaymentStatus,
            'status'                  => $updatedOrderStatus,
        ]);

        if (in_array($updatedOrderStatus, ['shipped', 'delivered'], true)) {
            $this->enrichCarrierFromEbay($order, $eb['orderId']);
        }

        EbayOrderSyncLog::create([
            'ebay_order_id'   => $eb['orderId'],
            'order_id'        => $order->id,
            'action'          => 'updated',
            'status'          => $updatedPaymentStatus,
            'payload_summary' => $this->buildPayloadSummary($eb),
        ]);
    }

    /**
     * Pull carrier + tracking number from eBay's own shipping fulfillment
     * record (whatever was used to mark the order shipped — whether that
     * happened via our system or manually in eBay's Seller Hub) and backfill
     * the order's carrier/tracking_number fields if they're not already set.
     *
     * Never overrides a value staff already entered — this only fills gaps,
     * so an admin's manual entry always wins. Best-effort: logs and moves on
     * on any failure, never breaks the sync.
     */
    private function enrichCarrierFromEbay(Order $order, string $ebayOrderId): void
    {
        if ($order->carrier && $order->tracking_number) {
            return;
        }

        try {
            $data        = $this->ebay->fetchShippingFulfillments($ebayOrderId);
            $fulfillment = $data['fulfillments'][0] ?? null;

            if (! $fulfillment) {
                return;
            }

            $carrier        = $fulfillment['shippingCarrierCode'] ?? null;
            $trackingNumber = $fulfillment['shipmentTrackingNumber'] ?? null;

            if (! $carrier && ! $trackingNumber) {
                return;
            }

            $order->update(array_filter([
                'carrier'         => $order->carrier ?: $carrier,
                'tracking_number' => $order->tracking_number ?: $trackingNumber,
            ], fn ($v) => $v !== null));

            Log::info('eBay carrier/tracking backfilled from shipping fulfillment', [
                'ebay_order_id' => $ebayOrderId,
                'order_ref'     => $order->ref,
                'carrier'       => $carrier,
            ]);
        } catch (\Throwable $e) {
            Log::warning('eBay shipping fulfillment fetch failed (non-blocking)', [
                'ebay_order_id' => $ebayOrderId,
                'order_ref'     => $order->ref,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Status mapping
    // -------------------------------------------------------------------------

    public function mapPaymentStatus(string $ebayStatus): string
    {
        return match (strtoupper($ebayStatus)) {
            'PAID'               => 'paid',
            'FAILED'             => 'failed',
            'FULLY_REFUNDED'     => 'refunded',
            'PARTIALLY_REFUNDED' => 'paid',
            default              => 'pending',
        };
    }

    public function mapOrderStatus(string $fulfillmentStatus, array $ebayOrder = []): string
    {
        $cancelled = strtoupper($ebayOrder['orderPaymentStatus'] ?? '') === 'FULLY_REFUNDED';

        if ($cancelled) {
            return 'cancelled';
        }

        return match (strtoupper($fulfillmentStatus)) {
            'NOT_STARTED' => 'confirmed',
            'IN_PROGRESS' => 'processing',
            'FULFILLED'   => 'shipped',
            'CANCELLED'   => 'cancelled',
            default       => 'confirmed',
        };
    }

    // -------------------------------------------------------------------------
    // Data extraction helpers
    // -------------------------------------------------------------------------

    private function extractBuyer(array $eb): array
    {
        $buyer    = $eb['buyer'] ?? [];
        $address  = $buyer['buyerRegistrationAddress'] ?? [];
        $contact  = $address['contactAddress'] ?? [];

        return [
            'username'  => $buyer['username'] ?? null,
            'full_name' => $address['fullName'] ?? null,
            'email'     => $address['email'] ?? null,
            'city'      => $contact['city'] ?? null,
            'country'   => $contact['countryCode'] ?? null,
        ];
    }

    private function extractShipping(array $eb): array
    {
        $instructions = $eb['fulfillmentStartInstructions'][0] ?? [];
        $shipTo       = $instructions['shippingStep']['shipTo'] ?? [];
        $contact      = $shipTo['contactAddress'] ?? [];

        return [
            'full_name'    => $shipTo['fullName'] ?? null,
            'address_line1' => $contact['addressLine1'] ?? ($contact['addressLine2'] ?? null),
            'city'         => $contact['city'] ?? null,
            'postal_code'  => $contact['postalCode'] ?? null,
            'country_code' => $contact['countryCode'] ?? null,
        ];
    }

    /**
     * Build a minimal, non-PII payload summary for the sync log and order record.
     */
    private function buildPayloadSummary(array $eb): array
    {
        return [
            'payment_status'     => $eb['orderPaymentStatus'] ?? null,
            'fulfillment_status' => $eb['orderFulfillmentStatus'] ?? null,
            'creation_date'      => $eb['creationDate'] ?? null,
            'last_modified_date' => $eb['lastModifiedDate'] ?? null,
            'line_item_count'    => count($eb['lineItems'] ?? []),
            'currency'           => $eb['pricingSummary']['total']['currency'] ?? null,
            'total_amount'       => $eb['pricingSummary']['total']['value'] ?? null,
        ];
    }

    private function generateRef(): string
    {
        $timestamp = strtoupper(base_convert(substr((string) now()->timestamp, -5), 10, 36));
        $rand      = strtoupper(Str::random(3));

        return "OKL-{$timestamp}{$rand}";
    }
}
