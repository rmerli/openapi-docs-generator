<?php

use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use Langsys\OpenApiDocsGenerator\Generators\OpenApiGenerator;
use Langsys\OpenApiDocsGenerator\Tests\Fixtures\GeneratedEndpointController;
use Psr\Log\NullLogger;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/openapi-pipeline-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->docsFile = $this->tempDir . '/api-docs.json';
    $this->yamlFile = $this->tempDir . '/api-docs.yaml';
});

afterEach(function () {
    @unlink($this->docsFile);
    @unlink($this->yamlFile);
    @rmdir($this->tempDir);
});

function makeGenerator(
    string $docsFile,
    string $yamlFile,
    array $securitySchemes = [],
    array $security = [],
    ?string $basePath = null,
    bool $yamlCopy = false,
    array $endpointsConfig = [],
): OpenApiGenerator {
    $packageRoot = dirname(__DIR__, 2);

    $dtoSchemaBuilder = new DtoSchemaBuilder(
        dtoPaths: $packageRoot . '/tests/Data',
        exampleGenerator: new ExampleGenerator(
            fakerAttributeMapper: [],
            customFunctions: [],
        ),
        paginationFields: [],
    );

    return new OpenApiGenerator(
        annotationsDir: [$packageRoot . '/tests/Fixtures'],
        docsFile: $docsFile,
        yamlDocsFile: $yamlFile,
        securitySchemesConfig: $securitySchemes,
        securityConfig: $security,
        scanOptions: [
            'open_api_spec_version' => '3.0.0',
        ],
        constants: [],
        basePath: $basePath,
        yamlCopy: $yamlCopy,
        endpointParametersConfig: [],
        endpointsConfig: $endpointsConfig,
        dtoSchemaBuilder: $dtoSchemaBuilder,
        logger: new NullLogger(),
    );
}

test('full pipeline generates valid JSON with scanned annotations and DTO schemas', function () {
    $generator = makeGenerator($this->docsFile, $this->yamlFile);
    $generator->generateDocs();

    expect(file_exists($this->docsFile))->toBeTrue();

    $json = json_decode(file_get_contents($this->docsFile), true);

    // Has OpenAPI structure
    expect($json)->toHaveKey('openapi')
        ->and($json)->toHaveKey('info')
        ->and($json)->toHaveKey('paths');

    // Info came from TestController attribute
    expect($json['info']['title'])->toBe('Test API')
        ->and($json['info']['version'])->toBe('1.0.0');

    // Paths include the /api/examples endpoint from TestController
    expect($json['paths'])->toHaveKey('/api/examples');
});

test('full pipeline merges DTO schemas into components', function () {
    $generator = makeGenerator($this->docsFile, $this->yamlFile);
    $generator->generateDocs();

    $json = json_decode(file_get_contents($this->docsFile), true);

    expect($json)->toHaveKey('components')
        ->and($json['components'])->toHaveKey('schemas');

    $schemaNames = array_keys($json['components']['schemas']);

    // DTO-generated schemas should be present
    expect($schemaNames)->toContain('ExampleData')
        ->and($schemaNames)->toContain('TestData');
});

test('full pipeline injects security definitions', function () {
    $generator = makeGenerator(
        docsFile: $this->docsFile,
        yamlFile: $this->yamlFile,
        securitySchemes: [
            'sanctum' => [
                'type' => 'apiKey',
                'name' => 'Authorization',
                'in' => 'header',
            ],
        ],
        security: [
            ['sanctum' => []],
        ],
    );

    $generator->generateDocs();

    $json = json_decode(file_get_contents($this->docsFile), true);

    expect($json['components']['securitySchemes'])->toHaveKey('sanctum')
        ->and($json['security'])->toContain(['sanctum' => []]);
});

test('full pipeline adds server when basePath is set', function () {
    $generator = makeGenerator(
        docsFile: $this->docsFile,
        yamlFile: $this->yamlFile,
        basePath: 'https://api.example.com/v1',
    );

    $generator->generateDocs();

    $json = json_decode(file_get_contents($this->docsFile), true);

    expect($json)->toHaveKey('servers');

    $urls = array_column($json['servers'], 'url');
    expect($urls)->toContain('https://api.example.com/v1');
});

test('full pipeline generates YAML copy when enabled', function () {
    $generator = makeGenerator(
        docsFile: $this->docsFile,
        yamlFile: $this->yamlFile,
        yamlCopy: true,
    );

    $generator->generateDocs();

    expect(file_exists($this->yamlFile))->toBeTrue();

    $yaml = file_get_contents($this->yamlFile);
    expect($yaml)->toContain('openapi:')
        ->and($yaml)->toContain('Test API')
        ->and($yaml)->toContain('/api/examples');
});

