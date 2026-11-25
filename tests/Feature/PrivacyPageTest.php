<?php

it('shows the privacy policy without signing in', function () {
    config(['green.privacy_contact' => 'owner@example.com']);

    $this->get('/privacy')
        ->assertOk()
        ->assertSee('Privacy policy')
        ->assertSee('mailto:owner@example.com', false);
});

it('links to the privacy policy from the sign-in page', function () {
    $this->get('/login')->assertOk()->assertSee(route('privacy'));
});

it('only claims HTTPS and required two-factor sign-in when they are true', function () {
    $this->get('/privacy')
        ->assertDontSee('served over HTTPS')
        ->assertSee('Every user can add a code from an authenticator app');

    $this->app['env'] = 'production';

    $this->get('https://localhost/privacy')
        ->assertSee('served over HTTPS')
        ->assertSee('Everyone signs in with a password and a code from an authenticator app');
});
