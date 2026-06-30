<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Fixtures;

use Langsys\OpenApiDocsGenerator\Tests\Data\PolymorphicReportData;

class GeneratedEndpointService
{
    public function run(): PolymorphicReportData
    {
        return PolymorphicReportData::from(['metrics' => []]);
    }
}
