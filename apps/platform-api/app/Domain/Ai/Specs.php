<?php

namespace App\Domain\Ai;

/**
 * JSON Schemas the model must answer with (structured outputs). They describe *intent* in a
 * compact vocabulary; the model never emits component trees, ids or commands. The deterministic
 * AppSpecCompiler turns them into allowlisted typed commands, which the canonical validator checks.
 * Lengths/counts are enforced by SpecNormalizer (kept out of the schema for provider compatibility).
 */
final class Specs
{
    public const SECTION_KINDS = ['hero', 'features', 'text', 'cta', 'form', 'table', 'faq', 'list', 'contact'];

    public const FIELD_TYPES = ['text', 'longtext', 'number', 'integer', 'boolean', 'date', 'datetime', 'email', 'phone', 'url', 'select'];

    public const FONTS = ['Kanit', 'Sarabun', 'Prompt', 'Noto Sans Thai', 'system'];

    public const EDIT_OPS = ['setText', 'setStyle', 'setTheme', 'addSection', 'removeComponent', 'addField', 'renamePage'];

    private static function str(): array
    {
        return ['type' => 'string'];
    }

    private static function strList(): array
    {
        return ['type' => 'array', 'items' => ['type' => 'string']];
    }

    private static function obj(array $props): array
    {
        return ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];
    }

    public static function section(): array
    {
        return self::obj([
            'kind' => ['type' => 'string', 'enum' => self::SECTION_KINDS],
            'heading' => self::str(),
            'text' => self::str(),
            'items' => ['type' => 'array', 'items' => self::obj(['title' => self::str(), 'text' => self::str()])],
            'buttonLabel' => self::str(),
            'buttonHref' => self::str(),
            'entity' => self::str(),
            'fields' => self::strList(),
        ]);
    }

    public static function field(): array
    {
        return self::obj([
            'name' => self::str(),
            'label' => self::str(),
            'type' => ['type' => 'string', 'enum' => self::FIELD_TYPES],
            'required' => ['type' => 'boolean'],
            'unique' => ['type' => 'boolean'],
            'options' => self::strList(),
        ]);
    }

    public static function plan(): array
    {
        $actions = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['create', 'read', 'update', 'delete']]];

        return self::obj([
            'summary' => self::str(),
            'confidence' => ['type' => 'number'],
            'missingRequirements' => self::strList(),
            'assumptions' => self::strList(),
            'siteName' => self::str(),
            'theme' => self::obj([
                'primary' => self::str(), 'secondary' => self::str(), 'accent' => self::str(),
                'headingFont' => ['type' => 'string', 'enum' => self::FONTS],
                'bodyFont' => ['type' => 'string', 'enum' => self::FONTS],
            ]),
            'pages' => ['type' => 'array', 'items' => self::obj([
                'name' => self::str(), 'path' => self::str(), 'title' => self::str(), 'description' => self::str(),
                'sections' => ['type' => 'array', 'items' => self::section()],
            ])],
            'entities' => ['type' => 'array', 'items' => self::obj([
                'name' => self::str(), 'label' => self::str(),
                'fields' => ['type' => 'array', 'items' => self::field()],
            ])],
            'permissions' => ['type' => 'array', 'items' => self::obj(['entity' => self::str(), 'public' => $actions, 'member' => $actions])],
            'workflows' => ['type' => 'array', 'items' => self::obj([
                'name' => self::str(), 'entity' => self::str(),
                'event' => ['type' => 'string', 'enum' => ['record.created', 'record.updated']],
                'setFieldName' => self::str(), 'setFieldValue' => self::str(),
                'emailTo' => self::str(), 'emailSubject' => self::str(), 'emailBody' => self::str(),
            ])],
        ]);
    }

    public static function edit(): array
    {
        return self::obj([
            'summary' => self::str(),
            'operations' => ['type' => 'array', 'items' => self::obj([
                'op' => ['type' => 'string', 'enum' => self::EDIT_OPS],
                'targetId' => self::str(),
                'key' => self::str(),
                'value' => self::str(),
                'breakpoint' => ['type' => 'string', 'enum' => ['base', 'tablet', 'mobile']],
                'section' => ['anyOf' => [self::section(), ['type' => 'null']]],
                'field' => ['anyOf' => [self::field(), ['type' => 'null']]],
            ])],
        ]);
    }
}
