<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WithdrawalResource\Pages;
use App\Filament\Support\Act;
use App\Models\Withdrawal;
use App\Services\AdminActions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WithdrawalResource extends Resource
{
    protected static ?string $model = Withdrawal::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationGroup = 'Queues';

    protected static ?string $navigationLabel = 'Withdrawals';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $n = Withdrawal::where('status', 'PENDING')->count();

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
                TextColumn::make('user.email')->label('Member')->searchable()->description(fn (Withdrawal $w) => $w->user->name.' · '.$w->user->country),
                TextColumn::make('amount')->formatStateUsing(fn ($state) => Act::naira($state))->weight('bold'),
                TextColumn::make('bank_name')->label('Pay to')->description(fn (Withdrawal $w) => $w->account_number.' · '.$w->account_name),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'PENDING' => 'warning', 'PAID' => 'success', default => 'danger',
                }),
                TextColumn::make('admin_note')->label('Reference / note')->toggleable(),
                TextColumn::make('created_at')->label('Requested')->since()->sortable(),
            ])
            ->actions([
                Action::make('paid')->label('Mark paid')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Withdrawal $w) => $w->status === 'PENDING')
                    ->modalDescription('Send the bank transfer first, then mark it paid.')
                    ->form([TextInput::make('reference')->label('Transfer reference (optional)')->maxLength(500)])
                    ->action(fn (Withdrawal $w, array $data) => Act::run(fn () => AdminActions::markWithdrawalPaid($w, $data['reference'] ?? null), 'Marked as paid')),
                Action::make('reject')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (Withdrawal $w) => $w->status === 'PENDING')
                    ->modalDescription('The amount goes back to the member\'s wallet.')
                    ->form([Textarea::make('note')->label('Reason (the member sees this)')->required()->maxLength(500)])
                    ->action(fn (Withdrawal $w, array $data) => Act::run(fn () => AdminActions::rejectWithdrawal($w, $data['note']), 'Withdrawal rejected and refunded to balance')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListWithdrawals::route('/')];
    }
}
