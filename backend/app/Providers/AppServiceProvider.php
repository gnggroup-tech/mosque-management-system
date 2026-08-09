<?php

namespace App\Providers;

use App\Models\Mosque;
use App\Models\User;
use App\Observers\MosqueObserver;
use App\Observers\UserObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Mosque::observe(MosqueObserver::class);
        User::observe(UserObserver::class);
    }
}
