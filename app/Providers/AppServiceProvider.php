<?php

namespace App\Providers;

use App\Models\CashBookCategory;
use App\Models\CashBookEntry;
use App\Observers\CashBookCategoryObserver;
use App\Observers\CashBookEntryObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::before(function ($user, $ability) {
            return $user->hasRole('super_admin') ? true : null;
        });

        CashBookEntry::observe(CashBookEntryObserver::class);
        CashBookCategory::observe(CashBookCategoryObserver::class);
    }
}
