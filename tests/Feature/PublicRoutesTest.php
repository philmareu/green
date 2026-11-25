<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;

it('answers Filament’s unused export and import downloads with a quiet 404', function (string $path) {
    Exceptions::fake();

    $this->get($path)->assertNotFound();

    Exceptions::assertNothingReported();
})->with([
    '/filament/exports/1/download',
    '/filament/imports/1/failed-rows/download',
]);

it('believes X-Forwarded-Proto only from a proxy listed in TRUSTED_PROXIES', function () {
    $sessionCookie = fn () => $this->withHeader('X-Forwarded-Proto', 'https')
        ->get('/login')
        ->getCookie(config('session.cookie'), decrypt: false);

    try {
        expect($sessionCookie()->isSecure())->toBeFalse();

        config(['trustedproxy.proxies' => '127.0.0.1']);

        expect($sessionCookie()->isSecure())->toBeTrue();
    } finally {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }
});
