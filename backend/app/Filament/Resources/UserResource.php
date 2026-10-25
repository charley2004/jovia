<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Support\Act;
use App\Models\User;
use App\Services\ActivationReminders;
use App\Services\AdminActions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Members';

    protected static ?string $navigationLabel = 'Members';

    protected static ?string $modelLabel = 'member';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('email')->label('Member')->searchable(['email', 'first_name', 'last_name', 'phone'])
                    ->description(fn (User $u) => $u->name.' · '.$u->phone.' · '.$u->country),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'ACTIVE' => 'success', 'AWAITING_VERIFICATION' => 'warning', 'SUSPENDED' => 'danger', default => 'gray',
                }),
                TextColumn::make('balance')->formatStateUsing(fn ($state) => Act::naira($state))->sortable(),
                TextColumn::make('role')->badge()->color(fn (string $state) => $state === 'ADMIN' ? 'primary' : 'gray')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Joined')->date()->sortable(),
                TextColumn::make('activation_reminders_sent')->label('Reminders')->toggleable()
                    ->formatStateUsing(fn (int $state, User $u) => match (true) {
                        $u->email_opt_out => 'Unsubscribed',
                        $u->status !== 'PENDING_PAYMENT' && $state === 0 => '—',
                        default => "{$state} of ".count(ActivationReminders::SCHEDULE),
                    })
                    ->description(fn (User $u) => $u->last_activation_reminder_at ? 'Last '.$u->last_activation_reminder_at->diffForHumans() : null),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'PENDING_PAYMENT' => 'Not activated', 'AWAITING_VERIFICATION' => 'Verifying', 'ACTIVE' => 'Active', 'SUSPENDED' => 'Suspended',
                ]),
            ])
            ->actions([
                Action::make('activate')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (User $u) => $u->role !== 'ADMIN' && in_array($u->status, ['PENDING_PAYMENT', 'AWAITING_VERIFICATION'], true))
                    ->modalHeading(fn (User $u) => "Activate {$u->name}?")
                    ->modalDescription('Only do this after confirming the activation fee is in your account. It activates the member and pays the Welcome Smash Bonus.')
                    ->form([
                        TextInput::make('sender')->label('Paid from (name on the sending account)')->required()->minLength(2)->maxLength(120)
                            ->default(fn (User $u) => $u->payments()->where('status', 'PENDING')->latest('id')->value('sender_name') ?? $u->name),
                        TextInput::make('reference')->label('Transfer reference (optional)')->maxLength(120),
                    ])
                    ->action(fn (User $u, array $data) => Act::run(fn () => AdminActions::activateManually($u, $data['sender'], $data['reference'] ?? null), 'Member activated')),
                ActionGroup::make([
                    Action::make('remind')->label('Send activation reminder')->icon('heroicon-o-envelope')
                        ->visible(fn (User $u) => $u->role !== 'ADMIN' && $u->status === 'PENDING_PAYMENT' && ! $u->email_opt_out)
                        ->requiresConfirmation()
                        ->modalDescription(fn (User $u) => ActivationReminders::mailerConfigured()
                            ? "Emails {$u->email} the next activation reminder now."
                            : 'No mail account is connected yet, so this email cannot be sent.')
                        ->action(function (User $u) {
                            if (! ActivationReminders::mailerConfigured()) {
                                Notification::make()->danger()->title('No mail account connected')->body('Fill in the MAIL_ lines in .env, then run update.sh.')->send();

                                return;
                            }
                            ActivationReminders::sendTo($u)
                                ? Notification::make()->success()->title('Reminder sent')->send()
                                : Notification::make()->danger()->title('The email could not be sent')->body('Check the mail settings in .env.')->send();
                        }),
                    Action::make('suspend')->icon('heroicon-o-no-symbol')->color('danger')
                        ->visible(fn (User $u) => $u->role !== 'ADMIN' && $u->status !== 'SUSPENDED')
                        ->requiresConfirmation()->modalDescription('They will be signed out and blocked until you restore them.')
                        ->action(fn (User $u) => Act::run(fn () => $u->update(['status' => 'SUSPENDED']), 'Member suspended')),
                    Action::make('restore')->icon('heroicon-o-arrow-path')->color('success')
                        ->visible(fn (User $u) => $u->status === 'SUSPENDED')
                        ->requiresConfirmation()
                        ->action(fn (User $u) => Act::run(fn () => $u->update(['status' => $u->activated_at ? 'ACTIVE' : 'PENDING_PAYMENT']), 'Member restored')),
                    Action::make('resetPassword')->label('Reset password')->icon('heroicon-o-key')
                        ->visible(fn (User $u) => $u->role !== 'ADMIN')
                        ->requiresConfirmation()->modalDescription('Sets a new temporary password for you to give the member.')
                        ->action(function (User $u) {
                            $temp = AdminActions::resetPassword($u);
                            Notification::make()->success()->persistent()->title('Temporary password: '.$temp)
                                ->body('Give it to the member and ask them to change it in Account settings.')->send();
                        }),
                    Action::make('adjust')->label('Adjust balance')->icon('heroicon-o-scale')
                        ->visible(fn (User $u) => $u->role !== 'ADMIN')
                        ->form([
                            TextInput::make('amount')->label('Amount in naira (use a minus sign to deduct)')->integer()->required(),
                            TextInput::make('reason')->label('Reason (the member sees this in their transactions)')->required()->minLength(3)->maxLength(200),
                        ])
                        ->action(fn (User $u, array $data) => Act::run(fn () => AdminActions::adjustBalance($u, (int) $data['amount'], $data['reason']), 'Balance adjusted')),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUsers::route('/')];
    }
}
