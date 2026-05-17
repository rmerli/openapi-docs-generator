<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class OpenApiSecurity
{
    /**
     * @param array<int|string, mixed>|null $security Set to [] to disable security for the operation.
     */
    public function __construct(
        public ?array $security = null,
    ) {}
}
