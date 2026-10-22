<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getTabs(): array
    {
        $tab = fn (string $label, string $status) => Tab::make($label)->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status));

        return [
            'disputed' => $tab('Problems', 'DISPUTED'),
            'to_ship' => $tab('To ship', 'PAID'),
            'shipped' => $tab('Shipped', 'SHIPPED'),
            'instalments' => $tab('Instalments', 'AWAITING_BALANCE'),
            'completed' => $tab('Completed', 'COMPLETED'),
            'all' => Tab::make('All'),
        ];
    }
}
