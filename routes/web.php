<?php

use App\Livewire\RegisterTenant;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/register', RegisterTenant::class)
    ->name('register')
    ->middleware('guest');
