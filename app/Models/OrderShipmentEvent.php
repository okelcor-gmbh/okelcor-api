<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderShipmentEvent extends Model
{
    protected $fillable = [
        'order_id',
        'order_ref',
        'event_date',
        'location',
        'status_label',
        'description',
        'admin_user_id',
    ];

    protected $casts = [
        'event_date' => 'date',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
