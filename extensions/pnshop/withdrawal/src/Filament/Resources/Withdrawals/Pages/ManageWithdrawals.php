<?php

namespace PnShop\Plugins\Withdrawal\Filament\Resources\Withdrawals\Pages;

use Filament\Resources\Pages\ManageRecords;
use PnShop\Plugins\Withdrawal\Filament\Resources\Withdrawals\WithdrawalResource;

class ManageWithdrawals extends ManageRecords
{
    protected static string $resource = WithdrawalResource::class;
}
