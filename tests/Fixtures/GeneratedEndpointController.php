<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\OpenApiEndpoint;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\OpenApiResponse;
use Langsys\OpenApiDocsGenerator\Tests\Data\ExampleData;
use Langsys\OpenApiDocsGenerator\Tests\Data\OptionalUnionTestRequest;
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

    /**
     * Create generated project from Laravel Data.
     */
    public function createWithDataRequest(OptionalUnionTestRequest $request): JsonResponse
    {
        return ExampleData::from(['example' => $request->required_field])->toJsonResponse(Response::HTTP_CREATED);
    }

    /**
     * List generated projects from Laravel Data.
     */
    public function listDataResponses(): array
    {
        return ExampleData::collect([
            ['example' => 'first'],
            ['example' => 'second'],
        ])->all();
    }

    /**
     * Create generated project with a custom Laravel Data factory.
     */
    public function createWithCustomFactory(): JsonResponse
    {
        return ExampleData::fromModel('factory')->toJsonResponse(Response::HTTP_ACCEPTED);
    }

    /**
     * Create generated project through service-style response.
     *
     * @return ExampleData
     */
    public function createWithDocBlockResponse(): JsonResponse
    {
        return response()->json(['example' => 'docblock']);
    }

    /**
     * Create generated project list through response helper.
     */
    public function createWithResponseJsonCollection(): JsonResponse
    {
        return response()->json(
            ExampleData::collect([
                ['example' => 'first'],
                ['example' => 'second'],
            ]),
            Response::HTTP_CREATED,
        );
    }

    /**
     * Delete generated project.
     */
    public function deleteWithNoContent(): JsonResponse
    {
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
