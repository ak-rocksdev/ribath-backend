<?php

namespace App\Console\Commands;

use App\Models\FeeActivityLog;
use App\Services\Keuangan\BillGeneratorService;
use Illuminate\Console\Command;

class GenerateMonthlyBills extends Command
{
    protected $signature = 'fee:generate-bills {--period=} {--cadence=all} {--actor=console}';

    protected $description = 'Generate bills for the given period (YYYY-MM, defaults to current Asia/Jakarta month). Idempotent.';

    public function handle(BillGeneratorService $generator): int
    {
        $period = $this->option('period') ?: null;
        $cadence = $this->option('cadence') ?: 'all';
        $actor = $this->option('actor') === 'scheduler'
            ? FeeActivityLog::ACTOR_SCHEDULER
            : FeeActivityLog::ACTOR_CONSOLE;

        $stats = $generator->generate($period, $cadence, $actor);

        $this->info('Bill generator finished.');
        foreach ($stats as $k => $v) {
            $this->line(sprintf('  %-25s : %s', $k, is_scalar($v) ? $v : json_encode($v)));
        }

        return self::SUCCESS;
    }
}
