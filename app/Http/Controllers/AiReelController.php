<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiReel;
use App\Models\AiReel;
use App\Models\AiReelSchedule;
use App\Services\AiReelScheduler;
use App\Services\AiReelSourceImageStorage;
use App\Services\ReelCreditService;
use App\Services\RunwayClient;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $reels = $agent->aiReels()
            ->with(['product', 'deliveries'])
            ->whereNotIn('status', ['generation_failed', 'canceled'])
            ->latest()
            ->limit(30)
            ->get();

        return view('ai-reels', [
            'agent' => $agent, 'organization' => $organization, 'categories' => $categories,
            'languages' => $languages, 'connections' => $connections, 'schedules' => $schedules, 'reels' => $reels,
            'balance' => $credits->balance($organization), 'minimumPurchase' => config('paddle.reel_minimum_purchase', 10),
            'durationCredits' => $credits->durationOptions(),
            'musicTracks' => config('reel_music.tracks', []),
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
        $request->merge([
            'timing_mode' => $request->input('timing_mode', 'auto'),
            'duration_seconds' => $request->input('duration_seconds', 5),
            'music_track' => $request->input('music_track', config('reel_music.default', 'bright')),
        ]);
        $data = $request->validate([
            'reel_count' => ['required', 'integer', 'min:1', 'max:365'],
            'duration_seconds' => ['required', 'integer', Rule::in([5, 10, 15])],
            'music_track' => ['required', 'string', Rule::in(array_keys(config('reel_music.tracks', [])))],
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

    public function storeCustom(Request $request, TenantContext $tenant, ReelCreditService $credits, AiReelSourceImageStorage $images)
    {
        $tenant->authorize(['owner', 'admin']);
        $request->merge([
            'duration_seconds' => $request->input('duration_seconds', 5),
            'music_track' => $request->input('music_track', config('reel_music.default', 'bright')),
        ]);
        $data = $request->validate([
            'prompt' => ['required', 'string', 'min:20', 'max:3000'], 'reference_url' => ['nullable', 'url', 'max:2000'],
            'duration_seconds' => ['required', 'integer', Rule::in([5, 10, 15])],
            'music_track' => ['required', 'string', Rule::in(array_keys(config('reel_music.tracks', [])))],
            'source_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:min_width=640,min_height=640,max_width=8000,max_height=8000'],
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
        $uploadedImage = $request->file('source_image');
        if ($uploadedImage) {
            $dimensions = @getimagesize($uploadedImage->getRealPath());
            $ratio = $dimensions ? $dimensions[0] / max(1, $dimensions[1]) : 0;
            if ($ratio < 0.5 || $ratio > 2) {
                throw ValidationException::withMessages([
                    'source_image' => 'The Reel image width-to-height ratio must be between 1:2 and 2:1.',
                ]);
            }
        }
        $storedImage = $uploadedImage ? $images->store($uploadedImage) : null;
        $creditCost = $credits->creditsForDuration((int) $data['duration_seconds']);
        try {
            $reel = DB::transaction(function () use ($agent, $data, $product, $credits, $storedImage, $creditCost): AiReel {
                $reel = $agent->aiReels()->create([
                    'product_id' => $product?->id, 'mode' => 'custom', 'providers' => array_values($data['providers']),
                    'duration_seconds' => $data['duration_seconds'], 'credit_cost' => $creditCost,
                    'music_track' => $data['music_track'],
                    'user_prompt' => $data['prompt'], 'reference_url' => $data['reference_url'] ?? null,
                    'source_image_url' => $storedImage['url'] ?? $product?->publicImageUrl(),
                    'source_image_path' => $storedImage['path'] ?? null, 'status' => 'queued',
                ]);
                $credits->debit($agent->organization, $creditCost, 'custom-reel:'.$reel->id, [
                    'reel_id' => $reel->id,
                    'duration_seconds' => (int) $data['duration_seconds'],
                ]);
                foreach ($data['providers'] as $provider) {
                    $reel->deliveries()->create(['provider' => $provider, 'status' => 'scheduled']);
                }

                return $reel;
            }, 3);
        } catch (\Throwable $exception) {
            $images->deletePath($storedImage['path'] ?? null);

            throw $exception;
        }
        GenerateAiReel::dispatch($reel->id)->onQueue('channels')->afterCommit();

        return redirect()->route('ai-reels.index', ['tab' => 'custom'])->with('reel_success', 'Your Reel is being generated. You will review it before publishing.');
    }

    public function status(AiReel $reel, TenantContext $tenant): JsonResponse
    {
        $tenant->authorize(['owner', 'admin']);
        abort_unless($reel->agent_id === $tenant->agent()->id && $reel->mode === 'custom', 404);

        return response()->json([
            'status' => $reel->status,
            'updated_at' => $reel->updated_at?->toIso8601String(),
        ]);
    }

    public function approve(Request $request, AiReel $reel, TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        abort_unless($reel->agent_id === $tenant->agent()->id && $reel->mode === 'custom', 404);
        abort_unless($reel->status === 'awaiting_approval' && $reel->video_path, 422);
        $data = $request->validate([
            'caption' => ['required', 'string', 'max:2200'],
        ]);
        $caption = trim(strip_tags($data['caption']));
        if ($caption === '') {
            throw ValidationException::withMessages(['caption' => 'The Reel caption is required.']);
        }
        $reel->update([
            'caption' => $caption,
            'status' => 'ready',
            'approved_at' => now(),
            'scheduled_for' => now(),
        ]);

        return back()->with('reel_success', 'Reel approved. It is queued for Facebook and Instagram publishing.');
    }

    public function cancel(AiReel $reel, TenantContext $tenant, RunwayClient $runway, ReelCreditService $credits, AiReelSourceImageStorage $images)
    {
        $tenant->authorize(['owner', 'admin']);
        abort_unless($reel->agent_id === $tenant->agent()->id && $reel->mode === 'custom', 404);
        $previousStatus = null;
        foreach (range(1, 3) as $_) {
            $reel->refresh();
            abort_unless(in_array($reel->status, ['queued', 'generating'], true), 422);
            $previousStatus = $reel->status;
            $changed = AiReel::query()
                ->whereKey($reel->id)
                ->where('status', $previousStatus)
                ->update(['status' => 'canceling', 'last_error' => null]);
            if ($changed === 1) {
                break;
            }
            $previousStatus = null;
        }
        abort_if($previousStatus === null, 409, 'The Reel status changed. Please try again.');
        $reel->refresh();
        if ($reel->runway_task_id) {
            try {
                $runway->cancel($reel->runway_task_id);
            } catch (\Throwable $exception) {
                report($exception);
                $reel->update(['status' => 'generating', 'last_error' => Str::limit('Runway cancellation failed: '.$exception->getMessage(), 1000)]);

                return back()->withErrors(['reel' => 'The Reel could not be stopped yet. Please try again.']);
            }
        } elseif ($previousStatus === 'generating') {
            return back()->with('reel_success', 'Cancellation requested. The Reel is stopping.');
        }

        $reel->update(['status' => 'canceled']);
        $images->delete($reel);
        if ($previousStatus === 'queued') {
            $credits->refund($reel, 'canceled_before_generation');
        }

        return back()->with('reel_success', 'Reel generation stopped. Nothing was published.');
    }

    public function destroy(AiReel $reel, TenantContext $tenant, AiReelSourceImageStorage $images)
    {
        $tenant->authorize(['owner', 'admin']);
        abort_unless($reel->agent_id === $tenant->agent()->id && $reel->mode === 'custom', 404);
        abort_unless($reel->status === 'awaiting_approval' && ! $reel->approved_at, 422);

        $videoPath = $reel->video_path;
        $images->delete($reel);
        if (filled($videoPath)) {
            Storage::disk('local')->delete($videoPath);
        }
        $reel->delete();

        return redirect()->route('ai-reels.index', ['tab' => 'custom'])
            ->with('reel_success', 'Reel preview removed. Nothing was published.');
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
