<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

it('keeps the committed OpenAPI artifact aligned with API v1 routes', function (): void {
    $specPath = base_path('docs/api/openapi.yaml');

    expect(file_exists($specPath))->toBeTrue();

    $spec = Yaml::parseFile($specPath);

    expect($spec)->toBeArray()
        ->and($spec['openapi'] ?? null)->toBe('3.1.0')
        ->and($spec['paths'] ?? null)->toBeArray();

    $documentedOperations = racklabOpenApiDocumentedOperations($spec['paths']);

    foreach (racklabOpenApiRouteOperations() as [$method, $path]) {
        expect($documentedOperations)->toContain($method.' '.racklabOpenApiComparablePath($path));
    }
});

it('documents write-endpoint request body intent in the OpenAPI artifact', function (): void {
    $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));
    $scriptProperties = $spec['paths']['/api/v1/scripts']['post']['requestBody']['content']['application/json']['schema']['properties'];
    $deploymentProperties = $spec['paths']['/api/v1/deployments']['post']['requestBody']['content']['application/json']['schema']['properties'];
    $networkProperties = $spec['paths']['/api/v1/networks']['post']['requestBody']['content']['application/json']['schema']['properties'];

    expect($scriptProperties['project_id']['description'])->toBe('Project that owns the script.')
        ->and($scriptProperties['runner_kind']['description'])->toBe('Runner substrate for this script version.')
        ->and($scriptProperties['source']['description'])->toBe('Executable script source or structured runner definition.')
        ->and($deploymentProperties['idempotency_key']['description'])->toContain('Client supplied key that makes duplicate deployment requests return the original operation.')
        ->and($deploymentProperties['lease_duration_minutes']['description'])->toContain('lease duration in minutes')
        ->and($networkProperties['network_offering_id']['description'])->toContain('Approved network offering id');
});

it('documents a concrete response for every API operation', function (): void {
    $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));
    $operations = racklabOpenApiDocumentedOperationsWithDetails($spec['paths']);

    foreach ($operations as $details) {
        expect($details['responses'])->not->toBe([]);
    }

    expect($operations['delete /api/v1/tokens/{}']['responses'])->toHaveKey('204')
        ->and($operations['delete /api/v1/floating-ips/{}']['responses'])->toHaveKey('204')
        ->and($operations['get /api/v1/artifacts/{}']['responses']['200']['content'])->toHaveKey('application/octet-stream')
        ->and($operations['get /api/v1/replay']['responses']['200']['content']['application/json']['schema']['properties'])->toHaveKeys(['gap', 'events'])
        ->and($operations['get /api/v1/projects']['responses']['200']['content']['application/json']['schema']['properties']['data']['type'])->toBe('array');
});

it('documents operation summaries and descriptions for every API operation', function (): void {
    $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));

    foreach (racklabOpenApiDocumentedOperationsWithDetails($spec['paths']) as $details) {
        expect($details['summary'])->not->toBe('')
            ->and($details['description'])->not->toBe('');
    }
});

it('documents concrete examples for high traffic API responses', function (): void {
    $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));
    $operations = racklabOpenApiDocumentedOperationsWithDetails($spec['paths']);

    $me = racklabOpenApiJsonExample($operations['get /api/v1/me'], '200')['data'];
    $project = racklabOpenApiJsonExample($operations['get /api/v1/projects'], '200')['data'][0];
    $deployment = racklabOpenApiJsonExample($operations['get /api/v1/deployments'], '200')['data'][0];
    $network = racklabOpenApiJsonExample($operations['post /api/v1/networks'], '201')['data'];
    $router = racklabOpenApiJsonExample($operations['post /api/v1/routers'], '201')['data'];
    $floatingIp = racklabOpenApiJsonExample($operations['post /api/v1/floating-ips'], '201')['data'];
    $securityGroup = racklabOpenApiJsonExample($operations['post /api/v1/security-groups'], '201')['data'];
    $providerDrift = racklabOpenApiJsonExample($operations['post /api/v1/provider-drifts/{}/repair'], '201')['data'];
    $token = racklabOpenApiJsonExample($operations['post /api/v1/tokens'], '201')['data'];
    $scriptRun = racklabOpenApiJsonExample($operations['get /api/v1/scripts/{}/runs/{}'], '200')['data'];

    expect($me)->toHaveKeys(['id', 'name', 'email', 'tenant', 'profile'])
        ->and($project)->toHaveKeys(['id', 'name', 'slug', 'tenant_id', 'is_personal_default', 'sharing_scope'])
        ->and($deployment)->toHaveKey('lease_expires_at')
        ->and($deployment['resources'][0])->toHaveKeys(['id', 'component_key', 'kind', 'state', 'provider', 'provider_resource_id'])
        ->and($network['subnets'][0]['cidr'])->toBe('10.42.0.0/24')
        ->and($network['subnets'][0]['subnet_pool_id'])->toBe('01HZSUBNETPOOL000000000')
        ->and($router['interfaces'])->toHaveCount(2)
        ->and($floatingIp['address'])->toBe('198.51.100.1')
        ->and($securityGroup['rules'][0]['provider_binding']['mode'])->toBe('fake-firewall-rule')
        ->and($providerDrift['drift'][0]['path'])->toBe('rules.0.port_min')
        ->and($token['authorization_header'])->toStartWith('Token ')
        ->and($scriptRun['artifacts'][0]['purpose'])->toBe('ansible_result');
});

