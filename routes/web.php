<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Plaid sends OAuth banks (Chase, Capital One, ...) back here after the user
 * approves access. Set PLAID_REDIRECT_URI to this URL and register it in the
 * Plaid dashboard. We hand the user back to the page that started Link.
 */
Route::get('/plaid/oauth', function (Request $request) {
    $returnUrl = $request->session()->get('plaid.link.return_url');

    abort_unless($returnUrl, 404);

    $request->session()->put('plaid.link.received_redirect_uri', $request->fullUrl());

    return redirect()->to($returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'oauth=1');
})->name('plaid.oauth');
