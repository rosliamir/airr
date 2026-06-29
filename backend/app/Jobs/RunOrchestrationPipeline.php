<?php

namespace App\Jobs;

use App\Models\OrchestrationRun;
use App\Services\Ai\Orchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

// M4 — async job that drives the multi-agent pipeline.
// Runs on the 'ai' queue (AI_QUEUE=ai in .env) with no automatic retries
// because LLM inference is expensive and a retry after failure may repeat a
// bad prompt rather than fix the root cause.
class RunOrchestrationPipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 1;

    public function __construct(public readonly OrchestrationRun $run) {}

    public function handle(Orchestrator $orchestrator): void
    {
        $orchestrator->execute($this->run);
    }

    public function failed(Throwable $e): void
    {
        $this->run->update([
            'status' => OrchestrationRun::STATUS_FAILED,
            'error'  => 'Job failed: ' . $e->getMessage(),
        ]);
    }

    public function queue(): string
    {
        return config('queue.ai_queue', 'ai');
    }
}
