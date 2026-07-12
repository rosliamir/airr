<?php

namespace App\Services;

use App\Models\Constant;
use App\Models\Dataset;
use App\Models\DataSource;
use App\Models\Menu;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;

// Resolves variable tokens used in template sections, report definitions, and
// datasource queries:
//   {{SYSTEM|KEY}}, {{GLOBAL|KEY}}, {{PROJECT|KEY}}         — constant lookup
//   {{PARA|name}}                                            — report's own custom parameter
//   {{DATA:source:dataset|field}}                            — ad-hoc dataset field lookup
//   {{API:api name|field}}                                   — ad-hoc API field lookup
//   {{SYSTEM:MENU|menu name}}                                — a menu's route
//   {{SYSTEM:PROJECT|project name}}                          — a project's code
//   {{SYSTEM:TEMPLATE|template name}}                        — a template's content
//   {{SYSTEM:API|api name:field}}                            — same as {{API:...|...}}
// The older {{SYSTEM:KEY}} / {{GLOBAL:KEY}} / {{PROJECT:KEY}} colon form is
// still accepted for backward compatibility with content saved before the
// pipe form was introduced.
class ConstantResolver
{
    public function __construct(protected ConnectorService $connector) {}

    /**
     * @param  array<string,mixed>|null  $row  Row/parameter context used to resolve DATA-type
     *                                          constants (report-render time). Null outside that context.
     * @param  array<string,mixed>|null  $customParams  Report custom-parameter runtime values,
     *                                          keyed by parameter name — resolves {{PARA|name}}.
     * @param  string|null  $reportName  The current report's name — resolves {{SYSTEM|REPORT_NAME}}.
     */
    public function resolve(string $text, ?int $projectId = null, ?User $user = null, ?array $row = null, ?array $customParams = null, ?string $reportName = null): string
    {
        // Normalize the pipe form of the three plain constant scopes to the
        // legacy colon form the rest of this method already handles — keeps
        // one implementation for both spellings. Only touches the SIMPLE
        // {{SCOPE|KEY}} shape; qualified forms like {{SYSTEM:MENU|name}}
        // already start with a colon and are untouched.
        $text = preg_replace('/\{\{(SYSTEM|GLOBAL|PROJECT)\|([A-Z0-9_]+)\}\}/', '{{$1:$2}}', $text);

        // {{PARA|name}} — a report's own custom parameter, referenced as a variable.
        if ($customParams) {
            $text = preg_replace_callback('/\{\{PARA\|([A-Za-z0-9_]+)\}\}/', function ($m) use ($customParams) {
                return (string) ($customParams[$m[1]] ?? '');
            }, $text);
        }

        // {{DATA:source_name:dataset_name|field_name}} — an ad-hoc reference to a field
        // from ANY dataset (not just the report's own), by data source + dataset name.
        // Interim implementation: resolves to that field's value on the FIRST row
        // returned (no join key) — good enough for single-row lookup datasets; a real
        // join/key-based lookup is future work.
        $text = preg_replace_callback('/\{\{DATA:([^:{}|]+):([^{}|]+)\|([^{}]+)\}\}/', function ($m) {
            return $this->resolveDataField(trim($m[1]), trim($m[2]), trim($m[3]));
        }, $text);

        // {{API:api name|field}} — an ad-hoc reference to one field of an API-type
        // data source's response (single GET to its base URL, no dataset needed).
        $text = preg_replace_callback('/\{\{API:([^{}|]+)\|([^{}]+)\}\}/', function ($m) {
            return $this->resolveApiField(trim($m[1]), trim($m[2]));
        }, $text);

        // {{SYSTEM:MENU|menu name}}, {{SYSTEM:PROJECT|project name}},
        // {{SYSTEM:TEMPLATE|template name}}, {{SYSTEM:API|api name:field}}
        $text = preg_replace_callback('/\{\{SYSTEM:(MENU|PROJECT|TEMPLATE|API)\|([^{}]+)\}\}/', function ($m) {
            return $this->resolveSystemSub($m[1], trim($m[2]));
        }, $text);

        // SYSTEM constants
        $systemConstants = Constant::where('scope', 'system')->get()->keyBy('key');
        $text = preg_replace_callback('/\{\{SYSTEM:([A-Z0-9_]+)(?::([^}]*))?\}\}/', function ($m) use ($user, $systemConstants, $projectId, $row, $reportName) {
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
                'USER_TYPE'     => $user?->user_type ?? '',
                'ROLES'         => $user ? $user->roles()->pluck('name')->implode(', ') : '',
                'REPORT_NAME'   => $reportName ?? '',
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

    private function resolveDataField(string $sourceName, string $datasetName, string $fieldName): string
    {
        try {
            $source = DataSource::where('name', $sourceName)->first();
            if (! $source) {
                return '';
            }
            $dataset = Dataset::where('data_source_id', $source->id)->where('name', $datasetName)->first();
            if (! $dataset) {
                return '';
            }
            $result = $this->connector->run($dataset, [], 1, 1);

            return (string) ($result['rows'][0][$fieldName] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveApiField(string $apiName, string $field): string
    {
        try {
            $source = DataSource::where('name', $apiName)->whereIn('type', DataSource::API_TYPES)->first();
            if (! $source) {
                return '';
            }
            $json = $this->connector->callApiSource($source);

            return (string) ($json[$field] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveSystemSub(string $subtype, string $value): string
    {
        try {
            switch ($subtype) {
                case 'MENU':
                    return (string) (Menu::where('label', $value)->first()?->route ?? '');
                case 'PROJECT':
                    return (string) (Project::where('name', $value)->first()?->code ?? '');
                case 'TEMPLATE':
                    $template = Template::where('name', $value)->first();

                    return (string) ($template?->body ?: $template?->header ?: '');
                case 'API':
                    if (! str_contains($value, ':')) {
                        return '';
                    }
                    [$apiName, $field] = array_map('trim', explode(':', $value, 2));

                    return $this->resolveApiField($apiName, $field);
                default:
                    return '';
            }
        } catch (\Throwable) {
            return '';
        }
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
