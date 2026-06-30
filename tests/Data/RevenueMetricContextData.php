<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

class RevenueMetricContextData extends BaseMetricContextData
{
    public function __construct(
        public ?string $paymentMethod = null,
    ) {}
}
