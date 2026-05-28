<?php

declare(strict_types=1);

use App\Artifacts\ScriptLogArtifactWriter;
use App\Contracts\ContainerRuntime;
use App\Jobs\RunAnsiblePlaybook;
use App\Jobs\RunConsoleScript;
use App\Jobs\RunUserScript;
use App\Models\Artifact;
use App\Models\ArtifactReference;
use App\Models\ScriptRun;
use App\Models\Tenant;
use App\Models\User;
use App\Runtime\ContainerOutputArtifact;
use App\Runtime\ContainerProcessRunner;
use App\Runtime\ContainerRunResult;
use App\Runtime\FakeContainerRuntime;
use App\Runtime\NativeContainerProcessRunner;
use App\Runtime\PodmanCommandBuilder;
use App\Runtime\PodmanContainerRuntime;
use App\Runtime\PodmanStaleContainerReaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('declares hardened container manifests for each script runner job', function (): void {
    $userScript = RunUserScript::containerManifest();
    $ansible = RunAnsiblePlaybook::containerManifest();
    $console = RunConsoleScript::containerManifest();

    expect($userScript->image)->toBe('racklab/user-script:v1')
        ->and($userScript->networkMode)->toBe('none')
        ->and($userScript->readOnlyRoot)->toBeTrue()
        ->and($userScript->tmpfs)->toContain('/tmp')
        ->and($userScript->user)->toBe('10001:10001')
        ->and($userScript->pidsLimit)->toBe(512)
        ->and($ansible->image)->toBe('racklab/ansible-runner:v1')
        ->and($ansible->networkMode)->toBe('egress-via-proxy')
        ->and($console->image)->toBe('racklab/console-script:v1')
        ->and($console->networkMode)->toBe('via-console-proxy')
        ->and($console->mounts)->toContain('/run/racklab/console-proxy.sock:/run/console-proxy.sock:ro');
});

it('runs a user script through the container runtime and persists the result', function (): void {
    [$tenant, $user] = provisionScriptRunActor();
    $runtime = new class implements ContainerRuntime
    {
        public ?string $image = null;

        public ?string $networkMode = null;

        public function run(App\Runtime\ContainerRunRequest $request): ContainerRunResult
        {
            $this->image = $request->manifest->image;
            $this->networkMode = $request->manifest->networkMode;

            return new ContainerRunResult(
                exitCode: 0,
                stdout: "script ok\n",
                stderr: '',
                metadata: ['container_id' => 'fake-container-1'],
            );
        }
    };

    app()->instance(ContainerRuntime::class, $runtime);

    $run = ScriptRun::query()->create([
        'tenant_id' => $tenant->getKey(),
        'actor_user_id' => $user->getKey(),
        'runner_kind' => 'user_script',
        'state' => 'queued',
        'command' => ['python', 'main.py'],
        'source' => 'print("ok")',
        'metadata' => [],
    ]);

    RunUserScript::dispatchSync($tenant->getKey(), $run->getKey());

    $run->refresh();

    expect($runtime->image)->toBe('racklab/user-script:v1')
        ->and($runtime->networkMode)->toBe('none')
        ->and($run->state)->toBe('succeeded')
        ->and($run->stdout)->toBe("script ok\n")
        ->and($run->exit_code)->toBe(0)
        ->and($run->metadata['container_id'])->toBe('fake-container-1');
});

it('marks script runs failed when the container exits non-zero', function (): void {
    [$tenant, $user] = provisionScriptRunActor();

    app()->instance(ContainerRuntime::class, new class implements ContainerRuntime
    {
        public function run(App\Runtime\ContainerRunRequest $request): ContainerRunResult
        {
            return new ContainerRunResult(
                exitCode: 2,
                stdout: '',
                stderr: "boom\n",
                metadata: ['container_id' => 'fake-container-2'],
            );
        }
    });

    $run = ScriptRun::query()->create([
        'tenant_id' => $tenant->getKey(),
        'actor_user_id' => $user->getKey(),
        'runner_kind' => 'user_script',
        'state' => 'queued',
        'command' => ['sh', 'run.sh'],
        'source' => 'exit 2',
        'metadata' => [],
    ]);

    RunUserScript::dispatchSync($tenant->getKey(), $run->getKey());

    expect($run->refresh()->state)->toBe('failed')
        ->and($run->stderr)->toBe("boom\n")
        ->and($run->exit_code)->toBe(2);
});

