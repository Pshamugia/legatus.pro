<?php

namespace App\Jobs;

use App\Models\SocialMediaPost;
use App\Services\MetaGraphClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublishSocialMediaStory implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(public int $postId) {}

    public function uniqueId(): string
    {
        return (string) $this->postId;
    }

    public function handle(MetaGraphClient $meta): void
    {
        $claimed = SocialMediaPost::query()
            ->whereKey($this->postId)
            ->where('status', 'published')
            ->whereIn('provider', ['facebook', 'instagram'])
            ->where('story_status', 'queued')
            ->update([
                'story_status' => 'publishing',
                'story_attempts' => DB::raw('story_attempts + 1'),
                'story_failure_reason' => null,
            ]);
        if ($claimed !== 1) {
            return;
        }

        $post = SocialMediaPost::query()->with('agent')->find($this->postId);
        if (! $post) {
            return;
        }

        $connection = $post->agent->channelConnections()
            ->where('provider', $post->provider)
            ->where('status', 'active')
            ->first();
        if (! $connection || ! $connection->isActive()) {
            $this->markFailed($post, ucfirst($post->provider).' Story was not published because the channel connection is inactive.');

            return;
        }

        try {
            $result = $post->provider === 'facebook'
                ? $meta->publishFacebookStory($connection, (string) $post->image_url)
                : $meta->publishInstagramStory($connection, (string) $post->image_url);
            $storyId = (string) ($result['post_id'] ?? $result['id'] ?? '');
            if ($storyId === '') {
                $this->markUnknown($post, 'Meta accepted the Story request without returning an identifier. Verify it in the native app before retrying.');

                return;
            }

            $post->update([
                'story_status' => 'published',
                'provider_story_id' => $storyId,
                'story_published_at' => now(),
                'story_failure_reason' => null,
            ]);
        } catch (ConnectionException) {
            $this->markUnknown($post, 'Story delivery outcome is unknown after a network timeout. Verify it in the native app before retrying.');
        } catch (RequestException $exception) {
            $status = $exception->response->status();
            if ($status === 408 || $status === 429 || $status >= 500) {
                $this->markUnknown($post, "Meta Story delivery returned HTTP {$status}; the outcome may be unknown. Verify it in the native app before retrying.");

                return;
            }

            $this->markFailed($post, "Meta rejected Story publishing with HTTP {$status}. Verify publishing permissions and media requirements.");
        } catch (\Throwable $exception) {
            $this->markFailed($post, Str::limit($exception->getMessage(), 2000, ''));
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $post = SocialMediaPost::query()->find($this->postId);
        if ($post && $post->story_status === 'publishing') {
            $this->markUnknown($post, 'The Story worker stopped before delivery could be confirmed. Verify it in the native app before retrying.');
        }
    }

    private function markFailed(SocialMediaPost $post, string $reason): void
    {
        $post->update([
            'story_status' => 'failed',
            'story_failure_reason' => Str::limit($reason, 2000, ''),
        ]);
    }

    private function markUnknown(SocialMediaPost $post, string $reason): void
    {
        $post->update([
            'story_status' => 'delivery_unknown',
            'story_failure_reason' => Str::limit($reason, 2000, ''),
        ]);
    }
}
