<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\Filament\Resources\Books\BookResource;
use App\Filament\Resources\PlaidItems\PlaidItemResource;
use App\Models\Account;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->columns(['default' => 2, 'md' => 4])
                    ->schema([
                        TextEntry::make('available_balance')
                            ->label('Available')
                            ->state(fn (Account $record): ?float => $record->spendableBalance())
                            ->money(fn (Account $record): string => $record->currency)
                            ->helperText(fn (Account $record): string => match (true) {
                                $record->isCredit() => 'Credit remaining',
                                $record->available_balance === null => 'Bank reports current balance only',
                                default => 'After pending activity',
                            })
                            ->weight('bold'),
                        TextEntry::make('current_balance')
                            ->label('Current')
                            ->money(fn (Account $record): string => $record->currency)
                            ->helperText(fn (Account $record): ?string => $record->isCredit() ? 'Owed' : null),
                        TextEntry::make('credit_limit')
                            ->label('Credit limit')
                            ->money(fn (Account $record): string => $record->currency)
                            ->visible(fn (Account $record): bool => $record->isCredit()),
                        TextEntry::make('pending_count')
                            ->label('Pending transactions')
                            ->state(fn (Account $record): int => $record->transactions()->onlyPending()->count()),
                        TextEntry::make('book.name')
                            ->label('Book')
                            ->badge()
                            ->color(fn (Account $record) => $record->book?->kind?->getColor() ?? 'gray')
                            ->placeholder('Unassigned')
                            ->url(fn (Account $record): ?string => $record->book
                                ? BookResource::getUrl('view', ['record' => $record->book])
                                : null),
                        TextEntry::make('plaidItem.institution_name')
                            ->label('Bank')
                            ->helperText(fn (Account $record): ?string => $record->official_name)
                            ->url(fn (Account $record): string => PlaidItemResource::getUrl(parameters: array_filter([
                                'search' => $record->plaidItem?->institution_name,
                            ]))),
                        TextEntry::make('subtype')
                            ->label('Type')
                            ->formatStateUsing(fn (?string $state): string => Str::headline($state ?? ''))
                            ->placeholder('Unknown'),
                        TextEntry::make('plaidItem.status')
                            ->label('Connection')
                            ->badge()
                            ->helperText(fn (Account $record): ?string => $record->plaidItem?->error_message),
                        TextEntry::make('plaidItem.last_synced_at')
                            ->label('Last synced')
                            ->since()
                            ->dateTimeTooltip()
                            ->placeholder('Never'),
                    ]),
            ]);
    }
}
