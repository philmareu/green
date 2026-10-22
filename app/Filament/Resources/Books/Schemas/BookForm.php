<?php

namespace App\Filament\Resources\Books\Schemas;

use App\Enums\BookKind;
use App\Models\CategorySet;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class BookForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->placeholder('e.g. Acme Consulting LLC')
                    ->required()
                    ->maxLength(255),
                ToggleButtons::make('kind')
                    ->options(BookKind::class)
                    ->default(BookKind::Business)
                    ->inline()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        $kind = $get->enum('kind', BookKind::class, isNullable: true);
                        $currentSetKind = CategorySet::query()->ownedBy(auth()->user())->whereKey($get('category_set_id'))->value('kind');

                        if (($currentSetKind !== $kind) && ($suggested = static::suggestedCategorySetId($kind))) {
                            $set('category_set_id', $suggested);
                        }
                    }),
                Select::make('category_set_id')
                    ->label('Categories')
                    ->options(fn (): array => CategorySet::query()->ownedBy(auth()->user())->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => static::suggestedCategorySetId(BookKind::Business))
                    ->placeholder('None')
                    ->helperText('The category set this Book uses. Manage sets under Categories.'),
            ]);
    }

    /**
     * The user's first category set of the given kind, if they have one.
     */
    protected static function suggestedCategorySetId(?BookKind $kind): ?int
    {
        if ($kind === null) {
            return null;
        }

        return CategorySet::query()->ownedBy(auth()->user())->where('kind', $kind)->orderBy('id')->value('id');
    }
}