it('does not publish generic fallback examples for current JSON API responses', function (): void {
    $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));

    foreach (racklabOpenApiDocumentedOperationsWithDetails($spec['paths']) as $operationId => $operation) {
        foreach ($operation['responses'] ?? [] as $response) {
            if (! is_array($response)) {
                continue;
            }

            $example = $response['content']['application/json']['example'] ?? null;

            if (! is_array($example)) {
                continue;
            }

            expect(racklabOpenApiUsesGenericFallbackExample($example))
                ->toBeFalse($operationId.' still uses the generic fallback example.');
        }
    }
});

it('documents operation specific schemas for high traffic JSON responses', function (): void {
    $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));
    $operations = racklabOpenApiDocumentedOperationsWithDetails($spec['paths']);

    $meSchema = racklabOpenApiJsonDataSchema($operations['get /api/v1/me'], '200');
    $projectsSchema = racklabOpenApiJsonDataSchema($operations['get /api/v1/projects'], '200');
    $tokenSchema = racklabOpenApiJsonDataSchema($operations['post /api/v1/tokens'], '201');
    $scriptRunSchema = racklabOpenApiJsonDataSchema($operations['get /api/v1/scripts/{}/runs/{}'], '200');

    expect($meSchema['properties'])->toHaveKeys(['id', 'name', 'email', 'tenant', 'profile'])
        ->and($projectsSchema['type'])->toBe('array')
        ->and($projectsSchema['items']['properties'])->toHaveKeys(['id', 'name', 'slug', 'tenant_id', 'is_personal_default', 'sharing_scope'])
        ->and($tokenSchema['properties'])->toHaveKeys(['plain_text_token', 'authorization_header'])
        ->and($scriptRunSchema['properties']['artifacts']['items']['properties'])->toHaveKeys(['id', 'kind', 'content_type', 'size_bytes', 'sha256', 'purpose', 'quarantined']);
});

/**
 * @param  array<string, mixed>  $paths
 * @return list<string>
 */
function racklabOpenApiDocumentedOperations(array $paths): array
{
    $operations = [];

    foreach ($paths as $path => $pathItem) {
        if (! is_array($pathItem)) {
            continue;
        }

        foreach (array_keys($pathItem) as $method) {
            if (! is_string($method)) {
                continue;
            }

            $operations[] = strtolower($method).' '.racklabOpenApiComparablePath($path);
        }
    }

    sort($operations);

    return array_values(array_unique($operations));
}

/**
 * @param  array<string, mixed>  $paths
 * @return array<string, array<string, mixed>>
 */
function racklabOpenApiDocumentedOperationsWithDetails(array $paths): array
{
    $operations = [];

    foreach ($paths as $path => $pathItem) {
        if (! is_array($pathItem)) {
            continue;
        }

        foreach ($pathItem as $method => $details) {
            if (! is_string($method)) {
                continue;
            }

            if (! is_array($details)) {
                continue;
            }

            if ($method === 'parameters') {
                continue;
            }

            $operations[strtolower($method).' '.racklabOpenApiComparablePath($path)] = $details;
        }
    }

    ksort($operations);

    return $operations;
}

/**
 * @return list<array{string, string}>
 */
function racklabOpenApiRouteOperations(): array
{
    $documentedMethods = ['delete', 'get', 'patch', 'post', 'put'];
    $operations = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with((string) $uri, 'api/v1')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            $method = strtolower((string) $method);

            if (! in_array($method, $documentedMethods, true)) {
                continue;
            }

            $operations[] = [$method, '/'.$uri];
        }
    }

    sort($operations);

    return array_values(array_unique($operations, SORT_REGULAR));
}

function racklabOpenApiComparablePath(string $path): string
{
    return (string) preg_replace('/\{[^}]+}/', '{}', $path);
}

/**
 * @param  array<string, mixed>  $operation
 * @return array<string, mixed>
 */
function racklabOpenApiJsonExample(array $operation, string $status): array
{
    $example = $operation['responses'][$status]['content']['application/json']['example'] ?? null;

    expect($example)->toBeArray();

    return $example;
}

/**
 * @param  array<string, mixed>  $example
 */
function racklabOpenApiUsesGenericFallbackExample(array $example): bool
{
    $data = $example['data'] ?? null;

    if (is_array($data) && ($data['id'] ?? null) === '01HZEXAMPLE0000000000000000') {
        return true;
    }

    return is_array($data)
        && array_key_exists(0, $data)
        && is_array($data[0])
        && ($data[0]['id'] ?? null) === '01HZEXAMPLE0000000000000000';
}

/**
 * @param  array<string, mixed>  $operation
 * @return array<string, mixed>
 */
function racklabOpenApiJsonDataSchema(array $operation, string $status): array
{
    $schema = $operation['responses'][$status]['content']['application/json']['schema']['properties']['data'] ?? null;

    expect($schema)->toBeArray();

    return $schema;
}
