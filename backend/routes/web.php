<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CouncilMemberController;
use App\Http\Controllers\Admin\DonationController;
use App\Http\Controllers\Admin\FaithfulController;
use App\Http\Controllers\Admin\MosqueController;
use App\Http\Controllers\Admin\MosqueCouncilController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));
Route::get('/dashboard', fn () => view('dashboard'))->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/admin/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('admin.audit-logs.index');

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/mosques', [MosqueController::class, 'index'])->middleware('permission:mosques.view')->name('mosques.index');
        Route::post('/mosques', [MosqueController::class, 'store'])->middleware('permission:mosques.create')->name('mosques.store');
        Route::get('/mosques/{mosque}', [MosqueController::class, 'show'])->middleware('permission:mosques.view')->name('mosques.show');
        Route::patch('/mosques/{mosque}', [MosqueController::class, 'update'])->middleware('permission:mosques.update')->name('mosques.update');
        Route::delete('/mosques/{mosque}', [MosqueController::class, 'destroy'])->middleware('permission:mosques.delete')->name('mosques.destroy');
        Route::get('/councils', [MosqueCouncilController::class, 'index'])->middleware('permission:councils.view')->name('councils.index');
        Route::post('/councils', [MosqueCouncilController::class, 'store'])->middleware('permission:councils.create')->name('councils.store');
        Route::get('/councils/{council}', [MosqueCouncilController::class, 'show'])->middleware('permission:councils.view')->name('councils.show');
        Route::patch('/councils/{council}', [MosqueCouncilController::class, 'update'])->middleware('permission:councils.update')->name('councils.update');
        Route::delete('/councils/{council}', [MosqueCouncilController::class, 'destroy'])->middleware('permission:councils.delete')->name('councils.destroy');
        Route::get('/council-members', [CouncilMemberController::class, 'index'])->middleware('permission:council-members.view')->name('council-members.index');
        Route::post('/council-members', [CouncilMemberController::class, 'store'])->middleware('permission:council-members.create')->name('council-members.store');
        Route::get('/council-members/{member}', [CouncilMemberController::class, 'show'])->middleware('permission:council-members.view')->name('council-members.show');
        Route::patch('/council-members/{member}', [CouncilMemberController::class, 'update'])->middleware('permission:council-members.update')->name('council-members.update');
        Route::delete('/council-members/{member}', [CouncilMemberController::class, 'destroy'])->middleware('permission:council-members.delete')->name('council-members.destroy');
        Route::get('/faithful', [FaithfulController::class, 'index'])->middleware('permission:faithful.view')->name('faithful.index');
        Route::post('/faithful', [FaithfulController::class, 'store'])->middleware('permission:faithful.manage')->name('faithful.store');
        Route::get('/faithful/{faithful}', [FaithfulController::class, 'show'])->middleware('permission:faithful.view')->name('faithful.show');
        Route::patch('/faithful/{faithful}', [FaithfulController::class, 'update'])->middleware('permission:faithful.manage')->name('faithful.update');
        Route::delete('/faithful/{faithful}', [FaithfulController::class, 'destroy'])->middleware('permission:faithful.manage')->name('faithful.destroy');
        Route::get('/donations', [DonationController::class, 'index'])->middleware('permission:contributions.view')->name('donations.index');
        Route::post('/donations', [DonationController::class, 'store'])->middleware('permission:contributions.manage')->name('donations.store');
        Route::get('/donations/{donation}', [DonationController::class, 'show'])->middleware('permission:contributions.view')->name('donations.show');
        Route::patch('/donations/{donation}', [DonationController::class, 'update'])->middleware('permission:contributions.manage')->name('donations.update');
        Route::post('/donations/{donation}/validate', [DonationController::class, 'validateDonation'])->middleware('permission:contributions.manage')->name('donations.validate');
        Route::post('/donations/{donation}/reject', [DonationController::class, 'reject'])->middleware('permission:contributions.manage')->name('donations.reject');
        Route::delete('/donations/{donation}', [DonationController::class, 'destroy'])->middleware('permission:contributions.manage')->name('donations.destroy');
    });
});
require __DIR__.'/auth.php';
