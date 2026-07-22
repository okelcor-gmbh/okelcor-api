<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\TradeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Manual trade-document upload previously always filed under the generic
 * 'shipment_document' type — there was no way to upload an externally
 * produced order confirmation / proforma / commercial invoice / packing
 * list / delivery note and have it recognized as that real document type
 * (raised by the order manager: she needed to centralize documents her
 * accountant already generated, not have the system generate its own).
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=AdminTradeDocumentUpload
 */
class AdminTradeDocumentUploadTest extends TestCase
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

    private function admin(string $role = 'order_manager'): AdminUser
    {
        return AdminUser::create([
            'name' => 'Ops ' . uniqid(), 'email' => 'ops' . uniqid() . '@okelcor.test', 'role' => $role,
            'password' => Hash::make('secret-pass-123'), 'is_active' => true, 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function order(): Order
    {
        return Order::create([
            'ref' => 'OKL-' . strtoupper(uniqid()), 'customer_name' => 'Acme Buyer', 'customer_email' => 'buyer@acme-tyres.com',
            'subtotal' => 1000, 'delivery_cost' => 0, 'total' => 1000, 'status' => 'confirmed',
            'payment_status' => 'paid', 'payment_stage' => 'deposit_paid', 'mode' => 'manual',
        ]);
    }

    public function test_upload_defaults_to_shipment_document_when_no_type_given(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/trade-documents/upload", [
                'file'       => UploadedFile::fake()->create('bol.pdf', 100, 'application/pdf'),
                'type_label' => 'Bill of Lading',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'shipment_document');
    }

    public function test_can_manually_upload_a_real_document_type(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/trade-documents/upload", [
                'file'       => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
                'type_label' => 'Commercial Invoice',
                'type'       => 'commercial_invoice',
            ]);

        $response->assertCreated()->assertJsonPath('data.type', 'commercial_invoice');
        $this->assertDatabaseHas('trade_documents', [
            'order_id' => $order->id, 'type' => 'commercial_invoice', 'status' => 'issued',
        ]);
    }

    public function test_uploading_a_replacement_supersedes_the_previous_one_of_the_same_type(): void
    {
        $order = $this->order();

        $first = TradeDocument::create([
            'order_id' => $order->id, 'order_ref' => $order->ref, 'type' => 'order_confirmation',
            'type_label' => 'Order Confirmation', 'status' => 'issued', 'file_path' => 'trade-documents/old.pdf',
            'issued_at' => now(),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/trade-documents/upload", [
                'file'       => UploadedFile::fake()->create('new-confirmation.pdf', 100, 'application/pdf'),
                'type_label' => 'Order Confirmation',
                'type'       => 'order_confirmation',
            ])
            ->assertCreated();

        $this->assertSame('superseded', $first->fresh()->status);
        $this->assertSame(2, TradeDocument::where('order_id', $order->id)->where('type', 'order_confirmation')->count());
        $this->assertDatabaseHas('trade_documents', [
            'order_id' => $order->id, 'type' => 'order_confirmation', 'status' => 'issued',
        ]);
    }

    public function test_uploading_a_shipment_document_never_supersedes_anything(): void
    {
        $order = $this->order();

        TradeDocument::create([
            'order_id' => $order->id, 'order_ref' => $order->ref, 'type' => 'shipment_document',
            'type_label' => 'CMR', 'status' => 'issued', 'file_path' => 'trade-documents/cmr.pdf', 'issued_at' => now(),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/trade-documents/upload", [
                'file'       => UploadedFile::fake()->create('bol.pdf', 100, 'application/pdf'),
                'type_label' => 'Bill of Lading',
            ])
            ->assertCreated();

        $this->assertSame(2, TradeDocument::where('order_id', $order->id)->where('status', 'issued')->count());
    }

    public function test_rejects_an_unrecognized_type(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/trade-documents/upload", [
                'file'       => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
                'type_label' => 'Something',
                'type'       => 'not_a_real_type',
            ])
            ->assertStatus(422);
    }
}
