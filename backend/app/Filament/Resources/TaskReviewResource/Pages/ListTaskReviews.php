<?php

namespace App\Filament\Resources\TaskReviewResource\Pages;

use App\Filament\Resources\TaskReviewResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTaskReviews extends ListRecords
{
    protected static string $resource = TaskReviewResource::class;

    public function getTabs(): array
    {
        $tab = fn (string $label, string $status) => Tab::make($label)->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status));

        return ['pending' => $tab('Pending', 'PENDING_REVIEW'), 'approved' => $tab('Approved', 'COMPLETED'), 'rejected' => $tab('Rejected', 'REJECTED')];
    }
}
