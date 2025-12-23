<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_unauthenticated_users_cannot_access_applications()
    {
        $response = $this->getJson('/api/applications');

        $response->assertStatus(403);
    }

    public function test_can_list_all_applications()
    {
        $plan = Plan::factory()->create([
            'type' => 'nbn',
            'name' => 'NBN 50',
            'monthly_cost' => 5999, // $59.99
        ]);

        $customer = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => \App\Enums\ApplicationStatus::Prelim,
            'address_1' => '123 Main St',
            'address_2' => 'Unit 1',
            'city' => 'Melbourne',
            'state' => 'VIC',
            'postcode' => '3000',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/applications');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'customer_name',
                        'address',
                        'plan_type',
                        'plan_name',
                        'state',
                        'plan_monthly_cost',
                    ],
                ],
                'links',
                'meta',
            ])
            ->assertJsonFragment([
                'id' => $application->id,
                'customer_name' => 'John Doe',
                'plan_type' => 'nbn',
                'plan_name' => 'NBN 50',
                'state' => 'VIC',
                'plan_monthly_cost' => '$59.99',
            ]);
    }

    public function test_applications_are_ordered_by_oldest_first()
    {
        $plan = Plan::factory()->create();
        $customer = Customer::factory()->create();

        $oldestApp = Application::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'created_at' => now()->subDays(3),
        ]);

        $newestApp = Application::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'created_at' => now()->subDays(1),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/applications');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertEquals($oldestApp->id, $data[0]['id']);
        $this->assertEquals($newestApp->id, $data[1]['id']);
    }

    public function test_can_filter_applications_by_plan_type()
    {
        $nbnPlan = Plan::factory()->create(['type' => 'nbn']);
        $mobilePlan = Plan::factory()->create(['type' => 'mobile']);
        $customer = Customer::factory()->create();

        Application::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $nbnPlan->id,
        ]);

        Application::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $mobilePlan->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/applications?plan_type=nbn');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('nbn', $data[0]['plan_type']);
    }

    public function test_order_id_only_shown_for_complete_applications()
    {
        $plan = Plan::factory()->create();
        $customer = Customer::factory()->create();

        $completeApp = Application::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => \App\Enums\ApplicationStatus::Complete,
            'order_id' => 'ORD-12345',
        ]);

        $prelimApp = Application::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => \App\Enums\ApplicationStatus::Prelim,
            'order_id' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/applications');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        
        // Find the complete application
        $completeData = collect($data)->firstWhere('id', $completeApp->id);
        $this->assertArrayHasKey('order_id', $completeData);
        $this->assertEquals('ORD-12345', $completeData['order_id']);
        
        // Find the prelim application
        $prelimData = collect($data)->firstWhere('id', $prelimApp->id);
        $this->assertArrayNotHasKey('order_id', $prelimData);
    }

    public function test_monthly_cost_is_formatted_correctly()
    {
        $plan = Plan::factory()->create(['monthly_cost' => 7950]); // $79.50
        $customer = Customer::factory()->create();

        Application::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/applications');

        $response->assertStatus(200)
            ->assertJsonFragment(['plan_monthly_cost' => '$79.50']);
    }

    public function test_applications_are_paginated()
    {
        $plan = Plan::factory()->create();
        $customer = Customer::factory()->create();

        // Create 20 applications
        Application::factory()->count(20)->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/applications');

        $response->assertStatus(200)
            ->assertJsonStructure(['meta' => ['total', 'per_page', 'current_page']]);
        
        $meta = $response->json('meta');
        $this->assertEquals(20, $meta['total']);
        $this->assertEquals(15, $meta['per_page']);
    }

    public function test_invalid_plan_type_returns_validation_error()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/applications?plan_type=invalid');

        $response->assertStatus(422)
            ->assertJsonValidationErrors('plan_type');
    }
}