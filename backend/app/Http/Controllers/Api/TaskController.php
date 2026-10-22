<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\TaskModule;
use App\Models\TaskSession;
use App\Models\Transaction;
use App\Services\Wallet;
use App\Support\Lagos;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Countdown timers. The page sends a heartbeat every ~5 s while it is visible;
// a gap longer than BEAT_GRACE means the member left, and that time is not counted.
class TaskController extends Controller
{
    private const BEAT_GRACE_MS = 20000;

    // Paid today plus rewards waiting for review, so pending proofs can't exceed the daily cap
    private static function earnedToday(int $userId, string $moduleKey): int
    {
        $since = Lagos::startOfDay();
        $paid = Transaction::where('user_id', $userId)->where('type', 'TASK')->where('module', $moduleKey)->where('created_at', '>=', $since)->sum('amount');
        $pending = TaskSession::where('user_id', $userId)->where('module_key', $moduleKey)->where('status', 'PENDING_REVIEW')->where('started_at', '>=', $since)->sum('reward');

        return (int) $paid + (int) $pending;
    }

    // Count whole seconds since the last heartbeat if it arrived within the grace window.
    // last_beat_at only advances by the seconds counted, so fractions carry to the next beat.
    private static function accrue(TaskSession $s): array
    {
        $nowMs = now()->getTimestampMs();
        $lastMs = $s->last_beat_at->getTimestampMs();
        $gap = $nowMs - $lastMs;
        if ($gap < 0 || $gap > self::BEAT_GRACE_MS) {
            return ['active_seconds' => $s->active_seconds, 'last_beat_at' => Carbon::createFromTimestampMs($nowMs)];
        }
        $add = intdiv($gap, 1000);

        return [
            'active_seconds' => min($s->duration_seconds, $s->active_seconds + $add),
            'last_beat_at' => Carbon::createFromTimestampMs($lastMs + $add * 1000),
        ];
    }

    private function running(Request $request, string $id): TaskSession
    {
        $s = TaskSession::with('module')->where('user_id', $request->user()->id)->find($id);
        if (! $s) {
            abort(404, 'Session not found.');
        }
        if ($s->status !== 'RUNNING') {
            abort(409, 'This session has already ended.');
        }

        return $s;
    }

    public function modules(Request $request)
    {
        $uid = $request->user()->id;
        $modules = TaskModule::where('active', true)->orderBy('sort_order')
            ->withCount(['content' => fn ($q) => $q->where('active', true)])->get();

        return response()->json(['modules' => $modules->map(function ($m) use ($uid) {
            $earned = self::earnedToday($uid, $m->key);

            return [
                'key' => $m->key, 'name' => $m->name, 'description' => $m->description,
                'minSeconds' => $m->min_seconds, 'maxSeconds' => $m->max_seconds,
                'rewardType' => $m->reward_type, 'rewardAmount' => $m->reward_amount, 'dailyCap' => $m->daily_cap,
                'requireFocus' => $m->require_focus, 'earnedToday' => $earned,
                'remainingToday' => max(0, $m->daily_cap - $earned), 'contentCount' => $m->content_count,
            ];
        })]);
    }

    public function content(string $key)
    {
        return response()->json(['items' => ContentItem::where('module_key', $key)->where('active', true)->latest('id')->get()->map(fn ($c) => [
            'id' => $c->id, 'title' => $c->title, 'url' => $c->url, 'provider' => $c->provider,
            'instructions' => $c->instructions, 'requiresProof' => $c->requires_proof, 'proofLabel' => $c->proof_label,
        ])]);
    }

    public function current(Request $request)
    {
        $s = TaskSession::where('user_id', $request->user()->id)->where('status', 'RUNNING')->latest('started_at')->first();

        return response()->json(['session' => $s?->toApi()]);
    }

