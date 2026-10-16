<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\ChannelMessageDispatcher;
use App\Services\TenantContext;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        $agent = $tenant->agent();
        $status = $request->string('status')->toString();
        $source = $request->string('source')->toString();
        $customerConversations = $agent->conversations()->where('channel', '!=', 'eval');
        $needsHumanCount = (clone $customerConversations)->where('status', 'human')->count();
        $query = (clone $customerConversations)->with([
            'messages' => fn ($messages) => $messages->with('channelMessage')->orderBy('id'),
            'lead',
        ])->latest('last_message_at');
        if (in_array($status, ['human', 'ai', 'closed'])) {
            $query->where('status', $status);
        }
        if ($source === 'meta_comments') {
            $query->whereIn('channel', ['facebook_comment', 'instagram_comment']);
        }
        $conversations = $query->get();
        $selected = $request->filled('conversation') ? $conversations->firstWhere('id', (int) $request->conversation) : $conversations->first();

        return view('inbox', compact('agent', 'conversations', 'selected', 'status', 'source', 'needsHumanCount'));
    }

    public function takeOver(Conversation $conversation, TenantContext $tenant)
    {
        $this->own($conversation, $tenant);
        $tenant->authorize(['owner', 'admin', 'agent']);
        $conversation->update(['status' => 'human', 'assigned_to' => auth()->user()->name, 'last_message_at' => now()]);

        return back()->with('success', 'Conversation assigned to you.');
    }

    public function reply(Request $request, Conversation $conversation, TenantContext $tenant, ChannelMessageDispatcher $dispatcher)
    {
        $this->own($conversation, $tenant);
        $tenant->authorize(['owner', 'admin', 'agent']);
        $isComment = data_get($conversation->context, 'source_type') === 'social_comment';
        $data = $request->validate(['message' => 'required|string|max:'.($isComment ? 1000 : 2000)]);
        $message = $conversation->messages()->create([
            'role' => 'human',
            'content' => $data['message'],
            'metadata' => ['operator_label' => 'Human operator'],
        ]);
        $conversation->update(['status' => 'human', 'assigned_to' => auth()->user()->name, 'last_message_at' => now()]);
        $delivery = $dispatcher->dispatch($message)?->fresh();

        if (in_array($conversation->channel, ['facebook', 'instagram', 'whatsapp', 'facebook_comment', 'instagram_comment'], true)) {
            if (! $delivery) {
                return back()->with('error', 'Reply was saved, but the Meta channel is not connected. Reconnect it before replying again.');
            }
            if ($delivery->status === 'failed') {
                return back()->with('error', 'Reply was not delivered. Verify the Meta channel connection before replying again.');
            }
            if ($delivery->status === 'delivery_unknown') {
                return back()->with('error', 'Reply delivery is uncertain. Check the native Meta inbox before resending.');
            }
        }

        return back()->with('success', 'Reply queued for delivery.');
    }

    public function release(Conversation $conversation, TenantContext $tenant)
    {
        $this->own($conversation, $tenant);
        $tenant->authorize(['owner', 'admin', 'agent']);
        $this->returnToAi($conversation);

        return back()->with('success', 'Legatus is handling this conversation again.');
    }

    public function close(Conversation $conversation, TenantContext $tenant)
    {
        $this->own($conversation, $tenant);
        $tenant->authorize(['owner', 'admin', 'agent']);
        $this->returnToAi($conversation);

        return to_route('inbox.index')->with('success', 'Conversation closed and returned to Legatus.');
    }

    public function poll(Conversation $conversation, TenantContext $tenant)
    {
        $this->own($conversation, $tenant);
        abort_if($conversation->channel === 'eval', 404);

        $messages = $conversation->messages()
            ->with('channelMessage')
            ->latest('id')
            ->limit(50)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($message) => [
                'id' => $message->id,
                'role' => $message->role,
                'display_role' => $this->displayRole($conversation, $message->role),
                'public_comment' => data_get($conversation->context, 'source_type') === 'social_comment',
                'content' => $message->content,
                'confidence' => $message->confidence,
                'feedback' => $message->role === 'assistant' ? $message->feedback : null,
                'sources' => $message->role === 'assistant' ? ($message->metadata['sources'] ?? []) : [],
                'products' => $message->role === 'assistant' ? ($message->metadata['products'] ?? []) : [],
                'delivery_status' => $this->deliveryStatus($message),
                'delivery_warning' => $this->deliveryWarning($message),
                'created_at' => $message->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'status' => $conversation->status,
            'assigned_to' => $conversation->assigned_to,
            'simulated' => str_starts_with($conversation->visitor_id, 'demo-'),
            'public_comment' => data_get($conversation->context, 'source_type') === 'social_comment',
            'provider' => str_replace('_comment', '', $conversation->channel),
            'messages' => $messages,
        ]);
    }

    private function own(Conversation $conversation, TenantContext $tenant): void
    {
        abort_unless($conversation->agent_id === $tenant->agent()->id, 404);
    }

    private function returnToAi(Conversation $conversation): void
    {
        $wasHuman = $conversation->status === 'human';
        $conversation->update([
            'status' => 'ai',
            'assigned_to' => null,
            'priority' => 'normal',
            'resolved_at' => null,
            'last_message_at' => now(),
        ]);
        if ($wasHuman) {
            $conversation->messages()->create(['role' => 'system', 'content' => 'Conversation returned to Legatus.']);
        }
    }

    private function deliveryStatus($message): ?string
    {
        return $message->channelMessage?->direction === 'outbound'
            ? $message->channelMessage->status
            : null;
    }

    private function deliveryWarning($message): ?string
    {
        return match ($this->deliveryStatus($message)) {
            'failed' => 'Not delivered. Verify the channel connection before replying again.',
            'delivery_unknown' => 'Delivery is uncertain. Check the native Meta inbox before resending.',
            default => null,
        };
    }

    private function displayRole(Conversation $conversation, string $role): string
    {
        if (data_get($conversation->context, 'source_type') !== 'social_comment') {
            return $role === 'human' ? 'Human operator' : ucfirst($role);
        }

        $provider = ucfirst(str_replace('_comment', '', $conversation->channel));

        return match ($role) {
            'customer' => $provider.' commenter · public comment',
            'assistant' => 'Legatus · public reply on '.$provider,
            'human' => 'Human operator · public reply on '.$provider,
            default => ucfirst($role).' · '.$provider.' comment thread',
        };
    }
}
