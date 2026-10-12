<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\PageController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});

Route::middleware(['auth', 'org'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', [PageController::class, 'home'])->name('home');
    Route::get('/profile', [PageController::class, 'profile']);
    Route::get('/leads/{lead}', [PageController::class, 'lead'])->whereNumber('lead');
    Route::get('/contacts/{contact}', [PageController::class, 'contact'])->whereNumber('contact');
    Route::get('/{key}', [PageController::class, 'show'])
        ->where('key', 'leads|contacts|pipeline|whatsapp|follow-ups|calendar|campaigns|audiences|retargeting|reports|team|imports|integrations|automation|audit|settings');
});
