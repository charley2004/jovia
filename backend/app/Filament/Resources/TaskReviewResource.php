<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaskReviewResource\Pages;
use App\Filament\Support\Act;
use App\Models\TaskSession;
use App\Services\AdminActions;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// Meta tasks that need proof: the reward is reserved and paid when approved here
class TaskReviewResource extends Resource
{
    protected static ?string $model = TaskSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Queues';

    protected static ?string $navigationLabel = 'Task proofs';

    protected static ?string $modelLabel = 'task proof';

    protected static ?int $navigationSort = 4;

    public static function getNavigationBadge(): ?string
    {
        $n = TaskSession::where('status', 'PENDING_REVIEW')->count();

        return $n ? (string) $n : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('proof')->with(['user', 'module']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('completed_at')
            ->columns([
                TextColumn::make('user.email')->label('Member')->searchable()->description(fn (TaskSession $s) => $s->user->name),
                TextColumn::make('module.name')->label('Task')->description(fn (TaskSession $s) => $s->content_title),
                TextColumn::make('proof')->wrap()
                    ->url(fn (TaskSession $s) => preg_match('#^https?://#', (string) $s->proof) ? $s->proof : null, shouldOpenInNewTab: true),
                TextColumn::make('reward')->formatStateUsing(fn ($state) => Act::naira($state)),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'PENDING_REVIEW' => 'warning', 'COMPLETED' => 'success', default => 'danger',
                }),
                TextColumn::make('review_note')->label('Note')->toggleable(),
                TextColumn::make('completed_at')->label('Finished')->since()->sortable(),
            ])
            ->actions([
                Action::make('approve')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (TaskSession $s) => $s->status === 'PENDING_REVIEW')
                    ->requiresConfirmation()
                    ->modalDescription(fn (TaskSession $s) => 'Pay the member '.Act::naira($s->reward).' for this task?')
                    ->action(fn (TaskSession $s) => Act::run(fn () => AdminActions::approveTaskProof($s), 'Approved and paid')),
                Action::make('reject')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (TaskSession $s) => $s->status === 'PENDING_REVIEW')
                    ->form([Textarea::make('note')->label('Reason (the member sees this)')->required()->maxLength(500)])
                    ->action(fn (TaskSession $s, array $data) => Act::run(fn () => AdminActions::rejectTaskProof($s, $data['note']), 'Proof rejected')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTaskReviews::route('/')];
    }
}
