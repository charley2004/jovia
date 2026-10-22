<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FbrClaim extends Model
{
    protected $guarded = ['id'];

    public function drop(): BelongsTo
    {
        return $this->belongsTo(FbrDrop::class, 'fbr_drop_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
