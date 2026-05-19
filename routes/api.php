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
use App\Http\Controllers\Admin\AdminLogisticsController;
use App\Http\Controllers\Admin\AdminTwoFactorController;
use App\Http\Controllers\Admin\AdminLoginTwoFactorController;
use App\Http\Controllers\Admin\SystemHealthController;
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

    // Mandatory 2FA setup flow — unauthenticated (no Sanctum token yet).
    // Used by admins who have never enabled 2FA. They receive a temp_token at
    // login and must complete setup here before a full session is issued.
    Route::post('admin/2fa/setup/enable',  [AdminTwoFactorController::class, 'setupEnable']);
    Route::post('admin/2fa/setup/confirm', [AdminTwoFactorController::class, 'setupConfirm']);

    // -------------------------------------------------------------------------
    // eBay OAuth callback — PUBLIC (no Sanctum; eBay redirects browser here)
    // State param is the CSRF guard (verified against Cache in controller).
    // -------------------------------------------------------------------------
    Route::get('admin/ebay/callback', [EbayListingController::class, 'callback'])
        ->middleware('throttle:10,1');

    // -------------------------------------------------------------------------
    // Admin — protected by Sanctum token auth
    // Role hierarchy:
    //   super_admin  — full access
    //   admin        — full access
    //   editor       — content only (products, articles, categories, hero slides, brands, media, settings)
    //   order_manager — operations only (orders, quote requests, contacts, newsletter)
    // -------------------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'auth.admin', 'ensure.admin.2fa'])->prefix('admin')->group(function () {

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

        // -----------------------------------------------------------------
        // Admin user management — admins.manage (super_admin only)
        // HARDENED: admin role can no longer manage other admin users
        // -----------------------------------------------------------------
        Route::middleware('permission:admins.manage')->group(function () {
            Route::get('users', [AdminUserController::class, 'index']);
            Route::post('users', [AdminUserController::class, 'store']);
            Route::get('users/{id}', [AdminUserController::class, 'show']);
            Route::put('users/{id}', [AdminUserController::class, 'update']);
            Route::delete('users/{id}', [AdminUserController::class, 'destroy']);
            Route::post('users/{id}/resend-credentials', [AdminUserController::class, 'resendCredentials']);
        });

        // -----------------------------------------------------------------
        // Content — products.edit (super_admin, admin, editor, content_manager)
        // -----------------------------------------------------------------

        // Bulk import / destructive — products.import (super_admin, admin)
        Route::middleware('permission:products.import')->group(function () {
            Route::post('products/import', [ProductImportController::class, 'import']);
            Route::get('products/export', [ProductImportController::class, 'export']);
            Route::delete('products/all', [AdminProductController::class, 'destroyAll']);
            Route::post('fet/engines/import', [AdminFetEngineController::class, 'import']);
        });

        // Content CRUD — products.edit
        Route::middleware('permission:products.edit')->group(function () {
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

            // Articles — articles.manage roles are same as products.edit
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

            // Media — media.upload roles match products.edit
            Route::get('media', [MediaController::class, 'index']);
            Route::post('media', [MediaController::class, 'store']);
            Route::delete('media/{id}', [MediaController::class, 'destroy']);

            // Site settings — settings.manage roles match products.edit
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
        // Orders — granular permission gates
        // -----------------------------------------------------------------

        // Read — orders.view (super_admin, admin, order_manager, sales_manager)
        Route::middleware('permission:orders.view')->group(function () {
            Route::get('orders', [AdminOrderController::class, 'index']);
            Route::get('orders/{id}', [AdminOrderController::class, 'show']);
            Route::get('orders/export', [OrderImportController::class, 'export']);
        });

        // Write — orders.update (super_admin, admin, order_manager)
        Route::middleware('permission:orders.update')->group(function () {
            Route::post('orders/import', [OrderImportController::class, 'import']);
            Route::put('orders/{id}', [AdminOrderController::class, 'update']);
            Route::patch('orders/{id}/status', [AdminOrderController::class, 'updateStatus']);
            Route::patch('orders/{id}/financials', [AdminOrderController::class, 'patchFinancials']);
            Route::post('orders/{id}/shipment-events', [AdminOrderShipmentEventController::class, 'store']);
            Route::put('orders/{id}/shipment-events/{event}', [AdminOrderShipmentEventController::class, 'update']);
            Route::delete('orders/{id}/shipment-events/{event}', [AdminOrderShipmentEventController::class, 'destroy']);
        });

        // Delete — orders.delete (super_admin only)
        Route::middleware('permission:orders.delete')->group(function () {
            Route::delete('orders/{id}', [AdminOrderController::class, 'destroy']);
        });

        // Mark paid — payments.mark_paid (super_admin, admin, order_manager)
        Route::middleware('permission:payments.mark_paid')->group(function () {
            Route::post('orders/{id}/mark-paid', [AdminOrderController::class, 'markPaid']);
        });

        // -----------------------------------------------------------------
        // Quotes — quotes.manage (super_admin, admin, order_manager, sales_manager)
        // -----------------------------------------------------------------
        Route::middleware('permission:quotes.manage')->group(function () {
            Route::get('quote-requests', [AdminQuoteRequestController::class, 'index']);
            Route::get('quote-requests/{id}', [AdminQuoteRequestController::class, 'show']);
            Route::put('quote-requests/{id}', [AdminQuoteRequestController::class, 'update']);
            Route::patch('quote-requests/{id}/status', [AdminQuoteRequestController::class, 'updateStatus']);
            Route::post('quote-requests/{id}/convert-to-order', [AdminQuoteRequestController::class, 'convertToOrder']);
            // Attachments streamed through controller — never via public URL
            Route::get('quote-attachments/{id}/download', [AdminQuoteAttachmentController::class, 'download']);
        });

        // -----------------------------------------------------------------
        // Contacts — contacts.view (super_admin, admin, order_manager, support)
        // -----------------------------------------------------------------
        Route::middleware('permission:contacts.view')->group(function () {
            Route::get('contact-messages', [AdminContactController::class, 'index']);
            Route::get('contact-messages/{id}', [AdminContactController::class, 'show']);
            Route::patch('contact-messages/{id}/status', [AdminContactController::class, 'updateStatus']);
        });

        // -----------------------------------------------------------------
        // EU entry certificates — eu_declarations.manage
        // -----------------------------------------------------------------
        Route::middleware('permission:eu_declarations.manage')->group(function () {
            Route::get('eu-declarations', [AdminEuDeclarationController::class, 'index']);
            Route::get('eu-declarations/{id}', [AdminEuDeclarationController::class, 'show']);
            Route::get('eu-declarations/{id}/download', [AdminEuDeclarationController::class, 'download']);
            Route::match(['post', 'patch'], 'eu-declarations/{id}/acknowledge', [AdminEuDeclarationController::class, 'acknowledge']);
        });

        // -----------------------------------------------------------------
        // Trade documents — trade_documents.manage
        // -----------------------------------------------------------------
        Route::middleware('permission:trade_documents.manage')->group(function () {
            Route::post('orders/{id}/trade-documents/order-confirmation', [AdminTradeDocumentController::class, 'generateOrderConfirmation']);
            Route::post('orders/{id}/trade-documents/proforma', [AdminTradeDocumentController::class, 'generateProforma']);
            Route::post('orders/{id}/generate-commercial-invoice', [AdminTradeDocumentController::class, 'generateCommercialInvoice']);
            Route::post('orders/{id}/generate-packing-list', [AdminTradeDocumentController::class, 'generatePackingList']);
            Route::post('orders/{id}/generate-delivery-note', [AdminTradeDocumentController::class, 'generateDeliveryNote']);
            Route::post('orders/{id}/trade-documents/upload', [AdminTradeDocumentController::class, 'uploadShipmentDocument']);
            Route::get('orders/{id}/trade-documents', [AdminTradeDocumentController::class, 'indexForOrder']);
            Route::post('orders/{orderId}/trade-documents/{documentId}/supersede', [AdminTradeDocumentController::class, 'supersede']);
            Route::get('trade-documents/{id}/download', [AdminTradeDocumentController::class, 'download']);
            Route::post('trade-documents/{id}/send-email', [AdminTradeDocumentController::class, 'sendEmail']);
            Route::delete('trade-documents/{id}', [AdminTradeDocumentController::class, 'destroy']);
        });

        // -----------------------------------------------------------------
        // Logistics dashboard — orders.view
        // -----------------------------------------------------------------
        Route::middleware('permission:orders.view')->group(function () {
            Route::get('logistics/dashboard', [AdminLogisticsController::class, 'dashboard']);
        });

        // -----------------------------------------------------------------
        // Newsletter — newsletter.manage
        // -----------------------------------------------------------------
        Route::middleware('permission:newsletter.manage')->group(function () {
            Route::get('newsletter', [AdminNewsletterController::class, 'index']);
            Route::delete('newsletter/{email}', [AdminNewsletterController::class, 'destroy']);
        });

        // -----------------------------------------------------------------
        // Supplier intelligence — supplier.view
        // -----------------------------------------------------------------
        Route::middleware('permission:supplier.view')->group(function () {
            Route::get('supplier/search', [SupplierController::class, 'search']);
            Route::get('supplier/alibaba-link', [SupplierController::class, 'alibabaLink']);
        });

        // -----------------------------------------------------------------
        // Customer management — customers.manage (super_admin, admin)
        // -----------------------------------------------------------------
        Route::middleware('permission:customers.manage')->group(function () {
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
            Route::get('customers/export', [AdminCustomerController::class, 'export']);
        });

        // Customer CSV import — customers.import (super_admin only)
        Route::middleware('permission:customers.import')->group(function () {
            Route::post('customers/import', [CustomerImportController::class, 'import']);
        });

        // -----------------------------------------------------------------
        // Security dashboard — security.view (super_admin, admin)
        // -----------------------------------------------------------------
        Route::middleware('permission:security.view')->group(function () {
            Route::get('security/summary', [SecurityController::class, 'summary']);
            Route::get('security/events', [SecurityController::class, 'events']);
            Route::get('security/login-history', [SecurityController::class, 'loginHistory']);
            Route::get('security/2fa-status', [SecurityController::class, 'twoFactorStatus']);

            // System health monitor
            Route::get('system/health', [SystemHealthController::class, 'index']);
            Route::get('system/errors', [SystemHealthController::class, 'errors']);
        });

        // Security management — security.manage (super_admin only)
        Route::middleware('permission:security.manage')->group(function () {
            Route::post('security/send-2fa-notices', [SecurityController::class, 'sendTwoFactorNotices']);
        });

        // -----------------------------------------------------------------
        // eBay listing sync — ebay.manage (super_admin, admin)
        // -----------------------------------------------------------------
        Route::middleware('permission:ebay.manage')->group(function () {
            Route::get('ebay/auth-url', [EbayListingController::class, 'authUrl']);
            Route::get('ebay/status', [EbayListingController::class, 'status']);
            Route::get('ebay/readiness', [EbayListingController::class, 'readiness']);
            Route::post('ebay/test-connection', [EbayListingController::class, 'testConnection']);
            Route::get('ebay/policies', [EbayListingController::class, 'policies']);
            Route::post('ebay/disconnect', [EbayListingController::class, 'disconnect']);
            Route::get('ebay/listings', [EbayListingController::class, 'listings']);
            Route::post('ebay/sync-all', [EbayListingController::class, 'syncAll']);
            Route::get('ebay/logs', [EbayListingController::class, 'logs']);
            Route::post('products/{id}/ebay/list', [EbayListingController::class, 'listProduct']);
            Route::patch('products/{id}/ebay/update', [EbayListingController::class, 'updateProduct']);
            Route::delete('products/{id}/ebay/remove', [EbayListingController::class, 'removeListing']);
            Route::post('products/{id}/ebay/refresh-status', [EbayListingController::class, 'refreshStatus']);
        });
    });
});
