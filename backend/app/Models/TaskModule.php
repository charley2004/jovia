<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskModule extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'require_focus' => 'boolean'];

    public function content(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'module_key', 'key');
    }

    public function sessionReward(int $durationSeconds): int
    {
        return $this->reward_type === 'FLAT' ? $this->reward_amount : $this->reward_amount * $durationSeconds;
    }
}
