<?php

namespace App\Filament\Resources\PlaidLogs\Pages;

use App\Filament\Resources\PlaidLogs\PlaidLogResource;
use App\Models\PlaidLog;
use Filament\Resources\Pages\ListRecords;

class ListPlaidLogs extends ListRecords
{
    protected static string $resource = PlaidLogResource::class;

    public function getSubheading(): string
    {
        return 'Every sync, Live balance, bank link and removal Green ran with Plaid. Entries are deleted after '.PlaidLog::RETENTION_DAYS.' days.';
    }
}