it('redacts configured secret values from persisted script stdout and stderr', function (): void {
    [$tenant, $user] = provisionScriptRunActor();

    app()->instance(ContainerRuntime::class, new class implements ContainerRuntime
    {
        public function run(App\Runtime\ContainerRunRequest $request): ContainerRunResult
        {
            return new ContainerRunResult(
                exitCode: 1,
                stdout: "token=super-secret-value\n",
                stderr: "failed with super-secret-value\n",
                metadata: ['container_id' => 'fake-redaction-container'],
            );
        }
    });

    $run = ScriptRun::query()->create([
        'tenant_id' => $tenant->getKey(),
        'actor_user_id' => $user->getKey(),
        'runner_kind' => 'user_script',
        'state' => 'queued',
        'command' => ['sh', 'run.sh'],
        'source' => 'echo "$SECRET"',
        'metadata' => [
            'redactions' => ['super-secret-value'],
        ],
    ]);

    RunUserScript::dispatchSync($tenant->getKey(), $run->getKey());

    $run->refresh();

    expect($run->stdout)->toBe("token=[redacted]\n")
        ->and($run->stderr)->toBe("failed with [redacted]\n")
        ->and(json_encode($run->metadata))->not->toContain('super-secret-value')
        ->and($run->metadata['redaction_count'])->toBe(2);
});

it('captures redacted script output as referenced log artifacts', function (): void {
    Storage::fake('local');
    [$tenant, $user] = provisionScriptRunActor();

    app()->instance(ContainerRuntime::class, new class implements ContainerRuntime
    {
        public function run(App\Runtime\ContainerRunRequest $request): ContainerRunResult
        {
            return new ContainerRunResult(
                exitCode: 1,
                stdout: "stdout api-token-123\n",
                stderr: "stderr api-token-123\n",
                metadata: ['container_id' => 'fake-log-container'],
            );
        }
    });

    $run = ScriptRun::query()->create([
        'tenant_id' => $tenant->getKey(),
        'actor_user_id' => $user->getKey(),
        'runner_kind' => 'user_script',
        'state' => 'queued',
        'command' => ['sh', 'run.sh'],
        'source' => 'echo "$SECRET"',
        'metadata' => [
            'redactions' => ['api-token-123'],
        ],
    ]);

    RunUserScript::dispatchSync($tenant->getKey(), $run->getKey());

    $run->refresh();
    $stdoutArtifact = Artifact::query()->whereKey($run->metadata['stdout_artifact_id'])->firstOrFail();
    $stderrArtifact = Artifact::query()->whereKey($run->metadata['stderr_artifact_id'])->firstOrFail();

    expect($stdoutArtifact->kind)->toBe('script_log')
        ->and($stdoutArtifact->tenant_id)->toBe($tenant->getKey())
        ->and($stdoutArtifact->content_type)->toBe('text/plain; charset=utf-8')
        ->and($stdoutArtifact->sha256)->toBe(hash('sha256', "stdout [redacted]\n"))
        ->and($stdoutArtifact->size_bytes)->toBe(strlen("stdout [redacted]\n"))
        ->and($stderrArtifact->kind)->toBe('script_log')
        ->and(Storage::disk('local')->get($stdoutArtifact->storage_path))->toBe("stdout [redacted]\n")
        ->and(Storage::disk('local')->get($stderrArtifact->storage_path))->toBe("stderr [redacted]\n")
        ->and(ArtifactReference::query()
            ->where('artifact_id', $stdoutArtifact->getKey())
            ->where('reference_type', ScriptRun::class)
            ->where('reference_id', $run->getKey())
            ->where('purpose', 'script_stdout')
            ->exists())->toBeTrue()
        ->and(ArtifactReference::query()
            ->where('artifact_id', $stderrArtifact->getKey())
            ->where('reference_type', ScriptRun::class)
            ->where('reference_id', $run->getKey())
            ->where('purpose', 'script_stderr')
            ->exists())->toBeTrue();
});

