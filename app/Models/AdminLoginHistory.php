<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class AdminLoginHistory extends Model
{
    protected $table = 'admin_login_histories';

    public $timestamps = false;

    protected $fillable = [
        'admin_id', 'admin_email', 'success', 'two_fa_used', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'success'    => 'boolean',
        'two_fa_used' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }

    public static function record(AdminUser $admin, bool $success, bool $twoFaUsed, Request $request): void
    {
        try {
            static::create([
                'admin_id'    => $admin->id,
                'admin_email' => $admin->email,
                'success'     => $success,
                'two_fa_used' => $twoFaUsed,
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent(),
            ]);
        } catch (\Throwable $e) {
            // A failed history write must never block a login — log and continue.
            \Illuminate\Support\Facades\Log::error('AdminLoginHistory::record failed', [
                'admin_email' => $admin->email,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
