<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderToolController;
use App\Http\Middleware\ToolAuth;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(ToolAuth::class)->group(function () {
    Route::get('/', [OrderToolController::class, 'index'])->name('tool.index');
    Route::post('/upload', [OrderToolController::class, 'upload'])->name('tool.upload');
    Route::get('/job/{jobId}', [OrderToolController::class, 'show'])->name('job.show');
    Route::post('/job/{jobId}/generate', [OrderToolController::class, 'generate'])->name('job.generate');
    Route::get('/job/{jobId}/result', [OrderToolController::class, 'result'])->name('job.result');
    Route::get('/job/{jobId}/download/{file}', [OrderToolController::class, 'download'])->name('job.download');
    Route::get('/job/{jobId}/zip', [OrderToolController::class, 'zip'])->name('job.zip');
});
