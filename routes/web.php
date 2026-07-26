<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LLMController;
use Telegram\Bot\Laravel\Facades\Telegram;

Route::get('/home', function() { return redirect(route('chat')); });

Route::middleware('auth')->group(function() {
    Route::get('/generate', [LLMController::class, 'generate'])->name('generate');

    Route::get('/', function() {
        return view('chat');
    })->name('chat');

});

