<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenAI (and OpenAI-compatible) AiProvider — for hosted/cloud AI services via
 * API key. NOTE: this deliberately sends data outside the premises, which
 * conflicts with AIRR's default "sovereign by design" posture (see
 * AiProvider interface docblock). It exists as an explicit, admin-chosen
 * opt-in (Settings > AI/Models > Provider = OpenAI) — Ollama remains the
 * on-premise default. Only enable this with the user's informed consent.
 */
class OpenAiProvider implements AiProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 120,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function embed(string $model, string $input): array
    {
        $res = $this->client()
            ->post('/embeddings', ['model' => $model, 'input' => $input])
            ->throw()
            ->json();

        $vector = $res['data'][0]['embedding'] ?? null;
        if (! is_array($vector)) {
            throw new RuntimeException("OpenAI returned no embedding for model [{$model}].");
        }

        return $vector;
    }

    public function generate(string $model, string $prompt, array $options = []): string
    {
        $messages = [];
        if (isset($options['system'])) {
            $messages[] = ['role' => 'system', 'content' => $options['system']];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = ['model' => $model, 'messages' => $messages];
        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        return (string) $this->client()
            ->post('/chat/completions', $payload)
            ->throw()
            ->json('choices.0.message.content', '');
    }

    public function listModels(): array
    {
        $data = $this->client()->get('/models')->throw()->json('data', []);

        return collect($data)->map(fn ($m) => ['name' => $m['id'] ?? ''])->values()->all();
    }

    public function pull(string $model): void
    {
        // No equivalent for hosted providers — models are managed by the vendor.
        throw new RuntimeException('pull() is not supported for the OpenAI provider.');
    }

    public function health(): bool
    {
        if (! $this->apiKey) {
            return false;
        }
        try {
            return $this->client()->timeout(5)->get('/models')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->apiKey)
            ->timeout($this->timeout)
            ->acceptJson();
    }
}
