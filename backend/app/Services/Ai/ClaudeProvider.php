<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Anthropic Claude AiProvider — hosted, API-key based. Same sovereignty caveat
 * as ExternalAiProvider/OpenAiProvider: this sends data off-premises and is an
 * explicit, admin-chosen opt-in (Settings > AI/Models > Provider = Claude).
 * Uses Anthropic's Messages API (distinct shape from OpenAI's chat/completions).
 */
class ClaudeProvider implements AiProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 120,
    ) {}

    public function name(): string
    {
        return 'claude';
    }

    public function embed(string $model, string $input): array
    {
        throw new RuntimeException('Claude does not provide an embeddings API — use a different provider for the embedding task.');
    }

    public function generate(string $model, string $prompt, array $options = []): string
    {
        $payload = [
            'model'      => $model,
            'max_tokens' => (int) ($options['max_tokens'] ?? 4096),
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ];
        if (isset($options['system'])) {
            $payload['system'] = $options['system'];
        }
        // NOTE: intentionally not forwarding options['temperature'] — newer Claude
        // models (e.g. claude-sonnet-5) reject it as a deprecated parameter.

        $content = (array) $this->client()
            ->post('/v1/messages', $payload)
            ->throw()
            ->json('content', []);

        // Models with extended thinking return multiple content blocks (e.g. a
        // leading "thinking" block with no "text" key) — content[0] is NOT
        // reliably the answer, so find the first "text" block instead.
        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text') {
                return (string) ($block['text'] ?? '');
            }
        }

        return '';
    }

    public function listModels(): array
    {
        $data = $this->client()->get('/v1/models')->throw()->json('data', []);

        return collect($data)->map(fn ($m) => ['name' => $m['id'] ?? ''])->values()->all();
    }

    public function pull(string $model): void
    {
        throw new RuntimeException('pull() is not supported for the Claude provider.');
    }

    public function health(): bool
    {
        if (! $this->apiKey) {
            return false;
        }
        try {
            return $this->client()->timeout(5)->get('/v1/models')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01'])
            ->timeout($this->timeout)
            ->acceptJson();
    }
}
