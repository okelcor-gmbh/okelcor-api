<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminTradeDocumentController;
use App\Http\Controllers\OrderController;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\QuoteRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CRM-7 proposal-driven orders should be able to go straight from an accepted
 * proposal to an issued Proforma Invoice, without a separate "accept the Order
 * Confirmation" click — that second acceptance step is redundant once the
 * customer already accepted the proposal that led to the order. Direct/manual
 * orders with no proposal history keep the original OC-acceptance gate, since
 * it's their only acceptance checkpoint.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=ProposalToProformaGate
 */
class ProposalToProformaGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('These tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();
    }

    private function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'ref'                         => 'OKL-' . strtoupper(uniqid()),
            'customer_name'               => 'Acme Buyer',
            'customer_email'              => 'buyer' . uniqid() . '@acme-tyres.com',
            'address'                     => '1 Test St',
            'city'                        => 'Berlin',
            'postal_code'                 => '10115',
            'country'                     => 'DE',
            'payment_method'              => 'bank_transfer',
            'subtotal'                    => 1000.00,
            'delivery_cost'               => 0.00,
            'total'                       => 1000.00,
            'status'                      => 'awaiting_proforma',
            'payment_status'              => 'pending',
            'mode'                        => 'manual',
            'customer_acceptance_status'  => 'pending',
        ], $overrides));
    }

    private function acceptedProposalQuote(Order $order, array $overrides = []): QuoteRequest
    {
        return QuoteRequest::create(array_merge([
            'ref_number'            => 'QR-' . uniqid(),
            'full_name'             => $order->customer_name,
            'email'                 => $order->customer_email,
            'country'               => 'DE',
            'tyre_category'         => 'pcr',
            'quantity'              => '100',
            'delivery_location'     => 'Berlin, DE',
            'notes'                 => 'Test inquiry',
            'status'                => 'quoted',
            'qualification_status'  => 'new',
            'order_id'              => $order->id,
            'proposal_status'       => 'converted',
            'proposal_number'       => 'QT-2026-0001',
            'proposal_accepted_at'  => now(),
        ], $overrides));
    }

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'name'      => 'Ops ' . uniqid(),
            'email'     => 'ops' . uniqid() . '@okelcor.test',
            'role'      => 'super_admin',
            'password'  => Hash::make('secret-pass-123'),
            'is_active' => true,
        ]);
    }

    private function adminRequest(AdminUser $admin, array $input = []): Request
    {
        $req = Request::create('/', 'POST', $input);
        $req->setUserResolver(fn () => $admin);
        return $req;
    }

    private function customerRequest(string $email): Request
    {
        $customer = new class($email) {
            public $email;
            public function __construct($email) { $this->email = $email; }
        };

        $req = Request::create('/', 'GET');
        $req->setUserResolver(fn () => $customer);
        return $req;
    }

    // ── Admin generation gate ────────────────────────────────────────────────

    public function test_proposal_driven_order_skips_oc_acceptance_gate(): void
    {
        $order = $this->order();
        $this->acceptedProposalQuote($order);

        $resp = app(AdminTradeDocumentController::class)
            ->generateProforma($this->adminRequest($this->admin()), $order->id);

        $this->assertSame(201, $resp->getStatusCode());
        $this->assertSame('proforma', $resp->getData(true)['data']['type']);
    }

    public function test_direct_order_still_requires_oc_acceptance(): void
    {
        $order = $this->order(); // no linked quote at all

        $resp = app(AdminTradeDocumentController::class)
            ->generateProforma($this->adminRequest($this->admin()), $order->id);

        $this->assertSame(409, $resp->getStatusCode());
        $this->assertSame('customer_acceptance_required', $resp->getData(true)['code']);
    }

    public function test_order_from_unaccepted_proposal_still_blocked(): void
    {
        $order = $this->order();
        $this->acceptedProposalQuote($order, ['proposal_accepted_at' => null, 'proposal_status' => 'sent']);

        $resp = app(AdminTradeDocumentController::class)
            ->generateProforma($this->adminRequest($this->admin()), $order->id);

        $this->assertSame(409, $resp->getStatusCode());
    }

    // ── Customer visibility ──────────────────────────────────────────────────

    public function test_customer_sees_proforma_on_proposal_driven_order_without_oc_acceptance(): void
    {
        $order = $this->order();
        $this->acceptedProposalQuote($order);

        app(AdminTradeDocumentController::class)
            ->generateProforma($this->adminRequest($this->admin()), $order->id);

        $payload = app(OrderController::class)
            ->show($this->customerRequest($order->customer_email), $order->ref)
            ->getData(true);

        $types = array_column($payload['data']['trade_documents'], 'type');
        $this->assertContains('proforma', $types);
    }

    public function test_customer_does_not_see_proforma_on_direct_order_until_oc_accepted(): void
    {
        $order = $this->order();

        // Admin overrides the gate (super_admin) to issue the proforma anyway,
        // simulating a document generated before the customer accepted the OC.
        app(AdminTradeDocumentController::class)
            ->generateProforma($this->adminRequest($this->admin(), ['override_acceptance' => true]), $order->id);

        $payload = app(OrderController::class)
            ->show($this->customerRequest($order->customer_email), $order->ref)
            ->getData(true);

        $types = array_column($payload['data']['trade_documents'], 'type');
        $this->assertNotContains('proforma', $types);
    }
}
