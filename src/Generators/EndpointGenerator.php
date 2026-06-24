<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\OpenApiEndpoint;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\OpenApiRequest;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\OpenApiResponse;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\OpenApiSecurity;
use OpenApi\Annotations as OA;
use OpenApi\Generator as OpenApiGen;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Spatie\LaravelData\Data;

class EndpointGenerator
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    private OA\OpenApi $openApi;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config,
    ) {}

    public function generate(OA\OpenApi $openApi): void
    {
        if (empty($this->config['enabled'])) {
            return;
        }

        if (! function_exists('app')) {
            return;
        }

        $this->openApi = $openApi;

        $router = app('router');
        $routes = $router->getRoutes();

        foreach ($routes as $route) {
            if (! $this->routeIncluded($route)) {
                continue;
            }

            $method = $this->resolveControllerMethod($route);
            if ($method === null) {
                continue;
            }

            $endpointMeta = $this->mergeEndpointAttributes($method);
            if ($endpointMeta?->include === false) {
                continue;
            }

            $path = $this->normalizePath($route->uri());
            $pathItem = $this->findOrCreatePathItem($openApi, $path);

            foreach ($this->routeMethods($route) as $httpMethod) {
                if ($this->operationExists($pathItem, $httpMethod)) {
                    continue;
                }

                $pathItem->{$httpMethod} = $this->buildOperation(
                    route: $route,
                    method: $method,
                    httpMethod: $httpMethod,
                    path: $path,
                    endpointMeta: $endpointMeta,
                );
            }
        }
    }

    private function buildOperation(
        mixed $route,
        ReflectionMethod $method,
        string $httpMethod,
        string $path,
        ?OpenApiEndpoint $endpointMeta,
    ): OA\Operation {
        $requestMeta = $this->firstAttribute($method, OpenApiRequest::class);
        $responseMetas = $this->attributes($method, OpenApiResponse::class);
        $securityMeta = $this->firstAttribute($method, OpenApiSecurity::class);
        $requestClass = $this->resolveRequestClass($method, $requestMeta);
        $validationRules = $this->resolveValidationRules($method);

        $parameters = $this->buildParameters($route, $httpMethod, $validationRules, $requestMeta);

        $props = [
            'path' => $path,
            'operationId' => $endpointMeta?->operationId ?? $this->operationId($route, $method, $httpMethod),
            'tags' => $endpointMeta?->tags ?? [$this->tagName($method)],
            'summary' => $endpointMeta?->summary ?? $this->summaryFromDocBlock($method) ?? Str::headline($method->getName()),
            'responses' => $this->buildResponses($method, $responseMetas),
        ];

        if ($endpointMeta?->description !== null) {
            $props['description'] = $endpointMeta->description;
        }

        if (! empty($parameters)) {
            $props['parameters'] = $parameters;
        }

        $requestBody = $this->buildRequestBody($httpMethod, $validationRules, $requestMeta, $requestClass);
        if ($requestBody !== null) {
            $props['requestBody'] = $requestBody;
        }

        $security = $this->resolveSecurity($route, $securityMeta);
        if ($security !== null) {
            $props['security'] = $security;
        }

        return $this->newOperation($httpMethod, $props);
    }

    private function routeIncluded(mixed $route): bool
    {
        $uri = trim($route->uri(), '/');
        $name = (string) ($route->getName() ?? '');
        $action = (string) $route->getActionName();
        $middleware = method_exists($route, 'gatherMiddleware') ? $route->gatherMiddleware() : [];

        foreach ((array) ($this->config['exclude'] ?? []) as $pattern) {
            if (Str::is($pattern, $uri) || ($name !== '' && Str::is($pattern, $name)) || Str::is($pattern, $action)) {
                return false;
            }
        }

        $prefixes = (array) ($this->config['prefixes'] ?? []);
        if (! empty($prefixes) && ! $this->matchesAny($uri, $prefixes)) {
            return false;
        }

        $names = (array) ($this->config['names'] ?? []);
        if (! empty($names) && ! $this->matchesAny($name, $names)) {
            return false;
        }

        $requiredMiddleware = (array) ($this->config['middleware'] ?? []);
        if (! empty($requiredMiddleware) && empty(array_intersect($requiredMiddleware, $middleware))) {
            return false;
        }

        return true;
    }

    private function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern, '/');
            if ($value === $pattern || Str::is($pattern, $value) || Str::startsWith($value, $pattern . '/')) {
                return true;
            }
        }

        return false;
    }

    private function resolveControllerMethod(mixed $route): ?ReflectionMethod
    {
        $action = $route->getActionName();
        if (! is_string($action) || ! str_contains($action, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $action, 2);
        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        return new ReflectionMethod($class, $method);
    }

    private function mergeEndpointAttributes(ReflectionMethod $method): ?OpenApiEndpoint
    {
        $classAttribute = $this->firstAttribute($method->getDeclaringClass(), OpenApiEndpoint::class);
        $methodAttribute = $this->firstAttribute($method, OpenApiEndpoint::class);

        if ($classAttribute === null) {
            return $methodAttribute;
        }

        if ($methodAttribute === null) {
            return $classAttribute;
        }

        return new OpenApiEndpoint(
            summary: $methodAttribute->summary ?? $classAttribute->summary,
            description: $methodAttribute->description ?? $classAttribute->description,
            tags: $methodAttribute->tags ?? $classAttribute->tags,
            operationId: $methodAttribute->operationId ?? $classAttribute->operationId,
            include: $methodAttribute->include ?? $classAttribute->include,
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    private function firstAttribute(ReflectionMethod|ReflectionClass $reflection, string $class): ?object
    {
        $attributes = $reflection->getAttributes($class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T[]
     */
    private function attributes(ReflectionMethod $method, string $class): array
    {
        return array_map(
            static fn ($attribute): object => $attribute->newInstance(),
            $method->getAttributes($class),
        );
    }

    private function findOrCreatePathItem(OA\OpenApi $openApi, string $path): OA\PathItem
    {
        if ($openApi->paths === OpenApiGen::UNDEFINED || ! is_array($openApi->paths)) {
            $openApi->paths = [];
        }

        foreach ($openApi->paths as $pathItem) {
            if ($pathItem instanceof OA\PathItem && $pathItem->path === $path) {
                return $pathItem;
            }
        }

        $pathItem = new OA\PathItem(['path' => $path]);
        $openApi->paths[] = $pathItem;

        return $pathItem;
    }

    private function routeMethods(mixed $route): array
    {
        $methods = array_map('strtolower', $route->methods());

        if (in_array('get', $methods, true)) {
            $methods = array_values(array_diff($methods, ['head']));
        }

        return array_values(array_intersect(self::METHODS, $methods));
    }

    private function operationExists(OA\PathItem $pathItem, string $httpMethod): bool
    {
        return $pathItem->{$httpMethod} !== OpenApiGen::UNDEFINED;
    }

    private function normalizePath(string $uri): string
    {
        $path = '/' . trim($uri, '/');
        $path = preg_replace('/\{([^}]+)\?\}/', '{$1}', $path) ?: $path;

        return $path === '/' ? '/' : $path;
    }

    private function operationId(mixed $route, ReflectionMethod $method, string $httpMethod): string
    {
        $name = $route->getName();
        if (is_string($name) && $name !== '') {
            return str_replace(['.', '-'], '_', $name);
        }

        $controller = preg_replace('/Controller$/', '', $method->getDeclaringClass()->getShortName())
            ?: $method->getDeclaringClass()->getShortName();

        return implode('_', array_filter([
            strtolower($httpMethod),
            Str::snake($controller),
            Str::snake($method->getName()),
        ]));
    }

    private function tagName(ReflectionMethod $method): string
    {
        $shortName = $method->getDeclaringClass()->getShortName();
        $shortName = preg_replace('/Controller$/', '', $shortName) ?: $shortName;

        return Str::headline($shortName);
    }

    private function summaryFromDocBlock(ReflectionMethod $method): ?string
    {
        $docComment = $method->getDocComment();
        if ($docComment === false) {
            return null;
        }

        $lines = preg_split('/\R/', $docComment) ?: [];
        $summaryLines = [];

        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('/^\/\*\*?|\*\/$/', '', $line) ?? $line;
            $line = trim(preg_replace('/^\*\s?/', '', $line) ?? $line);

            if ($line === '') {
                if (! empty($summaryLines)) {
                    break;
                }
                continue;
            }

            if (str_starts_with($line, '@')) {
                break;
            }

            $summaryLines[] = $line;
        }

        $summary = trim(implode(' ', $summaryLines));

        return $summary !== '' ? $summary : null;
    }

    private function resolveValidationRules(ReflectionMethod $method): array
    {
        $requestClass = $this->resolveRequestClass($method);
        if ($requestClass === null) {
            return [];
        }

        if (is_a($requestClass, FormRequest::class, true)) {
            return $this->rulesFromClass($requestClass);
        }

        if (is_a($requestClass, Data::class, true)) {
            return $this->rulesFromClass($requestClass);
        }

        return [];
    }

    private function resolveRequestClass(ReflectionMethod $method, ?OpenApiRequest $requestMeta = null): ?string
    {
        if ($requestMeta !== null && class_exists($requestMeta->class)) {
            return $requestMeta->class;
        }

        foreach ($method->getParameters() as $parameter) {
            $className = $this->parameterClassName($parameter);
            if ($className === null) {
                continue;
            }

            if (is_a($className, FormRequest::class, true) || is_a($className, Data::class, true)) {
                return $className;
            }
        }

        return null;
    }

    private function rulesFromClass(string $className): array
    {
        try {
            if (method_exists($className, 'rules')) {
                try {
                    $instance = app($className);
                } catch (\Throwable) {
                    $instance = new $className();
                }

                return (array) $instance->rules();
            }

            if (method_exists($className, 'getValidationRules')) {
                return (array) $className::getValidationRules([]);
            }
        } catch (\Throwable) {
            return [];
        }

        return [];
    }

    private function parameterClassName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType && ! $type->isBuiltin()
            ? $type->getName()
            : null;
    }

    private function buildParameters(
        mixed $route,
        string $httpMethod,
        array $validationRules,
        ?OpenApiRequest $requestMeta,
    ): array {
        $parameters = [];
        $pathNames = $this->pathParameterNames($route->uri());

        foreach ($pathNames as $name) {
            $parameters[] = $this->buildParameter($name, 'path', $validationRules[$name] ?? [], true);
        }

        $requestLocation = $requestMeta?->in;
        if ($requestLocation === null && $httpMethod === 'get') {
            $requestLocation = 'query';
        }

        if ($requestLocation === 'query') {
            foreach ($validationRules as $name => $rules) {
                if (in_array($name, $pathNames, true) || str_contains((string) $name, '.')) {
                    continue;
                }

                $parameters[] = $this->buildParameter((string) $name, 'query', $rules, $this->ruleListContains($rules, 'required'));
            }
        }

        return $parameters;
    }

    private function pathParameterNames(string $uri): array
    {
        preg_match_all('/\{([^}\?]+)\??\}/', $uri, $matches);

        return $matches[1] ?? [];
    }

    private function buildParameter(string $name, string $in, mixed $rules, bool $required): OA\Parameter
    {
        return new OA\Parameter([
            'parameter' => $name,
            'name' => $name,
            'in' => $in,
            'required' => $required,
            'schema' => $this->schemaFromRules($rules),
        ]);
    }

    private function buildRequestBody(
        string $httpMethod,
        array $validationRules,
        ?OpenApiRequest $requestMeta,
        ?string $requestClass,
    ): ?OA\RequestBody
    {
        if ($httpMethod === 'get' || $requestMeta?->in === 'query') {
            return null;
        }

        if ($requestClass !== null && is_a($requestClass, Data::class, true)) {
            return $this->requestBodyFromRef(SchemaNameResolver::resolve($requestClass), true);
        }

        if ($requestClass !== null && is_a($requestClass, FormRequest::class, true)) {
            $schemaName = class_basename($requestClass);
            $this->ensureFormRequestSchema($schemaName, $validationRules);

            return $this->requestBodyFromRef($schemaName, ! empty($validationRules));
        }

        if (empty($validationRules)) {
            return null;
        }

        $properties = [];
        $required = [];

        foreach ($validationRules as $name => $rules) {
            if (str_contains((string) $name, '.')) {
                continue;
            }

            $properties[] = new OA\Property([
                'property' => (string) $name,
                ...$this->schemaPropertiesFromRules($rules),
            ]);

            if ($this->ruleListContains($rules, 'required')) {
                $required[] = (string) $name;
            }
        }

        if (empty($properties)) {
            return null;
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if (! empty($required)) {
            $schema['required'] = $required;
        }

        return new OA\RequestBody([
            'required' => ! empty($required),
            'content' => [
                new OA\MediaType([
                    'mediaType' => 'application/json',
                    'schema' => new OA\Schema($schema),
                ]),
            ],
        ]);
    }

    private function requestBodyFromRef(string $schemaName, bool $required): OA\RequestBody
    {
        return new OA\RequestBody([
            'required' => $required,
            'content' => [
                new OA\MediaType([
                    'mediaType' => 'application/json',
                    'schema' => new OA\Schema([
                        'ref' => '#/components/schemas/' . $schemaName,
                    ]),
                ]),
            ],
        ]);
    }

    private function ensureFormRequestSchema(string $schemaName, array $validationRules): void
    {
        if ($this->componentSchemaExists($schemaName)) {
            return;
        }

        if ($this->openApi->components === OpenApiGen::UNDEFINED) {
            $this->openApi->components = new OA\Components([]);
        }

        if ($this->openApi->components->schemas === OpenApiGen::UNDEFINED) {
            $this->openApi->components->schemas = [];
        }

        $properties = [];
        $required = [];

        foreach ($validationRules as $name => $rules) {
            if (str_contains((string) $name, '.')) {
                continue;
            }

            $properties[] = new OA\Property([
                'property' => (string) $name,
                ...$this->schemaPropertiesFromRules($rules),
            ]);

            if ($this->ruleListContains($rules, 'required')) {
                $required[] = (string) $name;
            }
        }

        $schemaProps = [
            'schema' => $schemaName,
            'type' => 'object',
            'properties' => $properties,
        ];

        if (! empty($required)) {
            $schemaProps['required'] = $required;
        }

        $this->openApi->components->schemas[] = new OA\Schema($schemaProps);
    }

    private function componentSchemaExists(string $schemaName): bool
    {
        if ($this->openApi->components === OpenApiGen::UNDEFINED) {
            return false;
        }

        if ($this->openApi->components->schemas === OpenApiGen::UNDEFINED) {
            return false;
        }

        foreach ($this->openApi->components->schemas as $schema) {
            if ($schema instanceof OA\Schema && $schema->schema === $schemaName) {
                return true;
            }
        }

        return false;
    }

    private function buildResponses(ReflectionMethod $method, array $responseMetas): array
    {
        $responses = [];

        if (empty($responseMetas)) {
            $inferredClass = $this->responseClassFromReturnType($method);
            $inferredCollection = false;

            if ($inferredClass === null) {
                $bodyInference = $this->responseClassFromMethodBody($method);
                $inferredClass = $bodyInference?->className;
                $inferredCollection = $bodyInference?->collection ?? false;
                $inferredStatus = $bodyInference?->status ?? 200;
            } else {
                $inferredStatus = 200;
            }

            if ($inferredClass === null) {
                $inferredClass = $this->responseClassFromDocBlock($method);
                $inferredStatus = 200;
            }

            if ($inferredClass !== null) {
                $responseMetas[] = new OpenApiResponse(
                    $inferredClass,
                    status: $inferredStatus,
                    collection: $inferredCollection,
                );
            }
        }

        foreach ($responseMetas as $responseMeta) {
            $responses[] = $this->buildResponse(
                status: $responseMeta->status,
                className: $responseMeta->class,
                collection: $responseMeta->collection,
                description: $responseMeta->description,
            );
        }

        if (empty($responses)) {
            $responses[] = new OA\Response([
                'response' => $this->successStatusFromMethodBody($method) ?? 200,
                'description' => 'Successful response',
            ]);
        }

        foreach ((array) ($this->config['default_responses'] ?? []) as $status => $description) {
            $responses[] = new OA\Response([
                'response' => (string) $status,
                'description' => is_array($description) ? ($description['description'] ?? '') : (string) $description,
            ]);
        }

        return $responses;
    }

    private function responseClassFromReturnType(ReflectionMethod $method): ?string
    {
        $type = $method->getReturnType();
        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $className = $type->getName();

        return is_a($className, Data::class, true) ? $className : null;
    }

    private function responseClassFromDocBlock(ReflectionMethod $method): ?string
    {
        $docComment = $method->getDocComment();
        if ($docComment === false) {
            return null;
        }

        if (! preg_match('/@return\s+([^\s]+)/', $docComment, $matches)) {
            return null;
        }

        foreach (preg_split('/[|&]/', $matches[1]) ?: [] as $type) {
            $type = trim($type, "\\ \t\n\r\0\x0B");
            $type = preg_replace('/<.*>$/', '', $type) ?? $type;

            if ($type === '' || in_array(strtolower($type), ['self', 'static', '$this', 'void', 'null'], true)) {
                continue;
            }

            $className = $this->resolveClassFqcn($type, $method->getDeclaringClass());
            if ($className !== null && is_a($className, Data::class, true)) {
                return $className;
            }
        }

        return null;
    }

    private function responseClassFromMethodBody(ReflectionMethod $method): ?object
    {
        $body = $this->methodBody($method);
        if ($body === null) {
            return null;
        }

        preg_match_all(
            '/(?<![A-Za-z0-9_\\\\])([A-Z][A-Za-z0-9_\\\\]*)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
            $body,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($matches as $match) {
            $className = $this->resolveClassFqcn($match[1][0], $method->getDeclaringClass());
            if ($className === null || ! is_a($className, Data::class, true)) {
                continue;
            }

            return (object) [
                'className' => $className,
                'collection' => in_array($match[2][0], ['collect', 'collection'], true),
                'status' => $this->responseStatusFromDataChain($body, $match[0][1], $method->getDeclaringClass()),
            ];
        }

        return null;
    }

    private function responseStatusFromDataChain(string $body, int $offset, ReflectionClass $declaringClass): int
    {
        $chain = substr($body, $offset, 1000);

        if (! preg_match('/->toJsonResponse\s*\(\s*([^)]*)\)/', $chain, $matches)) {
            return $this->responseJsonStatusContainingOffset($body, $offset, $declaringClass) ?? 200;
        }

        $arguments = trim($matches[1] ?? '');
        if ($arguments === '') {
            return 200;
        }

        $firstArgument = trim(explode(',', $arguments)[0]);

        return $this->statusCodeFromExpression($firstArgument, $declaringClass) ?? 200;
    }

    private function successStatusFromMethodBody(ReflectionMethod $method): ?int
    {
        $body = $this->methodBody($method);
        if ($body === null) {
            return null;
        }

        preg_match_all('/response\(\)->json\s*\(/', $body, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] ?? [] as $match) {
            $openParenOffset = $match[1] + strlen($match[0]) - 1;

            $status = $this->responseJsonStatusFromCall($body, $openParenOffset, $method->getDeclaringClass());
            if ($status !== null) {
                return $status;
            }
        }

        return null;
    }

    private function responseJsonStatusContainingOffset(string $body, int $offset, ReflectionClass $declaringClass): ?int
    {
        preg_match_all('/response\(\)->json\s*\(/', $body, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] ?? [] as $match) {
            $openParenOffset = $match[1] + strlen($match[0]) - 1;

            $closeParenOffset = $this->findClosingParen($body, $openParenOffset);
            if ($closeParenOffset === null || $offset < $openParenOffset || $offset > $closeParenOffset) {
                continue;
            }

            return $this->responseJsonStatusFromCall($body, $openParenOffset, $declaringClass);
        }

        return null;
    }

    private function responseJsonStatusFromCall(string $body, int $openParenOffset, ReflectionClass $declaringClass): ?int
    {
        $closeParenOffset = $this->findClosingParen($body, $openParenOffset);
        if ($closeParenOffset === null) {
            return null;
        }

        $arguments = substr($body, $openParenOffset + 1, $closeParenOffset - $openParenOffset - 1);
        $statusArgument = $this->topLevelArgument($arguments, 1);

        return $statusArgument !== null
            ? $this->statusCodeFromExpression($statusArgument, $declaringClass)
            : null;
    }

    private function findClosingParen(string $source, int $openParenOffset): ?int
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($source);

        for ($i = $openParenOffset; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function topLevelArgument(string $arguments, int $index): ?string
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($arguments);

        for ($i = 0; $i < $length; $i++) {
            $char = $arguments[$i];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if (in_array($char, ['(', '[', '{'], true)) {
                $depth++;
                continue;
            }

            if (in_array($char, [')', ']', '}'], true)) {
                $depth--;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = trim(substr($arguments, $start, $i - $start));
                $start = $i + 1;
            }
        }

        $parts[] = trim(substr($arguments, $start));

        return $parts[$index] ?? null;
    }

    private function statusCodeFromExpression(string $expression, ReflectionClass $declaringClass): ?int
    {
        $expression = trim($expression, " \t\n\r\0\x0B\\");

        if (is_numeric($expression)) {
            return (int) $expression;
        }

        if (preg_match('/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::(HTTP_[A-Z0-9_]+)$/', $expression, $matches)) {
            $className = $this->resolveClassFqcn($matches[1], $declaringClass);
            if ($className !== null && defined($className . '::' . $matches[2])) {
                return (int) constant($className . '::' . $matches[2]);
            }

            return $this->commonHttpStatusCode($matches[2]);
        }

        return null;
    }

    private function commonHttpStatusCode(string $constant): ?int
    {
        return [
            'HTTP_CONTINUE' => 100,
            'HTTP_SWITCHING_PROTOCOLS' => 101,
            'HTTP_PROCESSING' => 102,
            'HTTP_EARLY_HINTS' => 103,
            'HTTP_OK' => 200,
            'HTTP_CREATED' => 201,
            'HTTP_ACCEPTED' => 202,
            'HTTP_NON_AUTHORITATIVE_INFORMATION' => 203,
            'HTTP_NO_CONTENT' => 204,
            'HTTP_RESET_CONTENT' => 205,
            'HTTP_PARTIAL_CONTENT' => 206,
            'HTTP_MULTI_STATUS' => 207,
            'HTTP_ALREADY_REPORTED' => 208,
            'HTTP_IM_USED' => 226,
            'HTTP_MULTIPLE_CHOICES' => 300,
            'HTTP_MOVED_PERMANENTLY' => 301,
            'HTTP_FOUND' => 302,
            'HTTP_SEE_OTHER' => 303,
            'HTTP_NOT_MODIFIED' => 304,
            'HTTP_USE_PROXY' => 305,
            'HTTP_RESERVED' => 306,
            'HTTP_TEMPORARY_REDIRECT' => 307,
            'HTTP_PERMANENTLY_REDIRECT' => 308,
            'HTTP_BAD_REQUEST' => 400,
            'HTTP_UNAUTHORIZED' => 401,
            'HTTP_PAYMENT_REQUIRED' => 402,
            'HTTP_FORBIDDEN' => 403,
            'HTTP_NOT_FOUND' => 404,
            'HTTP_METHOD_NOT_ALLOWED' => 405,
            'HTTP_NOT_ACCEPTABLE' => 406,
            'HTTP_PROXY_AUTHENTICATION_REQUIRED' => 407,
            'HTTP_REQUEST_TIMEOUT' => 408,
            'HTTP_CONFLICT' => 409,
            'HTTP_GONE' => 410,
            'HTTP_LENGTH_REQUIRED' => 411,
            'HTTP_PRECONDITION_FAILED' => 412,
            'HTTP_REQUEST_ENTITY_TOO_LARGE' => 413,
            'HTTP_REQUEST_URI_TOO_LONG' => 414,
            'HTTP_UNSUPPORTED_MEDIA_TYPE' => 415,
            'HTTP_REQUESTED_RANGE_NOT_SATISFIABLE' => 416,
            'HTTP_EXPECTATION_FAILED' => 417,
            'HTTP_I_AM_A_TEAPOT' => 418,
            'HTTP_MISDIRECTED_REQUEST' => 421,
            'HTTP_UNPROCESSABLE_ENTITY' => 422,
            'HTTP_LOCKED' => 423,
            'HTTP_FAILED_DEPENDENCY' => 424,
            'HTTP_TOO_EARLY' => 425,
            'HTTP_UPGRADE_REQUIRED' => 426,
            'HTTP_PRECONDITION_REQUIRED' => 428,
            'HTTP_TOO_MANY_REQUESTS' => 429,
            'HTTP_REQUEST_HEADER_FIELDS_TOO_LARGE' => 431,
            'HTTP_UNAVAILABLE_FOR_LEGAL_REASONS' => 451,
            'HTTP_INTERNAL_SERVER_ERROR' => 500,
            'HTTP_NOT_IMPLEMENTED' => 501,
            'HTTP_BAD_GATEWAY' => 502,
            'HTTP_SERVICE_UNAVAILABLE' => 503,
            'HTTP_GATEWAY_TIMEOUT' => 504,
            'HTTP_VERSION_NOT_SUPPORTED' => 505,
            'HTTP_VARIANT_ALSO_NEGOTIATES_EXPERIMENTAL' => 506,
            'HTTP_INSUFFICIENT_STORAGE' => 507,
            'HTTP_LOOP_DETECTED' => 508,
            'HTTP_NOT_EXTENDED' => 510,
            'HTTP_NETWORK_AUTHENTICATION_REQUIRED' => 511,
        ][$constant] ?? null;
    }

    private function methodBody(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();
        if ($file === false || ! is_file($file)) {
            return null;
        }

        $lines = file($file);
        if ($lines === false) {
            return null;
        }

        $start = $method->getStartLine();
        $length = $method->getEndLine() - $start + 1;

        return implode('', array_slice($lines, $start - 1, $length));
    }

    private function resolveClassFqcn(string $className, ReflectionClass $declaringClass): ?string
    {
        if (str_starts_with($className, '\\')) {
            return class_exists(ltrim($className, '\\')) ? ltrim($className, '\\') : null;
        }

        if (str_contains($className, '\\')) {
            return class_exists($className) ? $className : null;
        }

        $sameNamespace = $declaringClass->getNamespaceName() . '\\' . $className;
        if (class_exists($sameNamespace)) {
            return $sameNamespace;
        }

        foreach ($this->importsForClass($declaringClass) as $alias => $fqcn) {
            if ($alias === $className && class_exists($fqcn)) {
                return $fqcn;
            }
        }

        return class_exists($className) ? $className : null;
    }

    /**
     * @return array<string, string>
     */
    private function importsForClass(ReflectionClass $class): array
    {
        $file = $class->getFileName();
        if ($file === false || ! is_file($file)) {
            return [];
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            return [];
        }

        preg_match_all('/^use\s+([^;]+);/m', $contents, $matches);

        $imports = [];
        foreach ($matches[1] ?? [] as $useStatement) {
            $parts = preg_split('/\s+as\s+/i', trim($useStatement));
            $fqcn = trim($parts[0], '\\');
            $alias = isset($parts[1]) ? trim($parts[1]) : class_basename($fqcn);
            $imports[$alias] = $fqcn;
        }

        return $imports;
    }

    private function buildResponse(int|string $status, string $className, bool $collection, string $description): OA\Response
    {
        $ref = '#/components/schemas/' . SchemaNameResolver::resolve($className);
        $schema = $collection
            ? new OA\Schema([
                'type' => 'array',
                'items' => new OA\Items(['ref' => $ref]),
            ])
            : new OA\Schema(['ref' => $ref]);

        return new OA\Response([
            'response' => $status,
            'description' => $description,
            'content' => [
                new OA\MediaType([
                    'mediaType' => 'application/json',
                    'schema' => $schema,
                ]),
            ],
        ]);
    }

    private function resolveSecurity(mixed $route, ?OpenApiSecurity $securityMeta): ?array
    {
        if ($securityMeta !== null) {
            return $securityMeta->security;
        }

        $map = (array) ($this->config['security'] ?? []);
        if (empty($map)) {
            return null;
        }

        $middleware = method_exists($route, 'gatherMiddleware') ? $route->gatherMiddleware() : [];

        foreach ($middleware as $entry) {
            if (isset($map[$entry])) {
                return [[$map[$entry] => []]];
            }

            $base = explode(':', (string) $entry, 2)[0];
            if (isset($map[$base])) {
                return [[$map[$base] => []]];
            }
        }

        return null;
    }

    private function schemaFromRules(mixed $rules): OA\Schema
    {
        return new OA\Schema($this->schemaPropertiesFromRules($rules));
    }

    private function schemaPropertiesFromRules(mixed $rules): array
    {
        $rules = $this->normalizeRules($rules);
        $props = ['type' => 'string'];

        foreach ($rules as $rule) {
            $name = $this->ruleName($rule);
            $argument = $this->ruleArgument($rule);

            match ($name) {
                'integer', 'int' => $props['type'] = 'integer',
                'numeric', 'decimal' => $props['type'] = 'number',
                'boolean', 'bool' => $props['type'] = 'boolean',
                'array' => $props['type'] = 'array',
                'email' => [$props['type'], $props['format']] = ['string', 'email'],
                'url' => [$props['type'], $props['format']] = ['string', 'uri'],
                'date' => [$props['type'], $props['format']] = ['string', 'date'],
                'uuid' => [$props['type'], $props['format']] = ['string', 'uuid'],
                'nullable' => $props['nullable'] = true,
                'in' => $props['enum'] = $argument === null ? [] : explode(',', $argument),
                'max' => $this->applyMaximum($props, $argument),
                'min' => $this->applyMinimum($props, $argument),
                default => null,
            };
        }

        if (($props['type'] ?? null) === 'array' && ! isset($props['items'])) {
            $props['items'] = new OA\Items(['type' => 'string']);
        }

        return $props;
    }

    private function applyMaximum(array &$props, ?string $argument): void
    {
        if ($argument === null || ! is_numeric($argument)) {
            return;
        }

        if (($props['type'] ?? null) === 'string') {
            $props['maxLength'] = (int) $argument;
            return;
        }

        $props['maximum'] = (float) $argument;
    }

    private function applyMinimum(array &$props, ?string $argument): void
    {
        if ($argument === null || ! is_numeric($argument)) {
            return;
        }

        if (($props['type'] ?? null) === 'string') {
            $props['minLength'] = (int) $argument;
            return;
        }

        $props['minimum'] = (float) $argument;
    }

    private function ruleListContains(mixed $rules, string $needle): bool
    {
        foreach ($this->normalizeRules($rules) as $rule) {
            if ($this->ruleName($rule) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function normalizeRules(mixed $rules): array
    {
        if (is_string($rules)) {
            return explode('|', $rules);
        }

        if (is_array($rules)) {
            return $rules;
        }

        return [];
    }

    private function ruleName(mixed $rule): string
    {
        if (is_object($rule) && method_exists($rule, '__toString')) {
            $rule = (string) $rule;
        }

        if (! is_string($rule)) {
            return '';
        }

        return strtolower(strtok($rule, ':') ?: $rule);
    }

    private function ruleArgument(mixed $rule): ?string
    {
        if (is_object($rule) && method_exists($rule, '__toString')) {
            $rule = (string) $rule;
        }

        if (! is_string($rule) || ! str_contains($rule, ':')) {
            return null;
        }

        return substr($rule, strpos($rule, ':') + 1);
    }

    private function newOperation(string $httpMethod, array $props): OA\Operation
    {
        $class = match ($httpMethod) {
            'get' => OA\Get::class,
            'post' => OA\Post::class,
            'put' => OA\Put::class,
            'patch' => OA\Patch::class,
            'delete' => OA\Delete::class,
            'head' => OA\Head::class,
            'options' => OA\Options::class,
            default => OA\Trace::class,
        };

        return new $class($props);
    }
}
