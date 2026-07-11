<?php

namespace App\Services\Ai;

/**
 * AiProvider abstraction (FR-M15.6). Decouples all AI callers (M3 ingestion/
 * retrieval, M4 agents) from the underlying engine so editions can swap
 * implementations (Standard = Ollama; Enterprise = dedicated/tuned LLM)
 * without code change. On-premise only — implementations MUST NOT call
 * external/third-party AI APIs (sovereign-by-design).
 */
interface AiProvider
{
    /** Provider key, e.g. "ollama". */
    public function name(): string;

    /**
     * Generate an embedding vector for the given input text.
     *
     * @return float[]
     */
    public function embed(string $model, string $input): array;

    /**
     * Generate a completion for the given prompt.
     *
     * @param  array<string,mixed>  $options  e.g. ['temperature' => 0.2, 'system' => '...',
     *                                        'image' => ['data' => base64string, 'mime' => 'image/png']]
     *                                        (image is vision input — ignored by providers/models that
     *                                        don't support it)
     */
    public function generate(string $model, string $prompt, array $options = []): string;

    /**
     * List locally available models (FR-M15.7).
     *
     * @return array<int,array{name:string,size?:int,modified_at?:string}>
     */
    public function listModels(): array;

    /** Pull/download a model into the local engine (FR-M15.7). */
    public function pull(string $model): void;

    /** Health check — is the engine reachable and ready? (FR-M15.7) */
    public function health(): bool;
}
