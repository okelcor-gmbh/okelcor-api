<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One query against the public catalogue.
 *
 * Append-only and never updated: it is a record of something that happened.
 */
class SearchEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'term',
        'raw_term',
        'filters',
        'brand',
        'category',
        'season',
        'width',
        'height',
        'rim',
        'results_count',
        'has_results',
        'customer_id',
        'visitor_hash',
        'country',
        'locale',
        'created_at',
    ];

    protected $casts = [
        'filters'       => 'array',
        'results_count' => 'integer',
        'has_results'   => 'boolean',
        'created_at'    => 'datetime',
    ];

    /**
     * The visitor digest is an internal key for counting, never something to
     * hand out — it is the one field that could be correlated if it leaked.
     */
    protected $hidden = ['visitor_hash'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeFoundNothing($query)
    {
        return $query->where('has_results', false);
    }

    public function scopeSince($query, \DateTimeInterface $from)
    {
        return $query->where('created_at', '>=', $from);
    }
}
