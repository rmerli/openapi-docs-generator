<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class OpenApiRequest
{
    public function __construct(
        public string $class,
        public string $in = 'body',
    ) {}
}
