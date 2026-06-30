<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

class OrdersMetricContextData extends BaseMetricContextData
{
    public function __construct(
        public ?string $status = null,
    ) {}
}
