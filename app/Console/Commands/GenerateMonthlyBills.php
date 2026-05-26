<?php

namespace App\Console\Commands;

use App\Models\FeeActivityLog;
use App\Services\Keuangan\BillGeneratorService;
use Illuminate\Console\Command;

class GenerateMonthlyBills extends Command
{
    protected $signature = 'fee:generate-bills {--period=} {--cadence=all}';

    protected $description = 'Generate bills for the given period (YYYY-MM, defaults to current Asia/Jakarta month). Idempotent.';

    public function handle(BillGeneratorService $generator): int
    {
        $period = $this->option('period') ?: null;
        $cadence = $this->option('cadence') ?: 'all';

        // Console actor (not authenticated user) — generator writes audit log
        // with actor_kind=console so the run is traceable but not attributed
        // to any user.
        $stats = $generator->generate(
            $period,
            $cadence,
            FeeActivityLog::ACTOR_CONSOLE,
        );

        $this->info('Bill generator finished.');
        foreach ($stats as $k => $v) {
            $this->line(sprintf('  %-25s : %s', $k, is_scalar($v) ? $v : json_encode($v)));
        }

        return self::SUCCESS;
    }
}
