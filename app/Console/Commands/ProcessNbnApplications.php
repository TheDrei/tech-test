<?php

namespace App\Console\Commands;

use App\Jobs\ProcessNbnApplication;
use App\Models\Application;
use Illuminate\Console\Command;

class ProcessNbnApplications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'applications:process-nbn';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process all NBN applications with order status';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $applications = Application::whereHas('plan', function ($query) {
            $query->where('type', 'nbn');
        })
        ->where('status', 'order')
        ->get();

        $count = $applications->count();

        if ($count === 0) {
            $this->info('No NBN applications to process.');
            return Command::SUCCESS;
        }

        foreach ($applications as $application) {
            ProcessNbnApplication::dispatch($application);
        }

        $this->info("Dispatched {$count} NBN application(s) for processing.");

        return Command::SUCCESS;
    }
}