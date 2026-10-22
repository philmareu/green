<?php

namespace App\Filament\Resources\CategorySets;

use App\Filament\Resources\CategorySets\Pages\CreateCategorySet;
use App\Filament\Resources\CategorySets\Pages\EditCategorySet;
use App\Filament\Resources\CategorySets\Pages\ListCategorySets;
use App\Filament\Resources\CategorySets\Pages\ViewCategorySet;
use App\Filament\Resources\CategorySets\RelationManagers\CategoriesRelationManager;
use App\Filament\Resources\CategorySets\Schemas\CategorySetForm;
use App\Filament\Resources\CategorySets\Schemas\CategorySetInfolist;
use App\Filament\Resources\CategorySets\Tables\CategorySetsTable;
use App\Models\CategorySet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategorySetResource extends Resource
{
    protected static ?string $model = CategorySet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Categories';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'categories';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->ownedBy(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return CategorySetForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CategorySetInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CategorySetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CategoriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategorySets::route('/'),
            'create' => CreateCategorySet::route('/create'),
            'view' => ViewCategorySet::route('/{record}'),
            'edit' => EditCategorySet::route('/{record}/edit'),
        ];
    }
}
