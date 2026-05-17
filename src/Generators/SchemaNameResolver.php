<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

class SchemaNameResolver
{
    public static function resolve(string $className): string
    {
        $baseName = class_basename($className);

        if (str_contains($baseName, 'Resource')) {
            return str_replace('Resource', '', $baseName);
        }

        return $baseName;
    }
}
