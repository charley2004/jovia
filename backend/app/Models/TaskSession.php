<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskSession extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['last_beat_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime'];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(TaskModule::class, 'module_key', 'key');
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'moduleKey' => $this->module_key,
            'contentId' => $this->content_id,
            'contentTitle' => $this->content_title,
            'durationSeconds' => $this->duration_seconds,
            'activeSeconds' => $this->active_seconds,
            'status' => $this->status,
            'reward' => $this->reward,
            'reviewNote' => $this->review_note,
            'startedAt' => $this->started_at,
            'completedAt' => $this->completed_at,
        ];
    }
}
