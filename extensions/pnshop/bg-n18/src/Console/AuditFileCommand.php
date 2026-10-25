<?php

namespace PnShop\Plugins\BgN18\Console;

use Illuminate\Console\Command;
use PnShop\Plugins\BgN18\AuditFile;

class AuditFileCommand extends Command
{
    protected $signature = 'pnshop:n18-audit-file {month : The month, as YYYY-MM} {--output= : Write to this file instead of the screen}';

    protected $description = 'Write the monthly NRA audit file of the e-shop (Наредба Н-18, Приложение 38).';

    public function handle(AuditFile $file): int
    {
        $month = $this->argument('month');

        if (! is_string($month) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $match) !== 1) {
            $this->components->error('Give the month as YYYY-MM, e.g. 2026-10.');

            return self::INVALID;
        }

        $xml = $file->xml((int) $match[1], (int) $match[2]);
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            file_put_contents($output, $xml);
            $this->components->info("Written to {$output}.");
        } else {
            $this->output->write($xml);
        }

        return self::SUCCESS;
    }
}