    public function start(Request $request)
    {
        $data = $this->check($request, [
            'moduleKey' => 'required|string',
            'durationSeconds' => 'required|integer',
            'contentId' => 'nullable|integer',
        ]);
        $u = $request->user();
        $module = TaskModule::find($data['moduleKey']);
        if (! $module || ! $module->active) {
            abort(404, 'This task module is not available.');
        }
        $secs = (int) $data['durationSeconds'];
        if ($secs < $module->min_seconds || $secs > $module->max_seconds) {
            abort(400, "Choose a duration between {$module->min_seconds} and {$module->max_seconds} seconds.");
        }
        if (self::earnedToday($u->id, $module->key) >= $module->daily_cap) {
            abort(400, "You've reached today's {$module->name} limit. It resets at midnight (Lagos time).");
        }
        $title = null;
        if (! empty($data['contentId'])) {
            $item = ContentItem::where('module_key', $module->key)->where('active', true)->find($data['contentId']);
            if (! $item) {
                abort(404, 'That item is no longer available.');
            }
            $title = $item->title;
        }

        // One timer at a time: starting a new one abandons any other running timer
        $s = DB::transaction(function () use ($u, $module, $secs, $data, $title) {
            TaskSession::where('user_id', $u->id)->where('status', 'RUNNING')->update(['status' => 'ABANDONED']);

            return TaskSession::create([
                'user_id' => $u->id, 'module_key' => $module->key, 'content_id' => $data['contentId'] ?? null,
                'content_title' => $title, 'duration_seconds' => $secs, 'last_beat_at' => Carbon::createFromTimestampMs(now()->getTimestampMs()),
                'started_at' => now(),
            ]);
        });

        return response()->json(['session' => $s->fresh()->toApi()], 201);
    }

    public function beat(Request $request, string $id)
    {
        $s = $this->running($request, $id);
        $s->update(self::accrue($s));

        return response()->json(['session' => $s->toApi()]);
    }

    // Resume after a pause: restart the heartbeat clock without counting the gap
    public function resume(Request $request, string $id)
    {
        $s = $this->running($request, $id);
        $s->update(['last_beat_at' => Carbon::createFromTimestampMs(now()->getTimestampMs())]);

        return response()->json(['session' => $s->toApi()]);
    }

    public function complete(Request $request, string $id)
    {
        $data = $this->check($request, ['proof' => 'nullable|string|max:500']);
        $proof = trim((string) ($data['proof'] ?? ''));
        $s = $this->running($request, $id);
        $content = $s->content_id ? ContentItem::find($s->content_id) : null;
        $needsProof = $content && $content->requires_proof;
        if ($needsProof && mb_strlen($proof) < 2) {
            abort(400, 'Enter '.($content->proof_label ?: 'your proof').' to finish this task.');
        }
        $progress = self::accrue($s);
        if ($progress['active_seconds'] < $s->duration_seconds - 1) {
            $s->update($progress);
            abort(400, 'Keep the activity open until the timer finishes ('.($s->duration_seconds - $progress['active_seconds']).'s left).');
        }

        $uid = $request->user()->id;
        $full = $s->module->sessionReward($s->duration_seconds);
        $s = DB::transaction(function () use ($s, $uid, $full, $needsProof, $proof) {
            // Only one request can move RUNNING → COMPLETED, so a reward is never paid twice
            if (! TaskSession::whereKey($s->id)->where('status', 'RUNNING')->update(['status' => 'COMPLETED', 'active_seconds' => $s->duration_seconds, 'completed_at' => now()])) {
                abort(409, 'This session has already ended.');
            }
            $reward = min($full, max(0, $s->module->daily_cap - self::earnedToday($uid, $s->module_key)));
            if ($needsProof) {
                // Reward is reserved now and paid when an admin approves the proof
                TaskSession::whereKey($s->id)->update(['status' => 'PENDING_REVIEW', 'reward' => $reward, 'proof' => $proof]);
            } else {
                if ($reward > 0) {
                    Wallet::credit($uid, $reward, 'TASK', $s->module->name.($s->content_title ? " · {$s->content_title}" : '')." ({$s->duration_seconds}s)", $s->module_key);
                }
                TaskSession::whereKey($s->id)->update(['reward' => $reward]);
            }

            return $s->fresh();
        });

        return response()->json([
            'session' => $s->toApi(),
            'balance' => $request->user()->fresh()->balance,
            'pendingReview' => $s->status === 'PENDING_REVIEW',
            'capReached' => $s->reward < $full,
        ]);
    }

    public function abandon(Request $request, string $id)
    {
        $s = $this->running($request, $id);
        $s->update(['status' => 'ABANDONED', 'active_seconds' => self::accrue($s)['active_seconds']]);

        return response()->json(['ok' => true]);
    }

    public function history(Request $request)
    {
        return response()->json(['items' => TaskSession::where('user_id', $request->user()->id)->where('status', '!=', 'RUNNING')
            ->latest('started_at')->limit(30)->get()->map(fn ($s) => $s->toApi())]);
    }
}
