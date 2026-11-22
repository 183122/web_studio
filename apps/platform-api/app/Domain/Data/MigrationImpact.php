<?php

namespace App\Domain\Data;

/**
 * "Migration preview" for a proposed model change: what existing records would become invalid.
 * Nothing is ever rewritten or deleted automatically (safe evolution): removed fields keep their
 * stored values, and invalid values are flagged in the data view until edited.
 */
class MigrationImpact
{
    public function __construct(private readonly RecordService $records) {}

    public function analyze(DataContext $ctx, array $current, array $proposed): array
    {
        $currentFields = DataContext::fields($current);
        $proposedFields = DataContext::fields($proposed);
        $total = 0;
        $invalid = [];
        $populatedRemoved = [];
        $duplicates = [];
        $seen = [];

        $this->records->scope($ctx, $current)->orderBy('id')->chunk(500, function ($chunk) use (&$total, &$invalid, &$populatedRemoved, &$duplicates, &$seen, $currentFields, $proposedFields) {
            foreach ($chunk as $record) {
                $total++;
                $values = $record->values();
                foreach ($proposedFields as $id => $f) {
                    $value = $values[$id] ?? null;
                    if (FieldValue::validate($f, $value) !== null) {
                        $invalid[$id] = ($invalid[$id] ?? 0) + 1;
                    }
                    if ($f['unique'] && $value !== null) {
                        $key = mb_strtolower(trim(is_scalar($value) ? (string) $value : json_encode($value)));
                        if (isset($seen[$id][$key])) {
                            $duplicates[$id] = ($duplicates[$id] ?? 0) + 1;
                        }
                        $seen[$id][$key] = true;
                    }
                }
                foreach ($currentFields as $id => $f) {
                    if (! isset($proposedFields[$id]) && ($values[$id] ?? null) !== null) {
                        $populatedRemoved[$id] = ($populatedRemoved[$id] ?? 0) + 1;
                    }
                }
            }
        });

        $fields = [];
        foreach ($proposedFields as $id => $f) {
            $was = $currentFields[$id] ?? null;
            $fields[] = [
                'fieldId' => $id,
                'name' => $f['name'],
                'change' => $was === null ? 'added' : ($was == $f ? 'unchanged' : 'modified'),
                'invalidRecords' => $invalid[$id] ?? 0,
                'duplicateValues' => $duplicates[$id] ?? 0,
            ];
        }
        foreach ($currentFields as $id => $f) {
            if (! isset($proposedFields[$id])) {
                $fields[] = ['fieldId' => $id, 'name' => $f['name'], 'change' => 'removed', 'recordsWithData' => $populatedRemoved[$id] ?? 0];
            }
        }
        $risky = array_sum($invalid) + array_sum($duplicates) + array_sum($populatedRemoved);

        return ['totalRecords' => $total, 'requiresConfirmation' => $risky > 0, 'fields' => $fields];
    }
}
