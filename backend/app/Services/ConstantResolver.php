<?php

namespace App\Services;

use App\Models\Constant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Resolves {{SYSTEM:KEY}}, {{GLOBAL:KEY}}, {{PROJECT:KEY}} placeholders in
// template sections, report definitions, and datasource queries.
class ConstantResolver
{
    public function resolve(string $text, ?int $projectId = null, ?User $user = null): string
    {
        // SYSTEM constants
        $text = preg_replace_callback('/\{\{SYSTEM:([A-Z0-9_]+)(?::([^}]*))?\}\}/', function ($m) use ($user) {
            $key    = $m[1];
            $format = $m[2] ?? null;

            // Allow inline format override, else fall back to stored format.
            if (! $format) {
                $stored = Constant::where('scope', 'system')->where('key', $key)->value('format');
                $format = $stored;
            }

            return match ($key) {
                'DATE'      => now()->format($format ?? 'd/m/Y'),
                'TIME'      => now()->format($format ?? 'H:i'),
                'DATETIME'  => now()->format($format ?? 'd/m/Y H:i'),
                'YEAR'      => now()->format($format ?? 'Y'),
                'USER_NAME' => $user?->name ?? '',
                'USER_EMAIL'=> $user?->email ?? '',
                'PAGE'      => '{{PAGE}}', // kept for PDF renderer
                default     => '',
            };
        }, $text);

        // GLOBAL constants
        $globals = Constant::where('scope', 'global')->pluck('value', 'key');
        $text = preg_replace_callback('/\{\{GLOBAL:([A-Z0-9_]+)\}\}/', function ($m) use ($globals) {
            return $globals[$m[1]] ?? '';
        }, $text);

        // PROJECT constants
        if ($projectId) {
            $projects = Constant::where('scope', 'project')
                ->where('project_id', $projectId)
                ->pluck('value', 'key');
            $text = preg_replace_callback('/\{\{PROJECT:([A-Z0-9_]+)\}\}/', function ($m) use ($projects) {
                return $projects[$m[1]] ?? '';
            }, $text);
        }

        return $text;
    }
}