it('captures runner-produced result, screenshot, and serial artifacts', function (): void {
    Storage::fake('local');
    [$tenant, $user] = provisionScriptRunActor();

    app()->instance(ContainerRuntime::class, new class implements ContainerRuntime
    {
        public function run(App\Runtime\ContainerRunRequest $request): ContainerRunResult
        {
            return new ContainerRunResult(
                exitCode: 0,
                stdout: '',
                stderr: '',
                metadata: ['container_id' => 'fake-artifact-container'],
                artifacts: [
                    new ContainerOutputArtifact(
                        kind: 'script_log',
                        purpose: 'ansible_result',
                        content: "{\"changed\":true,\"token\":\"artifact-secret\"}\n",
                        contentType: 'application/json',
                        filename: 'ansible-result.json',
                    ),
                    new ContainerOutputArtifact(
                        kind: 'script_screenshot',
                        purpose: 'console_screenshot',
                        content: 'PNGDATA',
                        contentType: 'image/png',
                        filename: 'login-screen.png',
                    ),
                    new ContainerOutputArtifact(
                        kind: 'script_serial',
                        purpose: 'console_serial',
                        content: "serial artifact-secret\n",
                        contentType: 'text/plain; charset=utf-8',
                        filename: 'serial.log',
                    ),
                ],
            );
        }
    });

    $run = ScriptRun::query()->create([
        'tenant_id' => $tenant->getKey(),
        'actor_user_id' => $user->getKey(),
        'runner_kind' => 'console_script',
        'state' => 'queued',
        'command' => ['racklab-console', 'run'],
        'source' => '[]',
        'metadata' => [
            'redactions' => ['artifact-secret'],
        ],
    ]);

    RunConsoleScript::dispatchSync($tenant->getKey(), $run->getKey());

    $run->refresh();
    $artifacts = Artifact::query()
        ->whereIn('id', $run->metadata['output_artifact_ids'])
        ->orderBy('kind')
        ->get();

    expect($artifacts)->toHaveCount(3)
        ->and($artifacts->pluck('kind')->all())->toBe(['script_log', 'script_screenshot', 'script_serial'])
        ->and($run->metadata['redaction_count'])->toBe(2)
        ->and(json_encode($run->metadata))->not->toContain('artifact-secret');

    $log = $artifacts->firstWhere('kind', 'script_log');
    $screenshot = $artifacts->firstWhere('kind', 'script_screenshot');
    $serial = $artifacts->firstWhere('kind', 'script_serial');

    expect($log)->toBeInstanceOf(Artifact::class)
        ->and($screenshot)->toBeInstanceOf(Artifact::class)
        ->and($serial)->toBeInstanceOf(Artifact::class)
        ->and(Storage::disk('local')->get($log->storage_path))->toBe("{\"changed\":true,\"token\":\"[redacted]\"}\n")
        ->and(Storage::disk('local')->get($screenshot->storage_path))->toBe('PNGDATA')
        ->and(Storage::disk('local')->get($serial->storage_path))->toBe("serial [redacted]\n")
        ->and(ArtifactReference::query()
            ->where('artifact_id', $log->getKey())
            ->where('reference_type', ScriptRun::class)
            ->where('reference_id', $run->getKey())
            ->where('purpose', 'ansible_result')
            ->exists())->toBeTrue()
        ->and(ArtifactReference::query()
            ->where('artifact_id', $screenshot->getKey())
            ->where('purpose', 'console_screenshot')
            ->exists())->toBeTrue()
        ->and(ArtifactReference::query()
            ->where('artifact_id', $serial->getKey())
            ->where('purpose', 'console_serial')
            ->exists())->toBeTrue();
});

