<?php

use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\WebhookEventController as AdminWebhookEventController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome', [
    'canLogin' => Route::has('login'),
    'canRegister' => Route::has('register'),
]));

// Pixelforge -> us. Public, CSRF-exempt, authenticated by HMAC signature.
Route::post('/webhooks/pixelforge', WebhookController::class)->middleware('throttle:webhook')->name('webhooks.pixelforge');

// Scheduler trigger for hosts without a shell (disabled unless CRON_TOKEN is set).
Route::get('/cron/{token}', CronController::class)->middleware('throttle:cron')->name('cron');

Route::middleware('auth')->group(function () {
    Route::redirect('/dashboard', '/generate')->name('dashboard');

    Route::get('/generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('/generate', [GenerationController::class, 'store'])->middleware('throttle:generate')->name('generations.store');
    Route::get('/history', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('/generations/{generation}/download', [GenerationController::class, 'download'])->name('generations.download');
    Route::delete('/generations/{generation}', [GenerationController::class, 'destroy'])->middleware('throttle:actions')->name('generations.destroy');

    Route::get('/credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('/credits', [CreditController::class, 'purchase'])->middleware('throttle:actions')->name('credits.purchase');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('throttle:actions')->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->middleware('throttle:sensitive')->name('profile.destroy');

    // Operator console (admins only).
    Route::middleware(EnsureAdmin::class)->prefix('admin')->name('admin.')->group(function () {
        Route::redirect('/', '/admin/report');
        Route::get('/report', [AdminReportController::class, 'index'])->name('report');
        Route::get('/report/export', [AdminReportController::class, 'export'])->name('report.export');
        Route::get('/users', [AdminUserController::class, 'index'])->name('users');
        Route::post('/users/{user}/credits', [AdminUserController::class, 'adjust'])->middleware('throttle:actions')->name('users.credits');
        Route::get('/webhooks', [AdminWebhookEventController::class, 'index'])->name('webhooks');
    });
});

require __DIR__.'/auth.php';
