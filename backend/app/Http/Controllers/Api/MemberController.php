<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberNotification;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Settings;
use App\Support\Lagos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MemberController extends Controller
{
    private const EARNING_TYPES = ['WELCOME_BONUS', 'TASK', 'MARKETPLACE', 'FBR', 'ADJUSTMENT', 'SALE'];

    private static function payment(?Payment $p): ?array
    {
        return $p ? [
            'id' => $p->id, 'amount' => $p->amount, 'senderName' => $p->sender_name, 'reference' => $p->reference,
            'status' => $p->status, 'adminNote' => $p->admin_note, 'createdAt' => $p->created_at,
        ] : null;
    }

    public static function tx(Transaction $t): array
    {
        return ['id' => $t->id, 'type' => $t->type, 'amount' => $t->amount, 'description' => $t->description, 'module' => $t->module, 'createdAt' => $t->created_at];
    }

    private static function withdrawal(Withdrawal $w): array
    {
        return [
            'id' => $w->id, 'amount' => $w->amount, 'bankName' => $w->bank_name, 'accountNumber' => $w->account_number,
            'accountName' => $w->account_name, 'status' => $w->status, 'adminNote' => $w->admin_note, 'createdAt' => $w->created_at,
        ];
    }

    // Everything the dashboard needs on first load
    public function overview(Request $request)
    {
        $s = Settings::all();
        $u = $request->user();
        $earnings = Transaction::where('user_id', $u->id)->whereIn('type', self::EARNING_TYPES)->where('amount', '>', 0);

        return response()->json([
            'user' => $u->toApi(),
            'payment' => self::payment($u->payments()->latest('id')->first()),
            'stats' => [
                'balance' => $u->balance,
                'todayEarnings' => (int) (clone $earnings)->where('created_at', '>=', Lagos::startOfDay())->sum('amount'),
                'totalEarned' => (int) $earnings->sum('amount'),
                'totalWithdrawn' => (int) $u->withdrawals()->where('status', 'PAID')->sum('amount'),
                'pendingWithdrawals' => (int) $u->withdrawals()->where('status', 'PENDING')->sum('amount'),
            ],
            'recent' => $u->transactions()->latest('id')->limit(8)->get()->map(fn ($t) => self::tx($t)),
            'config' => [
                'activationFee' => $s['activationFee'],
                'welcomeBonus' => $s['welcomeBonus'],
                'marketplaceReward' => $s['marketplaceReward'],
                'fbrReward' => $s['fbrReward'],
                'minWithdrawal' => $s['minWithdrawal'],
                'usdToNgn' => $s['usdToNgn'],
                'bank' => ['bankName' => $s['activationBankName'], 'accountName' => $s['activationAccountName'], 'accountNumber' => $s['activationAccountNumber']],
                'supportUrl' => $s['supportUrl'],
                'supportEmail' => $s['supportEmail'],
            ],
        ]);
    }

    // ── Activation payment ──
    public function submitPayment(Request $request)
    {
        $data = $this->check($request, [
            'senderName' => 'required|string|min:2|max:120',
            'reference' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:500',
        ], ['senderName.*' => 'Enter the name on the account you paid from.']);
        $u = $request->user();
        if ($u->status !== 'PENDING_PAYMENT') {
            abort(400, 'Your payment has already been submitted.');
        }
        $payment = DB::transaction(function () use ($u, $data) {
            $p = Payment::create([
                'user_id' => $u->id, 'amount' => Settings::get('activationFee'), 'sender_name' => trim($data['senderName']),
                'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null,
            ])->refresh();
            $u->update(['status' => 'AWAITING_VERIFICATION']);

            return $p;
        });

        return response()->json(['payment' => self::payment($payment)], 201);
    }

    // Undo a payment submitted by mistake (only before an admin has reviewed it)
    public function cancelPayment(Request $request)
    {
        $u = $request->user();
        $p = $u->payments()->where('status', 'PENDING')->latest('id')->first();
        if (! $p || $u->status !== 'AWAITING_VERIFICATION') {
            abort(400, 'There is no pending payment to cancel.');
        }
        DB::transaction(function () use ($p, $u) {
            $p->delete();
            $u->update(['status' => 'PENDING_PAYMENT']);
        });

        return response()->json(['ok' => true]);
    }

    // Refunds are available whenever a paid account was never activated
    public function requestRefund(Request $request)
    {
        $data = $this->check($request, ['reason' => 'nullable|string|max:500']);
        $u = $request->user();
        if ($u->status === 'ACTIVE') {
            abort(400, 'Activated accounts are not eligible for a refund.');
        }
        $p = $u->payments()->whereIn('status', ['PENDING', 'REJECTED'])->latest('id')->first();
        if (! $p) {
            abort(400, 'There is no payment to refund.');
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        $p->update([
            'status' => 'REFUND_REQUESTED',
            'note' => implode(' · ', array_filter([$p->note, $reason ? "Refund reason: {$reason}" : null])) ?: null,
        ]);

        return response()->json(['payment' => self::payment($p)]);
    }

    // ── Bank account for withdrawals ──
    public function saveBank(Request $request)
    {
        $data = $this->check($request, [
            'bankName' => 'required|string|min:2|max:100',
            'accountNumber' => 'required|string',
            'accountName' => 'required|string|min:3|max:120',
        ], ['bankName.*' => 'Enter your bank name.', 'accountName.*' => 'Enter the account name.']);
        $number = preg_replace('/\D/', '', $data['accountNumber']);
        if (strlen($number) < 6 || strlen($number) > 20) {
            abort(400, 'Enter a valid account number.');
        }
        $u = $request->user();
        $u->update(['bank_name' => trim($data['bankName']), 'account_number' => $number, 'account_name' => trim($data['accountName'])]);

        return response()->json(['user' => $u->toApi()]);
    }

    // ── Withdrawals ──
    public function withdrawals(Request $request)
    {
        return response()->json(['items' => $request->user()->withdrawals()->latest('id')->limit(50)->get()->map(fn ($w) => self::withdrawal($w))]);
    }

    public function withdraw(Request $request)
    {
        $data = $this->check($request, ['amount' => 'required|integer|min:1'], ['amount.*' => 'Enter a whole naira amount.']);
        $amount = (int) $data['amount'];
        $u = $request->user();
        $min = Settings::get('minWithdrawal');
        if (! $u->account_number) {
            abort(400, 'Add a bank account first.');
        }
        if ($min && $amount < $min) {
            abort(400, 'The minimum withdrawal is '.Lagos::naira($min).'.');
        }
        $w = DB::transaction(function () use ($u, $amount) {
            // Conditional decrement: fails if another request already spent the balance
            if (! User::whereKey($u->id)->where('balance', '>=', $amount)->decrement('balance', $amount)) {
                abort(400, 'This is more than your available balance.');
            }
            $w = Withdrawal::create([
                'user_id' => $u->id, 'amount' => $amount, 'bank_name' => $u->bank_name,
                'account_number' => $u->account_number, 'account_name' => $u->account_name,
            ])->refresh();
            Transaction::create(['user_id' => $u->id, 'type' => 'WITHDRAWAL', 'amount' => -$amount, 'description' => "Withdrawal #{$w->id} to {$u->bank_name}"]);

            return $w;
        });

        return response()->json(['withdrawal' => self::withdrawal($w)], 201);
    }

    // ── Transaction history ──
    public function transactions(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $q = $request->user()->transactions();
        $total = (clone $q)->count();

        return response()->json([
            'items' => $q->latest('id')->skip(($page - 1) * 20)->take(20)->get()->map(fn ($t) => self::tx($t)),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / 20)),
        ]);
    }

    // ── Notifications ──
    public function notifications(Request $request)
    {
        $q = MemberNotification::where('user_id', $request->user()->id);

        return response()->json([
            'items' => (clone $q)->latest('id')->limit(30)->get()->map(fn ($n) => [
                'id' => $n->id, 'title' => $n->title, 'body' => $n->body, 'link' => $n->link, 'readAt' => $n->read_at, 'createdAt' => $n->created_at,
            ]),
            'unread' => $q->whereNull('read_at')->count(),
        ]);
    }

    public function readNotifications(Request $request)
    {
        MemberNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    // ── Profile ──
    public function profile(Request $request)
    {
        $data = $this->check($request, ['firstName' => 'required|string|max:80', 'lastName' => 'required|string|max:80']);
        $u = $request->user();
        $u->update(['first_name' => trim($data['firstName']), 'last_name' => trim($data['lastName'])]);

        return response()->json(['user' => $u->toApi()]);
    }

    public function password(Request $request)
    {
        $data = $this->check($request, ['current' => 'required|string', 'next' => 'required|string|min:8|max:128'], [
            'current.*' => 'Enter your current password.',
            'next.*' => 'New password must be at least 8 characters.',
        ]);
        $u = $request->user();
        if (! Hash::check($data['current'], $u->password)) {
            abort(400, 'Your current password is incorrect.');
        }
        $u->update(['password' => $data['next']]);

        return response()->json(['ok' => true]);
    }
}
