<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Fixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\OpenApiEndpoint;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\OpenApiResponse;
use Langsys\OpenApiDocsGenerator\Tests\Data\ExampleData;

#[OpenApiEndpoint(tags: ['Projects'])]
class GeneratedEndpointController
{
    #[OpenApiEndpoint(summary: 'Show generated project')]
    public function show(GeneratedEndpointRequest $request): ExampleData
    {
    }

    #[OpenApiEndpoint(summary: 'Store generated project')]
    #[OpenApiResponse(ExampleData::class, status: 201)]
    public function store(GeneratedEndpointRequest $request): void
    {
    }
}
