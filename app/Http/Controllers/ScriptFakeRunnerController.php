<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Jobs\RunAnsiblePlaybook;
use App\Jobs\RunConsoleScript;
use App\Models\Project;
use App\Models\Script;
use App\Models\ScriptApproval;
use App\Models\ScriptRun;
use App\Models\ScriptVersion;
use App\Models\User;
use App\Scripts\AnsiblePlaybookValidator;
use App\Scripts\ConsoleScriptPrimitiveValidator;
use App\Scripts\ScriptRunnerRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ScriptFakeRunnerController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        AnsiblePlaybookValidator $ansiblePlaybooks,
        ConsoleScriptPrimitiveValidator $consoleScripts,
    ): RedirectResponse {
        $request->validate([
            'project_id' => ['required', 'string'],
            'runner_kind' => ['required', 'string', 'in:ansible,console_script'],
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        $project = $this->project($request->string('project_id')->toString());
        $runnerKind = $request->string('runner_kind')->toString();
        $this->authorizeRunner($accessResolver, $user, $context, $project, $runnerKind);

        $command = $this->command($runnerKind);
        $source = $this->source($runnerKind);
        $ansiblePlaybooks->assertValid($runnerKind, $source);
        $consoleScripts->assertValid($runnerKind, $source);
        $run = DB::transaction(function () use ($context, $project, $user, $runnerKind, $command, $source): ScriptRun {
            $script = $this->createScript($context, $project, $user, $runnerKind, $command, $source);
            /** @var ScriptVersion $version */
            $version = $script->currentVersion()->firstOrFail();

            ScriptApproval::query()->create([
                'tenant_id' => $context->activeTenantId,
                'script_id' => $script->getKey(),
                'script_version_id' => $version->getKey(),
                'approved_by_id' => $user->getKey(),
                'scope_type' => 'project',
                'scope_id' => $project->getKey(),
                'state' => 'active',
                'metadata' => ['source' => 'dashboard_fake_runner'],
            ]);

            /** @var ScriptRun $run */
            $run = ScriptRun::query()->create([
                'tenant_id' => $context->activeTenantId,
                'actor_user_id' => $user->getKey(),
                'project_id' => $project->getKey(),
                'script_id' => $script->getKey(),
                'script_version_id' => $version->getKey(),
                'runner_kind' => $runnerKind,
                'state' => 'queued',
                'command' => $version->command,
                'source' => $version->source,
                'metadata' => [
                    'approval_id' => $script->approvals()->latest('created_at')->value('id'),
                    'dashboard_fake_runner' => true,
                ],
            ]);

            return $run;
        });

        $this->dispatchRun($context->activeTenantId, $run->resourceId(), $runnerKind);

        return redirect()
            ->route('dashboard')
            ->with('script_run_id', $run->getKey());
    }

    private function project(string $projectId): Project
    {
        /** @var Project|null $project */
        $project = Project::query()->whereKey($projectId)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        return $project;
    }

    private function authorizeRunner(
        AccessResolver $accessResolver,
        User $user,
        TenantContext $context,
        Project $project,
        string $runnerKind,
    ): void {
        $actor = new ActorIdentity((string) $user->id);

        foreach (['project.read', ScriptRunnerRegistry::createPermission($runnerKind), 'script.approve'] as $permission) {
            if (! $accessResolver->permitted($actor, new Permission($permission), $project, $context)->allowed) {
                throw new AuthorizationException('You are not allowed to run this automation workflow.');
            }
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function createScript(
        TenantContext $context,
        Project $project,
        User $user,
        string $runnerKind,
        array $command,
        string $source,
    ): Script {
        /** @var Script $script */
        $script = Script::query()->create([
            'tenant_id' => $context->activeTenantId,
            'project_id' => $project->getKey(),
            'owner_user_id' => $user->getKey(),
            'name' => $runnerKind === 'ansible' ? 'Dashboard Ansible Check' : 'Dashboard Console Check',
            'slug' => $runnerKind.'-dashboard-'.Str::lower(Str::ulid()->toBase32()),
            'runner_kind' => $runnerKind,
            'state' => 'draft',
            'sharing_scope' => 'tenant_local',
            'shared_with_tenants' => [],
            'metadata' => ['source' => 'dashboard_fake_runner'],
        ]);

        /** @var ScriptVersion $version */
        $version = ScriptVersion::query()->create([
            'tenant_id' => $context->activeTenantId,
            'script_id' => $script->getKey(),
            'created_by_id' => $user->getKey(),
            'version_number' => 1,
            'command' => $command,
            'source' => $source,
            'executable_hash' => ScriptRunnerRegistry::executableHash($command, $source),
            'metadata' => [],
        ]);

        $script->forceFill(['current_version_id' => $version->getKey()])->save();

        return $script->refresh();
    }

    /**
     * @return list<string>
     */
    private function command(string $runnerKind): array
    {
        return $runnerKind === 'ansible'
            ? ['ansible-playbook', 'site.yml']
            : ['racklab-console', 'run'];
    }

    private function source(string $runnerKind): string
    {
        if ($runnerKind === 'ansible') {
            return <<<'YAML'
- hosts: all
  gather_facts: false
  tasks:
    - name: dashboard connectivity check
      ansible.builtin.debug:
        msg: racklab fake runtime
YAML;
        }

        return json_encode([
            ['op' => 'type_string', 'text' => 'student'],
            ['op' => 'send_key', 'key' => 'ENTER'],
            ['op' => 'wait_screen', 'needle' => '$'],
            ['op' => 'capture_screenshot', 'name' => 'shell-prompt'],
            ['op' => 'capture_serial', 'name' => 'boot-log'],
        ], JSON_THROW_ON_ERROR);
    }

    private function dispatchRun(string $tenantId, string $runId, string $runnerKind): void
    {
        if (config('racklab.container_runtime') === 'fake') {
            match ($runnerKind) {
                'ansible' => RunAnsiblePlaybook::dispatchSync($tenantId, $runId),
                'console_script' => RunConsoleScript::dispatchSync($tenantId, $runId),
                default => null,
            };

            return;
        }

        match ($runnerKind) {
            'ansible' => RunAnsiblePlaybook::dispatch($tenantId, $runId),
            'console_script' => RunConsoleScript::dispatch($tenantId, $runId),
            default => null,
        };
    }
}
