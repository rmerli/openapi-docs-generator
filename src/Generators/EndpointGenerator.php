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
        $validationRules = $this->resolveValidationRules($method);

        $parameters = $this->buildParameters($route, $httpMethod, $validationRules, $requestMeta);

        $props = [
            'path' => $path,
            'operationId' => $endpointMeta?->operationId ?? $this->operationId($route, $method, $httpMethod),
            'tags' => $endpointMeta?->tags ?? [$this->tagName($method)],
            'responses' => $this->buildResponses($method, $responseMetas),
        ];

        if ($endpointMeta?->summary !== null) {
            $props['summary'] = $endpointMeta->summary;
        }

        if ($endpointMeta?->description !== null) {
            $props['description'] = $endpointMeta->description;
        }

        if (! empty($parameters)) {
            $props['parameters'] = $parameters;
        }

        $requestBody = $this->buildRequestBody($httpMethod, $validationRules, $requestMeta);
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

        return strtolower($httpMethod) . ucfirst($method->getDeclaringClass()->getShortName()) . ucfirst($method->getName());
    }

    private function tagName(ReflectionMethod $method): string
    {
        $shortName = $method->getDeclaringClass()->getShortName();
        $shortName = preg_replace('/Controller$/', '', $shortName) ?: $shortName;

        return Str::headline($shortName);
    }

    private function resolveValidationRules(ReflectionMethod $method): array
    {
        foreach ($method->getParameters() as $parameter) {
            $className = $this->parameterClassName($parameter);
            if ($className === null) {
                continue;
            }

            if (is_a($className, FormRequest::class, true)) {
                return $this->rulesFromClass($className);
            }

            if (is_a($className, Data::class, true)) {
                return $this->rulesFromClass($className);
            }
        }

        return [];
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

    private function buildRequestBody(string $httpMethod, array $validationRules, ?OpenApiRequest $requestMeta): ?OA\RequestBody
    {
        if ($requestMeta !== null && is_a($requestMeta->class, Data::class, true) && $requestMeta->in !== 'query') {
            return new OA\RequestBody([
                'required' => true,
                'content' => [
                    new OA\MediaType([
                        'mediaType' => 'application/json',
                        'schema' => new OA\Schema([
                            'ref' => '#/components/schemas/' . SchemaNameResolver::resolve($requestMeta->class),
                        ]),
                    ]),
                ],
            ]);
        }

        if ($httpMethod === 'get' || empty($validationRules)) {
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

    private function buildResponses(ReflectionMethod $method, array $responseMetas): array
    {
        $responses = [];

        if (empty($responseMetas)) {
            $inferredClass = $this->responseClassFromReturnType($method);
            if ($inferredClass !== null) {
                $responseMetas[] = new OpenApiResponse($inferredClass);
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
                'response' => 200,
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
