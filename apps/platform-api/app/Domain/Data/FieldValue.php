<?php

namespace App\Domain\Data;

use App\Domain\Schema\Rules;

/** Mirror of validateFieldValue() in packages/app-schema/src/rules.ts (parity: fixtures/field-values.json). */
final class FieldValue
{
    public const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    public const DATETIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/';

    public const EMAIL = '/^[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,253}\.[A-Za-z]{2,63}$/';

    public const PHONE = '/^[0-9+\-() ]{3,32}$/';

    public const EMAIL_TARGET = '/^field:[A-Za-z][A-Za-z0-9_-]{0,63}$/';

    public const PLACEHOLDER = '/\{\{\s*([a-z][a-z0-9_]{0,39})\s*\}\}/';

    public const RESERVED_NAMES = ['id', 'created_at', 'updated_at', 'version'];

    private static function isRealDate(string $value): bool
    {
        [$y, $m, $d] = array_map('intval', explode('-', substr($value, 0, 10)));

        return checkdate($m, $d, $y) && $y >= 1900 && $y <= 2200;
    }

    public static function validate(array $field, mixed $value): ?string
    {
        if ($value === null) {
            return ($field['required'] ?? false) ? 'required' : null;
        }
        $type = $field['type'];
        switch ($type) {
            case 'text':
            case 'longtext':
                if (! is_string($value)) {
                    return 'must be text';
                }
                $max = $field['maxLength'] ?? ($type === 'text' ? 255 : 10000);
                if (mb_strlen($value, 'UTF-8') > $max) {
                    return "must be at most {$max} characters";
                }
                if (! mb_check_encoding($value, 'UTF-8') || preg_match(Rules::FORBIDDEN_CONTROL, $value) === 1) {
                    return 'contains control characters';
                }
                if ($type === 'text' && preg_match(Rules::NEWLINE, $value) === 1) {
                    return 'must be a single line';
                }

                return null;
            case 'number':
            case 'integer':
                if (! is_int($value) && ! is_float($value)) {
                    return 'must be a number';
                }
                if (! is_finite((float) $value)) {
                    return 'must be a number';
                }
                if ($type === 'integer' && ! (is_int($value) || floor($value) === $value)) {
                    return 'must be a whole number';
                }
                if (isset($field['min']) && $value < $field['min']) {
                    return "must be at least {$field['min']}";
                }
                if (isset($field['max']) && $value > $field['max']) {
                    return "must be at most {$field['max']}";
                }

                return null;
            case 'boolean':
                return is_bool($value) ? null : 'must be true or false';
            case 'date':
                return is_string($value) && preg_match(self::DATE, $value) === 1 && self::isRealDate($value) ? null : 'must be a date (YYYY-MM-DD)';
            case 'datetime':
                return is_string($value) && preg_match(self::DATETIME, $value) === 1 && self::isRealDate($value) ? null : 'must be a date and time';
            case 'email':
                return is_string($value) && strlen($value) <= 254 && preg_match(self::EMAIL, $value) === 1 ? null : 'must be an e-mail address';
            case 'phone':
                return is_string($value) && preg_match(self::PHONE, $value) === 1 ? null : 'must be a phone number';
            case 'url':
                return is_string($value) && preg_match('/^https?:\/\//', $value) === 1 && Rules::isSafeHref($value) ? null : 'must be an http(s) URL';
            case 'select':
                return is_string($value) && in_array($value, $field['options'] ?? [], true) ? null : 'is not one of the options';
            default:
                return 'has an unsupported type';
        }
    }
}
