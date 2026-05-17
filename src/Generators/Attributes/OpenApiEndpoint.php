<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class OpenApiEndpoint
{
    /**
     * @param string[]|null $tags
     */
    public function __construct(
        public ?string $summary = null,
        public ?string $description = null,
        public ?array $tags = null,
        public ?string $operationId = null,
        public ?bool $include = null,
    ) {}
}