it('runs Ansible playbooks through the opt-in fake runtime and captures result artifacts', function (): void {
    Storage::fake('local');
    [$tenant, $user] = provisionScriptRunActor();
    app()->instance(ContainerRuntime::class, new FakeContainerRuntime);

    $run = ScriptRun::query()->create([
        'tenant_id' => $tenant->getKey(),
        'actor_user_id' => $user->getKey(),
        'runner_kind' => 'ansible',
        'state' => 'queued',
        'command' => ['ansible-playbook', 'site.yml'],
        'source' => <<<'YAML'
- hosts: all
  gather_facts: false
  tasks:
    - name: hello
      ansible.builtin.debug:
        msg: hello
YAML,
        'metadata' => [],
    ]);

    RunAnsiblePlaybook::dispatchSync($tenant->getKey(), $run->getKey());

    $run->refresh();
    $artifact = Artifact::query()->whereKey($run->metadata['output_artifact_ids'][0])->firstOrFail();

    expect($run->state)->toBe('succeeded')
        ->and($run->metadata['runtime'])->toBe('fake')
        ->and($run->metadata['runner_kind'])->toBe('ansible')
        ->and($run->metadata['plays'])->toBe(1)
        ->and($run->metadata['tasks'])->toBe(1)
        ->and($artifact->kind)->toBe('script_log')
        ->and(ArtifactReference::query()
            ->where('artifact_id', $artifact->getKey())
            ->where('purpose', 'ansible_result')
            ->exists())->toBeTrue()
        ->and(Storage::disk('local')->get($artifact->storage_path))->toContain('"runner":"ansible"');
});

it('runs console automation through the opt-in fake runtime and captures screenshot and serial artifacts', function (): void {
    Storage::fake('local');
    [$tenant, $user] = provisionScriptRunActor();
    app()->instance(ContainerRuntime::class, new FakeContainerRuntime);

    $run = ScriptRun::query()->create([
        'tenant_id' => $tenant->getKey(),
        'actor_user_id' => $user->getKey(),
        'runner_kind' => 'console_script',
        'state' => 'queued',
        'command' => ['racklab-console', 'run'],
        'source' => json_encode([
            ['op' => 'type_string', 'text' => 'student'],
            ['op' => 'send_key', 'key' => 'ENTER'],
            ['op' => 'wait_screen', 'needle' => '$'],
            ['op' => 'capture_screenshot', 'name' => 'shell-prompt'],
            ['op' => 'capture_serial', 'name' => 'boot-log'],
        ], JSON_THROW_ON_ERROR),
        'metadata' => [],
    ]);

    RunConsoleScript::dispatchSync($tenant->getKey(), $run->getKey());

    $run->refresh();
    $artifacts = Artifact::query()
        ->whereIn('id', $run->metadata['output_artifact_ids'])
        ->orderBy('kind')
        ->get();

    expect($run->state)->toBe('succeeded')
        ->and($run->metadata['runtime'])->toBe('fake')
        ->and($run->metadata['runner_kind'])->toBe('console_script')
        ->and($run->metadata['console_steps_executed'])->toBe(5)
        ->and($artifacts->pluck('kind')->all())->toBe(['script_screenshot', 'script_serial'])
        ->and(Storage::disk('local')->get($artifacts->firstWhere('kind', 'script_screenshot')->storage_path))->toContain('fake screenshot')
        ->and(Storage::disk('local')->get($artifacts->firstWhere('kind', 'script_serial')->storage_path))->toContain('wait_screen:$');
});

it('binds the fake container runtime only when explicitly configured', function (): void {
    config(['racklab.container_runtime' => 'fake']);

    expect(app(ContainerRuntime::class))->toBeInstanceOf(FakeContainerRuntime::class);
});

it('builds a hardened Podman command from the manifest and script run command', function (): void {
    [, $user] = provisionScriptRunActor();
    $run = ScriptRun::query()->create([
        'tenant_id' => Tenant::query()->firstOrFail()->getKey(),
        'actor_user_id' => $user->getKey(),
        'runner_kind' => 'user_script',
        'state' => 'queued',
        'command' => ['python', 'main.py'],
        'source' => 'print("ok")',
        'metadata' => [],
    ]);

    $command = app(PodmanCommandBuilder::class)->build($run, RunUserScript::containerManifest());

    expect($command)->toContain('podman')
        ->and($command)->toContain('--rm')
        ->and($command)->toContain('--label=racklab.kind=script-run')
        ->and($command)->toContain('--label=racklab.script_run_id='.$run->getKey())
        ->and($command)->toContain('--network=none')
        ->and($command)->toContain('--read-only')
        ->and($command)->toContain('--tmpfs=/tmp')
        ->and($command)->toContain('--user=10001:10001')
        ->and($command)->toContain('--cap-drop=all')
        ->and($command)->toContain('--security-opt=no-new-privileges')
        ->and($command)->toContain('--cpus=2')
        ->and($command)->toContain('--memory=4g')
        ->and($command)->toContain('--pids-limit=512')
        ->and(array_slice($command, -3))->toBe(['racklab/user-script:v1', 'python', 'main.py']);
});

