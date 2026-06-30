<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

class ConstructorDocblockReportData extends Data
{
    /**
     * @param  Collection<int, AbstractMetricResultData>  $metrics
     */
    public function __construct(
        public Collection $metrics,
    ) {}
}
