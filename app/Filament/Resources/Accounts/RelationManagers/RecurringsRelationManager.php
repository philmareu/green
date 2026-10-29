<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Filament\Actions\PlaidActions;
use App\Filament\Resources\Recurrings\Schemas\RecurringForm;
use App\Filament\Resources\Recurrings\Tables\RecurringsTable;
use App\Models\Account;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The account's bills, flex spends and income, added and edited here without
 * picking the account. A row opens the item's page with its due dates.
 */
class RecurringsRelationManager extends RelationManager
{
    protected static string $relationship = 'recurrings';

    protected static ?string $title = 'Recurring';

    /** @var array<string, string> */
    protected $listeners = [PlaidActions::SYNCED_EVENT => '$refresh'];

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Account $ownerRecord */
        $overdue = $ownerRecord->recurrings()->overdue()->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'danger';
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        return 'Overdue bills and income';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        /** @var Account $account */
        $account = $this->getOwnerRecord();

        return RecurringForm::configure($schema, $account);
    }

    public function table(Table $table): Table
    {
        return RecurringsTable::configure($table, withinAccount: true)
            ->modelLabel('recurring item')
            ->pluralModelLabel('recurring')
            ->recordTitleAttribute('name')
            ->headerActions([
                CreateAction::make()
                    ->label('New recurring item')
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'user_id' => auth()->id()]),
            ]);
    }
}
