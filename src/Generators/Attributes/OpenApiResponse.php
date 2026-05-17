<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class OpenApiResponse
{
    public function __construct(
        public string $class,
        public int|string $status = 200,
        public bool $collection = false,
        public string $description = 'Successful response',
    ) {}
}
