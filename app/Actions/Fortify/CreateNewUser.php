<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Identity\PersonalProjectProvisioner;
use App\Models\User;
use App\Tenancy\DefaultTenantContextResolver;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final readonly class CreateNewUser implements CreatesNewUsers
{
    public function __construct(
        private DefaultTenantContextResolver $tenantContext,
        private PersonalProjectProvisioner $personalProjects,
    ) {}

    /**
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(12), 'confirmed'],
        ])->validate();

        $user = User::query()->create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
        ]);

        $context = $this->tenantContext->resolve($user);
        $this->personalProjects->ensureFor($user, $context);

        return $user;
    }
}
