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
 *   marketing       — content + catalogue + campaigns: everything the person
 *                     optimizing products and running e-mail campaigns touches,
 *                     and nothing operational (no orders, quotes or customers)
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

        // ── System health ─────────────────────────────────────────────────
        // Split out of security.view: hardening that to super_admin-only was
        // right for the audit trail, but it silently took the system-health
        // panel away from `admin` too, and the section has 403'd for them
        // since. Operational state (queue, mail, backups) is an admin
        // concern; login/audit history stays super_admin-only above.
        'system.view'             => ['super_admin', 'admin'],

        // ── Orders ────────────────────────────────────────────────────────
        // `support` added in Session 84, settling a divergence frontend found:
        // the admin panel has been offering the Orders page to support all
        // along and the API has been refusing it, so the page 403'd. Granting
        // is the right half to move — a support role that cannot see an order
        // cannot answer the commonest support question there is. Read only;
        // `orders.update` is unchanged.
        'orders.view'             => ['super_admin', 'admin', 'order_manager', 'sales_manager', 'finance', 'support'],
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

        // Putting a payment state back to what is true. Same holders as
        // mark_paid — the order manager is the person who knows whether the
        // money arrived, and making her ask someone else to correct a record
        // she is responsible for is what produced the original complaint. Kept
        // on its own key so it can be narrowed later without also removing the
        // ability to record a payment: withdrawing a claim of payment and
        // making one are different acts, even where they are the same people.
        'payments.correct_state'  => ['super_admin', 'admin', 'order_manager'],

        // ── Products / content ────────────────────────────────────────────
        'products.view'           => ['super_admin', 'admin', 'editor', 'content_manager', 'sales_manager', 'viewer', 'marketing'],
        'products.edit'           => ['super_admin', 'admin', 'editor', 'content_manager', 'marketing'],
        'products.import'         => ['super_admin', 'admin'],
        'products.delete_all'     => ['super_admin', 'admin'],

        // ── Media ─────────────────────────────────────────────────────────
        'media.upload'            => ['super_admin', 'admin', 'editor', 'content_manager', 'marketing'],

        // ── Content types ─────────────────────────────────────────────────
        'articles.manage'         => ['super_admin', 'admin', 'editor', 'content_manager', 'marketing'],
        'promotions.manage'       => ['super_admin', 'admin', 'editor', 'content_manager', 'marketing'],
        'fet.manage'              => ['super_admin', 'admin', 'editor', 'content_manager', 'marketing'],

        // ── Settings ──────────────────────────────────────────────────────
        'settings.manage'         => ['super_admin', 'admin', 'editor', 'marketing'],

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
        'newsletter.manage'       => ['super_admin', 'admin', 'order_manager', 'marketing'],

        // ── Marketing contacts / bulk email ────────────────────────────────
        'marketing.manage'        => ['super_admin', 'admin', 'order_manager', 'marketing'],

        // ── Customer behaviour analytics ──────────────────────────────────
        // `sales_manager` added here as Session 79 said it should be, in the
        // same change that widened admin_users.role — see the migration.
        'analytics.view'          => ['super_admin', 'admin', 'order_manager', 'editor', 'sales_manager', 'marketing'],

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

        // ── Staff contribution ledger ─────────────────────────────────────
        // Every role holds `staff.self`, and that is the design rather than an
        // oversight: nothing may be measured about a person that the person
        // cannot see, and the surest way to guarantee it is to make their own
        // view the one permission nobody can be missing. A viewer with no other
        // access still gets their own record.
        'staff.self'              => self::ROLES,

        // Seeing someone else's work is a manager's act, and stops with the
        // roles that actually manage people. `sales_manager` is deliberately
        // absent — it is a pipeline role, not a line-management one, and
        // widening it later is a one-line change here.
        'staff.view_team'         => ['super_admin', 'admin', 'order_manager'],

        // Agreeing that self-reported work happened. Narrower than viewing it,
        // because a verification is the thing that turns someone's own claim
        // into a countersigned record.
        'staff.verify'            => ['super_admin', 'admin'],
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
        'marketing',
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
