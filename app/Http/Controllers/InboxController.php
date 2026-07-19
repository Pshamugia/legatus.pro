<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\TenantContext;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        $agent = $tenant->agent();
        $status = $request->string('status')->toString();
        $customerConversations = $agent->conversations()->where('channel', '!=', 'eval');
        $needsHumanCount = (clone $customerConversations)->where('status', 'human')->count();
        $query = (clone $customerConversations)->with(['messages' => fn ($messages) => $messages->orderBy('id'), 'lead'])->latest('last_message_at');
        if (in_array($status, ['human', 'ai', 'closed'])) {
            $query->where('status', $status);
        }
        $conversations = $query->get();
        $selected = $request->filled('conversation') ? $conversations->firstWhere('id', (int) $request->conversation) : $conversations->first();

        return view('inbox', compact('agent', 'conversations', 'selected', 'status', 'needsHumanCount'));
    }

    public function takeOver(Conversation $conversation, TenantContext $tenant)
    {
        $this->own($conversation, $tenant);
        $tenant->authorize(['owner', 'admin', 'agent']);
        $conversation->update(['status' => 'human', 'assigned_to' => auth()->user()->name, 'last_message_at' => now()]);

        return back()->with('success', 'Conversation assigned to you.');
    }

    public function reply(Request $request, Conversation $conversation, TenantContext $tenant)
    {
        $this->own($conversation, $tenant);
        $tenant->authorize(['owner', 'admin', 'agent']);
        $data = $request->validate(['message' => 'required|string|max:2000']);
        $conversation->messages()->create(['role' => 'human', 'content' => $data['message'], 'metadata' => ['operator' => auth()->user()->name]]);
        $conversation->update(['status' => 'human', 'assigned_to' => auth()->user()->name, 'last_message_at' => now()]);

        return back();
    }

    public function release(Conversation $conversation, TenantContext $tenant)
    {
        $this->own($conversation, $tenant);
        $tenant->authorize(['owner', 'admin', 'agent']);
        $conversation->update(['status' => 'ai', 'assigned_to' => null, 'priority' => 'normal', 'last_message_at' => now()]);
        $conversation->messages()->create(['role' => 'system', 'content' => 'Conversation returned to Legatus.']);

        return back()->with('success', 'Legatus is handling this conversation again.');
    }

    public function close(Conversation $conversation, TenantContext $tenant)
    {
        $this->own($conversation, $tenant);
        $tenant->authorize(['owner', 'admin', 'agent']);
        $conversation->update(['status' => 'closed', 'outcome' => $conversation->outcome ?: 'resolved', 'resolved_at' => now(), 'last_message_at' => now()]);

        return back()->with('success', 'Conversation closed.');
    }

    public function poll(Conversation $conversation, TenantContext $tenant)
    {
        $this->own($conversation, $tenant);
        abort_if($conversation->channel === 'eval', 404);

        $messages = $conversation->messages()
            ->latest('id')
            ->limit(50)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($message) => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'confidence' => $message->confidence,
                'sources' => $message->role === 'assistant' ? ($message->metadata['sources'] ?? []) : [],
                'created_at' => $message->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'status' => $conversation->status,
            'assigned_to' => $conversation->assigned_to,
            'simulated' => str_starts_with($conversation->visitor_id, 'demo-'),
            'messages' => $messages,
        ]);
    }

    private function own(Conversation $conversation, TenantContext $tenant): void
    {
        abort_unless($conversation->agent_id === $tenant->agent()->id, 404);
    }
}
