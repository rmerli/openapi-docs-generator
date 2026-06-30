<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Illuminate\Support\Collection;

class SeriesMetricResultData extends AbstractMetricResultData
{
    public function __construct(
        /** @var Collection<int, ExampleData> */
        public Collection $observations,
    ) {}
}
