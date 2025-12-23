<?php

namespace Tests\Feature;

use App\Jobs\ProcessNbnApplication;
use App\Models\Application;
use App\Models\Customer;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessNbnApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dispatches_jobs_for_nbn_applications_with_order_status()
    {
        Queue::fake();

        $nbnPlan = Plan::factory()->create(['type' => 'nbn']);
        $mobilePlan = Plan::factory()->create(['type' => 'mobile']);
        $customer = Customer::factory()->create();

        // Create NBN application with order status (should be processed)
        $nbnOrderApp = Application::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $nbnPlan->id,
            'status' => \App\Enums\ApplicationStatus::Order,
        ]);

        // Create NBN application with different status (should NOT be processed)
        Application::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $nbnPlan->id,
            'status' => \App\Enums\ApplicationStatus::Prelim,
        ]);

        // Create mobile application with order status (should NOT be processed)
        Application::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $mobilePlan->id,
            'status' => \App\Enums\ApplicationStatus::Order,
        ]);

        $this->artisan('applications:process-nbn')
            ->assertSuccessful();

        Queue::assertPushed(ProcessNbnApplication::class, 1);
        Queue::assertPushed(ProcessNbnApplication::class, function ($job) use ($nbnOrderApp) {
            return $job->application->id === $nbnOrderApp->id;
        });
    }

    public function test_job_sends_correct_data_to_b2b_endpoint()
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'order_id' => 'ORD-123456',
            ], 200),
        ]);

        $plan = Plan::factory()->create([
            'type' => 'nbn',
            'name' => 'NBN 100',
        ]);

        $customer = Customer::factory()->create();

        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => \App\Enums\ApplicationStatus::Order,
            'address_1' => '123 Main St',
            'address_2' => 'Unit 5',
            'city' => 'Sydney',
            'state' => 'NSW',
            'postcode' => '2000',
        ]);

        $job = new ProcessNbnApplication($application);
        $job->handle();

        Http::assertSent(function ($request) use ($application, $plan) {
            $body = $request->data();
            return $body['address_1'] === $application->address_1 &&
                   $body['address_2'] === $application->address_2 &&
                   $body['city'] === $application->city &&
                   $body['state'] === $application->state &&
                   $body['postcode'] === $application->postcode &&
                   $body['plan_name'] === $plan->name;
        });
    }

    public function test_successful_order_updates_application_status_and_order_id()
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'order_id' => 'ORD-123456',
            ], 200),
        ]);

        $plan = Plan::factory()->create(['type' => 'nbn']);
        $customer = Customer::factory()->create();

        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => \App\Enums\ApplicationStatus::Order,
        ]);

        $job = new ProcessNbnApplication($application);
        $job->handle();

        $application->refresh();

        $this->assertEquals(\App\Enums\ApplicationStatus::Complete, $application->status);
        $this->assertEquals('ORD-123456', $application->order_id);
    }

    public function test_failed_order_updates_application_status_to_order_failed()
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'error' => 'Invalid address',
            ], 400),
        ]);

        $plan = Plan::factory()->create(['type' => 'nbn']);
        $customer = Customer::factory()->create();

        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => \App\Enums\ApplicationStatus::Order,
        ]);

        $job = new ProcessNbnApplication($application);
        $job->handle();

        $application->refresh();

        $this->assertEquals(\App\Enums\ApplicationStatus::OrderFailed, $application->status);
        $this->assertNull($application->order_id);
    }

    public function test_exception_during_processing_updates_status_to_order_failed()
    {
        Http::fake(function () {
            throw new \Exception('Network error');
        });

        $plan = Plan::factory()->create(['type' => 'nbn']);
        $customer = Customer::factory()->create();

        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => \App\Enums\ApplicationStatus::Order,
        ]);

        $job = new ProcessNbnApplication($application);

        try {
            $job->handle();
        } catch (\Exception $e) {
            // Expected
        }

        $application->refresh();

        $this->assertEquals(\App\Enums\ApplicationStatus::OrderFailed, $application->status);
    }

    public function test_command_handles_no_applications_gracefully()
    {
        $this->artisan('applications:process-nbn')
            ->expectsOutput('No NBN applications to process.')
            ->assertSuccessful();
    }

    public function test_multiple_applications_are_queued()
    {
        Queue::fake();

        $nbnPlan = Plan::factory()->create(['type' => 'nbn']);
        $customer = Customer::factory()->create();

        Application::factory()->count(5)->create([
            'customer_id' => $customer->id,
            'plan_id' => $nbnPlan->id,
            'status' => \App\Enums\ApplicationStatus::Order,
        ]);

        $this->artisan('applications:process-nbn')
            ->assertSuccessful();

        Queue::assertPushed(ProcessNbnApplication::class, 5);
    }
}