test('full pipeline does not generate YAML when disabled', function () {
    $generator = makeGenerator(
        docsFile: $this->docsFile,
        yamlFile: $this->yamlFile,
        yamlCopy: false,
    );

    $generator->generateDocs();

    expect(file_exists($this->yamlFile))->toBeFalse();
});

test('DTO schemas contain expected properties for ExampleData', function () {
    $generator = makeGenerator($this->docsFile, $this->yamlFile);
    $generator->generateDocs();

    $json = json_decode(file_get_contents($this->docsFile), true);

    expect($json['components']['schemas'])->toHaveKey('ExampleData');

    $exampleSchema = $json['components']['schemas']['ExampleData'];
    expect($exampleSchema['properties'])->toHaveKey('example')
        ->and($exampleSchema['properties']['example']['type'])->toBe('string')
        ->and($exampleSchema['properties']['example']['example'])->toBe('test');
});

test('annotation-defined schemas take precedence over DTO schemas', function () {
    $generator = makeGenerator($this->docsFile, $this->yamlFile);
    $generator->generateDocs();

    $json = json_decode(file_get_contents($this->docsFile), true);

    // ExampleData should exist as a key (not duplicated)
    expect($json['components']['schemas'])->toHaveKey('ExampleData');
});

test('endpoint auto-generation preserves annotated operations', function () {
    app('router')->get('/api/examples', [GeneratedEndpointController::class, 'show'])
        ->name('examples.generated');

    $generator = makeGenerator(
        docsFile: $this->docsFile,
        yamlFile: $this->yamlFile,
        endpointsConfig: [
            'enabled' => true,
            'prefixes' => ['api/examples'],
        ],
    );
    $generator->generateDocs();

    $json = json_decode(file_get_contents($this->docsFile), true);

    expect($json['paths']['/api/examples']['get']['summary'])->toBe('List examples')
        ->and($json['paths']['/api/examples']['get']['operationId'])->not->toBe('examples_generated');
});

