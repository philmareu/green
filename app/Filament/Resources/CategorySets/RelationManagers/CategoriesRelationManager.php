<?php

namespace App\Filament\Resources\CategorySets\RelationManagers;

use App\Enums\CategoryType;
use App\Models\Category;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class CategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'categories';

    protected static ?string $recordTitleAttribute = 'name';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                        ->where('category_set_id', $this->getOwnerRecord()->getKey())
                        ->where('parent_id', $get('parent_id'))),
                Select::make('parent_id')
                    ->label('Parent')
                    ->options(fn (?Category $record): array => $this->getOwnerRecord()->categories()
                        ->topLevel()
                        ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->placeholder('None (top level)')
                    ->searchable()
                    ->live()
                    ->disabled(fn (?Category $record): bool => (bool) $record?->children()->exists())
                    ->helperText(fn (?Category $record): string => $record?->children()->exists()
                        ? 'It has subcategories, so it stays at the top level.'
                        : 'Subcategories take their parent’s type.'),
                ToggleButtons::make('type')
                    ->options(CategoryType::class)
                    ->default(CategoryType::Expense)
                    ->inline()
                    ->required()
                    ->hidden(fn (Get $get): bool => $get->filled('parent_id')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('parent'))
            ->columns([
                TextColumn::make('name')
                    ->formatStateUsing(fn (Category $record): string => $record->parent_id ? "↳ {$record->name}" : $record->name)
                    ->weight(fn (Category $record): ?string => $record->parent_id ? null : 'medium')
                    ->searchable(),
            ])
            ->defaultGroup(Group::make('type')
                ->titlePrefixedWithLabel(false)
                ->orderQueryUsing(fn (Builder $query): Builder => $query->orderByRaw(
                    "case {$query->qualifyColumn('type')} when 'income' then 0 when 'expense' then 1 else 2 end",
                )))
            ->groupingSettingsHidden()
            // Each parent followed by its subcategories, alphabetically.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('coalesce((select parents.name from categories as parents where parents.id = categories.parent_id), categories.name)')
                ->orderByRaw('coalesce(categories.parent_id, categories.id)')
                ->orderByRaw('categories.parent_id is not null')
                ->orderBy('categories.name'))
            ->paginated(false)
            ->headerActions([
                CreateAction::make()->label('New category'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(fn (Category $record): ?string => $record->children()->exists()
                        ? 'Its subcategories move to the top level.'
                        : null),
            ])
            ->emptyStateHeading('No categories yet')
            ->emptyStateDescription('Add the categories this set offers its Books.');
    }
}
