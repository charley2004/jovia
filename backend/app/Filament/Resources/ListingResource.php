<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ListingResource\Pages;
use App\Filament\Support\Act;
use App\Models\Listing;
use App\Services\AdminActions;
use App\Services\Settings;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ListingResource extends Resource
{
    protected static ?string $model = Listing::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Queues';

    protected static ?string $navigationLabel = 'Marketplace listings';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        $n = Listing::where('status', 'PENDING')->count();

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
                ImageColumn::make('image_path')->label('')->disk('public')->square()->size(56),
                TextColumn::make('title')->searchable()->weight('bold')->description(fn (Listing $l) => $l->category.' · '.$l->quantity.' in stock'),
                TextColumn::make('price')->formatStateUsing(fn ($state) => Act::naira($state)),
                TextColumn::make('description')->wrap()->limit(120),
                TextColumn::make('user.email')->label('Seller')->searchable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'PENDING' => 'warning', 'APPROVED' => 'success', default => 'danger',
                }),
                TextColumn::make('created_at')->label('Submitted')->since()->sortable(),
            ])
            ->actions([
                Action::make('approve')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Listing $l) => $l->status === 'PENDING')
                    ->requiresConfirmation()
                    ->modalDescription(fn () => 'The listing goes live and the member is paid '.Act::naira(Settings::get('marketplaceReward')).'.')
                    ->action(fn (Listing $l) => Act::run(fn () => AdminActions::approveListing($l), 'Listing approved and reward paid')),
                Action::make('reject')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (Listing $l) => $l->status === 'PENDING')
                    ->form([Textarea::make('note')->label('Reason (the member sees this)')->required()->maxLength(500)])
                    ->action(fn (Listing $l, array $data) => Act::run(fn () => AdminActions::rejectListing($l, $data['note']), 'Listing rejected')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListListings::route('/')];
    }
}
