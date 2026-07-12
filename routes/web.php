<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FluentFormsWebhookController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OrderToolController;
use App\Http\Controllers\SchoolOnboardingController;
use App\Http\Controllers\ShopExportController;
use App\Http\Middleware\ToolAuth;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// FluentForms-Webhook (kein Login/CSRF — Secret in der URL)
Route::post('/webhooks/fluentforms/{secret}', [FluentFormsWebhookController::class, 'receive'])->name('webhooks.fluentforms');

Route::middleware(ToolAuth::class)->group(function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');

    Route::get('/auftragsdokumente', [OrderToolController::class, 'index'])->name('tool.index');
    Route::post('/upload', [OrderToolController::class, 'upload'])->name('tool.upload');
    Route::get('/shop-export', [ShopExportController::class, 'form'])->name('shop.form');
    Route::post('/shop-export', [ShopExportController::class, 'fetch'])->name('shop.fetch');
    Route::get('/job/{jobId}', [OrderToolController::class, 'show'])->name('job.show');
    Route::post('/job/{jobId}/generate', [OrderToolController::class, 'generate'])->name('job.generate');
    Route::get('/job/{jobId}/result', [OrderToolController::class, 'result'])->name('job.result');
    Route::get('/job/{jobId}/download/{file}', [OrderToolController::class, 'download'])->name('job.download');
    Route::get('/job/{jobId}/zip', [OrderToolController::class, 'zip'])->name('job.zip');

    // Modul 2: Schul-Onboarding
    Route::get('/schulen', [SchoolOnboardingController::class, 'index'])->name('schools.index');
    Route::get('/schulen/neu', [SchoolOnboardingController::class, 'create'])->name('schools.create');
    Route::post('/schulen', [SchoolOnboardingController::class, 'store'])->name('schools.store');
    Route::get('/schulen/printify/blueprints', [SchoolOnboardingController::class, 'printifyBlueprintSearch'])->name('schools.printify.blueprints');
    Route::get('/schulen/printify/providers', [SchoolOnboardingController::class, 'printifyProviderSearch'])->name('schools.printify.providers');
    Route::get('/schulen/{onboarding}', [SchoolOnboardingController::class, 'show'])->name('schools.show');
    Route::put('/schulen/{onboarding}', [SchoolOnboardingController::class, 'update'])->name('schools.update');
    Route::post('/schulen/{onboarding}/vorschau', [SchoolOnboardingController::class, 'preview'])->name('schools.preview');
    Route::post('/schulen/{onboarding}/anlegen', [SchoolOnboardingController::class, 'provision'])->name('schools.provision');
    Route::post('/schulen/{onboarding}/ondemand-sync', [SchoolOnboardingController::class, 'ondemandSync'])->name('schools.ondemand-sync');
    Route::delete('/schulen/{onboarding}', [SchoolOnboardingController::class, 'destroy'])->name('schools.destroy');
});
