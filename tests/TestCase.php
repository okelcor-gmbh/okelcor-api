<?php

namespace Tests;

use App\Models\Product;
use App\Support\ProductSearch;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Product and ProductSearch memoise which columns exist (deploy-order
        // guards). Static state survives between tests in one process, and the
        // minimal-schema harnesses rebuild tables with different columns — a
        // stale answer from the previous test would corrupt this one.
        Product::flushSlugColumnCache();
        ProductSearch::flushColumnCache();

        // Same trap, same fix: the order detail endpoint embeds a finance
        // block guarded by these, and most harnesses build orders without the
        // profitability tables.
        \App\Models\OrderFinanceRecord::forgetAvailableCheck();
        \App\Models\LiquidityWeek::forgetAvailableCheck();
    }
}
