<?php

namespace App\Domain\Data;

use App\Support\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * CSV export/import keyed by field *names*. Export neutralises spreadsheet formulas (CSV injection).
 * Import is all-or-nothing: any invalid row rolls back the whole file; dry run only reports.
 * Imports do not trigger workflows (no mass e-mails).
 */
class CsvService
{
    public const MAX_ROWS = 5000;

    public function __construct(private readonly RecordService $records) {}

    /** @return resource */
    public function export(DataContext $ctx, array $model, string $env = RecordService::ENV_PREVIEW)
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Thai correctly
        fputcsv($out, array_merge(['id'], array_column($model['fields'], 'name'), ['created_at', 'updated_at']), escape: '');
        $this->records->scope($ctx, $model, $env)->orderBy('created_at')->chunk(500, function ($chunk) use ($out, $model) {
            foreach ($chunk as $record) {
                $values = $record->values();
                $row = [$record->id];
                foreach ($model['fields'] as $f) {
                    $row[] = self::cell($values[$f['id']] ?? null);
                }
                $row[] = $record->created_at?->toIso8601String();
                $row[] = $record->updated_at?->toIso8601String();
                fputcsv($out, $row, escape: '');
            }
        });
        rewind($out);

        return $out;
    }

    public static function cell(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        $s = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;

        return preg_match('/^[=+\-@\t\r]/', $s) === 1 ? "'".$s : $s;
    }

    /** @return array{rows:int, created:int, dryRun:bool, errors:list<array{row:int, field:string, message:string}>} */
    public function import(DataContext $ctx, array $model, string $path, Actor $actor, bool $dryRun, string $env = RecordService::ENV_PREVIEW): array
    {
        $ctx->assertAllowed($model, $actor, 'create');
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh, escape: '');
        if ($header === false || $header === [null]) {
            throw ApiException::unprocessable('csv_empty', 'ไฟล์ CSV ว่างเปล่า');
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $byName = [];
        foreach ($model['fields'] as $f) {
            $byName[$f['name']] = $f['id'];
            $byName[$f['label']] = $f['id'];
        }
        $columns = [];
        $unknown = [];
        foreach ($header as $i => $h) {
            $h = trim((string) $h);
            if (isset($byName[$h])) {
                $columns[$i] = $byName[$h];
            } elseif (! in_array($h, ['id', 'created_at', 'updated_at', ''], true)) {
                $unknown[] = $h;
            }
        }
        if ($unknown !== []) {
            throw ApiException::unprocessable('csv_unknown_columns', 'พบคอลัมน์ที่ไม่มีในตาราง: '.implode(', ', $unknown), ['columns' => $unknown]);
        }

        $rows = [];
        while (($line = fgetcsv($fh, escape: '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw ApiException::unprocessable('csv_too_many_rows', 'นำเข้าได้สูงสุด '.self::MAX_ROWS.' แถวต่อครั้ง');
            }
            $input = [];
            foreach ($columns as $i => $fieldId) {
                $cell = $line[$i] ?? null;
                $input[$fieldId] = is_string($cell) && str_starts_with($cell, "'") && preg_match('/^\'[=+\-@]/', $cell) === 1 ? substr($cell, 1) : $cell;
            }
            $rows[] = $input;
        }
        fclose($fh);

        $errors = [];
        foreach ($rows as $n => $input) {
            try {
                RecordInput::validate($model, $input);
            } catch (ApiException $e) {
                foreach ($e->details['fields'] ?? [] as $fieldId => $msg) {
                    $errors[] = ['row' => $n + 2, 'field' => $fieldId, 'message' => $msg];
                }
            }
        }
        if ($dryRun || $errors !== []) {
            return ['rows' => count($rows), 'created' => 0, 'dryRun' => $dryRun, 'errors' => array_slice($errors, 0, 200)];
        }

        DB::transaction(function () use ($ctx, $model, $rows, $actor, $env, &$errors) {
            foreach ($rows as $n => $input) {
                try {
                    $this->records->create($ctx, $model, $input, $actor, 'import', $env, runWorkflows: false);
                } catch (ApiException $e) {
                    foreach ($e->details['fields'] ?? ['_' => $e->getMessage()] as $fieldId => $msg) {
                        $errors[] = ['row' => $n + 2, 'field' => (string) $fieldId, 'message' => $msg];
                    }
                    throw $e;
                }
            }
        });

        return ['rows' => count($rows), 'created' => count($rows), 'dryRun' => false, 'errors' => []];
    }
}