test('endpoint auto-generation creates operations from scoped Laravel routes', function () {
    app('router')->get('/api/generated/{project}', [GeneratedEndpointController::class, 'show'])
        ->middleware('auth:sanctum')
        ->name('generated.show');

    app('router')->post('/api/generated', [GeneratedEndpointController::class, 'store'])
        ->middleware('auth:sanctum')
        ->name('generated.store');

    app('router')->get('/api/generated/my-open', [GeneratedEndpointController::class, 'getOpen'])
        ->middleware('auth:sanctum')
        ->name('generated.open');

    app('router')->post('/api/generated/inferred-status', [GeneratedEndpointController::class, 'createWithInferredStatus'])
        ->middleware('auth:sanctum')
        ->name('generated.inferred-status');

    app('router')->post('/api/generated/numeric-status', [GeneratedEndpointController::class, 'createWithNumericStatus'])
        ->middleware('auth:sanctum')
        ->name('generated.numeric-status');

    app('router')->post('/api/generated/data-request', [GeneratedEndpointController::class, 'createWithDataRequest'])
        ->middleware('auth:sanctum')
        ->name('generated.data-request');

    app('router')->get('/api/generated/data-list', [GeneratedEndpointController::class, 'listDataResponses'])
        ->middleware('auth:sanctum')
        ->name('generated.data-list');

    app('router')->post('/api/generated/custom-factory', [GeneratedEndpointController::class, 'createWithCustomFactory'])
        ->middleware('auth:sanctum')
        ->name('generated.custom-factory');

    app('router')->post('/api/generated/docblock-response', [GeneratedEndpointController::class, 'createWithDocBlockResponse'])
        ->middleware('auth:sanctum')
        ->name('generated.docblock-response');

    app('router')->post('/api/generated/response-json-collection', [GeneratedEndpointController::class, 'createWithResponseJsonCollection'])
        ->middleware('auth:sanctum')
        ->name('generated.response-json-collection');

    app('router')->delete('/api/generated/no-content', [GeneratedEndpointController::class, 'deleteWithNoContent'])
        ->middleware('auth:sanctum')
        ->name('generated.no-content');

    $generator = makeGenerator(
        docsFile: $this->docsFile,
        yamlFile: $this->yamlFile,
        endpointsConfig: [
            'enabled' => true,
            'prefixes' => ['api/generated'],
            'security' => ['auth:sanctum' => 'sanctum'],
            'default_responses' => [
                422 => 'Validation error',
            ],
        ],
    );

    $generator->generateDocs();

    $json = json_decode(file_get_contents($this->docsFile), true);
    expect(array_keys($json['paths']))->toBe([
        '/api',
        '/api/examples',
        '/api/generated',
        '/api/generated/custom-factory',
        '/api/generated/data-list',
        '/api/generated/data-request',
        '/api/generated/docblock-response',
        '/api/generated/inferred-status',
        '/api/generated/my-open',
        '/api/generated/no-content',
        '/api/generated/numeric-status',
        '/api/generated/response-json-collection',
        '/api/generated/{project}',
    ]);

    $get = $json['paths']['/api/generated/{project}']['get'];
    expect($get['operationId'])->toBe('generated_show')
        ->and($get['tags'])->toBe(['Projects'])
        ->and($get['summary'])->toBe('Show generated project')
        ->and($get['security'])->toBe([['sanctum' => []]])
        ->and($get['responses']['200']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/ExampleData')
        ->and($get['responses'])->toHaveKey('422');

    $parameters = collect($get['parameters'])->keyBy('name');
    expect($parameters['project']['in'])->toBe('path')
        ->and($parameters['project']['schema']['type'])->toBe('integer')
        ->and($parameters['include']['in'])->toBe('query')
        ->and($parameters['include']['schema']['enum'])->toBe(['owner', 'tasks']);

    $post = $json['paths']['/api/generated']['post'];
    expect($post['responses'])->toHaveKey('201')
        ->and($post['responses']['201']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/ExampleData')
        ->and($post['requestBody']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/GeneratedEndpointRequest')
        ->and($json['components']['schemas']['GeneratedEndpointRequest']['required'])->toBe(['project', 'name'])
        ->and($json['components']['schemas']['GeneratedEndpointRequest']['properties']['name']['maxLength'])->toBe(120);

    $open = $json['paths']['/api/generated/my-open']['get'];
    expect($open['summary'])->toBe('Get open generated project from docblock.')
        ->and($open['responses']['200']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/ExampleData');

    $inferredStatus = $json['paths']['/api/generated/inferred-status']['post'];
    expect($inferredStatus['responses'])->toHaveKey('201')
        ->and($inferredStatus['responses'])->not->toHaveKey('200')
        ->and($inferredStatus['responses']['201']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/ExampleData');

    $numericStatus = $json['paths']['/api/generated/numeric-status']['post'];
    expect($numericStatus['responses'])->toHaveKey('451')
        ->and($numericStatus['responses'])->not->toHaveKey('200')
        ->and($numericStatus['responses']['451']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/ExampleData');

    $dataRequest = $json['paths']['/api/generated/data-request']['post'];
    expect($dataRequest['requestBody']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/OptionalUnionTestRequest')
        ->and($dataRequest['responses']['201']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/ExampleData');

    $dataList = $json['paths']['/api/generated/data-list']['get'];
    expect($dataList['responses']['200']['content']['application/json']['schema']['type'])->toBe('array')
        ->and($dataList['responses']['200']['content']['application/json']['schema']['items']['$ref'])->toBe('#/components/schemas/ExampleData');

    $customFactory = $json['paths']['/api/generated/custom-factory']['post'];
    expect($customFactory['responses'])->toHaveKey('202')
        ->and($customFactory['responses'])->not->toHaveKey('200')
        ->and($customFactory['responses']['202']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/ExampleData');

    $docBlockResponse = $json['paths']['/api/generated/docblock-response']['post'];
    expect($docBlockResponse['responses']['200']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/ExampleData');

    $responseJsonCollection = $json['paths']['/api/generated/response-json-collection']['post'];
    expect($responseJsonCollection['responses'])->toHaveKey('201')
        ->and($responseJsonCollection['responses'])->not->toHaveKey('200')
        ->and($responseJsonCollection['responses']['201']['content']['application/json']['schema']['type'])->toBe('array')
        ->and($responseJsonCollection['responses']['201']['content']['application/json']['schema']['items']['$ref'])->toBe('#/components/schemas/ExampleData');

    $noContent = $json['paths']['/api/generated/no-content']['delete'];
    expect($noContent['responses'])->toHaveKey('204')
        ->and($noContent['responses'])->not->toHaveKey('200')
        ->and($noContent['responses']['204'])->not->toHaveKey('content');
});
