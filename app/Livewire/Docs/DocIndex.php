<?php

declare(strict_types=1);

namespace App\Livewire\Docs;

use App\Docs\VisibleDocList;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Browser docs index: lists the docs the actor may read in the active
 * tenant (AccessResolver `docs.view` + draft/publish visibility), with a
 * read link, a per-doc Edit affordance, and a New-doc action.
 */
final class DocIndex extends Component
{
    public function render(): View
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = app(TenantContextStore::class)->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        return view('livewire.docs.doc-index', [
            'docs' => app(VisibleDocList::class)->forUser($user, $context),
        ]);
    }
}