it('allows an explicit Podman binary prefix for rootful integration hosts', function (): void {
    [, $user] = provisionScriptRunActor();
    $run = ScriptRun::query()->create([
        'tenant_id' => Tenant::query()->firstOrFail()->getKey(),
        'actor_user_id' => $user->getKey(),
        'runner_kind' => 'user_script',
        'state' => 'queued',
        'command' => ['sh', '-c', 'echo ok'],
        'source' => 'echo ok',
        'metadata' => [],
    ]);

    $builder = new PodmanCommandBuilder(['sudo', 'podman']);

    expect(array_slice($builder->build($run, RunUserScript::containerManifest()), 0, 3))->toBe(['sudo', 'podman', 'run'])
        ->and($builder->cleanup($run))->toBe(['sudo', 'podman', 'rm', '-f', '--ignore', '--time=0', 'racklab-script-'.$run->getKey()])
        ->and($builder->listScriptContainers())->toBe([
            'sudo',
            'podman',
            'ps',
            '-a',
            '--filter=label=racklab.kind=script-run',
            '--format=json',
        ]);
});

it('binds the configured Podman binary prefix from RackLab config', function (): void {
    config(['racklab.podman.binary' => 'sudo podman']);
    app()->forgetInstance(PodmanCommandBuilder::class);

    expect(app(PodmanCommandBuilder::class)->listScriptContainers())->toBe([
        'sudo',
        'podman',
        'ps',
        '-a',
        '--filter=label=racklab.kind=script-run',
        '--format=json',
    ]);
});

it('runs Podman through an injectable process runner', function (): void {
    [, $user] = provisionScriptRunActor();
    $run = ScriptRun::query()->create([
        'tenant_id' => Tenant::query()->firstOrFail()->getKey(),
        'actor_user_id' => $user->getKey(),
        'runner_kind' => 'user_script',
        'state' => 'queued',
        'command' => ['sh', 'run.sh'],
        'source' => 'echo ok',
        'metadata' => [],
    ]);
    $processRunner = new class implements ContainerProcessRunner
    {
        /**
         * @var list<string>
         */
        public array $command = [];

        /**
         * @param  list<string>  $command
         */
        public function run(array $command, int $timeoutSeconds): ContainerRunResult
        {
            $this->command = $command;

            return new ContainerRunResult(0, "podman ok\n", '', ['process' => 'fake']);
        }
    };

    $runtime = new PodmanContainerRuntime(new PodmanCommandBuilder, $processRunner);
    $result = $runtime->run(new App\Runtime\ContainerRunRequest($run, RunUserScript::containerManifest()));

    expect($processRunner->command)->toContain('racklab/user-script:v1')
        ->and($result->stdout)->toBe("podman ok\n")
        ->and($result->metadata['process'])->toBe('fake')
        ->and($result->metadata['runtime'])->toBe('podman');
});

