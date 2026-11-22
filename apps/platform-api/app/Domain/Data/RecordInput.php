<?php

namespace App\Domain\Data;

/**
 * Normalises raw input (HTML forms, CSV, JSON) to typed values, then validates with FieldValue.
 * '' becomes null; numeric strings become numbers; "true"/"1"/"on" become booleans.
 */
final class RecordInput
{
    public static function normalize(array $field, mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return null;
            }
            switch ($field['type']) {
                case 'number':
                    return is_numeric($trimmed) ? $trimmed + 0 : $raw;
                case 'integer':
                    return preg_match('/^-?\d{1,15}$/', $trimmed) === 1 ? (int) $trimmed : $raw;
                case 'boolean':
                    return match (strtolower($trimmed)) {
                        'true', '1', 'on', 'yes', 'ใช่' => true,
                        'false', '0', 'off', 'no', 'ไม่ใช่' => false,
                        default => $raw,
                    };
                case 'email':
                    return mb_strtolower($trimmed);
                case 'text':
                case 'phone':
                case 'url':
                case 'date':
                case 'datetime':
                case 'select':
                    return $trimmed;
                default:
                    return $raw; // longtext keeps its whitespace
            }
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $input  fieldId => raw
     * @param  array<string, mixed>|null  $existing  current values (update) or null (create)
     * @return array<string, mixed> full, validated values for the model's current fields
     */
    public static function validate(array $model, array $input, ?array $existing = null): array
    {
        $fields = DataContext::fields($model);
        $errors = [];
        foreach (array_keys($input) as $key) {
            if (! isset($fields[$key])) {
                $errors[(string) $key] = 'unknown field';
            }
        }
        $values = [];
        foreach ($fields as $id => $field) {
            $value = array_key_exists($id, $input)
                ? self::normalize($field, $input[$id])
                : ($existing[$id] ?? null);
            $err = FieldValue::validate($field, $value);
            if ($err !== null) {
                $errors[$id] = $err;
            }
            $values[$id] = $value;
        }
        if ($errors !== []) {
            throw DataException::invalid($errors);
        }

        return $values;
    }
}
