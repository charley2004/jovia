<?php

namespace App\Filament\Resources\ListingResource\Pages;

use App\Filament\Resources\ListingResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListListings extends ListRecords
{
    protected static string $resource = ListingResource::class;

    public function getTabs(): array
    {
        $tab = fn (string $label, string $status) => Tab::make($label)->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status));

        return ['pending' => $tab('Pending', 'PENDING'), 'approved' => $tab('Approved', 'APPROVED'), 'rejected' => $tab('Rejected', 'REJECTED')];
    }
}
