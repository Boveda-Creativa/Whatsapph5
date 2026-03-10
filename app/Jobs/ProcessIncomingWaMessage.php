<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessIncomingWaMessage implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $conversationId, public string $text) {}

    /**
     * Execute the job.
     */
    public function handle(\App\Services\LeadFlow $flow)
    {
        $c = \App\Models\WaConversation::findOrFail($this->conversationId);
        $flow->handle($c, $this->text);
    }
}
