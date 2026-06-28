<?php

namespace Tests\Feature;

use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\InvoiceDownloadController;
use App\Http\Controllers\OrderController;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Customer-facing invoice listing + download.
 *
 * Focuses on the self-healing behaviour added to make the invoice section
 * resilient to a one-off PDF generation failure (pdf_url=null) that previously
 * left customers permanently unable to download. Pre-places files on a faked
 * disk so the tests are deterministic and don't depend on DomPDF rendering.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=CustomerInvoice
 */
class CustomerInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('Customer invoice tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'customer_type'     => 'b2b',
            'first_name'        => 'Acme',
            'last_name'         => 'Buyer',
            'email'             => 'buyer' . uniqid() . '@acme-tyres.com',
            'password'          => Hash::make('secret-pass-123'),
            'phone'             => '+49 30 1234567',
            'country'           => 'DE',
            'company_name'      => 'Acme Tyres GmbH',
            'is_active'         => true,
            'onboarding_status' => 'active',
        ], $overrides));
    }

    private function invoice(Customer $customer, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'customer_id'    => $customer->id,
            'invoice_number' => 'INV-TEST-' . strtoupper(uniqid()),
            'issued_at'      => now(),
            'amount'         => 100.00,
            'status'         => 'paid',
            'pdf_url'        => null,
            'released_at'    => now(),
            'order_ref'      => 'AB-' . strtoupper(uniqid()),
        ], $overrides));
    }

    private function order(Customer $customer, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'ref'            => 'OKL-' . strtoupper(uniqid()),
            'customer_name'  => $customer->full_name,
            'customer_email' => $customer->email,
            'address'        => '1 Test St',
            'city'           => 'Berlin',
            'postal_code'    => '10115',
            'country'        => 'DE',
            'payment_method' => 'bank_transfer',
            'subtotal'       => 100.00,
            'delivery_cost'  => 0.00,
            'total'          => 100.00,
            'status'         => 'processing',
            'payment_status' => 'paid',
            'mode'           => 'manual',
        ], $overrides));
    }

    private function customerRequest(Customer $customer, string $method = 'GET'): Request
    {
        $req = Request::create('/', $method);
        $req->setUserResolver(fn () => $customer);
        return $req;
    }

    private function orderRow(Customer $customer, string $ref): array
    {
        $payload = app(OrderController::class)
            ->index($this->customerRequest($customer))
            ->getData(true);

        return collect($payload['data'])->firstWhere('ref', $ref);
    }

    private function putCanonical(Invoice $invoice): string
    {
        $path = "invoices/{$invoice->invoice_number}.pdf";
        Storage::disk('public')->put($path, '%PDF-1.4 fake test pdf');
        return $path;
    }

    // ── ensurePdf ───────────────────────────────────────────────────────────────

    public function test_ensure_pdf_adopts_existing_canonical_file_when_url_null(): void
    {
        Storage::fake('public');
        $c = $this->customer();
        $inv = $this->invoice($c, ['pdf_url' => null]);
        $canonical = $this->putCanonical($inv);

        $path = app(InvoiceService::class)->ensurePdf($inv);

        $this->assertSame($canonical, $path);
        $this->assertSame($canonical, $inv->fresh()->pdf_url, 'pdf_url should be repaired to the canonical path.');
    }

    public function test_ensure_pdf_repairs_storage_prefixed_url(): void
    {
        Storage::fake('public');
        $c = $this->customer();
        $inv = $this->invoice($c);
        $canonical = $this->putCanonical($inv);
        // Simulate a legacy/absolute URL stored in the column.
        $inv->updateQuietly(['pdf_url' => "https://api.okelcor.com/storage/{$canonical}"]);

        $path = app(InvoiceService::class)->ensurePdf($inv);

        $this->assertSame($canonical, $path);
    }

    public function test_ensure_pdf_returns_null_when_no_file_and_order_missing(): void
    {
        Storage::fake('public');
        $c = $this->customer();
        $inv = $this->invoice($c, ['pdf_url' => null, 'order_ref' => 'NO-SUCH-ORDER']);

        $this->assertNull(app(InvoiceService::class)->ensurePdf($inv));
    }

    // ── download endpoint ────────────────────────────────────────────────────────

    public function test_download_blocks_unreleased_invoice(): void
    {
        Storage::fake('public');
        $c = $this->customer();
        $inv = $this->invoice($c, ['released_at' => null]);
        $this->putCanonical($inv);

        $resp = (new InvoiceDownloadController())->download($this->customerRequest($c), $inv);

        $this->assertSame(423, $resp->getStatusCode());
    }

    public function test_download_forbidden_for_other_customer(): void
    {
        Storage::fake('public');
        $owner = $this->customer();
        $other = $this->customer();
        $inv = $this->invoice($owner);
        $this->putCanonical($inv);

        $resp = (new InvoiceDownloadController())->download($this->customerRequest($other), $inv);

        $this->assertSame(403, $resp->getStatusCode());
    }

    public function test_download_self_heals_null_pdf_url_and_serves_file(): void
    {
        Storage::fake('public');
        $c = $this->customer();
        $inv = $this->invoice($c, ['pdf_url' => null]);
        $canonical = $this->putCanonical($inv); // file present, but column is null

        $resp = (new InvoiceDownloadController())->download($this->customerRequest($c), $inv);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame($canonical, $inv->fresh()->pdf_url, 'Download should repair the null pdf_url.');
    }

    public function test_download_404_when_pdf_truly_unavailable(): void
    {
        Storage::fake('public');
        $c = $this->customer();
        $inv = $this->invoice($c, ['pdf_url' => null, 'order_ref' => 'NO-SUCH-ORDER']); // no file, no order

        $resp = (new InvoiceDownloadController())->download($this->customerRequest($c), $inv);

        $this->assertSame(404, $resp->getStatusCode());
    }

    // ── listing ──────────────────────────────────────────────────────────────────

    public function test_listing_self_heals_so_download_available_is_true(): void
    {
        Storage::fake('public');
        $c = $this->customer();
        $inv = $this->invoice($c, ['pdf_url' => null]);
        $this->putCanonical($inv); // file present, column null

        $payload = app(CustomerAuthController::class)
            ->invoices($this->customerRequest($c))
            ->getData(true);

        $this->assertCount(1, $payload['data']);
        $row = $payload['data'][0];
        $this->assertTrue($row['download_available'], 'download_available must be true after self-heal.');
        $this->assertNotNull($row['pdf_url']);
    }

    public function test_listing_excludes_unreleased_invoices(): void
    {
        Storage::fake('public');
        $c = $this->customer();
        $this->invoice($c, ['released_at' => now()]);   // visible
        $this->invoice($c, ['released_at' => null]);    // held (reverse-charge)

        $payload = app(CustomerAuthController::class)
            ->invoices($this->customerRequest($c))
            ->getData(true);

        $this->assertCount(1, $payload['data'], 'Held (unreleased) invoices must not appear in the customer list.');
    }

    // ── order-detail invoice fields ──────────────────────────────────────────────

    public function test_order_detail_exposes_released_invoice_download(): void
    {
        Storage::fake('public');
        $c = $this->customer();
        $order = $this->order($c);
        $inv = $this->invoice($c, ['order_ref' => $order->ref, 'released_at' => now()]);

        $row = $this->orderRow($c, $order->ref);

        $this->assertTrue($row['invoice_available']);
        $this->assertSame($inv->id, $row['invoice_id']);
        $this->assertSame($inv->invoice_number, $row['invoice_number']);
        $this->assertNotNull($row['invoice_download_url']);
        $this->assertFalse($row['invoice_pending_release']);
    }

    public function test_order_detail_flags_held_reverse_charge_invoice(): void
    {
        Storage::fake('public');
        $c = $this->customer();
        $order = $this->order($c, ['is_reverse_charge' => true]);
        $this->invoice($c, ['order_ref' => $order->ref, 'released_at' => null]); // held

        $row = $this->orderRow($c, $order->ref);

        $this->assertTrue($row['invoice_pending_release']);
        $this->assertFalse($row['invoice_available']);
        $this->assertNull($row['invoice_number']);
        $this->assertNull($row['invoice_download_url']);
    }

    public function test_order_detail_pending_release_when_paid_reverse_charge_without_invoice(): void
    {
        $c = $this->customer();
        $order = $this->order($c, ['is_reverse_charge' => true, 'payment_status' => 'paid']);

        $row = $this->orderRow($c, $order->ref);

        $this->assertTrue($row['invoice_pending_release']);
        $this->assertFalse($row['invoice_available']);
    }

    public function test_order_detail_no_invoice_flags_for_standard_unpaid_order(): void
    {
        $c = $this->customer();
        $order = $this->order($c, ['is_reverse_charge' => false, 'payment_status' => 'pending']);

        $row = $this->orderRow($c, $order->ref);

        $this->assertFalse($row['invoice_available']);
        $this->assertFalse($row['invoice_pending_release']);
        $this->assertNull($row['invoice_number']);
    }
}
