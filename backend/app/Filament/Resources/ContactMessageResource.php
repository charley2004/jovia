<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContactMessageResource\Pages;
use App\Filament\Support\Act;
use App\Models\ContactMessage;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

// Messages from the Contact page. Reply by email or phone, then mark them answered.
class ContactMessageResource extends Resource
{
    protected static ?string $model = ContactMessage::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Queues';

    protected static ?string $navigationLabel = 'Messages';

    protected static ?int $navigationSort = 6;

    public static function getNavigationBadge(): ?string
    {
        $n = ContactMessage::where('status', 'NEW')->count();

        return $n ? (string) $n : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('name'),
            TextEntry::make('email')->copyable(),
            TextEntry::make('phone')->placeholder('—')->copyable(),
            TextEntry::make('topic')->formatStateUsing(fn (string $state) => ContactMessage::TOPICS[$state] ?? $state),
            TextEntry::make('user.email')->label('Member account')->placeholder('Not signed in'),
            TextEntry::make('created_at')->label('Sent')->dateTime(),
            TextEntry::make('message')->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-line']),
            TextEntry::make('admin_note')->label('Admin note')->placeholder('—')->columnSpanFull(),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('From')->searchable(['name', 'email', 'phone'])
                    ->description(fn (ContactMessage $m) => $m->email.($m->phone ? ' · '.$m->phone : '')),
                TextColumn::make('topic')->badge()->formatStateUsing(fn (string $state) => ContactMessage::TOPICS[$state] ?? $state),
                TextColumn::make('message')->wrap()->limit(120)->searchable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'NEW' => 'warning', 'ANSWERED' => 'success', default => 'gray',
                }),
                TextColumn::make('created_at')->label('Sent')->since()->sortable(),
            ])
            ->actions([
                ViewAction::make(),
                Action::make('email')->label('Reply by email')->icon('heroicon-o-paper-airplane')->color('gray')
                    ->url(fn (ContactMessage $m) => 'mailto:'.$m->email.'?subject='.rawurlencode('Re: your message to Jovia Network'))
                    ->openUrlInNewTab(),
                Action::make('answered')->label('Mark answered')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (ContactMessage $m) => $m->status === 'NEW')
                    ->form([Textarea::make('note')->label('Note for the team (optional)')->maxLength(1000)])
                    ->action(fn (ContactMessage $m, array $data) => Act::run(fn () => $m->update(['status' => 'ANSWERED', 'admin_note' => $data['note'] ?: $m->admin_note]), 'Marked answered')),
                Action::make('close')->icon('heroicon-o-archive-box')->color('gray')
                    ->visible(fn (ContactMessage $m) => $m->status !== 'CLOSED')
                    ->requiresConfirmation()->modalDescription('Close this message without a reply (for spam or duplicates).')
                    ->action(fn (ContactMessage $m) => Act::run(fn () => $m->update(['status' => 'CLOSED']), 'Message closed')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListContactMessages::route('/')];
    }
}
