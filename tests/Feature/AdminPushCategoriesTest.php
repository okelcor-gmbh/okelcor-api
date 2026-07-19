<?php

namespace Tests\Feature;

use App\Support\AdminPushCategories;
use Tests\TestCase;

/**
 * Pure lookup-table logic — no database involved, runs fine under sqlite.
 */
class AdminPushCategoriesTest extends TestCase
{
    public function test_resolves_known_types_to_their_category(): void
    {
        $this->assertSame('financial_revision_request', AdminPushCategories::forType('financial_revision_requested'));
        $this->assertSame('financial_revision_result', AdminPushCategories::forType('financial_revision_approved'));
        $this->assertSame('financial_revision_result', AdminPushCategories::forType('financial_revision_rejected'));
        $this->assertSame('inbox_reply', AdminPushCategories::forType('email_reply_received'));
        $this->assertSame('inbox_reply', AdminPushCategories::forType('customer_message_reply'));
        $this->assertSame('new_lead', AdminPushCategories::forType('inbound_email_lead_received'));
        $this->assertSame('follow_up_due', AdminPushCategories::forType('follow_up_due'));
    }

    public function test_returns_null_for_an_unmapped_type(): void
    {
        $this->assertNull(AdminPushCategories::forType('some_future_notification_type'));
    }
}
