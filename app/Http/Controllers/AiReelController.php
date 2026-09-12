<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiReel;
use App\Models\AiReel;
use App\Models\AiReelSchedule;
use App\Services\AiReelScheduler;
use App\Services\ReelCreditService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AiReelController extends Controller
{
    public function index(Request $request, TenantContext $tenant, ReelCreditService $credits)
    {
        $agent = $tenant->agent();
        $organization = $tenant->organization();
        $products = $agent->customerProducts()->where('is_active', true)->where('stock', '>', 0)->get();
        $categories = $products->flatMap(fn ($product) => [$product->category, ...((array) data_get($product->metadata, 'genres', [])), ...((array) data_get($product->metadata, 'taxonomy', []))])
            ->filter(fn ($value) => is_scalar($value) && trim((string) $value) !== '')->unique(fn ($value) => Str::lower((string) $value))->sort()->values();
        $languages = $agent->knowledgeSources()->where('source_scope', 'language')->where('status', 'ready')->pluck('taxonomy_label')->filter()->values();
        $connections = $agent->channelConnections()->whereIn('provider', ['facebook', 'instagram'])->where('status', 'active')->get()->keyBy('provider');
        $schedules = $agent->aiReelSchedules()->withCount(['reels', 'reels as published_reels_count' => fn ($query) => $query->where('status', 'published')])->latest()->get();
        $reels = $agent->aiReels()->with(['product', 'deliveries'])->latest()->limit(30)->get();

        return view('ai-reels', [
            'agent' => $agent, 'organization' => $organization, 'categories' => $categories,
            'languages' => $languages, 'connections' => $connections, 'schedules' => $schedules, 'reels' => $reels,
            'balance' => $credits->balance($organization), 'minimumPurchase' => config('paddle.reel_minimum_purchase', 10),
            'reelPriceId' => config('paddle.reel_credit_price'), 'paddleClientToken' => config('paddle.client_token'),
            'paddleEnvironment' => config('paddle.environment'), 'billingReference' => Crypt::encryptString((string) $organization->id),
            'activeTab' => $request->query('tab', 'schedule'), 'canManage' => in_array($tenant->role(), ['owner', 'admin'], true),
        ]);
    }

    public function purchaseComplete()
    {
        return redirect()->route('ai-reels.index', ['checkout' => 'complete'])->with('reel_success', 'Payment received. Credits will appear after Paddle confirms the transaction.');
    }

    public function storeSchedule(Request $request, TenantContext $tenant, AiReelScheduler $scheduler)
    {
        $tenant->authorize(['owner', 'admin']);
        $request->merge(['timing_mode' => $request->input('timing_mode', 'auto')]);
        $data = $request->validate([
            'reel_count' => ['required', 'integer', 'min:1', 'max:365'],
            'starts_on' => ['required', 'date', 'after_or_equal:today'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on', 'before_or_equal:'.now()->addYear()->toDateString()],
            'categories' => ['nullable', 'array'], 'categories.*' => ['string', 'max:255'],
            'languages' => ['nullable', 'array'], 'languages.*' => ['string', 'max:150'],
            'providers' => ['required', 'array', 'min:1'], 'providers.*' => [Rule::in(['facebook', 'instagram'])],
            'timezone' => ['required', 'timezone'], 'timing_mode' => ['required', Rule::in(['auto', 'custom'])],
            'posting_times' => ['nullable', 'array', 'max:24'], 'posting_times.*' => ['date_format:H:i'],
            'ai_tone' => ['required', Rule::in(['simple', 'creative', 'academic'])],
        ]);
        $data['posting_times'] = collect($data['posting_times'] ?? [])
            ->filter(fn ($time) => filled($time))->unique()->sort()->values()->all();
        if ($data['timing_mode'] === 'custom' && empty($data['posting_times'])) {
            return back()->withErrors(['posting_times' => 'Add at least one daily publishing time.'])->withInput();
        }
        $active = $tenant->agent()->channelConnections()->whereIn('provider', $data['providers'])->where('status', 'active')->pluck('provider');
        if (collect($data['providers'])->diff($active)->isNotEmpty()) {
            return back()->withErrors(['providers' => 'Connect every selected Meta account first.'])->withInput();
        }
        $scheduler->create($tenant->agent(), $data);

        return redirect()->route('ai-reels.index')->with('reel_success', 'Paid Reel schedule created. Video generation has started.');
    }

    public function storeCustom(Request $request, TenantContext $tenant, ReelCreditService $credits)
    {
        $tenant->authorize(['owner', 'admin']);
        $data = $request->validate([
            'prompt' => ['required', 'string', 'min:20', 'max:3000'], 'reference_url' => ['nullable', 'url', 'max:2000'],
            'providers' => ['required', 'array', 'min:1'], 'providers.*' => [Rule::in(['facebook', 'instagram'])],
        ]);
        $agent = $tenant->agent();
        $active = $agent->channelConnections()->whereIn('provider', $data['providers'])->where('status', 'active')->pluck('provider');
        if (collect($data['providers'])->diff($active)->isNotEmpty()) {
            return back()->withErrors(['providers' => 'Connect every selected Meta account first.'])->withInput();
        }
        $product = filled($data['reference_url'] ?? null)
            ? $agent->customerProducts()->where('is_active', true)->get()->first(fn ($candidate) => $candidate->matchesPublicProductUrl($data['reference_url']))
            : null;
        $reel = DB::transaction(function () use ($agent, $data, $product, $credits): AiReel {
            $reel = $agent->aiReels()->create([
                'product_id' => $product?->id, 'mode' => 'custom', 'providers' => array_values($data['providers']),
                'user_prompt' => $data['prompt'], 'reference_url' => $data['reference_url'] ?? null,
                'source_image_url' => $product?->publicImageUrl(), 'status' => 'queued',
            ]);
            $credits->debit($agent->organization, 1, 'custom-reel:'.$reel->id, ['reel_id' => $reel->id]);
            foreach ($data['providers'] as $provider) {
                $reel->deliveries()->create(['provider' => $provider, 'status' => 'scheduled']);
            }

            return $reel;
        }, 3);
        GenerateAiReel::dispatch($reel->id)->onQueue('channels')->afterCommit();

        return redirect()->route('ai-reels.index', ['tab' => 'custom'])->with('reel_success', 'Your Reel is being generated. You will review it before publishing.');
    }

    public function approve(AiReel $reel, TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        abort_unless($reel->agent_id === $tenant->agent()->id && $reel->mode === 'custom', 404);
        abort_unless($reel->status === 'awaiting_approval' && $reel->video_path, 422);
        $reel->update(['status' => 'ready', 'approved_at' => now(), 'scheduled_for' => now()]);

        return back()->with('reel_success', 'Reel approved. It is queued for Facebook and Instagram publishing.');
    }

    public function pause(AiReelSchedule $schedule, TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        abort_unless($schedule->agent_id === $tenant->agent()->id, 404);
        $paused = $schedule->status === 'active';
        $schedule->update(['status' => $paused ? 'paused' : 'active', 'paused_at' => $paused ? now() : null]);

        return back()->with('reel_success', $paused ? 'Reel schedule paused.' : 'Reel schedule resumed.');
    }
}
