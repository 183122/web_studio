<?php

namespace App\Domain\Schema;

/** Full validation pipeline: size limit → JSON Schema → semantic rules. */
final class DefinitionValidator
{
    /** @return list<array{path:string,code:string,message:string}> */
    public static function validate(array $doc): array
    {
        $json = json_encode(DocumentCodec::restoreObjects($doc), DocumentCodec::JSON_FLAGS);
        if (strlen($json) > (int) config('studio.schema.max_document_bytes')) {
            return [['path' => '/', 'code' => 'document_too_large', 'message' => 'the document exceeds the maximum size']];
        }
        $structural = StructuralValidator::validateDefinition(json_decode($json, false, 128, JSON_THROW_ON_ERROR));
        if ($structural !== []) {
            return $structural;
        }

        return SemanticValidator::validate($doc);
    }

    public static function assertValid(array $doc): void
    {
        $issues = self::validate($doc);
        if ($issues !== []) {
            throw new SchemaException($issues);
        }
    }
}
