<?php

namespace App\Models;

use App\Support\WidgetTheme;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Agent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = ['channels' => 'array', 'settings' => 'array', 'is_active' => 'boolean'];

    public function hasCustomAssistantName(): bool
    {
        $name = trim((string) $this->name);

        return $name !== '' && ! in_array(mb_strtolower($name), ['legatus', 'ai assistant', 'ai ასისტენტი'], true);
    }

    public function assistantDisplayName(): string
    {
        return $this->hasCustomAssistantName() ? trim((string) $this->name) : 'AI Assistant';
    }

    public function websiteWidgetEnabled(): bool
    {
        // Null predates per-channel controls, so keep existing installations on
        // until an owner or admin explicitly disables the website channel.
        return ! is_array($this->channels) || in_array('web', $this->channels, true);
    }

    public function humanHandoffEnabled(): bool
    {
        return (bool) data_get($this->settings, 'human_handoff_enabled', true);
    }

    /**
     * @return array{preset: string, primary: string, accent: string, primary_foreground: string, accent_foreground: string}
     */
    public function widgetTheme(): array
    {
        return WidgetTheme::resolve(data_get($this->settings, 'widget_theme'));
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Products that may be exposed to this business's customers.
     *
     * Once a real source is connected, static Build Week fixture products
     * must never be mixed into the live business catalogue.
     */
    public function customerProducts(): HasMany
    {
        $products = $this->products();
        $hasRealSource = $this->knowledgeSources()
            ->where(function ($query): void {
                $query->whereNotNull('url')->where('url', '!=', '')
                    ->orWhere(function ($fileQuery): void {
                        $fileQuery->whereNotNull('file_path')->where('file_path', '!=', '');
                    });
            })
            ->exists();

        if (! $hasRealSource) {
            return $products;
        }

        $staticSourceIds = $this->knowledgeSources()
            ->whereNull('url')
            ->whereNull('file_path')
            ->pluck('id');

        if ($staticSourceIds->isNotEmpty()) {
            $products->where(function ($query) use ($staticSourceIds): void {
                $query->whereNull('metadata->source_id')
                    ->orWhereNotIn('metadata->source_id', $staticSourceIds);
            });
        }

        return $products;
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function channelConnections(): HasMany
    {
        return $this->hasMany(ChannelConnection::class);
    }

    public function knowledgeSources(): HasMany
    {
        return $this->hasMany(KnowledgeSource::class);
    }

    public function commerceConnection(): HasOne
    {
        return $this->hasOne(CommerceConnection::class);
    }
}
