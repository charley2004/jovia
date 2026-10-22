<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Resources\PaymentResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    public function getTabs(): array
    {
        $tab = fn (string $label, string $status) => Tab::make($label)->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status));

        return [
            'pending' => $tab('Pending', 'PENDING'),
            'refunds' => $tab('Refund requests', 'REFUND_REQUESTED'),
            'approved' => $tab('Approved', 'APPROVED'),
            'rejected' => $tab('Rejected', 'REJECTED'),
            'refunded' => $tab('Refunded', 'REFUNDED'),
        ];
    }
}
