<?php

declare(strict_types=1);

use App\Livewire\Hello;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/hello', Hello::class)->name('hello');
