<?php

namespace Tests\Feature;

use App\Http\Controllers\TradeDocumentController;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\TradeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Customer uploads a signed/stamped copy of the proforma invoice back
 * through the portal — the documented "customer agreed to this price and
 * these terms" paper trail the order manager asked for.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=SignedProformaUpload
 */
class SignedProformaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('These tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();
        Storage::fake('local');
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'customer_type'          => 'b2b',
            'first_name'             => 'Acme',
            'last_name'              => 'Buyer',
            'email'                  => 'buyer' . uniqid() . '@acme-tyres.com',
            'password'               => Hash::make('secret-pass-123'),
            'country'                => 'DE',
            'company_name'           => 'Acme Tyres GmbH',
            'is_active'              => true,
            'onboarding_status'      => 'active',
            'approved_for_documents' => true,
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
            'subtotal'       => 1000.00,
            'delivery_cost'  => 0.00,
            'total'          => 1000.00,
            'status'         => 'confirmed',
            'payment_status' => 'pending',
            'mode'           => 'manual',
        ], $overrides));
    }

    private function issuedProforma(Order $order): TradeDocument
    {
        return TradeDocument::create([
            'order_id'  => $order->id,
            'order_ref' => $order->ref,
            'type'      => 'proforma',
            'number'    => 'PI-2026-0001',
            'status'    => 'issued',
            'issued_at' => now(),
        ]);
    }

    private function customerRequest(Customer $customer, array $files = []): Request
    {
        $req = Request::create('/', 'POST', [], [], $files);
        $req->setUserResolver(fn () => $customer);
        return $req;
    }

    public function test_customer_can_upload_signed_proforma(): void
    {
        $c     = $this->customer();
        $order = $this->order($c);
        $this->issuedProforma($order);

        $file = UploadedFile::fake()->create('signed-pi.pdf', 200, 'application/pdf');

        $resp = app(TradeDocumentController::class)
            ->uploadSignedProforma($this->customerRequest($c, ['file' => $file]), $order->ref);

        $this->assertSame(201, $resp->getStatusCode());

        $doc = TradeDocument::where('order_id', $order->id)->where('type', 'proforma_signed')->first();
        $this->assertNotNull($doc);
        $this->assertSame('issued', $doc->status);
        $this->assertSame('signed-pi.pdf', $doc->original_filename);
        Storage::disk('local')->assertExists($doc->getRawOriginal('file_path'));

        $this->assertNotNull(OrderLog::where('order_id', $order->id)->where('action', 'proforma_signed_returned')->first());
    }

    public function test_blocked_when_no_proforma_issued_yet(): void
    {
        $c     = $this->customer();
        $order = $this->order($c); // no proforma created

        $file = UploadedFile::fake()->create('signed-pi.pdf', 200, 'application/pdf');

        $resp = app(TradeDocumentController::class)
            ->uploadSignedProforma($this->customerRequest($c, ['file' => $file]), $order->ref);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('no_proforma', $resp->getData(true)['code']);
    }

    public function test_blocked_when_documents_not_approved(): void
    {
        $c     = $this->customer(['approved_for_documents' => false]);
        $order = $this->order($c);
        $this->issuedProforma($order);

        $file = UploadedFile::fake()->create('signed-pi.pdf', 200, 'application/pdf');

        $resp = app(TradeDocumentController::class)
            ->uploadSignedProforma($this->customerRequest($c, ['file' => $file]), $order->ref);

        $this->assertSame(403, $resp->getStatusCode());
    }

    public function test_cannot_upload_to_another_customers_order(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $order = $this->order($owner);
        $this->issuedProforma($order);

        $file = UploadedFile::fake()->create('signed-pi.pdf', 200, 'application/pdf');

        $resp = app(TradeDocumentController::class)
            ->uploadSignedProforma($this->customerRequest($other, ['file' => $file]), $order->ref);

        $this->assertSame(404, $resp->getStatusCode());
    }

    public function test_second_upload_supersedes_the_first(): void
    {
        $c     = $this->customer();
        $order = $this->order($c);
        $this->issuedProforma($order);

        app(TradeDocumentController::class)->uploadSignedProforma(
            $this->customerRequest($c, ['file' => UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf')]),
            $order->ref
        );

        app(TradeDocumentController::class)->uploadSignedProforma(
            $this->customerRequest($c, ['file' => UploadedFile::fake()->create('v2.pdf', 100, 'application/pdf')]),
            $order->ref
        );

        $signed = TradeDocument::where('order_id', $order->id)->where('type', 'proforma_signed')->get();
        $this->assertCount(2, $signed);
        $this->assertSame(1, $signed->where('status', 'issued')->count());
        $this->assertSame('v2.pdf', $signed->firstWhere('status', 'issued')->original_filename);
    }

    public function test_signed_copy_appears_in_customer_trade_documents_list(): void
    {
        $c     = $this->customer();
        $order = $this->order($c);
        $this->issuedProforma($order);

        app(TradeDocumentController::class)->uploadSignedProforma(
            $this->customerRequest($c, ['file' => UploadedFile::fake()->create('signed.pdf', 100, 'application/pdf')]),
            $order->ref
        );

        $req = Request::create('/', 'GET');
        $req->setUserResolver(fn () => $c);

        $payload = app(TradeDocumentController::class)->index($req, $order->ref)->getData(true);
        $types   = array_column($payload['data'], 'type');

        $this->assertContains('proforma_signed', $types);
    }

    // ── Payment-gated document types (balance against bill of lading) ───────

    public function test_packing_list_and_delivery_note_hidden_until_fully_paid(): void
    {
        $c     = $this->customer();
        $order = $this->order($c, ['payment_status' => 'pending']); // not fully paid

        TradeDocument::create([
            'order_id' => $order->id, 'order_ref' => $order->ref,
            'type' => 'packing_list', 'number' => 'PL-2026-0001', 'status' => 'issued', 'issued_at' => now(),
        ]);
        TradeDocument::create([
            'order_id' => $order->id, 'order_ref' => $order->ref,
            'type' => 'delivery_note', 'number' => 'DN-2026-0001', 'status' => 'issued', 'issued_at' => now(),
        ]);
        TradeDocument::create([
            'order_id' => $order->id, 'order_ref' => $order->ref,
            'type' => 'shipment_document', 'type_label' => 'Bill of Lading', 'status' => 'issued', 'issued_at' => now(),
        ]);

        $req = Request::create('/', 'GET');
        $req->setUserResolver(fn () => $c);

        $payload = app(TradeDocumentController::class)->index($req, $order->ref)->getData(true);
        $types   = array_column($payload['data'], 'type');

        $this->assertNotContains('packing_list', $types);
        $this->assertNotContains('delivery_note', $types);
        $this->assertNotContains('shipment_document', $types);
    }

    public function test_packing_list_and_delivery_note_visible_once_fully_paid(): void
    {
        $c     = $this->customer();
        $order = $this->order($c, ['payment_status' => 'paid']);

        TradeDocument::create([
            'order_id' => $order->id, 'order_ref' => $order->ref,
            'type' => 'packing_list', 'number' => 'PL-2026-0002', 'status' => 'issued', 'issued_at' => now(),
        ]);
        TradeDocument::create([
            'order_id' => $order->id, 'order_ref' => $order->ref,
            'type' => 'delivery_note', 'number' => 'DN-2026-0002', 'status' => 'issued', 'issued_at' => now(),
        ]);

        $req = Request::create('/', 'GET');
        $req->setUserResolver(fn () => $c);

        $payload = app(TradeDocumentController::class)->index($req, $order->ref)->getData(true);
        $types   = array_column($payload['data'], 'type');

        $this->assertContains('packing_list', $types);
        $this->assertContains('delivery_note', $types);
    }
}