it('marks Podman script runs timed out and cleans up the named container', function (): void {
    [$tenant, $user] = provisionScriptRunActor();
    $run = ScriptRun::query()->create([
        'tenant_id' => $tenant->getKey(),
        'actor_user_id' => $user->getKey(),
        'runner_kind' => 'user_script',
        'state' => 'queued',
        'command' => ['sh', 'run.sh'],
        'source' => 'sleep 999',
        'metadata' => ['redactions' => ['secret-timeout-token']],
    ]);
    $processRunner = new class implements ContainerProcessRunner
    {
        /**
         * @var list<list<string>>
         */
        public array $commands = [];

        /**
         * @var list<int>
         */
        public array $timeouts = [];

        /**
         * @param  list<string>  $command
         */
        public function run(array $command, int $timeoutSeconds): ContainerRunResult
        {
            $this->commands[] = $command;
            $this->timeouts[] = $timeoutSeconds;

            if (count($this->commands) === 1) {
                return new ContainerRunResult(
                    exitCode: 124,
                    stdout: "partial secret-timeout-token\n",
                    stderr: "timed out with secret-timeout-token\n",
                    metadata: ['timed_out' => true],
                    timedOut: true,
                );
            }

            return new ContainerRunResult(0, '', '', ['cleanup' => 'ok']);
        }
    };

    $runtime = new PodmanContainerRuntime(new PodmanCommandBuilder, $processRunner);

    (new App\Runtime\ScriptContainerRunner($runtime, app(ScriptLogArtifactWriter::class)))
        ->run($run->getKey(), RunUserScript::containerManifest());

    $run->refresh();

    expect($run->state)->toBe('timed_out')
        ->and($run->exit_code)->toBe(124)
        ->and($run->stdout)->toBe("partial [redacted]\n")
        ->and($run->stderr)->toBe("timed out with [redacted]\n")
        ->and($run->metadata['timed_out'])->toBeTrue()
        ->and($run->metadata['runtime'])->toBe('podman')
        ->and($run->metadata['container_name'])->toBe('racklab-script-'.$run->getKey())
        ->and($run->metadata['cleanup_exit_code'])->toBe(0)
        ->and($run->metadata['cleanup_timed_out'])->toBeFalse()
        ->and($processRunner->commands[1])->toBe(['podman', 'rm', '-f', '--ignore', '--time=0', 'racklab-script-'.$run->getKey()])
        ->and($processRunner->timeouts[1])->toBe(30);
});

it('normalizes native process timeouts into container run results', function (): void {
    $result = (new NativeContainerProcessRunner)->run([PHP_BINARY, '-r', 'sleep(2);'], 1);

    expect($result->timedOut)->toBeTrue()
        ->and($result->exitCode)->toBe(124)
        ->and($result->metadata['timed_out'])->toBeTrue()
        ->and($result->metadata['timeout_seconds'])->toBe(1)
        ->and($result->stderr)->toContain('timed out');
});

it('reaps stale RackLab script containers by Podman label', function (): void {
    $processRunner = new class implements ContainerProcessRunner
    {
        /**
         * @var list<list<string>>
         */
        public array $commands = [];

        /**
         * @param  list<string>  $command
         */
        public function run(array $command, int $timeoutSeconds): ContainerRunResult
        {
            $this->commands[] = $command;

            if (count($this->commands) === 1) {
                return new ContainerRunResult(0, json_encode([
                    [
                        'Names' => ['racklab-script-stale'],
                        'Labels' => [
                            'racklab.kind' => 'script-run',
                            'racklab.created_at' => '600',
                        ],
                    ],
                    [
                        'Names' => ['racklab-script-fresh'],
                        'Labels' => [
                            'racklab.kind' => 'script-run',
                            'racklab.created_at' => '950',
                        ],
                    ],
                    [
                        'Names' => ['not-racklab-script'],
                        'Labels' => [
                            'racklab.kind' => 'script-run',
                            'racklab.created_at' => '500',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR), '');
            }

            return new ContainerRunResult(0, '', '');
        }
    };

    $reaper = new PodmanStaleContainerReaper(new PodmanCommandBuilder, $processRunner);

    $reaped = $reaper->reap(maxAgeSeconds: 300, now: new DateTimeImmutable('@1000'));

    expect($reaped)->toBe(1)
        ->and($processRunner->commands[0])->toBe([
            'podman',
            'ps',
            '-a',
            '--filter=label=racklab.kind=script-run',
            '--format=json',
        ])
        ->and($processRunner->commands[1])->toBe(['podman', 'rm', '-f', '--ignore', '--time=0', 'racklab-script-stale']);
});

it('exposes stale script container reaping through Artisan', function (): void {
    $processRunner = new class implements ContainerProcessRunner
    {
        /**
         * @param  list<string>  $command
         */
        public function run(array $command, int $timeoutSeconds): ContainerRunResult
        {
            return new ContainerRunResult(0, '[]', '');
        }
    };
    app()->instance(ContainerProcessRunner::class, $processRunner);

    expect(Artisan::call('racklab:reap-script-containers', ['--max-age' => 600]))->toBe(0);
});

/**
 * @return array{Tenant, User}
 */
function provisionScriptRunActor(): array
{
    return [
        Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']),
        User::factory()->create(['name' => 'Script Runner']),
    ];
}
