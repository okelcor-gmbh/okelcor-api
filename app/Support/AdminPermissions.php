<?php

namespace App\Support;

/**
 * Single source of truth for role → permission mapping.
 *
 * All role checks in middleware, controllers, and the auth payload
 * derive from this class. Never hardcode role arrays elsewhere.
 *
 * Roles (ordered by access level):
 *   super_admin     — full system access + admin management
 *   admin           — operational access, no admin user management
 *   order_manager   — orders, quotes, EU declarations, trade docs, newsletter
 *   finance         — invoice reconciliation + the finance half of order sign-off
 *   sales_manager   — read-only orders + quotes (view pipeline, no mutations)
 *   content_manager — all content (products, articles, hero, promotions, media)
 *   support         — customer read-only + contacts
 *   editor          — products, articles, categories, hero, brands, media, settings
 *   viewer          — product catalogue read-only
 */
class AdminPermissions
{
    /**
     * permission → roles that hold it
     */
    public const MAP = [
        // ── Admin user management ─────────────────────────────────────────
        'admins.manage'           => ['super_admin'],
        'admins.roles.assign'     => ['super_admin'],

        // ── Security / audit ──────────────────────────────────────────────
        'security.view'           => ['super_admin'],   // hardened: super_admin only
        'security.manage'         => ['super_admin'],

        // ── Orders ────────────────────────────────────────────────────────
        'orders.view'             => ['super_admin', 'admin', 'order_manager', 'sales_manager', 'finance'],
        'orders.update'           => ['super_admin', 'admin', 'order_manager'],
        'orders.delete'           => ['super_admin'],
        'orders.import'           => ['super_admin', 'admin', 'order_manager'],
        'orders.export'                     => ['super_admin', 'admin', 'order_manager', 'sales_manager', 'finance'],
        'orders.approve_financial_revision' => ['super_admin', 'admin'],

        // ── Order confirmation sign-off ───────────────────────────────────
        // Two signatures, and the business asked for exactly two roles to hold
        // them: the order manager and finance. `admin` is deliberately NOT on
        // either list — a control that any administrator can satisfy on their
        // own is not a separation of duties, it is a checkbox. super_admin is
        // on both as break-glass, and OrderSignoffService still refuses to let
        // one person fill both slots, so holding both permissions buys nobody
        // the ability to self-approve.
        'orders.signoff_ops'      => ['super_admin', 'order_manager'],
        'orders.signoff_finance'  => ['super_admin', 'finance'],

        // Sending a confirmation that is not fully signed. Recorded as
        // `signoff_bypassed` on the order, which is the point: the escape hatch
        // exists so the business is never stuck, and leaves a mark so it is
        // never quietly routine.
        'orders.signoff_bypass'   => ['super_admin'],

        // ── Finance system reconciliation (sevDesk) ───────────────────────
        'finance.view'            => ['super_admin', 'admin', 'finance', 'order_manager'],
        'finance.manage'          => ['super_admin', 'admin', 'finance'],

        // ── Payments ──────────────────────────────────────────────────────
        'payments.mark_paid'      => ['super_admin', 'admin', 'order_manager'],
        'payments.refund'         => ['super_admin', 'admin'],

        // ── Products / content ────────────────────────────────────────────
        'products.view'           => ['super_admin', 'admin', 'editor', 'content_manager', 'sales_manager', 'viewer'],
        'products.edit'           => ['super_admin', 'admin', 'editor', 'content_manager'],
        'products.import'         => ['super_admin', 'admin'],
        'products.delete_all'     => ['super_admin', 'admin'],

        // ── Media ─────────────────────────────────────────────────────────
        'media.upload'            => ['super_admin', 'admin', 'editor', 'content_manager'],

        // ── Content types ─────────────────────────────────────────────────
        'articles.manage'         => ['super_admin', 'admin', 'editor', 'content_manager'],
        'promotions.manage'       => ['super_admin', 'admin', 'editor', 'content_manager'],
        'fet.manage'              => ['super_admin', 'admin', 'editor', 'content_manager'],

        // ── Settings ──────────────────────────────────────────────────────
        'settings.manage'         => ['super_admin', 'admin', 'editor'],

        // ── Quotes ────────────────────────────────────────────────────────
        'quotes.manage'           => ['super_admin', 'admin', 'order_manager', 'sales_manager'],
        'quotes.view'             => ['super_admin', 'admin', 'order_manager', 'sales_manager'],
        'quotes.update'           => ['super_admin', 'admin', 'order_manager'],

        // ── CRM (follow-ups, communications, email templates) ─────────────
        'crm.view'                => ['super_admin', 'admin', 'order_manager', 'sales_manager'],
        'crm.update'              => ['super_admin', 'admin', 'order_manager'],

        // ── Proposals (CRM-7 proposal lifecycle) ──────────────────────────
        'proposals.manage'        => ['super_admin', 'admin', 'order_manager', 'sales_manager'],

        // ── Contacts ──────────────────────────────────────────────────────
        'contacts.view'           => ['super_admin', 'admin', 'order_manager', 'support'],

        // ── EU entry certificates ─────────────────────────────────────────
        'eu_declarations.manage'  => ['super_admin', 'admin', 'order_manager'],

        // ── Trade documents ───────────────────────────────────────────────
        'trade_documents.manage'  => ['super_admin', 'admin', 'order_manager'],

        // ── Newsletter ────────────────────────────────────────────────────
        'newsletter.manage'       => ['super_admin', 'admin', 'order_manager'],

        // ── Marketing contacts / bulk email ────────────────────────────────
        'marketing.manage'        => ['super_admin', 'admin', 'order_manager'],

        // ── Customer behaviour analytics ──────────────────────────────────
        // `sales_manager` added here as Session 79 said it should be, in the
        // same change that widened admin_users.role — see the migration.
        'analytics.view'          => ['super_admin', 'admin', 'order_manager', 'editor', 'sales_manager'],

        // ── Customers ─────────────────────────────────────────────────────
        'customers.view'          => ['super_admin', 'admin', 'support'],
        'customers.create'        => ['super_admin', 'admin', 'sales_manager'],
        'customers.manage'        => ['super_admin', 'admin'],
        'customers.export'        => ['super_admin', 'admin'],
        'customers.import'        => ['super_admin'],

        // ── Supplier intelligence ─────────────────────────────────────────
        // ── Partner sales log ─────────────────────────────────────────────
        // `sales_manager` added here as Session 73 said it should be, in the
        // same change that widened admin_users.role. Read and export only —
        // verifying and correcting a partner's reported figure stay with the
        // roles that already held them, because widening the column was
        // permission to store the role, not a decision to expand what it does.
        'partners.view'           => ['super_admin', 'admin', 'order_manager', 'sales_manager'],
        'partners.manage'         => ['super_admin', 'admin'],
        'partner_sales.view'      => ['super_admin', 'admin', 'order_manager', 'sales_manager'],
        'partner_sales.verify'    => ['super_admin', 'admin', 'order_manager'],
        'partner_sales.export'    => ['super_admin', 'admin', 'order_manager', 'sales_manager'],

        // Rewriting a figure a partner reported is a stronger act than signing
        // one off, so it gets its own key even though the role list currently
        // matches `verify`. Narrowing it later is then a one-line change here
        // rather than a new permission threaded through routes and tests.
        'partner_sales.correct'   => ['super_admin', 'admin', 'order_manager'],

        'supplier.view'           => ['super_admin', 'admin', 'order_manager'],

        // ── Carrier shipment tracking (GLS / DHL / ocean freight) ─────────
        'tracking.view'           => ['super_admin', 'admin', 'order_manager', 'sales_manager'],

        // ── eBay ──────────────────────────────────────────────────────────
        'ebay.manage'             => ['super_admin', 'admin'],
    ];

    /**
     * All valid admin role names.
     */
    public const ROLES = [
        'super_admin',
        'admin',
        'order_manager',
        'finance',
        'sales_manager',
        'content_manager',
        'support',
        'editor',
        'viewer',
    ];

    /**
     * Return all permission keys granted to a given role.
     */
    public static function for(string $role): array
    {
        return array_values(
            array_keys(
                array_filter(self::MAP, fn (array $roles) => in_array($role, $roles, true))
            )
        );
    }

    /**
     * Check whether a role holds a specific permission.
     */
    public static function can(string $role, string $permission): bool
    {
        return in_array($role, self::MAP[$permission] ?? [], true);
    }
}
