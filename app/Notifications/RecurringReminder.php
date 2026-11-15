<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Enums\RecurringKind;
use App\Filament\Resources\Recurrings\RecurringResource;
use App\Models\Occurrence;
use App\Models\Recurring;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;

/**
 * A reminder set on a bill or income, about one of its due dates. It carries
 * the note rather than the reminder, because a one-off reminder is deleted
 * once it's sent.
 */
class RecurringReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Recurring $recurring, public Occurrence $occurrence, public ?string $note = null) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannels(NotificationType::Reminder);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->note ?? $this->dueLine())
            ->body($this->note !== null
                ? $this->dueLine().' · '.$this->amount()
                : $this->amount())
            ->info()
            ->icon(Heroicon::OutlinedBellAlert)
            ->actions([
                Action::make('view')
                    ->url($this->recurringUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    /**
     * Email passes through a mail provider, so it leaves the amount out.
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Reminder: {$this->recurring->name}")
            ->when($this->note !== null, fn (MailMessage $mail) => $mail->line($this->note))
            ->line($this->dueLine().'.')
            ->action('View '.$this->recurring->name, $this->recurringUrl());
    }

    /**
     * "Netflix is due Mon, Nov 3", or "Retainer is expected Mon, Nov 3".
     */
    protected function dueLine(): string
    {
        $verb = $this->recurring->kind === RecurringKind::Income ? 'expected' : 'due';

        return "{$this->recurring->name} is {$verb} {$this->occurrence->expectedOn()->format('D, M j')}";
    }

    protected function amount(): string
    {
        return Number::currency((float) $this->recurring->amount, $this->recurring->account?->currency ?? 'USD');
    }

    protected function recurringUrl(): string
    {
        return RecurringResource::getUrl('view', ['record' => $this->recurring], panel: 'admin');
    }
}
