<?php

use App\Filament\Pages\NotificationPreferences;
use App\Models\User;
use Livewire\Livewire;

it('sends guests to the login page', function () {
    $this->get('/notification-preferences')->assertRedirect('/login');
});

it('is linked from the user menu', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/')->assertSee(NotificationPreferences::getUrl());
});

it('shows the default channels until the user saves their own', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(NotificationPreferences::class)
        ->assertFormSet(['negative_balance' => ['database'], 'reminder' => ['database']]);
});

it('saves the choices for the signed-in user only', function () {
    $user = User::factory()->create();
    $someoneElse = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(NotificationPreferences::class)
        ->fillForm(['negative_balance' => ['database', 'mail']])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Saved');

    expect($user->refresh()->notification_preferences)->toBe(['negative_balance' => ['database', 'mail'], 'reminder' => ['database']])
        ->and($someoneElse->refresh()->notification_preferences)->toBeNull();
});

it('lets the user turn a notification off', function () {
    $user = User::factory()->create(['notification_preferences' => ['negative_balance' => ['mail']]]);
    $this->actingAs($user);

    Livewire::test(NotificationPreferences::class)
        ->fillForm(['negative_balance' => []])
        ->call('save');

    expect($user->refresh()->notification_preferences)->toBe(['negative_balance' => [], 'reminder' => ['database']]);
});

it('rejects a channel that does not exist', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(NotificationPreferences::class)
        ->fillForm(['negative_balance' => ['sms']])
        ->call('save')
        ->assertHasFormErrors(['negative_balance.0']);
});
