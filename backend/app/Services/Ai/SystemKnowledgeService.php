<?php

namespace App\Services\Ai;

use App\Models\DataSource;
use App\Models\Role;

/**
 * M3 (FR-M3.2b) — turn AIRR's own structured knowledge into descriptive,
 * embeddable text so the RAG/Text-to-SQL layer can ground answers in the actual
 * system (schema, RBAC, …). Output is Markdown so ChunkerService splits it by
 * logical section (one heading per table/role).
 */
class SystemKnowledgeService
{
    /**
     * Database schema of a data source (FR-M3.2b · feeds semantic schema cache).
     * Reuses the M2 schema_cache — no live DB hit.
     */
    public function dbSchema(DataSource $source): string
    {
        $tables = $source->schema_cache ?? [];
        if (! $tables) {
            return "# Database schema: {$source->name}\n\nNo schema has been introspected yet.";
        }

        $out = ["# Database schema: {$source->name} ({$source->type})", ''];
        foreach ($tables as $t) {
            $name = $t['table'] ?? 'unknown';
            $cols = $t['columns'] ?? [];
            $out[] = "## Table: {$name}";
            $colLine = array_map(fn ($c) => ($c['name'] ?? '?') . ' (' . ($c['type'] ?? 'unknown') . ')', $cols);
            $out[] = 'Columns: ' . (implode(', ', $colLine) ?: 'none');
            $out[] = "Table `{$name}` has " . count($cols) . ' column(s).';
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * RBAC model: roles, their clearance, and the permissions each grants
     * (FR-M3.2b). Helps the AI reason about who can see/do what.
     */
    public function rbac(): string
    {
        $roles = Role::with('permissions:id,key')->get();
        if ($roles->isEmpty()) {
            return "# RBAC model\n\nNo roles defined.";
        }

        $out = ['# RBAC model (roles & permissions)', ''];
        foreach ($roles as $role) {
            $perms = $role->permissions->pluck('key')->all();
            $out[] = "## Role: {$role->name} ({$role->slug})";
            if ($role->description) {
                $out[] = $role->description;
            }
            $out[] = "Clearance level: {$role->clearance}.";
            $out[] = 'Permissions: ' . (implode(', ', $perms) ?: 'none') . '.';
            $out[] = '';
        }

        return implode("\n", $out);
    }
}
