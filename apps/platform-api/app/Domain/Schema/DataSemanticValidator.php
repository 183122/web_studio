<?php

namespace App\Domain\Schema;

use App\Domain\Data\FieldValue;

/** Mirror of packages/app-schema/src/semantic-data.ts. */
final class DataSemanticValidator
{
    private const TEXT_LIKE = ['text', 'longtext', 'email', 'phone', 'url'];

    private const NUMERIC = ['number', 'integer'];

    private const VALUE_OPS = ['eq', 'neq', 'contains', 'gt', 'lt'];

    private static function singleLine(string $v): bool
    {
        return preg_match(Rules::NEWLINE, $v) !== 1 && preg_match(Rules::FORBIDDEN_CONTROL, $v) !== 1;
    }

    /**
     * @param  array<string,string>  $parentOf  childId => parentId
     * @return list<array{path:string,code:string,message:string}>
     */
    public static function validate(array $doc, array $parentOf): array
    {
        $issues = [];
        $add = static function (string $path, string $code, string $message) use (&$issues): void {
            $issues[] = ['path' => $path, 'code' => $code, 'message' => $message];
        };

        $models = [];
        $modelNames = [];
        $fieldOwner = [];
        foreach ($doc['dataModels'] as $i => $m) {
            $base = "/dataModels/{$i}";
            if (isset($models[$m['id']])) {
                $add("{$base}/id", 'duplicate_model', 'duplicate model id');
            }
            if (isset($modelNames[$m['name']])) {
                $add("{$base}/name", 'duplicate_model', "model name \"{$m['name']}\" is used twice");
            }
            $models[$m['id']] = $m;
            $modelNames[$m['name']] = true;
            if (! self::singleLine($m['label'])) {
                $add("{$base}/label", 'invalid_label', 'label must be a single line');
            }
            $names = [];
            foreach ($m['fields'] as $j => $f) {
                $fb = "{$base}/fields/{$j}";
                if (isset($fieldOwner[$f['id']]) || isset($models[$f['id']])) {
                    $add("{$fb}/id", 'duplicate_field', "field id \"{$f['id']}\" is not unique");
                }
                $fieldOwner[$f['id']] = $m['id'];
                if (isset($names[$f['name']])) {
                    $add("{$fb}/name", 'duplicate_field', "field name \"{$f['name']}\" is used twice");
                }
                $names[$f['name']] = true;
                if (in_array($f['name'], FieldValue::RESERVED_NAMES, true)) {
                    $add("{$fb}/name", 'reserved_name', "\"{$f['name']}\" is reserved");
                }
                if (! self::singleLine($f['label'])) {
                    $add("{$fb}/label", 'invalid_label', 'label must be a single line');
                }
                if ($f['type'] === 'select' && ! array_key_exists('options', $f)) {
                    $add("{$fb}/options", 'invalid_field', 'select fields need options');
                }
                if ($f['type'] !== 'select' && array_key_exists('options', $f)) {
                    $add("{$fb}/options", 'invalid_field', 'options are only allowed on select fields');
                }
                if (array_key_exists('maxLength', $f) && ! in_array($f['type'], self::TEXT_LIKE, true)) {
                    $add("{$fb}/maxLength", 'invalid_field', 'maxLength is only allowed on text fields');
                }
                if ((array_key_exists('min', $f) || array_key_exists('max', $f)) && ! in_array($f['type'], self::NUMERIC, true)) {
                    $add("{$fb}/min", 'invalid_field', 'min/max are only allowed on number fields');
                }
                if (array_key_exists('min', $f) && array_key_exists('max', $f) && $f['min'] > $f['max']) {
                    $add("{$fb}/min", 'invalid_field', 'min must not exceed max');
                }
                if ($f['unique'] && in_array($f['type'], ['longtext', 'boolean'], true)) {
                    $add("{$fb}/unique", 'invalid_field', "{$f['type']} fields cannot be unique");
                }
            }
            if (! in_array($m['displayFieldId'], array_column($m['fields'], 'id'), true)) {
                $add("{$base}/displayFieldId", 'missing_field', 'display field must be one of the model fields');
            }
        }

        $fieldOf = static function (string $modelId, string $fieldId) use ($models): ?array {
            foreach ($models[$modelId]['fields'] ?? [] as $f) {
                if ($f['id'] === $fieldId) {
                    return $f;
                }
            }

            return null;
        };

        $permitted = [];
        foreach ($doc['permissions'] as $i => $p) {
            if (! isset($models[$p['modelId']])) {
                $add("/permissions/{$i}/modelId", 'missing_model', 'permission refers to an unknown model');
            }
            if (isset($permitted[$p['modelId']])) {
                $add("/permissions/{$i}/modelId", 'duplicate_permission', 'only one permission entry per model');
            }
            $permitted[$p['modelId']] = true;
        }
        foreach ($doc['dataModels'] as $i => $m) {
            if (! isset($permitted[$m['id']])) {
                $add("/dataModels/{$i}", 'missing_permission', "model \"{$m['name']}\" has no permission entry");
            }
        }

        $workflowIds = [];
        foreach ($doc['workflows'] as $i => $w) {
            $base = "/workflows/{$i}";
            if (isset($workflowIds[$w['id']])) {
                $add("{$base}/id", 'duplicate_workflow', 'duplicate workflow id');
            }
            $workflowIds[$w['id']] = true;
            $model = $models[$w['trigger']['modelId']] ?? null;
            if ($model === null) {
                $add("{$base}/trigger/modelId", 'missing_model', 'trigger refers to an unknown model');

                continue;
            }
            foreach ($w['conditions'] as $j => $c) {
                if ($fieldOf($model['id'], $c['fieldId']) === null) {
                    $add("{$base}/conditions/{$j}/fieldId", 'missing_field', 'condition refers to a field outside the trigger model');
                }
                $hasValue = array_key_exists('value', $c) && $c['value'] !== null;
                $valueOp = in_array($c['op'], self::VALUE_OPS, true);
                if ($valueOp && ! $hasValue) {
                    $add("{$base}/conditions/{$j}/value", 'invalid_condition', "{$c['op']} needs a value");
                }
                if (! $valueOp && $hasValue) {
                    $add("{$base}/conditions/{$j}/value", 'invalid_condition', "{$c['op']} takes no value");
                }
                if (in_array($c['op'], ['gt', 'lt'], true) && ! (is_int($c['value'] ?? null) || is_float($c['value'] ?? null))) {
                    $add("{$base}/conditions/{$j}/value", 'invalid_condition', "{$c['op']} needs a number");
                }
            }
            foreach ($w['actions'] as $j => $a) {
                $ab = "{$base}/actions/{$j}";
                if ($a['type'] === 'record.setField') {
                    $field = $fieldOf($model['id'], $a['fieldId']);
                    if ($field === null) {
                        $add("{$ab}/fieldId", 'missing_field', 'action refers to a field outside the trigger model');
                    } else {
                        $err = FieldValue::validate(['required' => false] + $field, $a['value']);
                        if ($err !== null) {
                            $add("{$ab}/value", 'invalid_value', "value {$err}");
                        }
                    }
                } else {
                    if (preg_match(FieldValue::EMAIL_TARGET, $a['to']) === 1) {
                        $field = $fieldOf($model['id'], substr($a['to'], 6));
                        if ($field === null || $field['type'] !== 'email') {
                            $add("{$ab}/to", 'invalid_recipient', 'recipient field must be an e-mail field of the trigger model');
                        }
                    } elseif (preg_match(FieldValue::EMAIL, $a['to']) !== 1) {
                        $add("{$ab}/to", 'invalid_recipient', 'recipient must be an e-mail address or field:<emailFieldId>');
                    }
                    if (! self::singleLine($a['subject'])) {
                        $add("{$ab}/subject", 'invalid_text', 'subject must be a single line');
                    }
                    if (preg_match(Rules::FORBIDDEN_CONTROL, $a['body']) === 1) {
                        $add("{$ab}/body", 'invalid_text', 'body contains control characters');
                    }
                    $names = array_column($model['fields'], 'name');
                    foreach ([$a['subject'], $a['body']] as $text) {
                        preg_match_all(FieldValue::PLACEHOLDER, $text, $matches);
                        foreach ($matches[1] as $name) {
                            if (! in_array($name, $names, true)) {
                                $add($ab, 'unknown_placeholder', "{{{$name}}} is not a field of {$model['name']}");
                            }
                        }
                    }
                }
            }
        }

        $components = $doc['components'];
        $nearestForm = static function (string $id) use ($parentOf, $components): ?array {
            $cur = $parentOf[$id] ?? null;
            while ($cur !== null) {
                if (($components[$cur]['type'] ?? null) === 'Form') {
                    return $components[$cur];
                }
                $cur = $parentOf[$cur] ?? null;
            }

            return null;
        };
        foreach ($components as $node) {
            $base = "/components/{$node['id']}";
            $type = $node['type'];
            if ($type === 'Form') {
                $modelId = $node['props']['modelId'] ?? '';
                if (is_string($modelId) && $modelId !== '' && ! isset($models[$modelId])) {
                    $add("{$base}/props/modelId", 'missing_model', 'form refers to an unknown model');
                }
                if ($nearestForm($node['id']) !== null) {
                    $add($base, 'nested_form', 'forms cannot be nested');
                }
            } elseif ($type === 'FormField') {
                $form = $nearestForm($node['id']);
                $fieldId = $node['props']['fieldId'] ?? '';
                if ($form === null) {
                    $add($base, 'field_outside_form', 'form fields must be placed inside a form');
                } elseif (is_string($fieldId) && $fieldId !== '') {
                    $modelId = is_string($form['props']['modelId'] ?? null) ? $form['props']['modelId'] : '';
                    if ($modelId === '' || ($fieldOwner[$fieldId] ?? null) !== $modelId) {
                        $add("{$base}/props/fieldId", 'missing_field', 'field does not belong to the form model');
                    }
                }
            } elseif ($type === 'DataTable') {
                $modelId = is_string($node['props']['modelId'] ?? null) ? $node['props']['modelId'] : '';
                if ($modelId !== '' && ! isset($models[$modelId])) {
                    $add("{$base}/props/modelId", 'missing_model', 'table refers to an unknown model');
                }
                $ids = is_array($node['props']['fieldIds'] ?? null) ? $node['props']['fieldIds'] : [];
                if (count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
                    $add("{$base}/props/fieldIds", 'duplicate_field', 'columns must be unique');
                }
                foreach ($ids as $fid) {
                    if (is_string($fid) && ($fieldOwner[$fid] ?? null) !== $modelId) {
                        $add("{$base}/props/fieldIds", 'missing_field', "column \"{$fid}\" does not belong to the table model");
                    }
                }
            }
        }

        return $issues;
    }

    /** @return list<string> */
    public static function modelReferences(array $doc, string $modelId): array
    {
        $refs = [];
        foreach ($doc['components'] as $n) {
            if (in_array($n['type'], ['Form', 'DataTable'], true) && ($n['props']['modelId'] ?? null) === $modelId) {
                $refs[] = $n['id'];
            }
        }
        foreach ($doc['workflows'] as $w) {
            if ($w['trigger']['modelId'] === $modelId) {
                $refs[] = $w['id'];
            }
        }

        return $refs;
    }
}
