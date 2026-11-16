<?php

namespace App\Notifications;

use App\Enums\NotificationType;
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
 * A flex spend's period is close to its budget, or over it.
 */
class BudgetAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  float  $spent  What the period had spent when the alert was raised.
     */
    public function __construct(public Recurring $recurring, public Occurrence $period, public float $spent) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannels(NotificationType::BudgetAlert);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $currency = $this->recurring->account?->currency ?? 'USD';

        return FilamentNotification::make()
            ->title($this->headline())
            ->body(sprintf(
                '%s of %s spent, %s.',
                Number::currency($this->spent, $currency),
                Number::currency($this->period->budget(), $currency),
                $this->periodLine(),
            ))
            ->status($this->isOver() ? 'danger' : 'warning')
            ->icon(Heroicon::OutlinedChartPie)
            ->actions([
                Action::make('view')
                    ->url($this->recurringUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    /**
     * Email passes through a mail provider, so it leaves the amounts out.
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->line("{$this->headline()}, {$this->periodLine()}.")
            ->action('View '.$this->recurring->name, $this->recurringUrl());
    }

    protected function isOver(): bool
    {
        return $this->spent > $this->period->budget();
    }

    /**
     * "Groceries is over budget", or "Groceries has used 85% of its budget".
     */
    protected function headline(): string
    {
        if ($this->isOver()) {
            return "{$this->recurring->name} is over budget";
        }

        $percent = (int) floor($this->spent / $this->period->budget() * 100);

        return "{$this->recurring->name} has used {$percent}% of its budget";
    }

    /**
     * "for the period through Thu, Oct 22"
     */
    protected function periodLine(): string
    {
        $end = $this->period->periodEnd()?->subDay();

        return $end !== null ? 'for the period through '.$end->format('D, M j') : 'for this period';
    }

    protected function recurringUrl(): string
    {
        return RecurringResource::getUrl('view', ['record' => $this->recurring], panel: 'admin');
    }
}
