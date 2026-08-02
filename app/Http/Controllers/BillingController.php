<?php

namespace App\Http\Controllers;

use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function index(Request $request, TenantContext $tenant): View
    {
        $organization = $tenant->organization($request->user());
        $plans = [
            'monthly' => ['label' => 'Monthly', 'price' => '$30', 'period' => 'every month', 'saving' => null],
            'six_months' => ['label' => '6 months', 'price' => '$162', 'period' => 'every 6 months', 'saving' => 'Save $18'],
            'yearly' => ['label' => 'Annual', 'price' => '$288', 'period' => 'every year', 'saving' => 'Save $72'],
        ];
        foreach ($plans as $key => &$plan) {
            $plan['price_id'] = config("paddle.prices.{$key}");
        }

        return view('billing.index', [
            'organization' => $organization,
            'subscription' => $organization->currentSubscription(),
            'plans' => $plans,
            'paddleEnvironment' => config('paddle.environment'),
            'paddleClientToken' => config('paddle.client_token'),
            'billingReference' => Crypt::encryptString((string) $organization->id),
        ]);
    }
}
