<?php

namespace App\Services\Reporting;

use App\Services\Ai\AiProvider;
use Illuminate\Http\UploadedFile;

// Shared AI "edit this report definition via a plain-language instruction"
// logic — used by both ReportController::generateFromPrompt() (editing a
// report directly) and SavedViewController (a viewer prompt-editing their own
// personal copy, without touching the source report). Everything here is
// pure definition-in, definition-out; callers own persistence/audit/history.
class DefinitionPromptEditor
{
    // Thrown when the AI call fails or its response can't be parsed as JSON —
    // callers catch this and turn it into their own HTTP error response.
    public function apply(array $existingDefinition, string $prompt, ?UploadedFile $file, string $model, AiProvider $ai): array
    {
        $genOptions = ['system' => $this->systemPrompt(), 'temperature' => 0.2];
        $attachmentNote = '';
        if ($file) {
            [$genOptions, $attachmentNote] = $this->attachFileToPrompt($file, $genOptions);
        }

        $userMessage = "Current definition:\n" . json_encode($existingDefinition, JSON_PRETTY_PRINT)
            . "\n\nInstruction: {$prompt}" . $attachmentNote;

        $raw = $ai->generate($model, $userMessage, $genOptions);
        $decoded = $this->extractJsonObject($raw);
        if ($decoded === null) {
            throw new \RuntimeException('AI_RESPONSE_INVALID');
        }

        $decoded['columns'] = $this->guardColumns($existingDefinition['columns'] ?? [], $decoded['columns'] ?? [], $prompt);
        $decoded['prompt'] = $prompt;
        $decoded['fixed_parameters_enabled'] = $existingDefinition['fixed_parameters_enabled'] ?? [];
        $decoded['custom_parameters'] = $existingDefinition['custom_parameters'] ?? [];
        $decoded['require_parameter_screen'] = $existingDefinition['require_parameter_screen'] ?? true;
        $decoded['filter_condition'] = $existingDefinition['filter_condition'] ?? '';
        $promptHistory = (array) ($existingDefinition['prompt_history'] ?? []);
        $promptHistory[] = ['text' => $prompt, 'at' => now()->toIso8601String()];
        $decoded['prompt_history'] = $promptHistory;

        return $decoded;
    }

