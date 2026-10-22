<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FbrDrop extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['opens_at' => 'datetime'];

    public function claims(): HasMany
    {
        return $this->hasMany(FbrClaim::class);
    }
}
