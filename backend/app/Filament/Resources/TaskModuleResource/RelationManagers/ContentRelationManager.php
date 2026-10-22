<?php

namespace App\Filament\Resources\TaskModuleResource\RelationManagers;

use App\Models\ContentItem;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

// Partner videos, tracks, games and portal tasks members run timers on
class ContentRelationManager extends RelationManager
{
    protected static string $relationship = 'content';

    protected static ?string $title = 'Content';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->required()->maxLength(160),
            TextInput::make('provider')->placeholder('e.g. YouTube, Spotify, Jovia Arcade')->maxLength(60),
            TextInput::make('url')->label('Link')->maxLength(500)->columnSpanFull()
                ->helperText('YouTube and Spotify links pause the timer when the media is paused. Audiomack links and other embeddable pages work too, or a site path like /games/snake.html.')
                ->regex('#^(https://\S+|/[\w\-./]+)$#')
                ->validationMessages(['regex' => 'Enter a full link starting with https:// (or a site path like /games/snake.html).']),
            Textarea::make('instructions')->label('Instructions for the member')->rows(3)->columnSpanFull()
                ->helperText('Shown for tasks members do elsewhere (meta tasks).'),
            Toggle::make('requires_proof')->label('Member must submit proof for review before being paid')->live(),
            TextInput::make('proof_label')->label('What proof to ask for')->placeholder('e.g. your Instagram username')->maxLength(120)
                ->visible(fn (Get $get) => $get('requires_proof')),
            Toggle::make('active')->label('Visible to members')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')->weight('bold')->description(fn (ContentItem $c) => $c->provider),
                TextColumn::make('url')->label('Link')->limit(50)->placeholder('No link'),
                IconColumn::make('requires_proof')->label('Proof')->boolean(),
                IconColumn::make('active')->boolean(),
            ])
            ->headerActions([CreateAction::make()->label('Add content')])
            ->actions([EditAction::make(), DeleteAction::make()]);
    }
}
