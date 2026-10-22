<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\Order;
use App\Models\User;
use App\Support\Lagos;
use Illuminate\Support\Facades\DB;

// Marketplace orders are paid from the buyer's wallet. The money is held on the
// order (paid_amount) and only reaches the seller when the buyer confirms delivery,
// or automatically `autoReleaseDays` after shipping.
class Orders
{
    public static function place(User $buyer, array $data): Order
    {
        $s = Settings::all();
        $notices = [];
        $order = DB::transaction(function () use ($buyer, $data, $s, &$notices) {
            $listing = Listing::find($data['listingId']);
            if (! $listing || $listing->status !== 'APPROVED') {
                abort(404, 'This product is no longer available.');
            }
            if ($listing->user_id === $buyer->id) {
                abort(400, 'You cannot buy your own listing.');
            }
            if (! Listing::whereKey($listing->id)->where('quantity', '>', 0)->decrement('quantity')) {
                abort(400, 'This product is sold out.');
            }
            $full = $data['plan'] === 'FULL';
            $payNow = $full ? $listing->price : (int) ceil($listing->price * $s['instalmentDepositPercent'] / 100);
            if (! Wallet::debit($buyer->id, $payNow, 'PURCHASE', ($full ? 'Payment' : 'Deposit')." for {$listing->title}")) {
                abort(400, 'You need '.Lagos::naira($payNow).' in your wallet to place this order.');
            }
            $order = Order::create([
                'listing_id' => $listing->id,
                'buyer_id' => $buyer->id,
                'seller_id' => $listing->user_id,
                'title' => $listing->title,
                'price' => $listing->price,
                'paid_amount' => $payNow,
                'plan' => $data['plan'],
                'status' => $full ? 'PAID' : 'AWAITING_BALANCE',
                'delivery_name' => $data['deliveryName'],
                'delivery_phone' => $data['deliveryPhone'],
                'delivery_address' => $data['deliveryAddress'],
                'due_at' => $full ? null : now()->addDays($s['instalmentDays']),
            ]);
            $notices[] = $full
                ? [$listing->user_id, 'New order — please ship', "{$listing->title} was bought for ".Lagos::naira($listing->price).'. Delivery details are in your Sales list.', 'dashboard.html#marketplace']
                : [$listing->user_id, 'Instalment order started', 'A buyer paid a '.Lagos::naira($payNow)." deposit for {$listing->title}. Don't ship until the balance is paid — we'll tell you.", 'dashboard.html#marketplace'];

            return $order;
        });
        Notifier::sendAll($notices);

        return $order;
    }

    public static function payBalance(User $buyer, int $id): void
    {
        $order = DB::transaction(function () use ($buyer, $id) {
            $o = Order::lockForUpdate()->findOrFail($id);
            if ($o->buyer_id !== $buyer->id || $o->status !== 'AWAITING_BALANCE') {
                abort(400, 'There is no balance to pay on this order.');
            }
            $due = $o->price - $o->paid_amount;
            if (! Wallet::debit($buyer->id, $due, 'PURCHASE', "Balance for {$o->title}")) {
                abort(400, 'You need '.Lagos::naira($due).' in your wallet to pay the balance.');
            }
            $o->update(['paid_amount' => $o->price, 'status' => 'PAID']);

            return $o;
        });
        Notifier::send($order->seller_id, 'Order fully paid — please ship', "The balance for {$order->title} has been paid. Delivery details are in your Sales list.", 'dashboard.html#marketplace');
    }

    // Both helpers only act if the order is still in the status that was read,
    // so two requests (or the scheduler) can never pay out the same order twice.
    private static function refund(Order $o, ?string $reason): void
    {
        $moved = Order::whereKey($o->id)->where('status', $o->status)->update(['status' => 'CANCELLED', 'closed_at' => now(), 'admin_note' => $reason ?: $o->admin_note]);
        if (! $moved) {
            abort(409, 'This order was just updated. Refresh and try again.');
        }
        if ($o->paid_amount) {
            Wallet::credit($o->buyer_id, $o->paid_amount, 'PURCHASE_REFUND', "Refund: {$o->title}".($reason ? " ({$reason})" : ''));
        }
        Listing::whereKey($o->listing_id)->increment('quantity');
    }

    private static function release(Order $o): int
    {
        $moved = Order::whereKey($o->id)->where('status', $o->status)->update(['status' => 'COMPLETED', 'closed_at' => now()]);
        if (! $moved) {
            abort(409, 'This order was just updated. Refresh and try again.');
        }
        $fee = (int) floor($o->price * Settings::get('marketplaceFeePercent') / 100);
        Wallet::credit($o->seller_id, $o->price - $fee, 'SALE', "Sale: {$o->title}".($fee ? ' (less '.Lagos::naira($fee).' fee)' : ''));

        return $o->price - $fee;
    }

    public static function cancel(User $user, int $id): void
    {
        $notice = DB::transaction(function () use ($user, $id) {
            $o = Order::lockForUpdate()->findOrFail($id);
            $isBuyer = $o->buyer_id === $user->id;
            if (! $isBuyer && $o->seller_id !== $user->id) {
                abort(404, 'Order not found.');
            }
            if (! in_array($o->status, ['AWAITING_BALANCE', 'PAID'], true)) {
                abort(400, 'Orders can only be cancelled before they are shipped.');
            }
            self::refund($o, $isBuyer ? 'cancelled by buyer' : 'cancelled by seller');

            return $isBuyer
                ? [$o->seller_id, 'Order cancelled', "The buyer cancelled their order for {$o->title}.", 'dashboard.html#marketplace']
                : [$o->buyer_id, 'Order cancelled by seller', "The seller cancelled your order for {$o->title}. ".Lagos::naira($o->paid_amount).' is back in your wallet.', 'dashboard.html#marketplace'];
        });
        Notifier::send(...$notice);
    }

