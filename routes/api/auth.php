<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');


Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});

Route::post('/email/verification-notification', [AuthController::class, 'sendVerificationEmail'])
    ->middleware('throttle:verification-notification');

Route::post('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->name('verification.verify')
    ->middleware(['signed', 'throttle:verify-email']);

Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware(['guest', 'throttle:forgot-password'])
    ->name('password.email');

Route::post('/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware(['guest', 'throttle:reset-password'])
    ->name('password.update');
