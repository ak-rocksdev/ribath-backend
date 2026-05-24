<?php

namespace App\Providers;

use App\Models\CashBookCategory;
use App\Models\CashBookEntry;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Observers\CashBookCategoryObserver;
use App\Observers\CashBookEntryObserver;
use App\Observers\FeeScheduleObserver;
use App\Observers\FeeTypeObserver;
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

        FeeType::observe(FeeTypeObserver::class);
        FeeSchedule::observe(FeeScheduleObserver::class);
    }
}
