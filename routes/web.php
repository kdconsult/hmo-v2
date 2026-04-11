<?php

use App\Livewire\RegisterTenant;
use Illuminate\Support\Facades\Route;

Route::domain(config('app.domain'))->group(function () {
    Route::get('/', function () {
        return view('welcome');
    });

    Route::get('/register', RegisterTenant::class)
        ->name('register')
        ->middleware(['guest', 'throttle:5,1']);
});
