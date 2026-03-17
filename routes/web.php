<?php

use App\Http\Controllers\AgentCallbackController;
use App\Http\Controllers\WecomCallbackController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::any('/wecom/callback', [WecomCallbackController::class, 'handle']);
Route::any('/wecom/callback/agent', [AgentCallbackController::class, 'handle']);
