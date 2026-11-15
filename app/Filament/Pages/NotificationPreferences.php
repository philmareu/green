<?php

namespace App\Filament\Pages;

use App\Enums\NotificationType;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * What the signed-in user is notified about, and where. Adding a case to
 * NotificationType adds its setting here.
 *
 * @property-read Schema $form
 */
class NotificationPreferences extends Page
{
    protected string $view = 'filament.pages.notification-preferences';

    protected static ?string $slug = 'notification-preferences';

    protected static ?string $title = 'Notifications';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * @var array<string, list<string>>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(collect(NotificationType::cases())
            ->mapWithKeys(fn (NotificationType $type): array => [$type->value => auth()->user()->notificationChannels($type)])
            ->all());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make(array_map($this->typeSection(...), NotificationType::cases()))
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->submit('save'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        auth()->user()->forceFill(['notification_preferences' => $this->form->getState()])->save();

        Notification::make()->title('Saved')->success()->send();
    }

    protected function typeSection(NotificationType $type): Section
    {
        return Section::make($type->label())
            ->description($type->description())
            ->schema([
                CheckboxList::make($type->value)
                    ->hiddenLabel()
                    ->options([
                        'database' => 'In Green',
                        'mail' => 'Email',
                    ])
                    ->descriptions([
                        'database' => 'Shows in the bell at the top of the page.',
                        'mail' => 'Names the account but not the balance. This server needs a mail provider set in its .env.',
                    ]),
            ]);
    }
}
