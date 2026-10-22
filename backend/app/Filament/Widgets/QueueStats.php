<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ListingResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\TaskReviewResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\WithdrawalResource;
use App\Filament\Support\Act;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TaskSession;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\Lagos;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

// What needs attention right now, plus the headline numbers
class QueueStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $pendingW = Withdrawal::where('status', 'PENDING');

        return [
            Stat::make('Pending payments', Payment::where('status', 'PENDING')->count())
                ->description(Payment::where('status', 'REFUND_REQUESTED')->count().' refund requests')->url(PaymentResource::getUrl())->color('warning'),
            Stat::make('Pending withdrawals', (clone $pendingW)->count())
                ->description(Act::naira($pendingW->sum('amount')).' to send')->url(WithdrawalResource::getUrl())->color('warning'),
            Stat::make('Listings to review', Listing::where('status', 'PENDING')->count())->url(ListingResource::getUrl()),
            Stat::make('Task proofs to review', TaskSession::where('status', 'PENDING_REVIEW')->count())->url(TaskReviewResource::getUrl()),
            Stat::make('Order problems', Order::where('status', 'DISPUTED')->count())->url(OrderResource::getUrl())->color('danger'),
            Stat::make('Members', User::where('role', 'MEMBER')->count())
                ->description(User::where('role', 'MEMBER')->where('status', 'ACTIVE')->count().' active')->url(UserResource::getUrl()),
            Stat::make('Activations today', Act::naira(Payment::where('status', 'APPROVED')->where('reviewed_at', '>=', Lagos::startOfDay())->sum('amount'))),
            Stat::make('Total paid out', Act::naira(Withdrawal::where('status', 'PAID')->sum('amount'))),
        ];
    }
}
