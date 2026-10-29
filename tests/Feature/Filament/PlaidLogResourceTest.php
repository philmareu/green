<?php

use App\Enums\PlaidLogResult;
use App\Filament\Resources\PlaidLogs\Pages\ListPlaidLogs;
use App\Models\PlaidItem;
use App\Models\PlaidLog;
use App\Models\User;
use Livewire\Livewire;

it('lists only your Plaid activity, newest first', function () {
    $user = User::factory()->create();
    $item = PlaidItem::factory()->for($user)->create();
    $finished = PlaidLog::factory()->for($item)->create(['started_at' => now()->subHour()]);
    $running = PlaidLog::factory()->for($item)->create([
        'started_at' => now(),
        'result' => PlaidLogResult::Unfinished,
        'duration_ms' => null,
        'pages' => null,
        'added' => null,
        'modified' => null,
        'removed' => null,
        'requests' => null,
    ]);
    $theirs = PlaidLog::factory()->create();
    $this->actingAs($user);

    Livewire::test(ListPlaidLogs::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$running, $finished], inOrder: true)
        ->assertCanNotSeeTableRecords([$theirs]);
});
