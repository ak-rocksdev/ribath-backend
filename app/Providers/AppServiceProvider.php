<?php

namespace App\Providers;

use App\Models\CashBookCategory;
use App\Models\CashBookEntry;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\RegistrationPeriodFeeOverride;
use App\Observers\CashBookCategoryObserver;
use App\Observers\CashBookEntryObserver;
use App\Observers\FeeScheduleObserver;
use App\Observers\FeeTypeObserver;
use App\Observers\RegistrationPeriodFeeOverrideObserver;
use Illuminate\Support\Facades\DB;
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

        RegistrationPeriodFeeOverride::observe(RegistrationPeriodFeeOverrideObserver::class);

        $this->protectAgainstDestructiveDbCommands();
    }

    /**
     * Block destructive DB commands (db:wipe, migrate:fresh, migrate:refresh,
     * migrate:reset) at the framework level so a stray Enter never nukes the
     * local dev database again.
     *
     * Override for an intentional reset:
     *
     *     # PowerShell / Bash
     *     DB_ALLOW_DESTRUCTIVE=true php artisan migrate:fresh --force
     *
     * Or flip DB_ALLOW_DESTRUCTIVE=true in .env (and back to false after).
     *
     * Tests are exempt — they boot through phpunit which uses the
     * RefreshDatabase trait that needs migrate:fresh internally.
     */
    private function protectAgainstDestructiveDbCommands(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        $allow = filter_var(env('DB_ALLOW_DESTRUCTIVE', false), FILTER_VALIDATE_BOOLEAN);

        DB::prohibitDestructiveCommands(! $allow);
    }
}
