<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use Tests\Larastan\Rules\HookspecEventTypedRule;
use Tests\Larastan\Rules\NoBareEventDispatchOnHookspecsRule;
use Tests\Larastan\Rules\NoBareScopeBypassRule;
use Tests\Larastan\Rules\NoLintOverridesRule;
use Tests\Larastan\Rules\NoSpatieBypassRule;
use Tests\Larastan\Rules\UntenantedRule;

it('flags bare tenant scope bypasses outside CrossTenantFetch', function (): void {
    $node = larastanTenancyRuleFirstNode('<?php $query->withoutGlobalScopes();', Node\Expr\MethodCall::class);
    $rule = new NoBareScopeBypassRule;

    expect($rule->processNode($node, larastanTenancyRuleScope('/home/app/Models/Project.php')))
        ->toHaveCount(1)
        ->and($rule->processNode($node, larastanTenancyRuleScope('/home/app/Domain/Tenancy/CrossTenantFetch.php')))
        ->toBe([]);
});

it('flags direct Spatie-style authorization checks outside AccessResolver', function (): void {
    $node = larastanTenancyRuleFirstNode('<?php $user->hasRole("Admin");', Node\Expr\MethodCall::class);
    $rule = new NoSpatieBypassRule;

    expect($rule->processNode($node, larastanTenancyRuleScope('/home/app/Http/Controllers/ProjectController.php')))
        ->toHaveCount(1)
        ->and($rule->processNode($node, larastanTenancyRuleScope('/home/app/Domain/Tenancy/AccessResolver.php')))
        ->toBe([]);
});

it('requires app models to declare tenant_id or opt out with Untenanted', function (): void {
    $rule = new UntenantedRule;
    $missingTenant = larastanTenancyRuleFirstNode('<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; final class Project extends Model {}', Node\Stmt\Class_::class);
    $withTenant = larastanTenancyRuleFirstNode('<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; /** @property string $tenant_id */ final class Project extends Model {}', Node\Stmt\Class_::class);
    $untenanted = larastanTenancyRuleFirstNode('<?php namespace App\Models; use App\Models\Attributes\Untenanted; use Illuminate\Database\Eloquent\Model; #[Untenanted(reason: "root")] final class Tenant extends Model {}', Node\Stmt\Class_::class);

    expect($rule->processNode($missingTenant, larastanTenancyRuleScope('/home/app/Models/Project.php')))
        ->toHaveCount(1)
        ->and($rule->processNode($withTenant, larastanTenancyRuleScope('/home/app/Models/Project.php')))
        ->toBe([])
        ->and($rule->processNode($untenanted, larastanTenancyRuleScope('/home/app/Models/Tenant.php')))
        ->toBe([]);
});

it('requires hookspec event classes to be readonly with typed properties', function (): void {
    $rule = new HookspecEventTypedRule;
    $mutable = larastanTenancyRuleFirstNode('<?php namespace App\Events\Hookspecs\Deployment; final class CreatingEvent { public $tenantId; }', Node\Stmt\Class_::class);
    $readonly = larastanTenancyRuleFirstNode('<?php namespace App\Events\Hookspecs\Deployment; final readonly class CreatingEvent { public function __construct(public string $tenantId) {} }', Node\Stmt\Class_::class);

    expect($rule->processNode($mutable, larastanTenancyRuleScope('/home/app/Events/Hookspecs/Deployment/CreatingEvent.php')))
        ->toHaveCount(2)
        ->and($rule->processNode($readonly, larastanTenancyRuleScope('/home/app/Events/Hookspecs/Deployment/CreatingEvent.php')))
        ->toBe([]);
});

it('forbids raw Event facade dispatch of hookspec events outside HookDispatcher', function (): void {
    $rule = new NoBareEventDispatchOnHookspecsRule;
    $node = larastanTenancyRuleFirstNode('<?php use Illuminate\Support\Facades\Event; Event::dispatch('.App\Events\Hookspecs\Deployment\CreatingEvent::class.'::class);', Node\Expr\StaticCall::class);

    expect($rule->processNode($node, larastanTenancyRuleScope('/home/app/Services/DeploymentService.php')))
        ->toHaveCount(1)
        ->and($rule->processNode($node, larastanTenancyRuleScope('/home/app/Plugins/HookDispatcher.php')))
        ->toBe([]);
});

it('forbids inline lint override comments in production code only', function (): void {
    $rule = new NoLintOverridesRule;
    $node = larastanTenancyRuleFirstNode('<?php /** @phpstan-ignore-next-line */ $value = 1;', Node\Stmt\Expression::class);

    expect($rule->processNode($node, larastanTenancyRuleScope('/home/app/Services/Unsafe.php')))
        ->toHaveCount(1)
        ->and($rule->processNode($node, larastanTenancyRuleScope('/home/tests/UnsafeTest.php')))
        ->toBe([]);
});

/**
 * @template T of Node
 *
 * @param  class-string<T>  $nodeClass
 * @return T
 */
function larastanTenancyRuleFirstNode(string $code, string $nodeClass): Node
{
    $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse($code) ?? [];
    $queue = $statements;

    while ($queue !== []) {
        $node = array_shift($queue);

        if ($node instanceof $nodeClass) {
            return $node;
        }

        if ($node instanceof Node) {
            foreach ($node->getSubNodeNames() as $subNodeName) {
                $child = $node->{$subNodeName};

                if ($child instanceof Node) {
                    $queue[] = $child;
                } elseif (is_array($child)) {
                    foreach ($child as $item) {
                        if ($item instanceof Node) {
                            $queue[] = $item;
                        }
                    }
                }
            }
        }
    }

    throw new RuntimeException(sprintf('Unable to find node of type %s.', $nodeClass));
}

function larastanTenancyRuleScope(string $file): Scope
{
    $scope = Mockery::mock(Scope::class);
    $scope->shouldReceive('getFile')->andReturn($file);
    $scope->shouldReceive('getClassReflection')->andReturn(null)->byDefault();

    return $scope;
}
