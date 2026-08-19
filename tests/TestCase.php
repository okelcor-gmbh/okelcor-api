<?php

namespace Tests;

use App\Models\Product;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Product memoises whether products.slug exists (deploy-order guard).
        // Static state survives between tests in one process, and the minimal-
        // schema harnesses rebuild `products` with different columns — a stale
        // answer from the previous test would corrupt this one.
        Product::flushSlugColumnCache();
    }
}