    public static function ship(User $seller, int $id): void
    {
        $o = Order::findOrFail($id);
        if ($o->seller_id !== $seller->id || $o->status !== 'PAID') {
            abort(400, 'Only fully paid orders can be marked as shipped.');
        }
        if (! Order::whereKey($id)->where('status', 'PAID')->update(['status' => 'SHIPPED', 'shipped_at' => now()])) {
            abort(409, 'This order was just updated. Refresh and try again.');
        }
        Notifier::send($o->buyer_id, 'Your order is on its way', "{$o->title} has been shipped. Confirm when it arrives — the seller is paid automatically after ".Settings::get('autoReleaseDays').' days.', 'dashboard.html#marketplace');
    }

    public static function confirmReceived(User $buyer, int $id): void
    {
        [$o, $amount] = DB::transaction(function () use ($buyer, $id) {
            $o = Order::lockForUpdate()->findOrFail($id);
            if ($o->buyer_id !== $buyer->id || $o->status !== 'SHIPPED') {
                abort(400, 'Only shipped orders can be confirmed.');
            }

            return [$o, self::release($o)];
        });
        Notifier::send($o->seller_id, 'Payment released', "The buyer confirmed delivery of {$o->title}. ".Lagos::naira($amount).' has been added to your wallet.', 'dashboard.html#wallet');
    }

    public static function dispute(User $buyer, int $id, string $reason): void
    {
        $o = Order::findOrFail($id);
        if ($o->buyer_id !== $buyer->id || $o->status !== 'SHIPPED') {
            abort(400, 'You can only report a problem on a shipped order.');
        }
        if (! Order::whereKey($id)->where('status', 'SHIPPED')->update(['status' => 'DISPUTED', 'dispute_reason' => $reason])) {
            abort(409, 'This order was just updated. Refresh and try again.');
        }
        Notifier::send($o->seller_id, 'Buyer reported a problem', "The buyer of {$o->title} reported: \"{$reason}\". Our team will review and contact you.", 'dashboard.html#marketplace');
    }

    // Admin decision on a dispute: refund the buyer or release to the seller
    public static function resolve(int $id, string $outcome, ?string $note): void
    {
        $notices = DB::transaction(function () use ($id, $outcome, $note) {
            $o = Order::lockForUpdate()->findOrFail($id);
            if ($o->status !== 'DISPUTED') {
                abort(400, 'This order is not in dispute.');
            }
            if ($outcome === 'refund') {
                self::refund($o, $note ?: 'dispute resolved for buyer');

                return [
                    [$o->buyer_id, 'Dispute resolved — refunded', Lagos::naira($o->paid_amount)." for {$o->title} is back in your wallet.", 'dashboard.html#wallet'],
                    [$o->seller_id, 'Dispute resolved for the buyer', "The order for {$o->title} was refunded.".($note ? " Note: {$note}" : ''), null],
                ];
            }
            $amount = self::release($o);
            Order::whereKey($o->id)->update(['admin_note' => $note ?: null]);

            return [
                [$o->seller_id, 'Dispute resolved — payment released', Lagos::naira($amount)." for {$o->title} has been added to your wallet.", 'dashboard.html#wallet'],
                [$o->buyer_id, 'Dispute resolved for the seller', "Payment for {$o->title} was released to the seller.".($note ? " Note: {$note}" : ''), null],
            ];
        });
        Notifier::sendAll($notices);
    }

    // Hourly: cancel instalment orders whose balance is overdue (deposit refunded)
    // and release payment on shipped orders the buyer never confirmed.
    public static function settle(): array
    {
        $days = Settings::get('autoReleaseDays');
        $cancelled = $released = 0;
        foreach (Order::where('status', 'AWAITING_BALANCE')->where('due_at', '<', now())->get() as $o) {
            try {
                DB::transaction(fn () => self::refund($o, 'balance not paid in time'));
            } catch (\Throwable) {
                continue;
            }
            $cancelled++;
            Notifier::sendAll([
                [$o->buyer_id, 'Instalment order cancelled', "The balance for {$o->title} wasn't paid in time. Your ".Lagos::naira($o->paid_amount).' deposit is back in your wallet.', 'dashboard.html#wallet'],
                [$o->seller_id, 'Instalment order cancelled', "The buyer didn't pay the balance for {$o->title}. The item is back in stock.", null],
            ]);
        }
        foreach (Order::where('status', 'SHIPPED')->where('shipped_at', '<', now()->subDays($days))->get() as $o) {
            try {
                $amount = DB::transaction(fn () => self::release($o));
            } catch (\Throwable) {
                continue;
            }
            $released++;
            Notifier::send($o->seller_id, 'Payment released', Lagos::naira($amount)." for {$o->title} was released automatically {$days} days after shipping.", 'dashboard.html#wallet');
        }

        return ['cancelled' => $cancelled, 'released' => $released];
    }
}
