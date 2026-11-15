<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;

class AccountWentNegative extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  float  $balance  The balance when it went negative.
     */
    public function __construct(public Account $account, public float $balance) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannels(NotificationType::NegativeBalance);
    }

    /**
     * Shown in the bell, behind the sign-in, so it can carry the balance.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title("{$this->account->display_name} is negative")
            ->body('Available balance: '.Number::currency($this->balance, $this->account->currency))
            ->danger()
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->actions([
                Action::make('view')
                    ->url($this->accountUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    /**
     * Email passes through a mail provider, so it leaves the balance out.
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('An account is negative')
            ->line("{$this->account->display_name} has dropped below zero.")
            ->action('View account', $this->accountUrl());
    }

    protected function accountUrl(): string
    {
        return AccountResource::getUrl('view', ['record' => $this->account], panel: 'admin');
    }
}
