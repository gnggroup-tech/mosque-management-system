<?php

namespace App\Providers;

use App\Models\CouncilMember;
use App\Models\Donation;
use App\Models\Faithful;
use App\Models\Mosque;
use App\Models\MosqueCouncil;
use App\Models\User;
use App\Observers\CouncilMemberObserver;
use App\Observers\DonationObserver;
use App\Observers\FaithfulObserver;
use App\Observers\MosqueCouncilObserver;
use App\Observers\MosqueObserver;
use App\Observers\UserObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}
    public function boot(): void
    {
        CouncilMember::observe(CouncilMemberObserver::class);
        Donation::observe(DonationObserver::class);
        Faithful::observe(FaithfulObserver::class);
        Mosque::observe(MosqueObserver::class);
        MosqueCouncil::observe(MosqueCouncilObserver::class);
        User::observe(UserObserver::class);
    }
}
