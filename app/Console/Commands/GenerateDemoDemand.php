<?php

namespace App\Console\Commands;

use App\Services\Analytics\DemoDemandGenerator;
use Illuminate\Console\Command;

class GenerateDemoDemand extends Command
{
    protected $signature = 'demo:generate-demand
                            {--count=600 : Number of synthetic requests}
                            {--months=6 : How far back they are spread}
                            {--seed=42 : Random seed (same seed, same data)}
                            {--purge : Delete all synthetic requests and stop}';

    protected $description = 'Create (or remove) SYNTHETIC blood requests flagged is_demo, for the analytics dashboard';

    public function handle(DemoDemandGenerator $generator): int
    {
        if ($this->option('purge')) {
            $this->info('Deleted '.$generator->purge().' synthetic requests. Real data was not touched.');

            return self::SUCCESS;
        }

        if (app()->environment('production') && ! $this->confirm('This is production. Create synthetic requests anyway?')) {
            return self::FAILURE;
        }

        $generator->purge();
        $made = $generator->generate((int) $this->option('count'), (int) $this->option('months'), (int) $this->option('seed'));

        $this->info("Created {$made['requests']} synthetic requests, {$made['responses']} fulfilments, {$made['logs']} donor response logs.");
        $this->warn('All of it is made up (is_demo = true). Remove with: php artisan demo:generate-demand --purge');

        return self::SUCCESS;
    }
}
