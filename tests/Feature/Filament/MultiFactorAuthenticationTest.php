<?php

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('requires an authenticator app in production only', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->isMultiFactorAuthenticationRequired())->toBeFalse();

    $this->app['env'] = 'production';

    expect($panel->isMultiFactorAuthenticationRequired())->toBeTrue();
});

it('asks for the authenticator code after the password', function () {
    $authenticator = AppAuthentication::make();
    $user = User::factory()->create(['app_authentication_secret' => $authenticator->generateSecret()]);

    $login = Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertSee('Verify your identity');

    $this->assertGuest();

    $login->set('data.multiFactor.app.code', '000000')
        ->call('authenticate')
        ->assertHasErrors();

    $this->assertGuest();

    $login->set('data.multiFactor.app.code', $authenticator->getCurrentCode($user))
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($user);
});
