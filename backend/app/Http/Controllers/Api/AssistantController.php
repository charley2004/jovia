<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantLog;
use App\Models\TaskModule;
use App\Services\Settings;
use App\Support\Lagos;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Jovia AI Assistant, powered by the Google Gemini API (free tier).
// Free-tier prompts may be used by Google to improve its products, so only the
// platform rules and the member's question are sent — never names, balances or contact details.
class AssistantController extends Controller
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private static function platformFacts(): string
    {
        $s = Settings::all();
        $n = fn ($v) => Lagos::naira($v);
        $modules = TaskModule::where('active', true)->orderBy('sort_order')->get()->map(fn ($m) => "- {$m->name}: ".
            ($m->reward_type === 'FLAT' ? $n($m->reward_amount).' per completed session' : $n($m->reward_amount).' per active second').
            ", sessions of {$m->min_seconds}–{$m->max_seconds} seconds, up to ".$n($m->daily_cap).' a day.')->implode("\n");
        $spill = collect($s['spilloverLevels'])->map(fn ($a, $i) => 'level '.($i + 2).' '.$n($a))->implode(', ');
        $support = ($s['supportUrl'] || $s['supportEmail'])
            ? '- Human support: '.implode(' · ', array_filter([$s['supportUrl'], $s['supportEmail']]))
            : '- For anything you cannot resolve, tell the member to use the Contact page.';
        $open = str_pad((string) $s['fbrOpenHour'], 2, '0', STR_PAD_LEFT);

        return <<<TXT
You are the Jovia AI Assistant inside the Jovia Network member dashboard (joviaplatform.ng). You help members understand how the platform works. Answer in plain, friendly English. Keep answers short — a few sentences or a short list.

How Jovia Network works:
- Members pay a one-time {$n($s['activationFee'])} activation fee by bank transfer; an admin verifies it. Activation credits a {$n($s['welcomeBonus'])} Welcome Smash Bonus.
- If a member paid but their account was never activated, they are entitled to a full refund (Request a refund on the dashboard). Activated accounts are not refundable.
- Task modules run on a countdown timer. Time only counts while the dashboard is open and, for videos and music, while the media is playing. Daily limits reset at midnight Lagos time.
{$modules}
- Some meta tasks need proof (for example a username or link); an admin reviews it before the reward is paid.
- Referrals: {$n($s['referralBonus'])} when someone the member invited activates, plus spillovers of {$spill}.
- Marketplace: members list products; each approved listing pays {$n($s['marketplaceReward'])}. Members can buy with their wallet balance, in full or with a {$s['instalmentDepositPercent']}% deposit and the balance within {$s['instalmentDays']} days. Payment is held until the buyer confirms delivery, or released to the seller {$s['autoReleaseDays']} days after shipping. Buyers can report a problem on a shipped order.
- Friday Bonus: every Friday at {$open}:00 Lagos time a number appears; the first {$s['fbrMaxWinners']} members to claim it win {$n($s['fbrReward'])}.
- Withdrawals go to the member's saved bank account and are paid within 24–48 hours on business days.
- Dollar amounts are paid in naira at \$1 = {$n($s['usdToNgn'])}. Membership is open across Africa.
- Dashboard tabs: Overview, Tasks, Market, Wallet, Referrals, Friday, Assistant, Account.
{$support}

Rules:
- You cannot see the member's account. For their balance, earnings, withdrawals or referrals, tell them which dashboard tab shows it.
- Never ask for or repeat passwords, bank details, phone numbers or other personal information. If a member shares some, tell them not to.
- Never promise or estimate income. Earnings depend on the member's own activity and are not guaranteed.
- Do not give investment, tax or legal advice; point to a professional instead.
- You cannot move money, approve anything or change accounts. For payment, withdrawal or account problems, explain what the member can do in the dashboard and when to contact support.
- If you don't know something, say so.
TXT;
    }

    private static function usedToday(int $userId): int
    {
        return AssistantLog::where('user_id', $userId)->where('created_at', '>=', Lagos::startOfDay())->count();
    }

    public function status(Request $request)
    {
        $limit = Settings::get('assistantDailyLimit');

        return response()->json([
            'available' => (bool) config('services.gemini.key'),
            'remaining' => max(0, $limit - self::usedToday($request->user()->id)),
            'limit' => $limit,
        ]);
    }

    public function chat(Request $request)
    {
        $key = config('services.gemini.key');
        if (! $key) {
            abort(503, 'The assistant is not switched on yet. Please check back soon.');
        }
        $data = $this->check($request, [
            'messages' => 'required|array|min:1|max:20',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required|string|max:2000',
        ]);
        $messages = array_values($data['messages']);
        if ($messages[0]['role'] !== 'user' || end($messages)['role'] !== 'user') {
            abort(400, 'Send a question to continue.');
        }

        $u = $request->user();
        $limit = Settings::get('assistantDailyLimit');
        $used = self::usedToday($u->id);
        if ($used >= $limit) {
            abort(429, "You've used today's {$limit} assistant messages. It resets at midnight (Lagos time).");
        }
        AssistantLog::create(['user_id' => $u->id]);

        try {
            $res = Http::timeout(30)
                ->withHeaders(['x-goog-api-key' => $key])
                ->post(sprintf(self::ENDPOINT, config('services.gemini.model')), [
                    'system_instruction' => ['parts' => [['text' => self::platformFacts()]]],
                    // Gemini calls the assistant's turns "model"
                    'contents' => array_map(fn ($m) => [
                        'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                        'parts' => [['text' => trim($m['content'])]],
                    ], $messages),
                    'generationConfig' => ['maxOutputTokens' => 1024],
                ]);
        } catch (ConnectionException $e) {
            Log::error('assistant connection error: '.$e->getMessage());
            abort(502, 'The assistant could not answer right now. Please try again.');
        }

        if ($res->status() === 429) {
            // Free-tier quota reached for the minute or the day
            abort(503, 'The assistant is busy right now. Please try again in a few minutes.');
        }
        if ($res->failed()) {
            Log::error('assistant API error', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 500)]);
            abort(502, 'The assistant could not answer right now. Please try again.');
        }

        $candidate = $res->json('candidates.0');
        $reply = trim(collect($candidate['content']['parts'] ?? [])
            ->reject(fn ($p) => ! empty($p['thought']))
            ->pluck('text')->filter()->implode("\n"));
        if ($reply === '' && ($res->json('promptFeedback.blockReason') || ($candidate['finishReason'] ?? null) === 'SAFETY')) {
            $reply = "Sorry, I can't help with that. For anything about your account, use the Contact page to reach our team.";
        }

        return response()->json([
            'reply' => $reply ?: "Sorry, I didn't catch that. Could you ask again?",
            'remaining' => max(0, $limit - $used - 1),
        ]);
    }
}
