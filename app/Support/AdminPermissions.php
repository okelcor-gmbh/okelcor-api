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
        'orders.view'             => ['super_admin', 'admin', 'order_manager', 'sales_manager'],
        'orders.update'           => ['super_admin', 'admin', 'order_manager'],
        'orders.delete'           => ['super_admin'],
        'orders.import'           => ['super_admin', 'admin', 'order_manager'],
        'orders.export'                     => ['super_admin', 'admin', 'order_manager', 'sales_manager'],
        'orders.approve_financial_revision' => ['super_admin', 'admin'],

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

        // ── Customers ─────────────────────────────────────────────────────
        'customers.view'          => ['super_admin', 'admin', 'support'],
        'customers.create'        => ['super_admin', 'admin', 'sales_manager'],
        'customers.manage'        => ['super_admin', 'admin'],
        'customers.export'        => ['super_admin', 'admin'],
        'customers.import'        => ['super_admin'],

        // ── Supplier intelligence ─────────────────────────────────────────
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
