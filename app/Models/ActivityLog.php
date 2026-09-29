<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'causer_id',
        'subject_type',
        'subject_id',
        'action',
        'description',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Logs visible to a given user: they are the causer, or they are (or own) the subject.
     */
    public function scopeVisibleTo($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('causer_id', $user->id)
                ->orWhere(function ($q2) use ($user) {
                    $q2->where('subject_type', User::class)->where('subject_id', $user->id);
                })
                ->orWhere(function ($q2) use ($user) {
                    // Bookings owned by this user as client or photographer
                    $q2->where('subject_type', Booking::class)
                        ->whereIn('subject_id', function ($sub) use ($user) {
                            $sub->select('id')->from('bookings')
                                ->where('client_id', $user->id)
                                ->orWhere('photographer_id', $user->id);
                        });
                });
        });
    }
}