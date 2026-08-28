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
            'monthly' => ['label' => 'Monthly', 'price' => '$30', 'social_price' => '$60', 'period' => 'every month', 'social_period' => 'Chat + Social · every month', 'saving' => null, 'social_saving' => null, 'addon' => '+$30/month'],
            'six_months' => ['label' => '6 months', 'price' => '$162', 'social_price' => '$324', 'period' => 'every 6 months', 'social_period' => 'Chat + Social · every 6 months', 'saving' => 'Save $18', 'social_saving' => 'Save $36', 'addon' => '+$162/6 months'],
            'yearly' => ['label' => 'Annual', 'price' => '$288', 'social_price' => '$576', 'period' => 'every year', 'social_period' => 'Chat + Social · every year', 'saving' => 'Save $72', 'social_saving' => 'Save $144', 'addon' => '+$288/year'],
        ];
        foreach ($plans as $key => &$plan) {
            $plan['price_id'] = config("paddle.prices.{$key}");
            $plan['social_price_id'] = config("paddle.social_prices.{$key}");
        }

        return view('billing.index', [
            'organization' => $organization,
            'subscription' => $organization->currentSubscription(),
            'plans' => $plans,
            'paddleEnvironment' => config('paddle.environment'),
            'paddleClientToken' => config('paddle.client_token'),
            'billingReference' => Crypt::encryptString((string) $organization->id),
            'selectedPeriod' => in_array($request->query('period'), array_keys($plans), true) ? $request->query('period') : 'monthly',
            'selectedPackage' => $request->query('package') === 'chat_social' ? 'chat_social' : 'chat',
        ]);
    }
}
