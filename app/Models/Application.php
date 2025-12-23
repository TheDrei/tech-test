<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Events\ApplicationCreated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Application extends Model
{
    use HasFactory;

    // ADD THIS: Allow mass assignment for these fields
    protected $fillable = [
        'customer_id',
        'plan_id',
        'status',
        'order_id',
        'address_1',
        'address_2',
        'city',
        'state',
        'postcode',
    ];

    protected $casts = [
        'status' => ApplicationStatus::class,
    ];

    protected $dispatchesEvents = [
        'created' => ApplicationCreated::class,
    ];

    // ADD THIS: Relationship to Customer
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    // ADD THIS: Relationship to Plan
    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    // KEEP THIS: Already exists
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_1,
            $this->address_2,
            $this->city,
            $this->state,
            $this->postcode,
        ]);

        return implode(', ', $parts);
    }
}