<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactMessage extends Model
{
    public const TOPICS = [
        'ACTIVATION' => 'Activation & payment',
        'WITHDRAWAL' => 'Withdrawals',
        'TASKS' => 'Tasks & timers',
        'MARKETPLACE' => 'Marketplace',
        'ACCOUNT' => 'My account',
        'PRIVACY' => 'Privacy & data',
        'OTHER' => 'Something else',
    ];

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
