<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FbrClaim;
use App\Models\FbrDrop;
use App\Services\Settings;
use App\Services\Wallet;
use App\Support\Lagos;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Friday Bonus Reward: every Friday at the configured hour (Lagos time) a random
// number appears on members' dashboards. The first N members to claim it win.
class FbrController extends Controller
{
    private static function todaysDrop(): ?FbrDrop
    {
        if (! Lagos::isFriday()) {
            return null;
        }
        $key = Lagos::dayKey();
        $drop = FbrDrop::where('day_key', $key)->first();
        if ($drop) {
            return $drop;
        }
        $s = Settings::all();
        try {
            return FbrDrop::create([
                'day_key' => $key, 'code' => (string) random_int(100000, 999999), 'reward' => $s['fbrReward'],
                'max_winners' => $s['fbrMaxWinners'], 'opens_at' => Lagos::todayAt($s['fbrOpenHour']),
            ]);
        } catch (QueryException) {
            // Another request created it at the same moment
            return FbrDrop::where('day_key', $key)->first();
        }
    }

    public function show(Request $request)
    {
        $s = Settings::all();
        $drop = self::todaysDrop();
        if (! $drop) {
            return response()->json(['state' => 'not_friday', 'reward' => $s['fbrReward'], 'openHour' => $s['fbrOpenHour']]);
        }
        $claims = $drop->claims()->count();
        $mine = $drop->claims()->where('user_id', $request->user()->id)->exists();
        $base = ['reward' => $drop->reward, 'maxWinners' => $drop->max_winners, 'claimsLeft' => max(0, $drop->max_winners - $claims), 'opensAt' => $drop->opens_at];
        if ($mine) {
            return response()->json($base + ['state' => 'claimed']);
        }
        if (now()->lt($drop->opens_at)) {
            return response()->json($base + ['state' => 'upcoming']);
        }
        if ($claims >= $drop->max_winners) {
            return response()->json($base + ['state' => 'closed']);
        }

        return response()->json($base + ['state' => 'open', 'code' => $drop->code]);
    }

    public function claim(Request $request)
    {
        $data = $this->check($request, ['code' => 'required|string'], ['code.*' => 'Enter the number shown.']);
        $drop = self::todaysDrop();
        if (! $drop || now()->lt($drop->opens_at)) {
            abort(400, 'There is no Friday Bonus to claim right now.');
        }
        if (trim($data['code']) !== $drop->code) {
            abort(400, 'That number does not match. Check it and try again.');
        }
        $uid = $request->user()->id;
        DB::transaction(function () use ($drop, $uid) {
            // Lock the drop row so the winner count can't be exceeded
            FbrDrop::whereKey($drop->id)->lockForUpdate()->first();
            if (FbrClaim::where('fbr_drop_id', $drop->id)->count() >= $drop->max_winners) {
                abort(400, "Too late — all of this week's rewards have been claimed.");
            }
            if (FbrClaim::where('fbr_drop_id', $drop->id)->where('user_id', $uid)->exists()) {
                abort(400, "You have already claimed this week's reward.");
            }
            FbrClaim::create(['fbr_drop_id' => $drop->id, 'user_id' => $uid]);
            Wallet::credit($uid, $drop->reward, 'FBR', "Friday Bonus Reward ({$drop->day_key})");
        });

        return response()->json(['ok' => true, 'reward' => $drop->reward]);
    }
}
