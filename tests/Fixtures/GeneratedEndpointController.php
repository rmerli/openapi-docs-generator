<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\OpenApiEndpoint;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\OpenApiResponse;
use Langsys\OpenApiDocsGenerator\Tests\Data\ExampleData;
use Symfony\Component\HttpFoundation\Response;

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

    /**
     * Get open generated project from docblock.
     *
     * @return JsonResponse
     */
    public function getOpen(): JsonResponse
    {
        return ExampleData::from(['example' => 'test'])->toJsonResponse();
    }

    /**
     * Create generated project with inferred status.
     */
    public function createWithInferredStatus(GeneratedEndpointRequest $request): JsonResponse
    {
        return ExampleData::from(['example' => 'test'])->toJsonResponse(Response::HTTP_CREATED);
    }

    /**
     * Create generated project with numeric uncommon status.
     */
    public function createWithNumericStatus(GeneratedEndpointRequest $request): JsonResponse
    {
        return ExampleData::from(['example' => 'test'])->toJsonResponse(451);
    }
}
