<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LLMController;

Route::middleware('auth')->group(function() {
    Route::get('/generate', [LLMController::class, 'generate'])->name('generate');

    Route::get('/', function() {
        return view('chat');
    });
});

