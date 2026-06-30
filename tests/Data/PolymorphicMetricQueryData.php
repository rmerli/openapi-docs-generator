<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Spatie\LaravelData\Data;

class PolymorphicMetricQueryData extends Data
{
    public function __construct(
        public string $metric,
        public BaseMetricContextData $context,
    ) {}
}
