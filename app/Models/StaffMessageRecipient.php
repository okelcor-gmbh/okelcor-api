<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffMessageRecipient extends Model
{
    protected $fillable = [
        'staff_message_id',
        'recipient_admin_user_id',
        'kind',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function message()
    {
        return $this->belongsTo(StaffMessage::class, 'staff_message_id');
    }

    public function recipient()
    {
        return $this->belongsTo(AdminUser::class, 'recipient_admin_user_id');
    }
}
