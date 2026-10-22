<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean', 'requires_proof' => 'boolean'];

    public function module(): BelongsTo
    {
        return $this->belongsTo(TaskModule::class, 'module_key', 'key');
    }
}
