<?php

use App\Models\PlaidLog;

it('prunes entries older than 14 days', function () {
    $this->travelTo('2026-10-29 12:00');
    $expired = PlaidLog::factory()->create(['started_at' => '2026-10-15 11:59']);
    $kept = PlaidLog::factory()->create(['started_at' => '2026-10-15 12:01']);

    $this->artisan('model:prune')->assertSuccessful();

    $this->assertModelMissing($expired);
    $this->assertModelExists($kept);
});

it('schedules pruning', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('model:prune')->assertSuccessful();
});
