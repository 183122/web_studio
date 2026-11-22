<?php

namespace App\Domain\Integrations\Sheets;

use App\Domain\Data\Actor;
use App\Domain\Data\DataContext;
use App\Domain\Data\RecordInput;
use App\Domain\Data\RecordService;
use App\Domain\Integrations\Google\GoogleClient;
use App\Domain\Integrations\Google\SheetsApi;
use App\Models\AppRecord;
use App\Models\Project;
use App\Models\SheetSync;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Google Sheets ↔ app records (M23). The app database stays canonical; Sheets is a mirror/input.
 * - Rows are identified by a `_studio_id` column (written on push) or, for pulls, by a key field.
 * - Pull validates every row with the same rules as forms/CSV; invalid rows are reported, valid rows applied.
 * - Push updates only mapped cells of known rows and appends new records (other columns untouched).
 * - Two-way: hashes of the last synced values detect rows changed on both sides → conflict policy.
 */
class SheetSyncService
{
    public const ID_COLUMN = '_studio_id';

    public function __construct(
        private readonly GoogleClient $google,
        private readonly SheetsApi $sheets,
        private readonly RecordService $records,
    ) {}

    private static function hash(array $values): string
    {
        ksort($values);

        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE));
    }

    private static function cell(mixed $v): string|int|float
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'TRUE' : 'FALSE',
            is_int($v), is_float($v) => $v,
            is_array($v) => json_encode($v, JSON_UNESCAPED_UNICODE),
            default => (string) $v,
        };
    }

    /** Validates a mapping against the model; pulls need every required field mapped. */
    public static function assertMapping(array $model, array $mapping, ?string $keyFieldId, string $direction): void
    {
        $fields = DataContext::fields($model);
        $seenColumns = [];
        foreach ($mapping as $m) {
            if (! isset($fields[$m['fieldId']])) {
                throw ApiException::unprocessable('mapping_invalid', "ไม่มีฟิลด์ {$m['fieldId']} ในตาราง");
            }
            $col = trim($m['column']);
            if ($col === '' || $col === self::ID_COLUMN || isset($seenColumns[$col])) {
                throw ApiException::unprocessable('mapping_invalid', "ชื่อคอลัมน์ \"{$col}\" ซ้ำหรือใช้ไม่ได้");
            }
            $seenColumns[$col] = true;
        }
        $mapped = array_column($mapping, 'fieldId');
        if ($direction !== 'push') {
            $missing = array_values(array_filter($model['fields'], fn ($f) => $f['required'] && ! in_array($f['id'], $mapped, true)));
            if ($missing !== []) {
                throw ApiException::unprocessable('mapping_incomplete', 'ต้องจับคู่ฟิลด์ที่จำเป็น: '.implode(', ', array_column($missing, 'label')));
            }
        }
        if ($keyFieldId !== null && ! in_array($keyFieldId, $mapped, true)) {
            throw ApiException::unprocessable('mapping_invalid', 'ฟิลด์คีย์ต้องเป็นหนึ่งในฟิลด์ที่จับคู่');
        }
    }

    private function context(SheetSync $sync): array
    {
        $project = Project::query()->findOrFail($sync->project_id);
        $ctx = DataContext::forEnvironment($project, $sync->environment);

        return [$project, $ctx, $ctx->model($sync->model_id)];
    }

    private function actor(SheetSync $sync): Actor
    {
        return new Actor('integration', $sync->id, 'admin', null, $sync->created_by);
    }

    /** @return array{header: list<string>, rows: list<list<string>>} */
    private function read(SheetSync $sync, $connection): array
    {
        $values = $this->sheets->values($connection, $sync->spreadsheet_id, $sync->sheet_title);
        $header = array_map(fn ($h) => trim((string) $h), $values[0] ?? []);

        return ['header' => $header, 'rows' => array_slice($values, 1)];
    }

    private function appValues(AppRecord $record, array $mapping): array
    {
        $values = $record->values();
        $out = [];
        foreach ($mapping as $m) {
            $out[$m['fieldId']] = $values[$m['fieldId']] ?? null;
        }

        return $out;
    }

    /**
     * Sheet → app. Returns stats; with $dryRun nothing is written.
     *
     * @param  array<string, true>  $skipPush  filled with record ids whose conflict must not be pushed
     */
    public function pull(SheetSync $sync, bool $dryRun, array &$skipPush = []): array
    {
        [, $ctx, $model] = $this->context($sync);
        $connection = $this->google->connectionFor($ctx->project->workspace_id, 'sheets');
        ['header' => $header, 'rows' => $rows] = $this->read($sync, $connection);
        $stats = ['rows' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'conflicts' => 0, 'errors' => []];
        if ($header === []) {
            return $stats + ['note' => 'empty_sheet'];
        }
        $colIndex = array_flip($header);
        $missing = array_values(array_filter(array_column($sync->mapping, 'column'), fn ($c) => ! isset($colIndex[$c])));
        if ($missing !== []) {
            throw ApiException::unprocessable('columns_missing', 'ไม่พบคอลัมน์ในชีต: '.implode(', ', $missing), ['columns' => $missing]);
        }
        $idCol = $colIndex[self::ID_COLUMN] ?? null;
        $fields = DataContext::fields($model);
        $state = DB::table('sheet_sync_rows')->where('sync_id', $sync->id)->get()->keyBy('record_id');
        $twoWay = $sync->direction === 'both';
        $seenKeys = [];

        foreach ($rows as $i => $row) {
            $rowNumber = $i + 2;
            $input = [];
            foreach ($sync->mapping as $m) {
                $raw = $row[$colIndex[$m['column']]] ?? '';
                $input[$m['fieldId']] = $raw === '' ? null : $raw;
            }
            if (array_filter($input, fn ($v) => $v !== null) === []) {
                continue; // blank row
            }
            $stats['rows']++;
            try {
                $normalized = RecordInput::validate($model, $input);
            } catch (ApiException $e) {
                foreach ($e->details['fields'] ?? ['_' => $e->getMessage()] as $fieldId => $msg) {
                    $stats['errors'][] = ['row' => $rowNumber, 'field' => $fields[$fieldId]['label'] ?? (string) $fieldId, 'message' => is_array($msg) ? $msg[0] : $msg];
                }

                continue;
            }

            // Identify the record: _studio_id first, then the key field.
            $record = null;
            $sid = $idCol !== null ? trim((string) ($row[$idCol] ?? '')) : '';
            if ($sid !== '') {
                $record = $this->records->scope($ctx, $model, $sync->environment)->whereKey($sid)->first();
            }
            if ($record === null && $sync->key_field_id && ($normalized[$sync->key_field_id] ?? null) !== null) {
                $keyValue = $normalized[$sync->key_field_id];
                $dupKey = mb_strtolower(trim((string) (is_scalar($keyValue) ? $keyValue : json_encode($keyValue))));
                if (isset($seenKeys[$dupKey])) {
                    $stats['errors'][] = ['row' => $rowNumber, 'field' => $fields[$sync->key_field_id]['label'], 'message' => "ค่าคีย์ซ้ำกับแถว {$seenKeys[$dupKey]}"];

                    continue;
                }
                $seenKeys[$dupKey] = $rowNumber;
                $record = $this->records->scope($ctx, $model, $sync->environment)->where("data->{$sync->key_field_id}", $keyValue)->first();
            }

            // Compare only the mapped fields (validation may add defaults for unmapped ones).
            $normalized = array_intersect_key($normalized, $input) + array_map(fn () => null, $input);
            $sheetHash = self::hash($normalized);
            if ($record === null) {
                if (! $dryRun) {
                    try {
                        $record = $this->records->create($ctx, $model, $normalized, $this->actor($sync), 'sheets', $sync->environment, runWorkflows: false);
                    } catch (ApiException $e) {
                        foreach ($e->details['fields'] ?? ['_' => $e->getMessage()] as $fieldId => $msg) {
                            $stats['errors'][] = ['row' => $rowNumber, 'field' => $fields[$fieldId]['label'] ?? (string) $fieldId, 'message' => is_array($msg) ? $msg[0] : $msg];
                        }

                        continue;
                    }
                    $this->remember($sync, $record->id, self::hash($this->appValues($record->fresh(), $sync->mapping)), $sheetHash);
                }
                $stats['created']++;

                continue;
            }

            $current = $this->appValues($record, $sync->mapping);
            $appHash = self::hash($current);
            if ($appHash === $sheetHash) {
                $stats['unchanged']++;
                if (! $dryRun) {
                    $this->remember($sync, $record->id, $appHash, $sheetHash);
                }

                continue;
            }
            $prev = $state[$record->id] ?? null;
            if ($twoWay && $prev !== null) {
                $appChanged = $prev->app_hash !== $appHash;
                $sheetChanged = $prev->sheet_hash !== $sheetHash;
                if (! $sheetChanged) {
                    continue; // only the app changed → push will update the sheet
                }
                if ($appChanged) {
                    $stats['conflicts']++;
                    if ($sync->conflict_policy === 'skip') {
                        $skipPush[$record->id] = true;

                        continue;
                    }
                    if ($sync->conflict_policy === 'app_wins') {
                        continue; // push overwrites the sheet row
                    }
                }
            } elseif ($twoWay && $prev === null && $sync->conflict_policy === 'app_wins') {
                continue; // first two-way sync: app is canonical
            }
            if (! $dryRun) {
                try {
                    $record = $this->records->update($ctx, $model, $record, $normalized, null, $this->actor($sync), 'sheets', runWorkflows: false);
                } catch (ApiException $e) {
                    foreach ($e->details['fields'] ?? ['_' => $e->getMessage()] as $fieldId => $msg) {
                        $stats['errors'][] = ['row' => $rowNumber, 'field' => $fields[$fieldId]['label'] ?? (string) $fieldId, 'message' => is_array($msg) ? $msg[0] : $msg];
                    }

                    continue;
                }
                $this->remember($sync, $record->id, self::hash($this->appValues($record->fresh(), $sync->mapping)), $sheetHash);
            }
            $stats['updated']++;
        }
        $stats['errors'] = array_slice($stats['errors'], 0, 200);

        return $stats;
    }

    /** App → sheet: updates mapped cells of rows with a known `_studio_id`, appends the rest. */
    public function push(SheetSync $sync, array $skip = []): array
    {
        [, $ctx, $model] = $this->context($sync);
        $connection = $this->google->connectionFor($ctx->project->workspace_id, 'sheets');
        ['header' => $header, 'rows' => $rows] = $this->read($sync, $connection);
        $stats = ['records' => 0, 'appended' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

        // Ensure header columns exist (append missing ones at the end; never reorder the user's sheet).
        $wanted = array_merge([self::ID_COLUMN], array_column($sync->mapping, 'column'));
        $newHeader = $header;
        foreach ($wanted as $col) {
            if (! in_array($col, $newHeader, true)) {
                $newHeader[] = $col;
            }
        }
        if (count($newHeader) > SheetsApi::MAX_COLUMNS) {
            throw ApiException::unprocessable('too_many_columns', 'ชีตมีคอลัมน์มากเกินไป');
        }
        $updates = [];
        if ($newHeader !== $header) {
            $updates[] = ['range' => 'A1:'.SheetsApi::columnLetter(count($newHeader) - 1).'1', 'values' => [$newHeader]];
        }
        $colIndex = array_flip($newHeader);
        $rowOf = [];
        foreach ($rows as $i => $row) {
            $sid = trim((string) ($row[$colIndex[self::ID_COLUMN]] ?? ''));
            if ($sid !== '') {
                $rowOf[$sid] = $i;
            }
        }

        $append = [];
        $remember = [];
        $this->records->scope($ctx, $model, $sync->environment)->orderBy('created_at')->orderBy('id')->limit(SheetsApi::MAX_ROWS)->get()
            ->each(function (AppRecord $record) use ($sync, $skip, $rows, $rowOf, $colIndex, $newHeader, &$updates, &$append, &$stats, &$remember) {
                $stats['records']++;
                if (isset($skip[$record->id])) {
                    $stats['skipped']++;

                    return;
                }
                $values = $this->appValues($record, $sync->mapping);
                if (isset($rowOf[$record->id])) {
                    $i = $rowOf[$record->id];
                    $changed = false;
                    foreach ($sync->mapping as $m) {
                        $col = $colIndex[$m['column']];
                        $want = self::cell($values[$m['fieldId']]);
                        if ((string) ($rows[$i][$col] ?? '') !== (string) $want) {
                            $updates[] = ['range' => SheetsApi::columnLetter($col).($i + 2), 'values' => [[$want]]];
                            $changed = true;
                        }
                    }
                    $stats[$changed ? 'updated' : 'unchanged']++;
                } else {
                    $line = array_fill(0, count($newHeader), '');
                    $line[$colIndex[self::ID_COLUMN]] = $record->id;
                    foreach ($sync->mapping as $m) {
                        $line[$colIndex[$m['column']]] = self::cell($values[$m['fieldId']]);
                    }
                    $append[] = $line;
                    $stats['appended']++;
                }
                $h = self::hash($values);
                $remember[$record->id] = $h;
            });

        foreach (array_chunk($updates, 500) as $chunk) {
            $this->sheets->batchUpdate($connection, $sync->spreadsheet_id, $sync->sheet_title, $chunk);
        }
        foreach (array_chunk($append, 500) as $chunk) {
            $this->sheets->append($connection, $sync->spreadsheet_id, $sync->sheet_title, $chunk);
        }
        foreach ($remember as $id => $h) {
            $this->remember($sync, $id, $h, $h);
        }
        $stats['orphanRows'] = count(array_diff_key($rowOf, $remember)) - count(array_intersect_key($rowOf, $skip));

        return $stats;
    }

    private function remember(SheetSync $sync, string $recordId, string $appHash, string $sheetHash): void
    {
        DB::table('sheet_sync_rows')->upsert(
            [['sync_id' => $sync->id, 'record_id' => $recordId, 'app_hash' => $appHash, 'sheet_hash' => $sheetHash, 'created_at' => now(), 'updated_at' => now()]],
            ['sync_id', 'record_id'], ['app_hash', 'sheet_hash', 'updated_at'],
        );
    }
}
