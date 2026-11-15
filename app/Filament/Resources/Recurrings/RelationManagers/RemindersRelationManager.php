<?php

namespace App\Filament\Resources\Recurrings\RelationManagers;

use App\Enums\RecurringKind;
use App\Models\Recurring;
use App\Models\Reminder;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Reminders on a bill or income, such as "cancel before it renews". Flex
 * spends have budget periods, not due dates, so they have none.
 */
class RemindersRelationManager extends RelationManager
{
    protected static string $relationship = 'reminders';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Recurring && $ownerRecord->kind !== RecurringKind::Flex;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('days_before')
                    ->label('Days before the due date')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(Reminder::MAX_DAYS_BEFORE)
                    ->default(3)
                    ->required()
                    ->helperText('Sent that morning. 0 sends it on the due date.'),
                ToggleButtons::make('repeats')
                    ->label('Remind me')
                    ->boolean(trueLabel: 'Every due date', falseLabel: 'Next due date only')
                    ->default(false)
                    ->inline()
                    ->required(),
                TextInput::make('note')
                    ->placeholder('e.g. Cancel before it renews')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('days_before')
                    ->label('When')
                    ->formatStateUsing(fn (Reminder $record): string => $record->scheduleLabel()),
                TextColumn::make('note')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('next')
                    ->label('Next reminder')
                    ->state(fn (Reminder $record): ?string => ($occurrence = $record->upcomingOccurrence(today()->toImmutable()))
                        ? $record->remindsOn($occurrence)->max(today())->format('D, M j').' for '.$occurrence->expectedOn()->format('M j')
                        : null)
                    ->placeholder('Nothing due'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add reminder'),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton(),
            ])
            ->paginated(false)
            ->emptyStateHeading('No reminders')
            ->emptyStateDescription('Get a heads-up before a due date, for example to cancel a subscription before it renews.');
    }
}
