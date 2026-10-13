<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsInOrganization;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ConversationAutomation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RespondToConversation implements ShouldQueue, ShouldBeUnique
{
    use Queueable, RunsInOrganization;

    public int $tries = 1; // an AI reply must never be sent twice by a retry

    public int $timeout = 120;

    public function __construct(public int $conversationId, public int $messageId, public int $organizationId) {}

    public function uniqueId(): string
    {
        return $this->organizationId.':'.$this->messageId;
    }

    public function handle(ConversationAutomation $automation): void
    {
        $this->withinOrganization($this->organizationId, null, function () use ($automation) {
            $c = Conversation::with(['contact', 'lead'])->find($this->conversationId);
            $m = Message::find($this->messageId);
            if ($c && $m) {
                $automation->handle($c, $m);
            }
        });
    }
}
