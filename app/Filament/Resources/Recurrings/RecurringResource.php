<?php

namespace App\Filament\Resources\Recurrings;

use App\Filament\Resources\Recurrings\Pages\CreateRecurring;
use App\Filament\Resources\Recurrings\Pages\EditRecurring;
use App\Filament\Resources\Recurrings\Pages\ListRecurrings;
use App\Filament\Resources\Recurrings\Pages\ViewRecurring;
use App\Filament\Resources\Recurrings\RelationManagers\OccurrencesRelationManager;
use App\Filament\Resources\Recurrings\Schemas\RecurringForm;
use App\Filament\Resources\Recurrings\Schemas\RecurringInfolist;
use App\Filament\Resources\Recurrings\Tables\RecurringsTable;
use App\Models\Recurring;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RecurringResource extends Resource
{
    protected static ?string $model = Recurring::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $navigationLabel = 'Recurring';

    protected static ?string $modelLabel = 'recurring item';

    protected static ?string $pluralModelLabel = 'recurring';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'recurring';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->ownedBy(auth()->user());
    }

    public static function getNavigationBadge(): ?string
    {
        $overdue = static::getEloquentQuery()->overdue()->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Overdue bills and income';
    }

    public static function form(Schema $schema): Schema
    {
        return RecurringForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RecurringInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecurringsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            OccurrencesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecurrings::route('/'),
            'create' => CreateRecurring::route('/create'),
            'view' => ViewRecurring::route('/{record}'),
            'edit' => EditRecurring::route('/{record}/edit'),
        ];
    }
}
