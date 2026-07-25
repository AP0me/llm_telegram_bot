<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LLMController;

Route::get('/generate', [LLMController::class, 'generate']);

