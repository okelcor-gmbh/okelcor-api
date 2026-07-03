<?php

namespace Tests\Feature;

use App\Http\Controllers\CustomerQuoteAcceptanceController;
use App\Models\Customer;
use App\Models\QuoteRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Customer accepts a CRM-7 proposal by printing, signing, and uploading it
 * back — an alternative to the digital "Accept" click, for customers who
 * prefer/need a wet-signature paper trail. Uploading IS an acceptance (same
 * effect as acceptProposal()), not just supplementary evidence.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=SignedProposalUpload
 */
class SignedProposalUploadTest extends TestCase
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
            'customer_type'     => 'b2b',
            'first_name'        => 'Acme',
            'last_name'         => 'Buyer',
            'email'             => 'buyer' . uniqid() . '@acme-tyres.com',
            'password'          => Hash::make('secret-pass-123'),
            'country'           => 'DE',
            'company_name'      => 'Acme Tyres GmbH',
            'is_active'         => true,
            'onboarding_status' => 'active',
        ], $overrides));
    }

    private function quote(Customer $customer, array $overrides = []): QuoteRequest
    {
        return QuoteRequest::create(array_merge([
            'ref_number'           => 'QR-' . uniqid(),
            'full_name'            => $customer->full_name,
            'email'                => $customer->email,
            'customer_id'          => $customer->id,
            'country'              => 'DE',
            'tyre_category'        => 'pcr',
            'quantity'             => '100',
            'delivery_location'    => 'Berlin, DE',
            'notes'                => 'Test inquiry',
            'status'               => 'quoted',
            'qualification_status' => 'new',
            'proposal_status'      => 'sent',
            'proposal_number'      => 'QT-2026-0001',
        ], $overrides));
    }

    private function customerRequest(Customer $customer, array $files = []): Request
    {
        $req = Request::create('/', 'POST', [], [], $files);
        $req->setUserResolver(fn () => $customer);
        return $req;
    }

    public function test_upload_accepts_the_proposal(): void
    {
        $c     = $this->customer();
        $quote = $this->quote($c);

        $file = UploadedFile::fake()->create('signed-proposal.pdf', 200, 'application/pdf');

        $resp = app(CustomerQuoteAcceptanceController::class)
            ->uploadSignedProposal($this->customerRequest($c, ['file' => $file]), $quote->ref_number);

        $this->assertSame(201, $resp->getStatusCode());
        $this->assertSame('accepted', $resp->getData(true)['data']['proposal_status']);

        $fresh = $quote->fresh();
        $this->assertSame('accepted', $fresh->proposal_status);
        $this->assertNotNull($fresh->proposal_accepted_at);
        $this->assertSame('signed-proposal.pdf', $fresh->proposal_signed_copy_original_filename);
        $this->assertNotNull($fresh->proposal_signed_copy_uploaded_at);
        Storage::disk('local')->assertExists($fresh->proposal_signed_copy_path);
    }

    public function test_blocked_when_no_active_proposal(): void
    {
        $c     = $this->customer();
        $quote = $this->quote($c, ['proposal_status' => 'draft']);

        $file = UploadedFile::fake()->create('signed-proposal.pdf', 200, 'application/pdf');

        $resp = app(CustomerQuoteAcceptanceController::class)
            ->uploadSignedProposal($this->customerRequest($c, ['file' => $file]), $quote->ref_number);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('no_active_proposal', $resp->getData(true)['code']);
    }

    public function test_blocked_when_expired(): void
    {
        $c     = $this->customer();
        $quote = $this->quote($c, ['proposal_expires_at' => now()->subDay()]);

        $file = UploadedFile::fake()->create('signed-proposal.pdf', 200, 'application/pdf');

        $resp = app(CustomerQuoteAcceptanceController::class)
            ->uploadSignedProposal($this->customerRequest($c, ['file' => $file]), $quote->ref_number);

        $this->assertSame(410, $resp->getStatusCode());
        $this->assertSame('expired', $quote->fresh()->proposal_status);
    }

    public function test_cannot_upload_for_another_customers_quote(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $quote = $this->quote($owner);

        $file = UploadedFile::fake()->create('signed-proposal.pdf', 200, 'application/pdf');

        $resp = app(CustomerQuoteAcceptanceController::class)
            ->uploadSignedProposal($this->customerRequest($other, ['file' => $file]), $quote->ref_number);

        $this->assertSame(404, $resp->getStatusCode());
    }
}
