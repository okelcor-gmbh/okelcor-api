<?php

use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerAddressController;
use App\Http\Controllers\CustomerOrderController;
use App\Http\Controllers\InvoiceDownloadController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\ContainerTrackingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\VatController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HeroSlideController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\QuoteRequestController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\Admin\AdminArticleController;
use App\Http\Controllers\Admin\AdminBrandController;
use App\Http\Controllers\Admin\AdminCategoryController;
use App\Http\Controllers\Admin\AdminContactController;
use App\Http\Controllers\Admin\AdminHeroSlideController;
use App\Http\Controllers\Admin\AdminNewsletterController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AdminOrderShipmentEventController;
use App\Http\Controllers\Admin\OrderImportController;
use App\Http\Controllers\Admin\AdminProductController;
use App\Http\Controllers\Admin\AdminQuoteRequestController;
use App\Http\Controllers\Admin\AdminQuoteAttachmentController;
use App\Http\Controllers\Admin\AdminSettingController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\ProductImportController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\AdminCustomerController;
use App\Http\Controllers\Admin\CustomerImportController;
use App\Http\Controllers\Admin\EbayListingController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\FetEngineController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\Admin\AdminFetEngineController;
use App\Http\Controllers\Admin\AdminPromotionController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminEuDeclarationController;
use App\Http\Controllers\Admin\AdminTradeDocumentController;
use App\Http\Controllers\Admin\AdminTwoFactorController;
use App\Http\Controllers\Admin\AdminLoginTwoFactorController;
use App\Http\Controllers\EuDeclarationController;
use App\Http\Controllers\TradeDocumentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // -------------------------------------------------------------------------
    // Customer auth — public (no token required)
    // -------------------------------------------------------------------------
    Route::prefix('auth')->group(function () {
        // Standard credential endpoints — 10 per IP per minute
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', [CustomerAuthController::class, 'register']);
            Route::post('login', [CustomerAuthController::class, 'login']);
            Route::post('reset-password', [CustomerAuthController::class, 'resetPassword']);
        });

        // Email-dispatching endpoints — stricter: 5 per IP per minute
        Route::middleware('throttle:auth-email')->group(function () {
            Route::post('forgot-password', [CustomerAuthController::class, 'forgotPassword']);
            Route::post('resend-verification', [CustomerAuthController::class, 'resendVerification']);
        });

        // Verification link — single-use, no rate limit needed
        Route::get('verify-email/{id}/{hash}', [CustomerAuthController::class, 'verifyEmail'])
            ->name('verification.verify');
    });

    // Customer auth — protected
    Route::middleware('auth.customer')->prefix('auth')->group(function () {
        Route::post('logout', [CustomerAuthController::class, 'logout']);
        Route::post('record-login', [CustomerAuthController::class, 'recordLogin']);
        Route::get('me', [CustomerAuthController::class, 'me']);
        Route::put('profile', [CustomerAuthController::class, 'updateProfile']);
        Route::put('change-password', [CustomerAuthController::class, 'changePassword']);

        // Quotes & Invoices
        Route::get('quotes', [CustomerAuthController::class, 'quotes']);
        Route::get('quotes/{ref}', [CustomerAuthController::class, 'quoteDetail']);
        Route::get('invoices', [CustomerAuthController::class, 'invoices']);

        // Addresses
        Route::get('addresses', [CustomerAddressController::class, 'index']);
        Route::post('addresses', [CustomerAddressController::class, 'store']);
        Route::put('addresses/{id}', [CustomerAddressController::class, 'update']);
        Route::delete('addresses/{id}', [CustomerAddressController::class, 'destroy']);

        // Orders — customer pay-now + EU entry certificate + trade documents
        Route::post('orders/{ref}/checkout', [CustomerOrderController::class, 'checkout'])->middleware('throttle:checkout');
        Route::post('orders/{ref}/declaration', [EuDeclarationController::class, 'sign']);
        Route::get('orders/{ref}/declaration/download', [EuDeclarationController::class, 'download']);
        Route::get('orders/{ref}/trade-documents', [TradeDocumentController::class, 'index']);
        Route::get('trade-documents/{id}/download', [TradeDocumentController::class, 'download']);
    });

    // Invoice download — protected by customer Bearer token
    Route::middleware('auth.customer')->group(function () {
        Route::get('invoices/{invoice}/download', [InvoiceDownloadController::class, 'download'])
            ->name('invoices.download');
    });

    // -------------------------------------------------------------------------
    // Public — no auth required
    // -------------------------------------------------------------------------

    // Products — temporarily public for payment gateway review
    // TODO: restore auth.customer middleware after review is complete
    Route::get('products/brands', [ProductController::class, 'brands']);
    Route::get('products/specs', [ProductController::class, 'specs']);
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{id}', [ProductController::class, 'show']);

    // Articles
    Route::get('articles', [ArticleController::class, 'index']);
    Route::get('articles/{slug}', [ArticleController::class, 'show']);

    // Categories
    Route::get('categories', [CategoryController::class, 'index']);

    // Hero slides
    Route::get('hero-slides', [HeroSlideController::class, 'index']);

    // Brands
    Route::get('brands', [BrandController::class, 'index']);

    // FET engine compatibility
    Route::get('fet/engines', [FetEngineController::class, 'index']);

    // Active promotion (shop banner)
    Route::get('promotions/active', [PromotionController::class, 'active']);

    // Site settings (public read-only)
    Route::get('settings/public', [SettingController::class, 'public']);
    Route::get('settings', [SettingController::class, 'index']);

    // Container tracking — rate limited: 30/min (guards external DHL + ShipsGo calls)
    Route::middleware('throttle:tracking')->group(function () {
        Route::get('tracking/{container}', ContainerTrackingController::class);
    });

    // Search — rate limited: 30/min
    Route::middleware('throttle:search')->group(function () {
        Route::get('search', SearchController::class);
    });

    // VAT validation — rate limited: 10/min
    Route::middleware('throttle:vat')->group(function () {
        Route::post('vat/validate', [VatController::class, 'validate']);
    });

    // Payments — rate limited: 20/min
    Route::middleware('throttle:payments')->group(function () {
        Route::post('payments/create-session', [PaymentController::class, 'createSession']);
        Route::post('payments/tax-preview', [PaymentController::class, 'taxPreview']);
    });

    // Stripe webhook — no rate limit, excluded from ForceJsonResponse
    Route::post('payments/webhook', [PaymentController::class, 'webhook'])
        ->withoutMiddleware([\App\Http\Middleware\ForceJsonResponse::class]);

    // Mollie is legacy/inactive until business account/API credentials are approved.
    Route::post('orders/mollie-webhook', fn () => response()->json([
        'message' => 'Mollie payments are currently disabled.',
    ], 410));

    // Customer order history — protected by customer Bearer token
    Route::middleware('auth.customer')->group(function () {
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{ref}', [OrderController::class, 'show']);
    });

    // Public forms — rate limited: 10/hour
    Route::middleware('throttle:public-form')->group(function () {
        Route::post('contact', [ContactController::class, 'store']);
        Route::post('orders', [OrderController::class, 'store']);
        Route::post('newsletter/subscribe', [NewsletterController::class, 'subscribe']);
    });

    // Quote requests — rate limited: 5/hour (stricter per spec)
    Route::middleware('throttle:quote-form')->group(function () {
        Route::post('quote-requests', [QuoteRequestController::class, 'store']);
    });

    // Newsletter confirmation (GET — no rate limit needed)
    Route::get('newsletter/confirm/{token}', [NewsletterController::class, 'confirm']);

    // -------------------------------------------------------------------------
    // Admin auth (no Sanctum guard — these issue the token)
    // -------------------------------------------------------------------------
    Route::post('admin/login', [AuthController::class, 'login']);
    Route::post('admin/login/2fa', AdminLoginTwoFactorController::class);

    // -------------------------------------------------------------------------
    // Admin — protected by Sanctum token auth
    // Role hierarchy:
    //   super_admin  — full access
    //   admin        — full access
    //   editor       — content only (products, articles, categories, hero slides, brands, media, settings)
    //   order_manager — operations only (orders, quote requests, contacts, newsletter)
    // -------------------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'auth.admin'])->prefix('admin')->group(function () {

        // Dashboard — all authenticated admin roles
        Route::get('dashboard', [AdminDashboardController::class, 'stats']);

        // Auth — all authenticated admin users
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        // 2FA management — all authenticated admin users
        Route::prefix('2fa')->group(function () {
            Route::get('status', [AdminTwoFactorController::class, 'status']);
            Route::post('enable', [AdminTwoFactorController::class, 'enable']);
            Route::post('confirm', [AdminTwoFactorController::class, 'confirm']);
            Route::post('disable', [AdminTwoFactorController::class, 'disable']);
            Route::post('recovery-codes/regenerate', [AdminTwoFactorController::class, 'regenerateRecoveryCodes']);
        });

        // Own profile — all authenticated admin roles
        Route::get('profile', [AdminUserController::class, 'profile']);
        Route::put('profile', [AdminUserController::class, 'updateProfile']);
        Route::put('profile/password', [AdminUserController::class, 'changePassword']);
        Route::put('change-password', [AdminUserController::class, 'changePassword']);

        // User management — super_admin, admin
        Route::middleware('admin.role:super_admin,admin')->group(function () {
            Route::get('users', [AdminUserController::class, 'index']);
            Route::post('users', [AdminUserController::class, 'store']);
            Route::get('users/{id}', [AdminUserController::class, 'show']);
            Route::put('users/{id}', [AdminUserController::class, 'update']);
            Route::delete('users/{id}', [AdminUserController::class, 'destroy']);
            Route::post('users/{id}/resend-credentials', [AdminUserController::class, 'resendCredentials']);
        });

        // -----------------------------------------------------------------
        // Content routes — super_admin, admin, editor
        // -----------------------------------------------------------------
        // Import / export — super_admin and admin only
        Route::middleware('admin.role:super_admin,admin')->group(function () {
            Route::post('products/import', [ProductImportController::class, 'import']);
            Route::get('products/export', [ProductImportController::class, 'export']);
            Route::delete('products/all', [AdminProductController::class, 'destroyAll']);

            // FET engine bulk import
            Route::post('fet/engines/import', [AdminFetEngineController::class, 'import']);
        });

        Route::middleware('admin.role:super_admin,admin,editor')->group(function () {

            // Products
            Route::post('products/bulk-stock', [AdminProductController::class, 'bulkStock']);
            Route::post('products/{id}/restore', [AdminProductController::class, 'restore']);
            Route::get('products', [AdminProductController::class, 'index']);
            Route::post('products', [AdminProductController::class, 'store']);
            Route::get('products/{product}', [AdminProductController::class, 'show']);
            Route::put('products/{product}', [AdminProductController::class, 'update']);
            Route::delete('products/{product}', [AdminProductController::class, 'destroy']);
            Route::post('products/{product}/images', [AdminProductController::class, 'uploadImages']);
            Route::delete('products/{product}/images/{image}', [AdminProductController::class, 'deleteImage']);

            // Articles
            Route::post('articles/{id}/restore', [AdminArticleController::class, 'restore']);
            Route::get('articles', [AdminArticleController::class, 'index']);
            Route::post('articles', [AdminArticleController::class, 'store']);
            Route::get('articles/{article}', [AdminArticleController::class, 'show']);
            Route::put('articles/{article}', [AdminArticleController::class, 'update']);
            Route::delete('articles/{article}', [AdminArticleController::class, 'destroy']);
            Route::post('articles/{id}/image', [AdminArticleController::class, 'uploadImage']);

            // Categories (fixed set — no create/delete)
            Route::get('categories', [AdminCategoryController::class, 'index']);
            Route::put('categories/{category}', [AdminCategoryController::class, 'update']);

            // Hero slides
            Route::get('hero-slides', [AdminHeroSlideController::class, 'index']);
            Route::post('hero-slides', [AdminHeroSlideController::class, 'store']);
            Route::get('hero-slides/{id}', [AdminHeroSlideController::class, 'show']);
            Route::put('hero-slides/{id}', [AdminHeroSlideController::class, 'update']);
            Route::post('hero-slides/{id}/media', [AdminHeroSlideController::class, 'uploadMedia']);
            Route::delete('hero-slides/{id}', [AdminHeroSlideController::class, 'destroy']);

            // Brands
            Route::get('brands', [AdminBrandController::class, 'index']);
            Route::post('brands', [AdminBrandController::class, 'store']);
            Route::get('brands/{id}', [AdminBrandController::class, 'show']);
            Route::put('brands/{id}', [AdminBrandController::class, 'update']);
            Route::post('brands/{id}/logo', [AdminBrandController::class, 'uploadLogo']);
            Route::delete('brands/{id}', [AdminBrandController::class, 'destroy']);

            // Media
            Route::get('media', [MediaController::class, 'index']);
            Route::post('media', [MediaController::class, 'store']);
            Route::delete('media/{id}', [MediaController::class, 'destroy']);

            // Site settings
            Route::get('settings', [AdminSettingController::class, 'index']);
            Route::put('settings', [AdminSettingController::class, 'update']);

            // FET engine compatibility
            Route::get('fet/engines', [AdminFetEngineController::class, 'index']);
            Route::post('fet/engines', [AdminFetEngineController::class, 'store']);
            Route::put('fet/engines/{id}', [AdminFetEngineController::class, 'update']);
            Route::delete('fet/engines/{id}', [AdminFetEngineController::class, 'destroy']);

            // Promotions
            Route::get('promotions', [AdminPromotionController::class, 'index']);
            Route::post('promotions', [AdminPromotionController::class, 'store']);
            Route::get('promotions/{id}', [AdminPromotionController::class, 'show']);
            Route::put('promotions/{id}', [AdminPromotionController::class, 'update']);
            Route::patch('promotions/{id}/toggle', [AdminPromotionController::class, 'toggle']);
            Route::delete('promotions/{id}', [AdminPromotionController::class, 'destroy']);
            Route::post('promotions/{id}/media', [AdminPromotionController::class, 'uploadMedia']);
        });

        // -----------------------------------------------------------------
        // Operations routes — super_admin, admin, order_manager
        // -----------------------------------------------------------------
        Route::middleware('admin.role:super_admin,admin,order_manager')->group(function () {

            // Quote requests
            Route::get('quote-requests', [AdminQuoteRequestController::class, 'index']);
            Route::get('quote-requests/{id}', [AdminQuoteRequestController::class, 'show']);
            Route::put('quote-requests/{id}', [AdminQuoteRequestController::class, 'update']);
            Route::patch('quote-requests/{id}/status', [AdminQuoteRequestController::class, 'updateStatus']);
            Route::post('quote-requests/{id}/convert-to-order', [AdminQuoteRequestController::class, 'convertToOrder']);

            // Quote attachments — streamed through controller, never via public URL
            Route::get('quote-attachments/{id}/download', [AdminQuoteAttachmentController::class, 'download']);

            // Contact messages
            Route::get('contact-messages', [AdminContactController::class, 'index']);
            Route::get('contact-messages/{id}', [AdminContactController::class, 'show']);
            Route::patch('contact-messages/{id}/status', [AdminContactController::class, 'updateStatus']);

            // Orders
            Route::post('orders/import', [OrderImportController::class, 'import']);
            Route::get('orders/export', [OrderImportController::class, 'export']);
            Route::get('orders', [AdminOrderController::class, 'index']);
            Route::get('orders/{id}', [AdminOrderController::class, 'show']);
            Route::put('orders/{id}', [AdminOrderController::class, 'update']);
            Route::patch('orders/{id}/status', [AdminOrderController::class, 'updateStatus']);
            Route::post('orders/{id}/mark-paid', [AdminOrderController::class, 'markPaid']);
            Route::post('orders/{id}/shipment-events', [AdminOrderShipmentEventController::class, 'store']);
            Route::put('orders/{id}/shipment-events/{event}', [AdminOrderShipmentEventController::class, 'update']);
            Route::delete('orders/{id}/shipment-events/{event}', [AdminOrderShipmentEventController::class, 'destroy']);
            Route::middleware('admin.role:super_admin')->group(function () {
                Route::delete('orders/{id}', [AdminOrderController::class, 'destroy']);
            });

            // EU entry certificates (Gelangensbestätigung) — reverse-charge orders
            Route::get('eu-declarations', [AdminEuDeclarationController::class, 'index']);
            Route::get('eu-declarations/{id}', [AdminEuDeclarationController::class, 'show']);
            Route::get('eu-declarations/{id}/download', [AdminEuDeclarationController::class, 'download']);
            Route::match(['post', 'patch'], 'eu-declarations/{id}/acknowledge', [AdminEuDeclarationController::class, 'acknowledge']);

            // Trade documents
            Route::post('orders/{id}/trade-documents/proforma', [AdminTradeDocumentController::class, 'generateProforma']);
            Route::get('orders/{id}/trade-documents', [AdminTradeDocumentController::class, 'indexForOrder']);
            Route::get('trade-documents/{id}/download', [AdminTradeDocumentController::class, 'download']);

            // Newsletter subscribers
            Route::get('newsletter', [AdminNewsletterController::class, 'index']);
            Route::delete('newsletter/{email}', [AdminNewsletterController::class, 'destroy']);

            // Supplier intelligence
            Route::get('supplier/search', [SupplierController::class, 'search']);
            Route::get('supplier/alibaba-link', [SupplierController::class, 'alibabaLink']);
        });

        // -----------------------------------------------------------------
        // Customer management — super_admin, admin
        // -----------------------------------------------------------------
        Route::middleware('admin.role:super_admin,admin')->group(function () {
            Route::get('customers/export', [AdminCustomerController::class, 'export']);
            Route::get('customers', [AdminCustomerController::class, 'index']);
            Route::get('customers/{id}', [AdminCustomerController::class, 'show']);
            Route::patch('customers/{id}', [AdminCustomerController::class, 'update']);
            Route::delete('customers/{id}', [AdminCustomerController::class, 'destroy']);
            Route::post('customers/{id}/suspend', [AdminCustomerController::class, 'suspend']);
            Route::post('customers/{id}/ban', [AdminCustomerController::class, 'ban']);
            Route::post('customers/{id}/activate', [AdminCustomerController::class, 'activate']);
            Route::post('customers/{id}/unlock', [AdminCustomerController::class, 'unlock']);
            Route::post('customers/{id}/logout-all', [AdminCustomerController::class, 'logoutAll']);
            Route::post('customers/{id}/force-password-reset', [AdminCustomerController::class, 'forcePasswordReset']);
            Route::get('customers/{id}/sessions', [AdminCustomerController::class, 'sessions']);
        });

        // Customer CSV import — super_admin only
        Route::middleware('admin.role:super_admin')->group(function () {
            Route::post('customers/import', [CustomerImportController::class, 'import']);
        });

        // -----------------------------------------------------------------
        // Security dashboard — super_admin, admin
        // -----------------------------------------------------------------
        Route::middleware('admin.role:super_admin,admin')->group(function () {
            Route::get('security/summary', [SecurityController::class, 'summary']);
            Route::get('security/events', [SecurityController::class, 'events']);
        });

        // -----------------------------------------------------------------
        // eBay listing sync — super_admin, admin
        // -----------------------------------------------------------------
        Route::middleware('admin.role:super_admin,admin')->group(function () {
            Route::get('ebay/auth-url', [EbayListingController::class, 'authUrl']);
            Route::get('ebay/listings', [EbayListingController::class, 'listings']);
            Route::post('ebay/sync-all', [EbayListingController::class, 'syncAll']);
            // Canonical URLs (per frontend spec)
            Route::post('products/{id}/ebay/list', [EbayListingController::class, 'listProduct']);
            Route::delete('products/{id}/ebay/remove', [EbayListingController::class, 'removeListing']);
            // Legacy aliases (keep for backward compat)
            Route::post('products/{id}/list-on-ebay', [EbayListingController::class, 'listProduct']);
            Route::delete('products/{id}/ebay-listing', [EbayListingController::class, 'removeListing']);
        });
    });
});