    private function systemPrompt(): string
    {
        return <<<'SYSTEM'
You edit a report definition JSON for a reporting tool. The renderer ONLY understands these keys — do
not invent other keys, they will be silently ignored:

- type: one of table | grouped | kpi | matrix | chart | document
- columns: array of {field, label, format, align, value_map, calc}.
    - field: the dataset column name this report column reads from (or, for a calculated column, a NEW
      unique name you invent — see "calc" below).
    - label: the column header text shown to the user.
    - format: one of:
        text
        number       — trims trailing zeros, e.g. 40.80 -> "40.8", 420.00 -> "420"
        number_fixed — ALWAYS exactly 2 decimals with a thousands separator, e.g. "9,999.99" or "420.00".
                       Use this whenever the instruction gives a fixed-decimal example like "9,999.99",
                       or says "2 decimal places" / "always show cents".
        currency     — same as number_fixed but prefixed "RM ", e.g. "RM 9,999.99"
        percent      — 1 decimal place with a % sign
        date (yyyy-mm-dd) | date_dmy (dd/mm/yyyy) | date_mdy (mm/dd/yyyy) | datetime | datetime_dmy
      A request like "change X to dd/mm/yyyy" or "format X as 9,999.99" means: find the column whose
      field matches X in the CURRENT columns array, and change ONLY that column's "format" — do not touch
      any other column.
    - align: one of left | right | center — column text alignment. "Right justify" / "align right" means
      set this to "right" (numbers are commonly right-aligned).
    - value_map: an object mapping the RAW stored value (as a string) to a display label — this is how
      you turn a raw code into a human label, e.g. status "0" meaning "Active" and anything else meaning
      "Inactive": {"0":"Active","*":"Inactive"} ("*" is the fallback for any value not explicitly listed).
      Use value_map whenever the instruction describes turning a raw value into a different label/word
      based on its value (active/inactive, yes/no, status codes, etc) — this is NOT a "format", it's a
      value_map.
    - calc: a simple arithmetic expression string over OTHER existing field names, e.g. "qty * price" or
      "(price - discount) * qty". Supports + - * / and parentheses only (no functions). Use this when the
      instruction asks to add a computed/total column derived from other columns. When adding a calc
      column, invent a new "field" name for it (e.g. "total_amount") and give it a clear "label". A calc
      expression may ALSO reference a stored constant with {{GLOBAL|KEY}} / {{SYSTEM|KEY}} /
      {{PROJECT|KEY}} (uppercase key) instead of a hardcoded number — e.g. "jumlah_bayaran * {{GLOBAL|SST}}"
      for "SST = jumlah_bayaran times the SST constant". Use this whenever the instruction names a
      constant/setting by name rather than giving a literal number.
  Adding a brand-new column (plain or calc), or removing one, is fine — see the CRITICAL RULE below for
  what "editing" a column vs "adding" one means for the REST of the columns array.
- groups: array of {field} — for type=grouped, groups rows by the first entry's field, with subtotals.
- aggregates: array of {field, fn, label} — fn is one of sum|avg|min|max|count. Used for KPI cards and
  group/grand totals.
- filters, sorts: array, kept as-is unless the instruction asks to change them.
- striped: boolean — true alternates row background colors (zebra striping). This is what "different
  row colors" / "alternate row white and gray" means.
- show_row_number: boolean — true adds a leading "#" column numbering each row 1, 2, 3, ... This is
  what "add numbering" / "row number column" means.
- conditional: array of rules, each: {field, op, value, style: {color, background}}. op is one of
  = | != | > | < | >= | <=. color/background are each one of red|green|amber|gray — this is how you
  color a column's text or cell background based on that row's value (e.g. "make font red when type is
  Commercial" -> {"field":"type","op":"=","value":"Commercial","style":{"color":"red"}}).

CRITICAL RULE — you are EDITING, not rewriting: every field present in the CURRENT definition's
"columns" array MUST still be present in your output, in the same order, UNLESS the instruction
explicitly says to remove one. If the instruction only asks to change formatting, value labels
(value_map), coloring, sorting, striping, numbering, or add ONE new/calculated column, then every other
column must be copied over unchanged — never drop, shorten, or regenerate the columns list from scratch
just because one column changed. Adding a new column means APPENDING to the existing list, not replacing
it. The same "only touch what's asked" rule applies to every other key: copy everything else
byte-for-byte from the current definition.

You will be given the CURRENT definition JSON and an instruction describing a change. The instruction
may be accompanied by an attached image (e.g. a screenshot/mockup of how the report should look) or an
attached document's extracted text (e.g. a spec describing the columns/layout needed) — read it and
incorporate what it shows/describes into the definition you produce, same as if it were written in the
instruction itself. Return ONLY the complete, updated definition as valid JSON — no markdown fences, no
explanation, no extra keys beyond the ones listed above (plus whatever untouched keys were already
present in the current definition).
SYSTEM;
    }

    // Turn an uploaded prompt attachment into either a vision "image" generate()
    // option (jpg/png/webp) or extracted text appended to the prompt (pdf via
    // smalot/pdfparser; txt/md/csv read as-is).
    private function attachFileToPrompt(UploadedFile $file, array $genOptions): array
    {
        $mime = (string) $file->getMimeType();

        if (str_starts_with($mime, 'image/')) {
            $genOptions['image'] = [
                'data' => base64_encode(file_get_contents($file->getRealPath())),
                'mime' => $mime,
            ];

            return [$genOptions, "\n\n(An image is attached — use it as the visual reference for this change.)"];
        }

        if ($mime === 'application/pdf') {
            $text = (new \Smalot\PdfParser\Parser())->parseFile($file->getRealPath())->getText();

            return [$genOptions, "\n\nAttached document content:\n" . mb_substr(trim($text), 0, 8000)];
        }

        // txt / md / csv
        return [$genOptions, "\n\nAttached document content:\n" . mb_substr(trim((string) file_get_contents($file->getRealPath())), 0, 8000)];
    }

    // Safety net against the AI silently dropping unrelated columns when asked
    // for an unrelated change (e.g. "format the date column as dd/mm/yyyy"
    // sometimes regenerates the whole columns array from scratch instead of
    // editing just that one field). Unless the instruction clearly names a
    // column, any column present before but missing from the AI's response is
    // restored (keeping the AI's edits to columns it DID keep).
    private function guardColumns(array $existingColumns, array $newColumns, string $prompt): array
    {
        if (! $existingColumns) {
            return $newColumns;
        }

        $byField = collect($newColumns)->filter(fn ($c) => ! empty($c['field']))->keyBy('field');
        $existingFields = collect($existingColumns)->pluck('field')->filter()->all();
        $promptLower = strtolower($prompt);

        $kept = collect($existingColumns)->filter(function ($c) use ($byField, $promptLower) {
            $field = $c['field'] ?? null;
            if ($field && $byField->has($field)) {
                return true;
            }
            $mentioned = false;
            foreach ([$field, $c['label'] ?? null] as $name) {
                if ($name && str_contains($promptLower, strtolower((string) $name))) {
                    $mentioned = true;
                    break;
                }
            }

            return ! $mentioned;
        })->map(fn ($c) => $byField->get($c['field'] ?? null, $c));

        $added = collect($newColumns)->filter(fn ($c) => ! empty($c['field']) && ! in_array($c['field'], $existingFields, true));

        return $kept->concat($added)->values()->all();
    }

    // AI models frequently wrap JSON in markdown fences or add stray prose even
    // when told not to — tolerate both instead of hard-failing on the first
    // json_decode() attempt.
    private function extractJsonObject(string $raw): ?array
    {
        $trimmed = trim($raw);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $trimmed, $m)) {
            $trimmed = trim($m[1]);
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $trimmed, $m)) {
            $decoded = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
