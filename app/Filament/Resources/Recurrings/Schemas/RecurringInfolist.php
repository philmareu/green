<?php

namespace App\Filament\Resources\Recurrings\Schemas;

use App\Enums\RecurringKind;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Recurring;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class RecurringInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $isFlex = fn (Recurring $record): bool => $record->kind === RecurringKind::Flex;

        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->columns(['default' => 2, 'md' => 4])
                    ->schema([
                        TextEntry::make('amount')
                            ->label(fn (Recurring $record): string => $isFlex($record) ? 'Budget' : 'Amount')
                            ->money(fn (Recurring $record): string => $record->account?->currency ?? 'USD')
                            ->helperText(fn (Recurring $record): string => $record->frequency->getLabel())
                            ->weight('bold'),
                        TextEntry::make('kind')
                            ->badge(),
                        TextEntry::make('account.name')
                            ->label('Account')
                            ->state(fn (Recurring $record): ?string => $record->account?->display_name)
                            ->url(fn (Recurring $record): ?string => $record->account
                                ? AccountResource::getUrl('view', ['record' => $record->account])
                                : null)
                            ->placeholder('Needs an account'),
                        TextEntry::make('starts_on')
                            ->label(fn (Recurring $record): string => $isFlex($record) ? 'First period' : 'First due')
                            ->date()
                            ->helperText(fn (Recurring $record): ?string => $record->ends_on ? 'Ends '.$record->ends_on->format('M j, Y') : null),
                        TextEntry::make('autopay')
                            ->label('Payment')
                            ->state(fn (Recurring $record): string => $record->autopay ? 'Autopay' : 'Pay by hand')
                            ->badge()
                            ->color(fn (Recurring $record): string => $record->autopay ? 'gray' : 'warning')
                            ->visible(fn (Recurring $record): bool => $record->kind === RecurringKind::Bill),
                        TextEntry::make('set_aside')
                            ->label('Set aside')
                            ->state(fn (Recurring $record): ?string => $record->needsSetAside()
                                ? Number::currency($record->monthlySetAside(), $record->account?->currency ?? 'USD').' a month'
                                : null)
                            ->helperText(fn (Recurring $record): ?string => ($byNow = $record->setAsideByNow()) !== null
                                ? Number::currency($byNow, $record->account?->currency ?? 'USD').' by now for '.$record->nextSetAsideDueDate()['expected_on']->format('M j')
                                : null)
                            ->visible(fn (Recurring $record): bool => $record->needsSetAside()),
                        TextEntry::make('rolls_over')
                            ->label('Rollover')
                            ->state(fn (Recurring $record): string => $record->rolls_over ? 'Rolls over' : 'Resets each period')
                            ->visible($isFlex),
                        TextEntry::make('payee')
                            ->label('Payee contains')
                            ->placeholder('Not set: link payments yourself')
                            ->hidden($isFlex),
                        TextEntry::make('amount_tolerance')
                            ->label('Match amounts')
                            ->state(fn (Recurring $record): string => $record->amount_tolerance === null ? 'Any amount' : "Within ±{$record->amount_tolerance}%")
                            ->hidden($isFlex),
                        TextEntry::make('category.full_name')
                            ->label('Category')
                            ->placeholder(fn (Recurring $record): string => $isFlex($record)
                                ? 'No category: nothing counts against the budget'
                                : 'Not set: payments keep their own category'),
                    ]),
            ]);
    }
}
