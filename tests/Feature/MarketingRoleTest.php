<?php

namespace Tests\Feature;

use App\Support\AdminPermissions;
use Tests\TestCase;

/**
 * The `marketing` role (Session 94) — content + catalogue + campaigns.
 *
 * Created for the person who optimizes products, writes articles and runs the
 * e-mail campaigns. He held `editor`, which could edit products on the API but
 * carried none of the campaign permissions — and the admin panel's nav did not
 * offer editors the Products page at all, so from where he sat he "had no
 * access to products". One role that says what the job is, instead of a
 * permission set that happens to be nearby.
 *
 * The boundary these tests hold: everything content, nothing operational.
 * A marketing role that could touch orders, payments or customer management
 * would just be `admin` under a nicer name.
 */
class MarketingRoleTest extends TestCase
{
    public function test_marketing_is_a_valid_role(): void
    {
        $this->assertContains('marketing', AdminPermissions::ROLES);
    }

    public function test_marketing_holds_content_catalogue_and_campaigns(): void
    {
        $granted = AdminPermissions::for('marketing');

        foreach ([
            'products.view',
            'products.edit',      // also gates articles, categories, hero, brands, media, settings routes
            'articles.manage',
            'media.upload',
            'promotions.manage',
            'fet.manage',
            'settings.manage',    // the site-wide product shipping/returns texts live there
            'marketing.manage',   // campaigns, marketing contacts, bulk email
            'newsletter.manage',  // the audience list those campaigns send to
            'analytics.view',     // what people search for is marketing data
            'staff.self',         // every role holds this, by design
        ] as $permission) {
            $this->assertContains($permission, $granted, "marketing should hold {$permission}");
        }
    }

    public function test_marketing_holds_nothing_operational(): void
    {
        $granted = AdminPermissions::for('marketing');

        foreach ([
            'orders.view',
            'orders.update',
            'payments.mark_paid',
            'payments.correct_state',
            'quotes.manage',
            'crm.view',
            'customers.view',
            'customers.manage',
            'finance.view',
            'trade_documents.manage',
            'partners.view',
            'ebay.manage',
            'products.import',    // that group carries export and delete-all — destructive
            'admins.manage',
            'security.view',
            'staff.view_team',    // seeing a colleague's record is a manager's act
        ] as $permission) {
            $this->assertNotContains($permission, $granted, "marketing must NOT hold {$permission}");
        }
    }

    public function test_editor_is_unchanged(): void
    {
        // The marketer moves OFF editor rather than editor being widened —
        // other people hold it, and their access is not this change's to grow.
        $granted = AdminPermissions::for('editor');

        $this->assertContains('products.edit', $granted);
        $this->assertNotContains('marketing.manage', $granted);
        $this->assertNotContains('newsletter.manage', $granted);
    }
}
