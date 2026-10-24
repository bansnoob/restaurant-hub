<?php

namespace App\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The branch a branch admin is locked to, or null. LockToOwnBranch sets it on
        // every website request; this default covers the pages outside that group.
        View::share('lockedBranch', null);
    }
}
