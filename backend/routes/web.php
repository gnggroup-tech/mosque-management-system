<?php

use App\Http\Controllers\Admin\AccountApprovalController;
use App\Http\Controllers\Admin\AccountDirectoryController;
use App\Http\Controllers\Admin\AccountStatusController;
use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CouncilMeetingController;
use App\Http\Controllers\Admin\CouncilMemberController;
use App\Http\Controllers\Admin\DonationController;
use App\Http\Controllers\Admin\FaithfulController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\MosqueController;
use App\Http\Controllers\Admin\MosqueCouncilController;
use App\Http\Controllers\Admin\ReportExportController;
use App\Http\Controllers\Admin\UserInvitationController;
use App\Http\Controllers\Admin\WaqfController;
use App\Http\Controllers\Admin\ZakatController;
use App\Http\Controllers\Auth\InvitationAcceptanceController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));
Route::post('/locale', LocaleController::class)->middleware('throttle:20,1')->name('locale.update');
Route::middleware(['guest', 'throttle:6,1', 'invitation.locale'])->group(function (): void {
    Route::get('/invitations/{token}', [InvitationAcceptanceController::class, 'show'])->name('invitations.show');
    Route::patch('/invitations/{token}', [InvitationAcceptanceController::class, 'update'])->name('invitations.update');
});
Route::get('/dashboard', fn () => view('dashboard'))->middleware(['auth', 'account.active', 'verified'])->name('dashboard');

