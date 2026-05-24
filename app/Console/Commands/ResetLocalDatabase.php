<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Safer alternative to `php artisan migrate:fresh`.
 *
 * Forces interactive confirmation steps before wiping the local database:
 *   1. Refuses to run in any non-local environment.
 *   2. Prints loud warning + current DB connection + name.
 *   3. Asks for explicit "yes" confirmation.
 *   4. Asks user to retype the database name exactly.
 *   5. Re-enables the destructive-commands prohibition after running so a
 *      subsequent stray `migrate:fresh` still fails.
 *
 * Typical use: developer running `php artisan db:reset-local --seed` to
 * start fresh with the standard seed set.
 */
class ResetLocalDatabase extends Command
{
    protected $signature = 'db:reset-local
        {--seed : Run the default DatabaseSeeder after migrating}';

    protected $description = 'Safely wipe + re-migrate the local database with multi-step confirmation (replaces `migrate:fresh` for everyday dev resets)';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Refused: db:reset-local only runs when APP_ENV=local.');
            $this->line('Current APP_ENV: '.app()->environment());

            return self::FAILURE;
        }

        $connection = Config::get('database.default');
        $database = Config::get("database.connections.{$connection}.database");

        $this->newLine();
        $this->components->error('DESTRUCTIVE OPERATION — this will WIPE the local database.');
        $this->newLine();
        $this->components->bulletList([
            "Connection : <fg=yellow>{$connection}</>",
            "Database   : <fg=yellow>{$database}</>",
            'Action     : DROP all tables, run all migrations from scratch',
            'Reversible : <fg=red>NO</> — all rows in every table will be lost',
        ]);
        $this->newLine();

        if (! $this->confirm('Are you absolutely sure you want to wipe this database?', false)) {
            $this->info('Cancelled. Database is untouched.');

            return self::SUCCESS;
        }

        $typed = $this->ask("Type the database name exactly to confirm (\"{$database}\")");

        if ($typed !== $database) {
            $this->error('Database name did not match. Cancelled. Database is untouched.');

            return self::FAILURE;
        }

        // Temporarily lift the destructive-commands guard so the underlying
        // migrate:fresh call can run, then re-lock it after.
        DB::prohibitDestructiveCommands(false);

        try {
            $this->info('Running migrate:fresh ...');
            $migrateExit = Artisan::call('migrate:fresh', ['--force' => true], $this->getOutput());

            if ($migrateExit !== self::SUCCESS) {
                return $migrateExit;
            }

            if ($this->option('seed')) {
                $this->info('Running db:seed ...');
                Artisan::call('db:seed', ['--force' => true], $this->getOutput());
            }
        } finally {
            DB::prohibitDestructiveCommands(true);
        }

        $this->newLine();
        $this->components->info('Local database reset complete.');

        return self::SUCCESS;
    }
}
