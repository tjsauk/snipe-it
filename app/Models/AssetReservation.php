<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class AssetReservation extends Model
{
    use SoftDeletes;

    protected $table = 'asset_reservations';

    protected $fillable = [
        'asset_id',
        'user_id',
        'reserved_from',
        'reserved_until',
        'status',
    ];

    protected $casts = [
        'reserved_from' => 'datetime',
        'reserved_until' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Scope: active reservations only */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function getReservedUntilUiAttribute()
    {
        $until = $this->reserved_until ?: $this->reserved_from;
        if (!$until) return null;

        return \Carbon\Carbon::parse($until)->subMinute();
    }
    /** Is this reservation currently in its active window (today between from & until)? */
    public function isCurrentWindow(): bool
    {
        $today = Carbon::today();

        $from = $this->reserved_from;
        $until = $this->reserved_until ?: $this->reserved_from;

        return $today->between($from, $until);
    }

    /** Is this reservation entirely in the future? */
    public function isFutureWindow(): bool
    {
        return $this->reserved_from->isFuture();
    }
}
