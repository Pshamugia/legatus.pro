<?php

namespace App\Http\Controllers;

use App\Models\SocialMediaSchedule;
use App\Services\SocialMediaScheduler;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SocialMediaController extends Controller
{
    public function index(TenantContext $tenant)
    {
        $agent = $tenant->agent();
        $connections = $agent->channelConnections()
            ->whereIn('provider', ['facebook', 'instagram'])
            ->get()
            ->keyBy('provider');
        $categories = $agent->customerProducts()->where('is_active', true)->get()
            ->flatMap(fn ($product) => [
                $product->category,
                ...((array) data_get($product->metadata, 'genres', [])),
                ...((array) data_get($product->metadata, 'taxonomy', [])),
            ])->filter(fn ($value): bool => is_scalar($value))->map(fn ($value) => trim((string) $value))->filter()->unique(fn ($value) => Str::lower($value))->sort()->values();
        $schedules = $agent->socialMediaSchedules()
            ->withCount([
                'posts',
                'posts as published_posts_count' => fn ($query) => $query->where('status', 'published'),
                'posts as failed_posts_count' => fn ($query) => $query->where('status', 'failed'),
            ])->latest()->get();
        $upcoming = $agent->socialMediaPosts()->whereIn('status', ['scheduled', 'queued'])
            ->orderBy('scheduled_for')->limit(12)->get();
        $canManage = in_array($tenant->role(), ['owner', 'admin'], true);

        return view('social-media', compact('agent', 'connections', 'categories', 'schedules', 'upcoming', 'canManage'));
    }

    public function store(Request $request, TenantContext $tenant, SocialMediaScheduler $scheduler)
    {
        $tenant->authorize(['owner', 'admin']);
        $data = $request->validate([
            'starts_on' => ['required', 'date', 'after_or_equal:today'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on', 'before_or_equal:'.now()->addYear()->toDateString()],
            'posts_per_day' => ['required', 'integer', 'min:1', 'max:24'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'max:255'],
            'providers' => ['required', 'array', 'min:1'],
            'providers.*' => [Rule::in(['facebook', 'instagram'])],
            'timezone' => ['required', 'timezone'],
        ]);

        $agent = $tenant->agent();
        $active = $agent->channelConnections()->whereIn('provider', $data['providers'])->where('status', 'active')->pluck('provider');
        $missing = collect($data['providers'])->diff($active);
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['providers' => 'Connect the selected Facebook/Instagram accounts before creating a schedule.']);
        }

        $scheduler->create($agent, $data);

        return redirect()->route('social-media.index')->with('social_success', 'Social media schedule created. Posts are ready in the publishing queue.');
    }

    public function pause(SocialMediaSchedule $schedule, TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        abort_unless($schedule->agent_id === $tenant->agent()->id, 404);
        $active = $schedule->status !== 'active';
        if ($active) {
            $schedule->posts()->where('status', 'scheduled')->where('scheduled_for', '<', now())->update(['status' => 'skipped']);
        }
        $schedule->update(['status' => $active ? 'active' : 'paused', 'paused_at' => $active ? null : now()]);

        return back()->with('social_success', $active ? 'Schedule resumed.' : 'Schedule paused.');
    }

    public function destroy(SocialMediaSchedule $schedule, TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        abort_unless($schedule->agent_id === $tenant->agent()->id, 404);
        $schedule->delete();

        return back()->with('social_success', 'Schedule and its post history were removed.');
    }
}
