<?php

namespace App\Providers;

use App\Models\Activity;
use App\Models\CouncilMember;
use App\Models\Donation;
use App\Models\Expense;
use App\Models\Faithful;
use App\Models\Mosque;
use App\Models\MosqueCouncil;
use App\Models\Subsidy;
use App\Models\User;
use App\Models\ZakatBeneficiary;
use App\Models\ZakatCollection;
use App\Models\ZakatDistribution;
use App\Models\WaqfAsset;
use App\Models\WaqfExpense;
use App\Models\WaqfRevenue;
use App\Observers\ActivityObserver;
use App\Observers\CouncilMemberObserver;
use App\Observers\DonationObserver;
use App\Observers\FinanceObserver;
use App\Observers\FaithfulObserver;
use App\Observers\MosqueCouncilObserver;
use App\Observers\MosqueObserver;
use App\Observers\UserObserver;
use App\Observers\ZakatObserver;
use App\Observers\WaqfObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}
    public function boot(): void
    {
        Activity::observe(ActivityObserver::class);
        CouncilMember::observe(CouncilMemberObserver::class);
        Donation::observe(DonationObserver::class);
        Expense::observe(FinanceObserver::class);
        Faithful::observe(FaithfulObserver::class);
        Mosque::observe(MosqueObserver::class);
        MosqueCouncil::observe(MosqueCouncilObserver::class);
        Subsidy::observe(FinanceObserver::class);
        User::observe(UserObserver::class);
        ZakatBeneficiary::observe(ZakatObserver::class);
        ZakatCollection::observe(ZakatObserver::class);
        ZakatDistribution::observe(ZakatObserver::class);
        WaqfAsset::observe(WaqfObserver::class);
        WaqfExpense::observe(WaqfObserver::class);
        WaqfRevenue::observe(WaqfObserver::class);
    }
}
