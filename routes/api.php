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
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MarketingContactController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\QuoteRequestController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\Admin\AdminArticleController;
use App\Http\Controllers\Admin\AdminBrandController;
use App\Http\Controllers\Admin\AdminCategoryController;
use App\Http\Controllers\Admin\AdminBulkEmailController;
use App\Http\Controllers\Admin\AdminContactController;
use App\Http\Controllers\Admin\AdminHeroSlideController;
use App\Http\Controllers\Admin\AdminMarketingContactController;
use App\Http\Controllers\Admin\AdminNewsletterController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AdminOrderShipmentEventController;
use App\Http\Controllers\Admin\OrderImportController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AdminWorkQueueController;
use App\Http\Controllers\Admin\AdminProductController;
use App\Http\Controllers\Admin\AdminQuoteRequestController;
use App\Http\Controllers\Admin\AdminLeadFunnelController;
use App\Http\Controllers\Admin\AdminQuoteAttachmentController;
use App\Http\Controllers\Admin\AdminSettingController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\ProductImportController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\AdminCustomerController;
use App\Http\Controllers\Admin\AdminCustomerDataQualityController;
use App\Http\Controllers\Admin\AdminCustomerApprovalController;
use App\Http\Controllers\Admin\AdminCustomerVerificationController;
use App\Http\Controllers\Admin\AdminCustomerAccessRequestController;
use App\Http\Controllers\CustomerAccessRequestController;
use App\Http\Controllers\CustomerNotificationController;
use App\Http\Controllers\CustomerNotificationPreferenceController;
use App\Http\Controllers\CustomerTrackingController;
use App\Http\Controllers\Admin\AdminTrackingController;
use App\Http\Controllers\Admin\AdminCrmFollowUpController;
use App\Http\Controllers\Admin\AdminCommunicationController;
use App\Http\Controllers\Admin\AdminCrmEmailController;
use App\Http\Controllers\Admin\CustomerImportController;
use App\Http\Controllers\Admin\EbayListingController;
use App\Http\Controllers\Admin\EbayOrderController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\FetEngineController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\Admin\AdminFetEngineController;
use App\Http\Controllers\Admin\AdminPromotionController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminEuDeclarationController;
use App\Http\Controllers\Admin\AdminOrderFinancialsController;
use App\Http\Controllers\Admin\AdminOrderPaymentMilestoneController;
use App\Http\Controllers\Admin\AdminTradeDocumentController;
use App\Http\Controllers\Admin\AdminLogisticsController;
use App\Http\Controllers\Admin\AdminTwoFactorController;
use App\Http\Controllers\Admin\AdminLoginTwoFactorController;
use App\Http\Controllers\Admin\SystemHealthController;
use App\Http\Controllers\EuDeclarationController;
use App\Http\Controllers\TradeDocumentController;
use App\Http\Controllers\DocumentVerificationController;
use App\Http\Controllers\CustomerQuoteAcceptanceController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\Admin\AdminProposalController;
use App\Http\Controllers\Admin\AdminQuoteRequestItemController;
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
        });

        // Password reset (token submission) — 5 per 15 minutes per IP
        Route::middleware('throttle:password-reset')->group(function () {
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

        // Quote / Order Confirmation acceptance (authenticated customer)
        Route::post('quotes/{id}/accept', [CustomerQuoteAcceptanceController::class, 'acceptQuote']);
        Route::post('quotes/{id}/reject', [CustomerQuoteAcceptanceController::class, 'rejectQuote']);
        Route::post('orders/{ref}/accept-order-confirmation', [CustomerQuoteAcceptanceController::class, 'acceptOrderConfirmation']);
        // CRM-7: Proposal acceptance/rejection by quote ref (authenticated)
        Route::post('quotes/{ref}/accept-proposal', [CustomerQuoteAcceptanceController::class, 'acceptProposal']);
        Route::post('quotes/{ref}/reject-proposal', [CustomerQuoteAcceptanceController::class, 'rejectProposal']);
        Route::post('orders/{ref}/reject-order-confirmation', [CustomerQuoteAcceptanceController::class, 'rejectOrderConfirmation']);

        // CRM-8: Customer-initiated access requests (portal)
        Route::get('customer/access-requests', [CustomerAccessRequestController::class, 'index']);
        Route::post('customer/access-requests', [CustomerAccessRequestController::class, 'store']);

        // Customer portal notifications ("Email = Inbox") — scoped to self
        Route::get('customer/notifications', [CustomerNotificationController::class, 'index']);
        Route::get('customer/notifications/unread-count', [CustomerNotificationController::class, 'unreadCount']);
        Route::post('customer/notifications/read-all', [CustomerNotificationController::class, 'readAll']);
        Route::post('customer/notifications/{id}/read', [CustomerNotificationController::class, 'markRead']);
        Route::post('customer/notifications/{id}/dismiss', [CustomerNotificationController::class, 'dismiss']);
        Route::get('customer/notification-preferences', [CustomerNotificationPreferenceController::class, 'show']);
        Route::put('customer/notification-preferences', [CustomerNotificationPreferenceController::class, 'update']);

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

        // Live delivery tracking (Traccar) — scoped to the customer's own order
        Route::get('orders/{ref}/tracking', [CustomerTrackingController::class, 'show'])
            ->middleware('throttle:tracking');
    });

    // Invoice download — protected by customer Bearer token
    Route::middleware('auth.customer')->group(function () {
        Route::get('invoices/{invoice}/download', [InvoiceDownloadController::class, 'download'])
            ->name('invoices.download');
    });

    // -------------------------------------------------------------------------
    // Public — no auth required
    // -------------------------------------------------------------------------

    // Public content reads — 120/min per IP (scraping / DDoS defence)
    Route::middleware('throttle:api-public')->group(function () {
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

        // i18n — locale auto-detection (country -> language)
        Route::get('i18n/locales', [LocaleController::class, 'index']);
        Route::get('i18n/resolve', [LocaleController::class, 'resolve']);
    });

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

    // Document verification — public, rate limited: 60/min
    Route::middleware('throttle:public-doc-verify')->group(function () {
        Route::get('documents/verify/{number}', [DocumentVerificationController::class, 'verify']);
    });

    // Order confirmation acceptance via secure token (non-account customers) — 20/min per IP
    Route::middleware('throttle:acceptance-links')->group(function () {
        // Token-only routes (canonical) — token is the sole key, no ref needed
        Route::get('documents/acceptance/{token}',         [CustomerQuoteAcceptanceController::class, 'acceptanceInfo']);
        Route::post('documents/acceptance/{token}/accept', [CustomerQuoteAcceptanceController::class, 'acceptByToken']);
        Route::post('documents/acceptance/{token}/reject', [CustomerQuoteAcceptanceController::class, 'rejectByToken']);

        // CRM-7: Public proposal acceptance via token (token = 64-char hex)
        Route::get('proposals/{token}',         [ProposalController::class, 'show']);
        Route::post('proposals/{token}/accept', [ProposalController::class, 'accept']);
        Route::post('proposals/{token}/reject', [ProposalController::class, 'reject']);

        // Legacy ref+token routes — kept for backwards compatibility and existing emails
        Route::get('orders/{ref}/accept-confirmation',  [CustomerQuoteAcceptanceController::class, 'confirmationTokenInfo']);
        Route::post('orders/{ref}/accept-confirmation', [CustomerQuoteAcceptanceController::class, 'acceptConfirmationByToken']);
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

        // SEO/ads landing-page lead intake (/tyre-wholesaler)
        Route::post('leads/tyre-wholesaler', [QuoteRequestController::class, 'storeWholesalerLead']);
    });

    // Newsletter confirmation (GET — no rate limit needed)
    Route::get('newsletter/confirm/{token}', [NewsletterController::class, 'confirm']);

    // Marketing contact unsubscribe (GET — no rate limit needed)
    Route::get('marketing-contacts/unsubscribe/{token}', [MarketingContactController::class, 'unsubscribe']);

    // -------------------------------------------------------------------------
    // Admin auth (no Sanctum guard — these issue the token)
    // -------------------------------------------------------------------------
    // Admin login — 5/min per IP+email (outer request guard; per-failure inline
    // counter in AuthController provides complementary lockout behaviour)
    Route::middleware('throttle:admin-login')->post('admin/login', [AuthController::class, 'login']);

    // Admin 2FA verification + mandatory setup — 10 per 5 min per IP
    Route::middleware('throttle:admin-2fa')->group(function () {
        Route::post('admin/login/2fa', AdminLoginTwoFactorController::class);

        // Mandatory 2FA setup flow — unauthenticated (no Sanctum token yet).
        // Used by admins who have never enabled 2FA. They receive a temp_token at
        // login and must complete setup here before a full session is issued.
        Route::post('admin/2fa/setup/enable',  [AdminTwoFactorController::class, 'setupEnable']);
        Route::post('admin/2fa/setup/confirm', [AdminTwoFactorController::class, 'setupConfirm']);
    });

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

        // Notifications (CRM-3 / CRM-3B) — all authenticated admin users, scoped to self
        Route::get('notifications', [AdminNotificationController::class, 'index']);
        Route::get('notifications/unread-count', [AdminNotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [AdminNotificationController::class, 'readAll']);
        Route::post('notifications/{id}/read', [AdminNotificationController::class, 'markRead']);
        Route::post('notifications/{id}/dismiss', [AdminNotificationController::class, 'dismiss']);

        // Work queue (CRM-3B) — actionable work for the logged-in admin
        Route::get('my-work', [AdminWorkQueueController::class, 'index']);

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
        Route::middleware(['permission:admins.manage', 'throttle:admin-sensitive'])->group(function () {
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
        Route::middleware(['permission:products.import', 'throttle:admin-sensitive'])->group(function () {
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
            Route::middleware('throttle:article-upload')->group(function () {
                Route::post('articles/{id}/image', [AdminArticleController::class, 'uploadImage']);
                Route::post('articles/{id}/og-image', [AdminArticleController::class, 'uploadOgImage']);
                Route::post('articles/{id}/body-image', [AdminArticleController::class, 'uploadBodyImage']);
            });

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
            Route::post('orders/{id}/financials/revision-request', [AdminOrderFinancialsController::class, 'requestRevision']);
            Route::post('orders/{id}/shipment-events', [AdminOrderShipmentEventController::class, 'store']);
            Route::put('orders/{id}/shipment-events/{event}', [AdminOrderShipmentEventController::class, 'update']);
            Route::delete('orders/{id}/shipment-events/{event}', [AdminOrderShipmentEventController::class, 'destroy']);
        });

        // Financial revision approval — orders.approve_financial_revision (super_admin, admin)
        Route::middleware('permission:orders.approve_financial_revision')->group(function () {
            Route::post('orders/{id}/financials/approve-revision', [AdminOrderFinancialsController::class, 'approveRevision']);
            Route::post('orders/{id}/financials/reject-revision', [AdminOrderFinancialsController::class, 'rejectRevision']);
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
        // Payment milestones — payments.mark_paid (super_admin, admin, order_manager)
        // -----------------------------------------------------------------
        Route::middleware('permission:payments.mark_paid')->prefix('orders/{id}/payment-milestones')->group(function () {
            Route::post('deposit-paid',     [AdminOrderPaymentMilestoneController::class, 'markDepositPaid']);
            Route::post('balance-due',      [AdminOrderPaymentMilestoneController::class, 'markBalanceDue']);
            Route::post('balance-paid',     [AdminOrderPaymentMilestoneController::class, 'markBalancePaid']);
            Route::post('release-shipment', [AdminOrderPaymentMilestoneController::class, 'releaseShipment']);
            Route::post('resend-email',     [AdminOrderPaymentMilestoneController::class, 'resendEmail']);
        });

        // -----------------------------------------------------------------
        // Quotes — read + pipeline (quotes.manage = view+write for SM; quotes.update = mutations only)
        // -----------------------------------------------------------------

        // Read — quotes.manage (sales_manager can view)
        Route::middleware('permission:quotes.manage')->group(function () {
            Route::get('quote-requests/summary', [AdminQuoteRequestController::class, 'summary']);
            Route::get('quote-requests/funnel', [AdminLeadFunnelController::class, 'index']);
            Route::get('quote-requests', [AdminQuoteRequestController::class, 'index']);
            Route::get('quote-requests/{id}', [AdminQuoteRequestController::class, 'show']);
            // Attachments streamed through controller — never via public URL
            Route::get('quote-attachments/{id}/download', [AdminQuoteAttachmentController::class, 'download']);
        });

        // Write — quotes.update (order_manager + admin; sales_manager read-only)
        Route::middleware('permission:quotes.update')->group(function () {
            Route::put('quote-requests/{id}', [AdminQuoteRequestController::class, 'update']);
            Route::patch('quote-requests/{id}/status', [AdminQuoteRequestController::class, 'updateStatus']);
            Route::post('quote-requests/{id}/convert-to-order', [AdminQuoteRequestController::class, 'convertToOrder']);
            // CRM-2 quality review
            Route::post('quote-requests/{id}/qualify', [AdminQuoteRequestController::class, 'qualify']);
            Route::post('quote-requests/{id}/reject', [AdminQuoteRequestController::class, 'rejectInquiry']);
            Route::post('quote-requests/{id}/spam', [AdminQuoteRequestController::class, 'markSpam']);
            // CRM-3 pipeline
            Route::post('quote-requests/{id}/assign', [AdminQuoteRequestController::class, 'assign']);
            Route::post('quote-requests/{id}/qualification', [AdminQuoteRequestController::class, 'updateQualification']);
            Route::post('quote-requests/{id}/notes', [AdminQuoteRequestController::class, 'updateNotes']);
        });

        // Convert to customer — customers.manage required
        Route::middleware('permission:customers.manage')->group(function () {
            Route::post('quote-requests/{id}/convert-to-customer', [AdminQuoteRequestController::class, 'convertToCustomer']);
        });

        // -----------------------------------------------------------------
        // CRM — follow-ups, communications, email (CRM-6)
        // -----------------------------------------------------------------

        // Follow-up management — crm.view (read) / crm.update (write)
        Route::middleware('permission:crm.view')->group(function () {
            Route::get('crm/follow-ups', [AdminCrmFollowUpController::class, 'index']);
            Route::get('crm/email-templates', [AdminCrmEmailController::class, 'templates']);
        });

        Route::middleware('permission:crm.update')->group(function () {
            Route::post('crm/follow-ups/{id}/complete', [AdminCrmFollowUpController::class, 'complete']);
            Route::post('crm/follow-ups/{id}/reschedule', [AdminCrmFollowUpController::class, 'reschedule']);
            Route::post('quote-requests/{id}/send-follow-up-email', [AdminCrmEmailController::class, 'sendFollowUpEmail']);
        });

        // Communication log — per customer (crm.view / crm.update)
        Route::middleware('permission:crm.view')->group(function () {
            Route::get('customers/{id}/communications', [AdminCommunicationController::class, 'indexForCustomer']);
            Route::get('quote-requests/{id}/communications', [AdminCommunicationController::class, 'indexForQuote']);
        });

        Route::middleware('permission:crm.update')->group(function () {
            Route::post('customers/{id}/communications', [AdminCommunicationController::class, 'storeForCustomer']);
            Route::post('quote-requests/{id}/communications', [AdminCommunicationController::class, 'storeForQuote']);
        });

        // -----------------------------------------------------------------
        // Quote request items — CRM-7 Fix 2 (quotes.update)
        // -----------------------------------------------------------------
        Route::middleware('permission:quotes.update')->group(function () {
            Route::get('quote-requests/{id}/items',                              [AdminQuoteRequestItemController::class, 'index']);
            Route::post('quote-requests/{id}/items',                             [AdminQuoteRequestItemController::class, 'store']);
            // import-from-inquiry must be before {itemId} to avoid route conflict
            Route::post('quote-requests/{id}/items/import-from-inquiry',         [AdminQuoteRequestItemController::class, 'importFromInquiry']);
            Route::patch('quote-requests/{id}/items/{itemId}',                   [AdminQuoteRequestItemController::class, 'update']);
            Route::delete('quote-requests/{id}/items/{itemId}',                  [AdminQuoteRequestItemController::class, 'destroy']);
        });

        // -----------------------------------------------------------------
        // Proposals — CRM-7 (proposals.manage)
        // -----------------------------------------------------------------
        Route::middleware('permission:proposals.manage')->group(function () {
            Route::post('quote-requests/{id}/proposal/draft',         [AdminProposalController::class, 'draft']);
            Route::post('quote-requests/{id}/proposal/mark-ready',    [AdminProposalController::class, 'markReady']);
            Route::post('quote-requests/{id}/proposal/send',          [AdminProposalController::class, 'send']);
            Route::post('quote-requests/{id}/proposal/generate-link', [AdminProposalController::class, 'generateLink']);
            Route::post('quote-requests/{id}/proposal/void',          [AdminProposalController::class, 'void']);
            Route::get('quote-requests/{id}/proposal/download',       [AdminProposalController::class, 'download']);
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
            Route::post('trade-documents/{id}/void', [AdminTradeDocumentController::class, 'void']);
            Route::delete('trade-documents/{id}', [AdminTradeDocumentController::class, 'destroy']);
            Route::post('orders/{id}/generate-acceptance-link', [AdminTradeDocumentController::class, 'generateAcceptanceLink']);
            Route::post('orders/{id}/send-acceptance-request',  [AdminTradeDocumentController::class, 'sendAcceptanceRequest']);
            Route::post('orders/{id}/acceptance/send',           [AdminTradeDocumentController::class, 'sendAcceptanceRequest']);
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
        // Marketing contacts + bulk email — marketing.manage
        // -----------------------------------------------------------------
        Route::middleware('permission:marketing.manage')->group(function () {
            Route::get('marketing-contacts', [AdminMarketingContactController::class, 'index']);
            Route::get('marketing-contacts/stats', [AdminMarketingContactController::class, 'stats']);
            Route::post('marketing-contacts/import', [AdminMarketingContactController::class, 'import']);
            Route::delete('marketing-contacts/{id}', [AdminMarketingContactController::class, 'destroy']);

            Route::get('bulk-emails', [AdminBulkEmailController::class, 'index']);
            Route::get('bulk-emails/recipient-count', [AdminBulkEmailController::class, 'recipientCount']);
            Route::get('bulk-emails/{id}', [AdminBulkEmailController::class, 'show']);
            Route::post('bulk-emails', [AdminBulkEmailController::class, 'store']);
        });

        // -----------------------------------------------------------------
        // Supplier intelligence — supplier.view
        // -----------------------------------------------------------------
        Route::middleware('permission:supplier.view')->group(function () {
            Route::get('supplier/search', [SupplierController::class, 'search']);
            Route::get('supplier/alibaba-link', [SupplierController::class, 'alibabaLink']);
        });

        // -----------------------------------------------------------------
        // Customer onboarding ("Add Customer") — customers.create
        // (super_admin, admin, sales_manager). Kept separate from
        // customers.manage so sales managers can onboard buyers without
        // gaining full customer-management (edit/delete/security) rights.
        // -----------------------------------------------------------------
        Route::middleware('permission:customers.create')->group(function () {
            Route::post('customers', [AdminCustomerController::class, 'store']);
        });

        // -----------------------------------------------------------------
        // Customer management — customers.manage (super_admin, admin)
        // -----------------------------------------------------------------
        Route::middleware('permission:customers.manage')->group(function () {
            Route::get('customers', [AdminCustomerController::class, 'index']);
            Route::get('customers/export', [AdminCustomerController::class, 'export']);

            // Data quality overview (CRM-5) — static routes before {id} to avoid routing conflict
            Route::get('customers/data-quality/summary', [AdminCustomerDataQualityController::class, 'summary']);
            Route::get('customers/data-quality/issues', [AdminCustomerDataQualityController::class, 'issues']);
            Route::get('customers/{id}', [AdminCustomerController::class, 'show']);
            Route::patch('customers/{id}', [AdminCustomerController::class, 'update']);
            Route::delete('customers/{id}', [AdminCustomerController::class, 'destroy']);

            // Security actions
            Route::post('customers/{id}/suspend', [AdminCustomerController::class, 'suspend']);
            Route::post('customers/{id}/ban', [AdminCustomerController::class, 'ban']);
            Route::post('customers/{id}/activate', [AdminCustomerController::class, 'activate']);
            Route::post('customers/{id}/unlock', [AdminCustomerController::class, 'unlock']);
            Route::post('customers/{id}/logout-all', [AdminCustomerController::class, 'logoutAll']);
            Route::post('customers/{id}/force-password-reset', [AdminCustomerController::class, 'forcePasswordReset']);
            Route::get('customers/{id}/sessions', [AdminCustomerController::class, 'sessions']);

            // Onboarding actions (CRM-1)
            Route::post('customers/{id}/approve', [AdminCustomerController::class, 'approve']);
            Route::post('customers/{id}/reject', [AdminCustomerController::class, 'reject']);
            Route::post('customers/{id}/invite', [AdminCustomerController::class, 'invite']);
            Route::post('customers/{id}/resend-invite', [AdminCustomerController::class, 'resendInvite']);
            Route::post('customers/{id}/block', [AdminCustomerController::class, 'blockOnboarding']);

            // Segmentation & access control (CRM-4)
            Route::patch('customers/{id}/access', [AdminCustomerController::class, 'updateAccess']);

            // Data quality per-customer actions (CRM-5)
            Route::post('customers/{id}/data-quality/recalculate', [AdminCustomerDataQualityController::class, 'recalculate']);
            Route::post('customers/{id}/data-quality/mark-clean', [AdminCustomerDataQualityController::class, 'markClean']);
            Route::post('customers/{id}/data-quality/ignore-duplicate', [AdminCustomerDataQualityController::class, 'ignoreDuplicate']);
            Route::post('customers/{id}/data-quality/link-duplicate', [AdminCustomerDataQualityController::class, 'linkDuplicate']);
            Route::post('customers/{id}/data-quality/merge-preview', [AdminCustomerDataQualityController::class, 'mergePreview']);
        });

        // -----------------------------------------------------------------
        // CRM-8 Buyer lifecycle — reads (customers.view), writes (customers.manage)
        // -----------------------------------------------------------------
        Route::middleware('permission:customers.view')->group(function () {
            Route::get('customer-approvals', [AdminCustomerApprovalController::class, 'index']);
            Route::get('customers/{id}/timeline', [AdminCustomerApprovalController::class, 'timeline']);
            Route::get('customers/{id}/verifications', [AdminCustomerVerificationController::class, 'index']);
            Route::get('customer-access-requests', [AdminCustomerAccessRequestController::class, 'index']);
        });

        Route::middleware('permission:customers.manage')->group(function () {
            // Approval profiles, tier, risk, health
            Route::post('customers/{id}/approval-profile', [AdminCustomerApprovalController::class, 'applyProfile']);
            Route::post('customers/{id}/set-tier', [AdminCustomerApprovalController::class, 'setTier']);
            Route::post('customers/{id}/risk', [AdminCustomerApprovalController::class, 'setRisk']);
            Route::post('customers/{id}/health/recalculate', [AdminCustomerApprovalController::class, 'recalculateHealth']);

            // Verifications
            Route::post('customers/{id}/verifications', [AdminCustomerVerificationController::class, 'store']);
            Route::patch('customers/{id}/verifications/{verificationId}', [AdminCustomerVerificationController::class, 'update']);

            // Access requests review
            Route::post('customer-access-requests/{id}/approve', [AdminCustomerAccessRequestController::class, 'approve']);
            Route::post('customer-access-requests/{id}/reject', [AdminCustomerAccessRequestController::class, 'reject']);
        });

        // Customer CSV import — customers.import (super_admin only)
        Route::middleware('permission:customers.import')->group(function () {
            Route::post('customers/import', [CustomerImportController::class, 'import']);
        });

        // -----------------------------------------------------------------
        // Fleet / GPS tracking (Traccar) — reads (tracking.view)
        // -----------------------------------------------------------------
        Route::middleware('permission:tracking.view')->prefix('tracking')->group(function () {
            Route::get('status', [AdminTrackingController::class, 'status']);
            Route::get('devices', [AdminTrackingController::class, 'devices']);
            Route::get('geofences', [AdminTrackingController::class, 'geofences']);
            Route::get('devices/{id}', [AdminTrackingController::class, 'device']);
            Route::get('devices/{id}/route', [AdminTrackingController::class, 'route']);
            Route::get('devices/{id}/trips', [AdminTrackingController::class, 'trips']);
        });

        // Assign a Traccar device to an order (write) — orders.update
        Route::middleware('permission:orders.update')->group(function () {
            Route::put('tracking/orders/{id}/device', [AdminTrackingController::class, 'assignDevice']);
            Route::put('tracking/orders/{id}/destination', [AdminTrackingController::class, 'setDestination']);
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
        Route::middleware(['permission:security.manage', 'throttle:admin-sensitive'])->group(function () {
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
            Route::get('ebay/logs', [EbayListingController::class, 'logs']);
            Route::post('products/{id}/ebay/list', [EbayListingController::class, 'listProduct']);
            Route::patch('products/{id}/ebay/update', [EbayListingController::class, 'updateProduct']);
            Route::delete('products/{id}/ebay/remove', [EbayListingController::class, 'removeListing']);
            Route::post('products/{id}/ebay/refresh-status', [EbayListingController::class, 'refreshStatus']);

            // eBay order sync (Sell Fulfillment API) — sync operations throttled separately
            Route::get('ebay/orders', [EbayOrderController::class, 'index']);
            Route::get('ebay/order-sync-logs', [EbayOrderController::class, 'logs']);
            Route::middleware('throttle:ebay-sync')->group(function () {
                Route::post('ebay/sync-all', [EbayListingController::class, 'syncAll']);
                Route::post('ebay/orders/sync', [EbayOrderController::class, 'sync']);
                Route::post('ebay/orders/{ebayOrderId}/sync', [EbayOrderController::class, 'syncOne']);
            });
        });
    });
});
