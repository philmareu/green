<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Spatie\LoginLink\Exceptions\NotAllowedInCurrentEnvironment;

beforeEach(function () {
    // CSRF checks are only skipped while the environment is "testing".
    $this->app['env'] = 'local';
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->user = User::factory()->create(['email' => 'dev@example.com']);
});

it('offers no login links locally unless LOGIN_LINK_ENABLED is on', function () {
    $this->get('/login')->assertOk()->assertDontSee('Log in as');

    expect(fn () => $this->withoutExceptionHandling()->post(route('loginLinkLogin'), ['email' => 'dev@example.com']))
        ->toThrow(NotAllowedInCurrentEnvironment::class);

    $this->assertGuest();
});

it('logs in with one click when LOGIN_LINK_ENABLED is on', function () {
    config(['login-link.allowed_environments' => ['local']]);
    User::factory()->create(['email' => 'other@example.com']);

    $this->get('/login')
        ->assertOk()
        ->assertSeeInOrder(['Log in as dev@example.com', 'Log in as other@example.com']);

    $this->post(route('loginLinkLogin'), ['email' => 'dev@example.com'])->assertRedirect('/');

    $this->assertAuthenticatedAs($this->user);
});
