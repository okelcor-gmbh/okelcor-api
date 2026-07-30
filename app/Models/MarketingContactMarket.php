<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (marketing contact, market) — a contact's membership of a market.
 *
 * A contact can hold several of these at once, which is the whole point of the
 * table: `marketing_contacts.market` could only ever express one. That column
 * still exists as the contact's *primary* market and is kept in sync from here
 * (see MarketingContact::refreshPrimaryMarket).
 */
class MarketingContactMarket extends Model
{
    protected $table = 'marketing_contact_markets';

    protected $fillable = [
        'contact_id',
        'market',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(MarketingContact::class, 'contact_id');
    }
}
