<?php

use App\Http\Controllers\SsoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// KERISI SSO hop — see SsoController for the HMAC verification + token handoff.
Route::get('/sso/consume', [SsoController::class, 'consume']);
