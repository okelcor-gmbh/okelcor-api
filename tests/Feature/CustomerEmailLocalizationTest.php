<?php

namespace Tests\Feature;

use App\Mail\CustomerInvitation;
use App\Models\Customer;
use Tests\TestCase;

/**
 * Customer email localization (infrastructure).
 *
 * Verifies the HasLocalePreference contract + the invitation email rendering in
 * the customer's language. No database — customers are instantiated, not saved,
 * and mailables are rendered directly in a chosen locale.
 *
 * Laravel auto-applies preferredLocale() when sending to a customer
 * (Mailable.php:657 / PendingMail.php:82), so the contract returning the right
 * locale + the lang files producing localized output together prove the feature.
 *
 * Run with: php artisan test --filter=CustomerEmailLocalization
 */
class CustomerEmailLocalizationTest extends TestCase
{
    private function customer(?string $lang): Customer
    {
        return new Customer(['first_name' => 'Klaus', 'preferred_language' => $lang]);
    }

    public function test_preferred_locale_resolves_and_falls_back(): void
    {
        $this->assertSame('de', $this->customer('de')->preferredLocale());
        $this->assertSame('fr', $this->customer('fr')->preferredLocale());
        $this->assertSame('en', $this->customer('en')->preferredLocale());
        $this->assertSame('en', $this->customer('zz')->preferredLocale());  // unsupported
        $this->assertSame('en', $this->customer(null)->preferredLocale());  // unset
    }

    public function test_invitation_renders_in_german(): void
    {
        $html = (new CustomerInvitation($this->customer('de'), 'https://okelcor.com/activate/abc'))
            ->locale('de')
            ->render();

        $this->assertStringContainsString('Hallo Klaus,', $html);
        $this->assertStringContainsString('Mein Konto aktivieren', $html);
        $this->assertStringContainsString('Ihre Okelcor-Kontoeinladung', $html); // localized <title>/subject
    }

    public function test_invitation_renders_in_english_by_default(): void
    {
        $html = (new CustomerInvitation($this->customer('en'), 'https://okelcor.com/activate/abc'))
            ->locale('en')
            ->render();

        $this->assertStringContainsString('Hello Klaus,', $html);
        $this->assertStringContainsString('Activate My Account', $html);
    }

    public function test_invitation_renders_in_french_and_spanish(): void
    {
        $fr = (new CustomerInvitation($this->customer('fr'), 'https://x'))->locale('fr')->render();
        $this->assertStringContainsString('Activer mon compte', $fr);

        $es = (new CustomerInvitation($this->customer('es'), 'https://x'))->locale('es')->render();
        $this->assertStringContainsString('Activar mi cuenta', $es);
    }
}
