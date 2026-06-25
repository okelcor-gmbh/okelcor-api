<?php

namespace App\Models;

use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable implements HasLocalePreference
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'customer_type',
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'country',
        'preferred_language',
        'company_name',
        'vat_number',
        'vat_verified',
        'industry',
        'email_verified_at',
        'must_reset_password',
        'is_active',
        'imported_from_wix',
        'status',
        'onboarding_status',
        'last_login_at',
        'last_login_ip',
        'last_login_location',
        'failed_login_count',
        'admin_notes',
        // Segmentation & access (CRM-4)
        'customer_segment',
        'access_level',
        'market_region',
        'approved_for_checkout',
        'approved_for_quotes',
        'approved_for_wholesale_pricing',
        'approved_for_documents',
        // Data quality (CRM-5)
        'data_quality_score',
        'data_quality_flags',
        'normalized_email',
        'normalized_company_name',
        'duplicate_group_id',
        'possible_duplicate_of',
        'data_review_status',
        // Buyer lifecycle (CRM-8)
        'buyer_tier',
        'verification_status',
        'health_score',
        'risk_level',
        'approved_by',
        'approved_at',
        'approval_notes',
        'rejection_reason',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'email_verified_at'    => 'datetime',
        'last_login_at'        => 'datetime',
        'vat_verified'         => 'boolean',
        'must_reset_password'  => 'boolean',
        'is_active'            => 'boolean',
        'imported_from_wix'    => 'boolean',
        'failed_login_count'   => 'integer',
        'onboarding_status'              => 'string',
        'approved_for_checkout'          => 'boolean',
        'approved_for_quotes'            => 'boolean',
        'approved_for_wholesale_pricing' => 'boolean',
        'approved_for_documents'         => 'boolean',
        'data_quality_score'             => 'integer',
        'data_quality_flags'             => 'array',
        // Buyer lifecycle (CRM-8)
        'health_score'                   => 'integer',
        'approved_at'                    => 'datetime',
    ];

    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    public function getIsLockedAttribute(): bool
    {
        return $this->status === 'locked';
    }

    /**
     * The customer's preferred locale for mail & notifications.
     * Implements HasLocalePreference so Laravel auto-localizes anything sent to
     * this customer. Falls back to English for unset / unsupported values.
     */
    public function preferredLocale(): string
    {
        return in_array($this->preferred_language, ['en', 'de', 'fr', 'es'], true)
            ? $this->preferred_language
            : 'en';
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function quoteRequests(): HasMany
    {
        return $this->hasMany(QuoteRequest::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function euDeclarations(): HasMany
    {
        return $this->hasMany(EuDeclaration::class);
    }

    public function loginHistory(): HasMany
    {
        return $this->hasMany(LoginHistory::class)->orderByDesc('created_at');
    }

    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class)->orderByDesc('created_at');
    }

    public function possibleDuplicateOf(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Customer::class, 'possible_duplicate_of');
    }

    public function communications(): HasMany
    {
        return $this->hasMany(CustomerCommunication::class)->orderByDesc('created_at');
    }

    // ── Buyer lifecycle (CRM-8) ──────────────────────────────────────────────

    public function verifications(): HasMany
    {
        return $this->hasMany(CustomerVerification::class)->orderByDesc('created_at');
    }

    public function timelineEvents(): HasMany
    {
        return $this->hasMany(CustomerTimelineEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function accessRequests(): HasMany
    {
        return $this->hasMany(CustomerAccessRequest::class)->orderByDesc('created_at');
    }

    public function approvedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by');
    }
}
