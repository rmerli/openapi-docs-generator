<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

class PolymorphicReportData extends Data
{
    public function __construct(
        /** @var Collection<int, AbstractMetricResultData> */
        public Collection $metrics,
    ) {}
}
