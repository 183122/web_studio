<?php

namespace App\Domain\Schema;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/** JSON Schema (draft 2020-12) validation using the shared schema files. */
final class StructuralValidator
{
    public const DEFINITION_ID = 'https://schemas.ai-studio.local/app-definition/v2.json';

    public const COMMAND_ID = 'https://schemas.ai-studio.local/app-command/v2.json';

    private static ?Validator $validator = null;

    private static function validator(): Validator
    {
        if (self::$validator === null) {
            $dir = config('studio.packages_path').'/app-schema/schema';
            $v = new Validator;
            $v->setMaxErrors(20);
            $v->setStopAtFirstError(false);
            $v->resolver()->registerFile(self::DEFINITION_ID, $dir.'/app-definition.schema.json');
            $v->resolver()->registerFile(self::COMMAND_ID, $dir.'/app-command.schema.json');
            self::$validator = $v;
        }

        return self::$validator;
    }

    /** @return list<array{path:string,code:string,message:string}> */
    public static function validateDefinition(mixed $objectTree): array
    {
        return self::run($objectTree, self::DEFINITION_ID);
    }

    /** @return list<array{path:string,code:string,message:string}> */
    public static function validateCommand(mixed $objectTree): array
    {
        return self::run($objectTree, self::COMMAND_ID);
    }

    private static function run(mixed $data, string $id): array
    {
        $result = self::validator()->validate($data, $id);
        if ($result->isValid()) {
            return [];
        }
        $root = $result->error();
        if ($root->keyword() === 'oneOf') {
            // Commands: do not leak every branch's errors; report one actionable issue.
            return [[
                'path' => '/'.implode('/', array_map('strval', $root->data()->fullPath())),
                'code' => 'schema.oneOf',
                'message' => 'does not match any supported command shape',
            ]];
        }
        $issues = [];
        $formatter = new ErrorFormatter;
        $walk = function (ValidationError $error) use (&$walk, &$issues, $formatter): void {
            if ($error->subErrors() !== []) {
                foreach ($error->subErrors() as $sub) {
                    $walk($sub);
                }

                return;
            }
            $issues[] = [
                'path' => '/'.implode('/', array_map('strval', $error->data()->fullPath())),
                'code' => 'schema.'.$error->keyword(),
                'message' => $formatter->formatErrorMessage($error),
            ];
        };
        $walk($root);

        return array_values(array_slice($issues, 0, 50));
    }
}
