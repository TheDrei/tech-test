<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ApplicationController extends Controller
{
    /**
     * List all applications with optional plan type filter
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'plan_type' => 'nullable|in:nbn,opticomm,mobile',
        ]);

        $query = Application::with(['customer', 'plan'])
            ->orderBy('created_at', 'asc');

        // Apply plan type filter if provided
        if ($request->filled('plan_type')) {
            $query->whereHas('plan', function ($q) use ($request) {
                $q->where('type', $request->plan_type);
            });
        }

        $applications = $query->paginate(15);

        return response()->json([
            'data' => $applications->map(function ($application) {
                $data = [
                    'id' => $application->id,
                    'customer_name' => $application->customer->full_name,
                    'address' => $application->full_address,
                    'plan_type' => $application->plan->type,
                    'plan_name' => $application->plan->name,
                    'state' => $application->state,
                    'plan_monthly_cost' => $this->formatCurrency($application->plan->monthly_cost),
                ];

                // Only include order_id for complete applications
                if ($application->status->value === 'complete') {
                    $data['order_id'] = $application->order_id;
                }

                return $data;
            }),
            'links' => [
                'first' => $applications->url(1),
                'last' => $applications->url($applications->lastPage()),
                'prev' => $applications->previousPageUrl(),
                'next' => $applications->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $applications->currentPage(),
                'from' => $applications->firstItem(),
                'last_page' => $applications->lastPage(),
                'per_page' => $applications->perPage(),
                'to' => $applications->lastItem(),
                'total' => $applications->total(),
            ],
        ]);
    }

    /**
     * Format cents to dollar format
     *
     * @param int $cents
     * @return string
     */
    private function formatCurrency(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }
}