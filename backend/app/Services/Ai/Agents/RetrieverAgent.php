<?php

namespace App\Services\Ai\Agents;

use App\Services\Ai\AiProvider;
use App\Services\Ai\ModelResolver;
use Illuminate\Support\Facades\DB;

// M4 (FR-M4.3) — semantic search over KB chunks using pgvector cosine similarity.
class RetrieverAgent implements AgentContract
{
    private const TOP_K         = 12;
    private const MIN_SCORE     = 0.50;

    public function __construct(
        private readonly AiProvider   $ai,
        private readonly ModelResolver $resolver,
    ) {}

    public function name(): string
    {
        return 'retriever';
    }

    /**
     * Reads:  context['prompt'], context['project'], context['kb_ids']
     * Writes: context['rag_chunks']       — top-k {id, heading, content, score}
     *         context['retriever_model']  — model name used for embedding
     */
    public function run(array $context): array
    {
        $prompt  = $context['prompt'] ?? '';
        $project = $context['project'] ?? null;
        $kbIds   = $context['kb_ids']  ?? [];

        $model   = $this->resolver->model('retrieval', $project);
        $vector  = $this->ai->embed($model, $prompt);

        $chunks = $this->searchChunks($vector, $kbIds);

        $context['rag_chunks']      = $chunks;
        $context['retriever_model'] = $model;

        return $context;
    }

    /**
     * @param  float[]  $vector
     * @param  int[]    $kbIds
     * @return array<int,array{id:int,heading:string,content:string,score:float}>
     */
    private function searchChunks(array $vector, array $kbIds): array
    {
        if (empty($vector)) {
            return [];
        }

        $pgVector = '[' . implode(',', $vector) . ']';

        $query = DB::table('kb_chunks')
            ->selectRaw(
                'id, heading, content, 1 - (embedding <=> ?::vector) AS score',
                [$pgVector]
            )
            ->orderByRaw('embedding <=> ?::vector', [$pgVector])
            ->limit(self::TOP_K);

        if (! empty($kbIds)) {
            $query->whereIn('knowledge_base_id', $kbIds);
        }

        return $query->get()
            ->filter(fn ($row) => $row->score >= self::MIN_SCORE)
            ->map(fn ($row) => [
                'id'      => $row->id,
                'heading' => $row->heading ?? '',
                'content' => $row->content,
                'score'   => round((float) $row->score, 4),
            ])
            ->values()
            ->toArray();
    }
}
