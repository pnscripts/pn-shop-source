<?php

namespace PnShop\Plugins\BgEuro\Console;

use Illuminate\Console\Command;
use PnShop\Plugins\BgEuro\EuroCheck;

class EuroCheckCommand extends Command
{
    protected $signature = 'pnshop:euro-check';

    protected $description = 'Check that the shop sells in euro only (Bulgaria, after the euro changeover).';

    public function handle(EuroCheck $check): int
    {
        foreach ($check->results() as $result) {
            $result['ok'] ? $this->components->info($result['message']) : $this->components->error($result['message']);
        }

        return $check->passes() ? self::SUCCESS : self::FAILURE;
    }
}
