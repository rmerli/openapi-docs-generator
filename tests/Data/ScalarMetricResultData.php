<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

class ScalarMetricResultData extends AbstractMetricResultData
{
    public function __construct(
        public string $value,
    ) {}
}
