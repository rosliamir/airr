<?php

namespace App\Services;

use App\Models\Constant;
use App\Models\User;

// Resolves {{SYSTEM:KEY}}, {{GLOBAL:KEY}}, {{PROJECT:KEY}}, {{DATA:KEY}} placeholders
// in template sections, report definitions, and datasource queries.
class ConstantResolver
{
    /**
     * @param  array<string,mixed>|null  $row  Row/parameter context used to resolve DATA-type
     *                                          constants (report-render time). Null outside that context.
     */
    public function resolve(string $text, ?int $projectId = null, ?User $user = null, ?array $row = null): string
    {
        // SYSTEM constants
        $systemConstants = Constant::where('scope', 'system')->get()->keyBy('key');
        $text = preg_replace_callback('/\{\{SYSTEM:([A-Z0-9_]+)(?::([^}]*))?\}\}/', function ($m) use ($user, $systemConstants, $projectId, $row) {
            $key    = $m[1];
            $format = $m[2] ?? null;
            $constant = $systemConstants->get($key);

            if ($constant && $constant->type === Constant::TYPE_IMAGE) {
                return $this->imageTag($constant);
            }
            if ($constant && $constant->type === Constant::TYPE_CALC) {
                return $this->resolveCalc($constant, $projectId, $user, $row);
            }

            if (! $format) {
                $format = $constant?->format;
            }

            return match ($key) {
                'DATE'          => now()->format($format ?? 'd/m/Y'),
                'TIME'          => now()->format($format ?? 'H:i'),
                'DATETIME'      => now()->format($format ?? 'd/m/Y H:i'),
                'YEAR'          => now()->format($format ?? 'Y'),
                'USER_NAME'     => $user?->name ?? '',
                'USER_EMAIL'    => $user?->email ?? '',
                'PAGE'          => '{{PAGE}}', // kept for PDF renderer
                'TOTAL_PAGE_NO' => '{{TOTAL_PAGE_NO}}', // kept for PDF renderer
                default         => $constant?->value ?? '',
            };
        }, $text);

        // GLOBAL constants
        $globals = Constant::where('scope', 'global')->get()->keyBy('key');
        $text = preg_replace_callback('/\{\{GLOBAL:([A-Z0-9_]+)\}\}/', function ($m) use ($globals, $projectId, $user, $row) {
            $constant = $globals->get($m[1]);
            if ($constant && $constant->type === Constant::TYPE_IMAGE) {
                return $this->imageTag($constant);
            }
            if ($constant && $constant->type === Constant::TYPE_CALC) {
                return $this->resolveCalc($constant, $projectId, $user, $row);
            }

            return $constant->value ?? '';
        }, $text);

        // PROJECT constants
        if ($projectId) {
            $projects = Constant::where('scope', 'project')
                ->where('project_id', $projectId)
                ->get()->keyBy('key');
            $text = preg_replace_callback('/\{\{PROJECT:([A-Z0-9_]+)\}\}/', function ($m) use ($projects, $projectId, $user, $row) {
                $constant = $projects->get($m[1]);
                if ($constant && $constant->type === Constant::TYPE_IMAGE) {
                    return $this->imageTag($constant);
                }
                if ($constant && $constant->type === Constant::TYPE_CALC) {
                    return $this->resolveCalc($constant, $projectId, $user, $row);
                }

                return $constant->value ?? '';
            }, $text);
        }

        // DATA constants — resolved against the given row context (report-render time).
        // Forward-compatible: wiring this into the actual report engine's per-row
        // rendering pass is future work (M7/M8); this just adds resolver support.
        $text = preg_replace_callback('/\{\{DATA:([A-Z0-9_]+)\}\}/', function ($m) use ($row) {
            $constant = Constant::where('type', Constant::TYPE_DATA)->where('key', $m[1])->first();
            if (! $constant || ! $row) {
                return '';
            }

            return (string) ($row[$constant->data_column] ?? '');
        }, $text);

        return $text;
    }

    // type=calc: recursively resolve the stored formula's own tokens, then
    // evaluate the resulting arithmetic expression with a restricted evaluator
    // (no eval() — only +, -, *, /, parentheses, and numeric literals reach it).
    private function resolveCalc(Constant $constant, ?int $projectId, ?User $user, ?array $row): string
    {
        if (! $constant->formula) {
            return '';
        }

        $resolved = $this->resolve($constant->formula, $projectId, $user, $row);

        try {
            $result = (new SafeArithmeticEvaluator())->evaluate($resolved);
        } catch (\Throwable) {
            return '';
        }

        return (string) $result;
    }

    private function imageTag(Constant $constant): string
    {
        if (! $constant->image_path) {
            return '';
        }

        $url = route('constants.image', $constant->id);

        return "<img src=\"{$url}\" alt=\"{$constant->label}\" />";
    }
}
