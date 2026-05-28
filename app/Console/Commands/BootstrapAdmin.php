<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\Tokens\TrackBTokenService;
use App\Domain\Rbac\DefaultRoleCatalog;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BootstrapAdmin extends Command
{
    protected $signature = 'racklab:bootstrap-admin
        {--email= : Email address for the first local administrator.}
        {--name= : Display name for the first local administrator.}
        {--password= : Initial password. Prefer --password-stdin outside tests.}
        {--password-stdin : Read the initial password from STDIN.}
        {--tenant-slug= : Tenant slug to create or use. Defaults to racklab.default_tenant_slug.}
        {--tenant-name= : Tenant display name when the tenant is created.}
        {--token-file= : Optional path that receives a one-time bootstrap API token.}';

    protected $description = 'Create or verify the first RackLab administrator for unattended Baseline installs.';

    public function handle(
        RbacDefaultsSynchronizer $rbac,
        PersonalProjectProvisioner $projects,
        TenantContextStore $tenantContext,
        TrackBTokenService $tokens,
    ): int {
        $email = $this->requiredStringOption('email');
        $name = $this->stringOption('name') ?: $email;
        $password = $this->password();
        $tenantSlug = $this->tenantSlug();
        $tenantName = $this->stringOption('tenant-name') ?: 'Default Tenant';

        if ($email === null || $password === null) {
            return self::FAILURE;
        }

        $rbac->sync();

        [$tenant, $user] = DB::transaction(function () use ($tenantSlug, $tenantName, $email, $name, $password): array {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->firstOrCreate(
                ['slug' => $tenantSlug],
                [
                    'name' => $tenantName,
                    'is_active' => true,
                ],
            );

            /** @var User $user */
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => $password,
                ],
            );

            return [$tenant, $user];
        });

        $context = new TenantContext(activeTenantId: $tenant->id);
        $tenantContext->set($context);
        $tenant->makeCurrent();

        try {
            $project = $projects->ensureFor($user, $context);
            $tokenFile = $this->stringOption('token-file');

            if ($tokenFile !== null) {
                $issue = $tokens->issue(
                    issuer: $user,
                    context: $context,
                    project: $project,
                    name: 'Bootstrap admin',
                    abilities: DefaultRoleCatalog::permissionsByRole()['admin'],
                    expiresAt: now()->addDay(),
                    request: Request::create('/racklab/bootstrap-admin', 'POST', server: [
                        'REMOTE_ADDR' => '127.0.0.1',
                        'HTTP_USER_AGENT' => 'racklab-bootstrap-admin',
                    ]),
                );

                $this->writeTokenFile($tokenFile, $issue->plainTextToken);
            }
        } finally {
            $tenantContext->forget();
            Tenant::forgetCurrent();
        }

        $this->components->info(sprintf('RackLab admin [%s] is ready in tenant [%s].', $email, $tenantSlug));

        return self::SUCCESS;
    }

    private function tenantSlug(): string
    {
        $slug = $this->stringOption('tenant-slug');

        if ($slug !== null) {
            return $slug;
        }

        $configured = config('racklab.default_tenant_slug', 'default');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : 'default';
    }

    private function password(): ?string
    {
        if ($this->option('password-stdin') === true) {
            $password = trim((string) stream_get_contents(STDIN));
        } else {
            $password = $this->stringOption('password') ?? '';
        }

        if ($password === '') {
            $this->components->error('Missing required option [--password] or [--password-stdin].');

            return null;
        }

        if (strlen($password) < 12) {
            $this->components->error('The bootstrap admin password must be at least 12 characters.');

            return null;
        }

        return $password;
    }

    private function requiredStringOption(string $name): ?string
    {
        $value = $this->stringOption($name);

        if ($value === null) {
            $this->components->error(sprintf('Missing required option [--%s].', $name));
        }

        return $value;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function writeTokenFile(string $path, string $token): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create token-file directory [%s].', $directory));
        }

        if (file_put_contents($path, $token.PHP_EOL) === false) {
            throw new RuntimeException(sprintf('Unable to write token file [%s].', $path));
        }

        chmod($path, 0600);
    }
}
