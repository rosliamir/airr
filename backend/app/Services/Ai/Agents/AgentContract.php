<?php

namespace App\Services\Ai\Agents;

// M4 — shared contract for all pipeline agents. Each agent reads what it
// needs from the shared $context bag and writes its results back into it.
// The Orchestrator persists input/output snapshots to orchestration_steps.
interface AgentContract
{
    /** Agent key — matches orchestration_steps.agent. */
    public function name(): string;

    /**
     * Execute the agent against the shared pipeline context.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>  updated context with agent outputs merged in
     */
    public function run(array $context): array;
}
