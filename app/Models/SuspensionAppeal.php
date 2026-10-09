<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuspensionAppeal extends Model
{
    protected $fillable = [
        'user_id', 'message', 'attachments', 'status',
        'admin_response', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}