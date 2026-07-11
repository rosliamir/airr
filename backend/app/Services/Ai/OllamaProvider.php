<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Ollama implementation of AiProvider (FR-M15.6) — on-premise local inference,
 * no external egress. Talks to the Ollama REST API (default :11434).
 */
class OllamaProvider implements AiProvider
{
    public function __construct(
        private readonly string $url,
        private readonly int $timeout = 120,
    ) {}

    public function name(): string
    {
        return 'ollama';
    }

    public function embed(string $model, string $input): array
    {
        $res = $this->client()
            ->post('/api/embeddings', ['model' => $model, 'prompt' => $input])
            ->throw()
            ->json();

        $vector = $res['embedding'] ?? null;
        if (! is_array($vector)) {
            throw new RuntimeException("Ollama returned no embedding for model [{$model}].");
        }

        return $vector;
    }

    public function generate(string $model, string $prompt, array $options = []): string
    {
        $payload = [
            'model'  => $model,
            'prompt' => $prompt,
            'stream' => false,
        ];
        if (isset($options['system'])) {
            $payload['system'] = $options['system'];
        }
        if ($image = $options['image'] ?? null) {
            $payload['images'] = [$image['data']]; // multimodal models (e.g. llava) only
        }
        if ($opts = array_intersect_key($options, array_flip(['temperature', 'top_p', 'top_k', 'num_ctx', 'seed']))) {
            $payload['options'] = $opts;
        }

        return (string) $this->client()
            ->post('/api/generate', $payload)
            ->throw()
            ->json('response', '');
    }

    public function listModels(): array
    {
        return $this->client()->get('/api/tags')->throw()->json('models', []);
    }

    public function pull(string $model): void
    {
        // Blocking pull; stream disabled so the call returns once complete.
        $this->client()->timeout(0)->post('/api/pull', ['model' => $model, 'stream' => false])->throw();
    }

    public function health(): bool
    {
        try {
            return $this->client()->timeout(5)->get('/api/tags')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->url, '/'))
            ->timeout($this->timeout)
            ->acceptJson();
    }
}
