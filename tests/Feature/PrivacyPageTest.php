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
