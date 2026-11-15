<?php

namespace App\Console\Commands;

use App\Services\Notifications\SendReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reminders:send')]
#[Description('Send the reminders set on bills and income whose due date is near')]
class SendRemindersCommand extends Command
{
    public function handle(SendReminders $sendReminders): int
    {
        $sent = $sendReminders->handle();

        $this->components->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
