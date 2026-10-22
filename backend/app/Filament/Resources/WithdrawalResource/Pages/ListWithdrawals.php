<?php

namespace App\Filament\Resources\WithdrawalResource\Pages;

use App\Filament\Resources\WithdrawalResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListWithdrawals extends ListRecords
{
    protected static string $resource = WithdrawalResource::class;

    public function getTabs(): array
    {
        $tab = fn (string $label, string $status) => Tab::make($label)->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status));

        return ['pending' => $tab('Pending', 'PENDING'), 'paid' => $tab('Paid', 'PAID'), 'rejected' => $tab('Rejected', 'REJECTED')];
    }
}