Route::middleware(['auth', 'account.active'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/admin/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('admin.audit-logs.index');

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/accounts/invitations/create', [UserInvitationController::class, 'create'])
            ->middleware('permission:users.invite')
            ->name('accounts.invitations.create');
        Route::post('/accounts/invitations', [UserInvitationController::class, 'store'])
            ->middleware(['permission:users.invite', 'throttle:6,1'])
            ->name('accounts.invitations.store');
        Route::post('/accounts/{account}/invitation/resend', [UserInvitationController::class, 'resend'])
            ->middleware(['permission:users.invite', 'throttle:6,1'])
            ->name('accounts.invitations.resend');
        Route::get('/accounts', [AccountDirectoryController::class, 'index'])
            ->middleware('permission:users.directory.view')
            ->name('accounts.index');
        Route::get('/accounts/{account}', [AccountDirectoryController::class, 'show'])
            ->middleware('permission:users.directory.view')
            ->name('accounts.show');
        Route::patch('/accounts/{account}/approve', AccountApprovalController::class)
            ->middleware('permission:users.approve')
            ->name('accounts.approve');
        Route::patch('/accounts/{account}/suspend', [AccountStatusController::class, 'suspend'])
            ->middleware('permission:users.suspend')
            ->name('accounts.suspend');
        Route::patch('/accounts/{account}/reactivate', [AccountStatusController::class, 'reactivate'])
            ->middleware('permission:users.reactivate')
            ->name('accounts.reactivate');
        Route::patch('/accounts/{account}/archive', [AccountStatusController::class, 'archive'])
            ->middleware('permission:users.archive')
            ->name('accounts.archive');
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
        Route::get('/council-meetings', [CouncilMeetingController::class, 'index'])->middleware('permission:council-meetings.view')->name('council-meetings.index');
        Route::post('/council-meetings', [CouncilMeetingController::class, 'store'])->middleware('permission:council-meetings.manage')->name('council-meetings.store');
        Route::get('/council-meetings/{meeting}', [CouncilMeetingController::class, 'show'])->middleware('permission:council-meetings.view')->name('council-meetings.show');
        Route::post('/council-meetings/{meeting}/send-notice', [CouncilMeetingController::class, 'sendNotice'])->middleware('permission:council-meetings.manage')->name('council-meetings.send-notice');
        Route::post('/council-meetings/{meeting}/attendance', [CouncilMeetingController::class, 'recordAttendance'])->middleware('permission:council-meetings.manage')->name('council-meetings.attendance');
        Route::post('/council-meetings/{meeting}/close', [CouncilMeetingController::class, 'close'])->middleware('permission:council-meetings.manage')->name('council-meetings.close');
        Route::post('/council-meetings/{meeting}/decisions', [CouncilMeetingController::class, 'addDecision'])->middleware('permission:council-meetings.manage')->name('council-meetings.decisions.store');
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
        Route::get('/zakat/collections', [ZakatController::class, 'index'])->middleware('permission:zakat.view')->name('zakat.collections.index');
        Route::post('/zakat/collections', [ZakatController::class, 'storeCollection'])->middleware('permission:zakat.manage')->name('zakat.collections.store');
        Route::post('/zakat/collections/{collection}/validate', [ZakatController::class, 'validateCollection'])->middleware('permission:zakat.manage')->name('zakat.collections.validate');
        Route::post('/zakat/beneficiaries', [ZakatController::class, 'storeBeneficiary'])->middleware('permission:zakat.manage')->name('zakat.beneficiaries.store');
        Route::post('/zakat/distributions', [ZakatController::class, 'storeDistribution'])->middleware('permission:zakat.manage')->name('zakat.distributions.store');
        Route::post('/zakat/distributions/{distribution}/validate', [ZakatController::class, 'validateDistribution'])->middleware('permission:zakat.manage')->name('zakat.distributions.validate');
        Route::get('/waqf/assets', [WaqfController::class, 'index'])->middleware('permission:waqf.view')->name('waqf.assets.index');
        Route::post('/waqf/assets', [WaqfController::class, 'storeAsset'])->middleware('permission:waqf.manage')->name('waqf.assets.store');
        Route::post('/waqf/revenues', [WaqfController::class, 'storeRevenue'])->middleware('permission:waqf.manage')->name('waqf.revenues.store');
        Route::post('/waqf/revenues/{revenue}/validate', [WaqfController::class, 'validateRevenue'])->middleware('permission:waqf.manage')->name('waqf.revenues.validate');
        Route::post('/waqf/expenses', [WaqfController::class, 'storeExpense'])->middleware('permission:waqf.manage')->name('waqf.expenses.store');
        Route::patch('/waqf/expenses/{expense}', [WaqfController::class, 'updateExpense'])->middleware('permission:waqf.manage')->name('waqf.expenses.update');
        Route::post('/waqf/expenses/{expense}/validate', [WaqfController::class, 'validateExpense'])->middleware('permission:waqf.manage')->name('waqf.expenses.validate');
        Route::get('/activities', [ActivityController::class, 'index'])->middleware('permission:activities.view')->name('activities.index');
        Route::post('/activities', [ActivityController::class, 'store'])->middleware('permission:activities.manage')->name('activities.store');
        Route::get('/activities/{activity}', [ActivityController::class, 'show'])->middleware('permission:activities.view')->name('activities.show');
        Route::patch('/activities/{activity}', [ActivityController::class, 'update'])->middleware('permission:activities.manage')->name('activities.update');
        Route::post('/activities/{activity}/publish', [ActivityController::class, 'publish'])->middleware('permission:activities.manage')->name('activities.publish');
        Route::post('/activities/{activity}/cancel', [ActivityController::class, 'cancel'])->middleware('permission:activities.manage')->name('activities.cancel');
        Route::post('/activities/{activity}/register', [ActivityController::class, 'register'])->middleware('permission:activities.view')->name('activities.register');
        Route::delete('/activities/{activity}/register', [ActivityController::class, 'unregister'])->middleware('permission:activities.view')->name('activities.unregister');
        Route::delete('/activities/{activity}', [ActivityController::class, 'destroy'])->middleware('permission:activities.manage')->name('activities.destroy');
        Route::get('/announcements', [AnnouncementController::class, 'index'])->middleware('permission:announcements.view')->name('announcements.index');
        Route::post('/announcements', [AnnouncementController::class, 'store'])->middleware('permission:announcements.manage')->name('announcements.store');
        Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show'])->middleware('permission:announcements.view')->name('announcements.show');
        Route::patch('/announcements/{announcement}', [AnnouncementController::class, 'update'])->middleware('permission:announcements.manage')->name('announcements.update');
        Route::post('/announcements/{announcement}/publish', [AnnouncementController::class, 'publish'])->middleware('permission:announcements.manage')->name('announcements.publish');
        Route::post('/announcements/{announcement}/read', [AnnouncementController::class, 'markRead'])->middleware('permission:announcements.view')->name('announcements.read');
        Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->middleware('permission:announcements.manage')->name('announcements.destroy');
        Route::get('/finances/report', [FinanceController::class, 'report'])->middleware('permission:finances.view')->name('finances.report');
        Route::get('/reports', [ReportExportController::class, 'index'])->middleware('permission:reports.view')->name('reports.index');
        Route::get('/reports/export', [ReportExportController::class, 'export'])->middleware('permission:reports.view')->name('reports.export');
        Route::post('/finances/subsidies', [FinanceController::class, 'storeSubsidy'])->middleware('permission:finances.manage')->name('finances.subsidies.store');
        Route::post('/finances/subsidies/{subsidy}/validate', [FinanceController::class, 'validateSubsidy'])->middleware('permission:finances.manage')->name('finances.subsidies.validate');
        Route::post('/finances/expenses', [FinanceController::class, 'storeExpense'])->middleware('permission:finances.manage')->name('finances.expenses.store');
        Route::post('/finances/expenses/{expense}/validate', [FinanceController::class, 'validateExpense'])->middleware('permission:finances.manage')->name('finances.expenses.validate');
    });
});
require __DIR__.'/auth.php';
