<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Support\Act;
use App\Models\Order;
use App\Services\Orders;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

// Buyers pay from their wallet and the money is held until delivery is confirmed.
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Queues';

    protected static ?string $navigationLabel = 'Orders & disputes';

    protected static ?int $navigationSort = 5;

    public const LABELS = [
        'AWAITING_BALANCE' => 'Balance due', 'PAID' => 'Paid — to ship', 'SHIPPED' => 'Shipped',
        'COMPLETED' => 'Completed', 'CANCELLED' => 'Cancelled', 'DISPUTED' => 'Problem reported',
    ];

    public static function getNavigationBadge(): ?string
    {
        $n = Order::where('status', 'DISPUTED')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $resolve = fn (string $outcome, string $label, string $color, string $desc) => Action::make($outcome)->label($label)->color($color)
            ->visible(fn (Order $o) => $o->status === 'DISPUTED')
            ->modalDescription($desc)
            ->form([Textarea::make('note')->label('Note for both sides (optional)')->maxLength(500)])
            ->action(fn (Order $o, array $data) => Act::run(fn () => Orders::resolve($o->id, $outcome, $data['note'] ?? null), $outcome === 'refund' ? 'Buyer refunded' : 'Seller paid'));

        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')->label('Order')->searchable()->weight('bold')
                    ->description(fn (Order $o) => '#'.$o->id.' · '.Act::naira($o->price).' · paid '.Act::naira($o->paid_amount).' · '.strtolower($o->plan)),
                TextColumn::make('buyer.email')->label('Buyer')->searchable(),
                TextColumn::make('seller.email')->label('Seller')->searchable(),
                TextColumn::make('delivery_address')->label('Deliver to')->wrap()->limit(80)
                    ->description(fn (Order $o) => $o->delivery_name.' · '.$o->delivery_phone)->toggleable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => self::LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'DISPUTED' => 'danger', 'COMPLETED' => 'success', 'CANCELLED' => 'gray', default => 'warning',
                    }),
                TextColumn::make('dispute_reason')->label('Problem')->wrap()->color('danger'),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->actions([
                $resolve('refund', 'Refund buyer', 'gray', 'Return the full amount paid to the buyer\'s wallet and put the item back in stock.'),
                $resolve('release', 'Pay seller', 'success', 'Release the payment to the seller\'s wallet.'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOrders::route('/')];
    }
}
