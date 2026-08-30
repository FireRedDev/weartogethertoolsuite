<?php

use App\Http\Controllers\AdminStatusController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CloseOrderWindowController;
use App\Http\Controllers\FluentFormsWebhookController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OrderToolController;
use App\Http\Controllers\PresentationSheetController;
use App\Http\Controllers\SchoolOnboardingController;
use App\Http\Controllers\ShopExportController;
use App\Http\Controllers\StatisticsController;
use App\Http\Middleware\ToolAuth;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// FluentForms-Webhook (kein Login/CSRF — Secret in der URL)
Route::post('/webhooks/fluentforms/{secret}', [FluentFormsWebhookController::class, 'receive'])->name('webhooks.fluentforms');
// Dieselbe URL im Browser (GET) öffnen = Test, ob Secret/URL stimmen
Route::get('/webhooks/fluentforms/{secret}', [FluentFormsWebhookController::class, 'verify'])->name('webhooks.fluentforms.verify');

// Schullogo ausliefern (Vorschau/Download im Tool). Bewusst ohne Zugangsschutz,
// damit Printify/Dynamic Mockups die Datei notfalls selbst laden können.
Route::get('/schul-logo/{onboarding}/{slot}', [SchoolOnboardingController::class, 'logoShow'])->name('schools.logo.show');

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
    Route::post('/schulen/{onboarding}/logo/{slot}', [SchoolOnboardingController::class, 'logoUpload'])->name('schools.logo.upload');
    Route::delete('/schulen/{onboarding}/logo/{slot}', [SchoolOnboardingController::class, 'logoReset'])->name('schools.logo.reset');
    Route::post('/schulen/{onboarding}/vorschau', [SchoolOnboardingController::class, 'preview'])->name('schools.preview');
    Route::post('/schulen/{onboarding}/anlegen', [SchoolOnboardingController::class, 'provision'])->name('schools.provision');
    Route::post('/schulen/{onboarding}/ondemand-sync', [SchoolOnboardingController::class, 'ondemandSync'])->name('schools.ondemand-sync');
    Route::post('/schulen/{onboarding}/folgejahr', [SchoolOnboardingController::class, 'duplicate'])->name('schools.duplicate');
    Route::post('/schulen/{onboarding}/seite-pruefen', [SchoolOnboardingController::class, 'checkShopPage'])->name('schools.check-page');
    Route::delete('/schulen/{onboarding}', [SchoolOnboardingController::class, 'destroy'])->name('schools.destroy');

    // Präsentationsblatt je Bestellfenster
    Route::post('/schulen/{onboarding}/blatt/{slot}', [PresentationSheetController::class, 'upload'])->name('sheet.upload');
    Route::delete('/schulen/{onboarding}/blatt/{slot}', [PresentationSheetController::class, 'deleteUpload'])->name('sheet.delete');
    Route::put('/schulen/{onboarding}/blatt', [PresentationSheetController::class, 'update'])->name('sheet.update');
    Route::post('/schulen/{onboarding}/blatt-zuruecksetzen', [PresentationSheetController::class, 'resetRows'])->name('sheet.reset-rows');
    Route::get('/schulen/{onboarding}/blatt-bild/{slot}', [PresentationSheetController::class, 'image'])->name('sheet.image');
    Route::get('/schulen/{onboarding}/blatt-vorschau', [PresentationSheetController::class, 'preview'])->name('sheet.preview');
    Route::get('/schulen/{onboarding}/blatt.pdf', [PresentationSheetController::class, 'pdf'])->name('sheet.pdf');

    // Modul 3: Bestellfenster schließen
    Route::get('/bestellfenster-schliessen', [CloseOrderWindowController::class, 'index'])->name('close-window.index');
    Route::post('/bestellfenster-schliessen/{onboarding}', [CloseOrderWindowController::class, 'close'])->name('close-window.close');
    Route::post('/bestellfenster-oeffnen/{onboarding}', [CloseOrderWindowController::class, 'reopen'])->name('close-window.reopen');

    // Modul 4: Statistiken (Umsatzauswertung nach Schuljahr)
    Route::get('/statistiken', [StatisticsController::class, 'index'])->name('statistics.index');
    // Fortschritt des Hintergrund-Aufbaus (die Ladeseite fragt das im Takt ab)
    Route::get('/statistiken/fortschritt', [StatisticsController::class, 'progress'])->name('statistics.progress');

    // Admin-Informationen: Live-Status aller Schnittstellen
    Route::get('/admin-informationen', [AdminStatusController::class, 'index'])->name('admin.status');
    Route::post('/admin-informationen/sicherung', [AdminStatusController::class, 'backup'])->name('admin.backup');
});
