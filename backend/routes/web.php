<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\MosqueController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/admin/audit-logs', [AuditLogController::class, 'index'])
        ->middleware('permission:audit.view')
        ->name('admin.audit-logs.index');

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/mosques', [MosqueController::class, 'index'])
            ->middleware('permission:mosques.view')->name('mosques.index');
        Route::post('/mosques', [MosqueController::class, 'store'])
            ->middleware('permission:mosques.create')->name('mosques.store');
        Route::get('/mosques/{mosque}', [MosqueController::class, 'show'])
            ->middleware('permission:mosques.view')->name('mosques.show');
        Route::patch('/mosques/{mosque}', [MosqueController::class, 'update'])
            ->middleware('permission:mosques.update')->name('mosques.update');
        Route::delete('/mosques/{mosque}', [MosqueController::class, 'destroy'])
            ->middleware('permission:mosques.delete')->name('mosques.destroy');
    });
});

require __DIR__.'/auth.php';
