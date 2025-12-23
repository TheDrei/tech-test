<?php

namespace App\Jobs;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessNbnApplication implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Application $application
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $response = Http::post(env('NBN_B2B_ENDPOINT'), [
                'address_1' => $this->application->address_1,
                'address_2' => $this->application->address_2,
                'city' => $this->application->city,
                'state' => $this->application->state,
                'postcode' => $this->application->postcode,
                'plan_name' => $this->application->plan->name,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                $this->application->update([
                    'order_id' => $data['order_id'],
                    'status' => \App\Enums\ApplicationStatus::Complete,
                ]);

                Log::info("NBN application {$this->application->id} processed successfully.", [
                    'order_id' => $data['order_id'],
                ]);
            } else {
                $this->application->update([
                    'status' => \App\Enums\ApplicationStatus::OrderFailed,
                ]);

                Log::error("NBN application {$this->application->id} failed to process.", [
                    'response' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            $this->application->update([
                'status' => \App\Enums\ApplicationStatus::OrderFailed,
            ]);

            Log::error("Exception processing NBN application {$this->application->id}: {$e->getMessage()}");
            
            throw $e;
        }
    }
}