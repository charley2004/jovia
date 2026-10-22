<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaskModuleResource\Pages;
use App\Filament\Resources\TaskModuleResource\RelationManagers\ContentRelationManager;
use App\Filament\Support\Act;
use App\Models\TaskModule;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

// Rates, limits and content for the timed task modules
class TaskModuleResource extends Resource
{
    protected static ?string $model = TaskModule::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Task modules';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Module')->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')->required()->maxLength(80),
                    Select::make('reward_type')->options(['FLAT' => 'Flat amount per completed session', 'PER_SECOND' => 'Amount per active second'])->required()->live(),
                ]),
                TextInput::make('description')->required()->maxLength(255),
            ]),
            Section::make('Timer and rewards')->schema([
                Grid::make(2)->schema([
                    TextInput::make('min_seconds')->label('Shortest session (seconds)')->integer()->minValue(5)->required(),
                    TextInput::make('max_seconds')->label('Longest session (seconds)')->integer()->maxValue(3600)->required()->gte('min_seconds'),
                    TextInput::make('reward_amount')->label(fn (Get $get) => $get('reward_type') === 'PER_SECOND' ? 'Naira per active second' : 'Naira per session')->integer()->minValue(0)->required(),
                    TextInput::make('daily_cap')->label('Daily limit per member (₦)')->integer()->minValue(0)->required(),
                ]),
                Toggle::make('active')->label('Live for members'),
                Toggle::make('require_focus')->label('Pause the timer when the member leaves the page')
                    ->helperText('Turn off for tasks members do on other sites (meta tasks).'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->weight('bold')->description(fn (TaskModule $m) => $m->description),
                TextColumn::make('reward_amount')->label('Reward')->formatStateUsing(fn ($state, TaskModule $m) => Act::naira($state).($m->reward_type === 'PER_SECOND' ? ' / second' : ' / session')),
                TextColumn::make('daily_cap')->label('Daily limit')->formatStateUsing(fn ($state) => Act::naira($state)),
                TextColumn::make('min_seconds')->label('Session length')->formatStateUsing(fn ($state, TaskModule $m) => "{$m->min_seconds}–{$m->max_seconds} s"),
                TextColumn::make('content_count')->counts('content')->label('Content'),
                IconColumn::make('active')->boolean(),
            ])
            ->actions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ContentRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaskModules::route('/'),
            'edit' => Pages\EditTaskModule::route('/{record}/edit'),
        ];
    }
}
