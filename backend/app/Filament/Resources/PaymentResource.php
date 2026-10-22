<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Filament\Support\Act;
use App\Models\Payment;
use App\Services\AdminActions;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Queues';

    protected static ?string $navigationLabel = 'Activation payments';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $n = Payment::whereIn('status', ['PENDING', 'REFUND_REQUESTED'])->count();

        return $n ? (string) $n : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('user.email')->label('Member')->searchable()
                    ->description(fn (Payment $p) => $p->user->name.' · '.$p->user->phone.' · '.$p->user->country),
                TextColumn::make('sender_name')->label('Paid from')->searchable()->description(fn (Payment $p) => $p->reference ? 'Ref: '.$p->reference : null),
                TextColumn::make('amount')->formatStateUsing(fn ($state) => Act::naira($state)),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'PENDING', 'REFUND_REQUESTED' => 'warning', 'APPROVED' => 'success', 'REJECTED' => 'danger', default => 'gray',
                }),
                TextColumn::make('note')->wrap()->limit(80)->toggleable(),
                TextColumn::make('admin_note')->label('Admin note')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Submitted')->since()->sortable(),
            ])
            ->actions([
                Action::make('approve')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Payment $p) => in_array($p->status, ['PENDING', 'REJECTED'], true))
                    ->requiresConfirmation()
                    ->modalHeading('Approve this payment?')
                    ->modalDescription('Only approve after confirming the money is in your account. This activates the member and pays the Welcome Smash Bonus.')
                    ->action(fn (Payment $p) => Act::run(fn () => AdminActions::approvePayment($p), 'Member activated')),
                Action::make('reject')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (Payment $p) => $p->status === 'PENDING')
                    ->form([Textarea::make('note')->label('Reason (the member sees this)')->required()->maxLength(500)])
                    ->action(fn (Payment $p, array $data) => Act::run(fn () => AdminActions::rejectPayment($p, $data['note']), 'Payment rejected')),
                Action::make('refunded')->label('Mark refunded')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                    ->visible(fn (Payment $p) => in_array($p->status, ['REFUND_REQUESTED', 'REJECTED'], true))
                    ->form([Textarea::make('note')->label('Note for the member (optional)')->maxLength(500)])
                    ->action(fn (Payment $p, array $data) => Act::run(fn () => AdminActions::markRefunded($p, $data['note'] ?? null), 'Marked as refunded')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPayments::route('/')];
    }
}
