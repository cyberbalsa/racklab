<?php

declare(strict_types=1);

namespace App\Livewire\Docs;

use App\Docs\DocService;
use App\Docs\DocVisibilityPolicy;
use App\Docs\MarkdownRenderer;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Doc;
use App\Models\DocVersion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Browser docs author/editor. Markdown is the source of truth (the
 * TipTap WYSIWYG awaits the round-trip spike per the M8 milestone), so
 * v1 edits Markdown directly with a live rendered preview.
 *
 * Persistence goes through `DocService` (versioned, audited). Every
 * mutation is gated by `AccessResolver` against the parent Project plus
 * the draft/publish visibility policy: `docs.create` to create,
 * `docs.edit` (+ canEdit) to update, `docs.publish` to publish.
 */
final class DocEditor extends Component
{
    public ?string $docId = null;

    public string $title = '';

    public string $markdown = '';

    public string $editorMessage = '';

    public ?string $projectId = null;

    public bool $isPublished = false;

    /** @var array<string, string> */
    public array $projectOptions = [];

    public function mount(?Doc $doc = null): void
    {
        [$user, $context] = $this->context();

        if ($doc instanceof Doc) {
            $project = $this->projectForDoc($doc);
            $this->authorizeEdit($user, $context, $doc, $project);

            $this->docId = $doc->id;
            $this->title = $doc->title;
            $this->projectId = $doc->project_id;
            $this->isPublished = $doc->published_at !== null;
            $current = $doc->load('currentVersion')->currentVersion;
            $this->markdown = $current instanceof DocVersion ? $current->markdown_source : '';

            return;
        }

        $this->projectOptions = $this->creatableProjects($user, $context);
        $this->projectId = array_key_first($this->projectOptions);
    }

    public function save(): void
    {
        [$user, $context] = $this->context();

        $this->validate([
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'markdown' => ['required', 'string'],
            'editorMessage' => ['nullable', 'string', 'max:255'],
        ]);

        if ($this->docId === null) {
            $this->createDoc($user, $context);

            return;
        }

        $this->updateDoc($user, $context);
    }

    public function publish(): void
    {
        [$user, $context] = $this->context();

        if ($this->docId === null) {
            return;
        }

        $doc = $this->locateDoc($context, $this->docId);
        $project = $this->projectForDoc($doc);

        if (! $this->permits($user, 'docs.publish', $project, $context)) {
            throw new AuthorizationException('You may not publish this doc.');
        }

        app(DocService::class)->publish($user, $context, $doc);
        $this->isPublished = true;
        session()->flash('docs-status', __('racklab.docs.published_flash'));
    }

    public function render(): View
    {
        $preview = $this->markdown === ''
            ? ''
            : app(MarkdownRenderer::class)->render($this->markdown);

        return view('livewire.docs.doc-editor', ['preview' => $preview]);
    }

    private function createDoc(User $user, TenantContext $context): void
    {
        $project = $this->projectId === null ? null : $this->readableProject($this->projectId, $context);

        if (! $project instanceof Project || ! $this->permits($user, 'docs.create', $project, $context)) {
            throw new AuthorizationException('You may not create a doc in this project.');
        }

        $doc = app(DocService::class)->create(
            $user,
            $context,
            $this->title,
            $this->markdown,
            $project,
            null,
            $this->editorMessage === '' ? null : $this->editorMessage,
        );

        session()->flash('docs-status', __('racklab.docs.created_flash'));
        $this->redirectRoute('docs.edit', ['doc' => $doc->getKey()], navigate: true);
    }

    private function updateDoc(User $user, TenantContext $context): void
    {
        $doc = $this->locateDoc($context, (string) $this->docId);
        $project = $this->projectForDoc($doc);
        $this->authorizeEdit($user, $context, $doc, $project);

        app(DocService::class)->update(
            $user,
            $context,
            $doc,
            $this->title,
            $this->markdown,
            $this->editorMessage === '' ? null : $this->editorMessage,
        );

        session()->flash('docs-status', __('racklab.docs.saved_flash'));
    }

    /**
     * @return array{User, TenantContext}
     */
    private function context(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = app(TenantContextStore::class)->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        return [$user, $context];
    }

    private function locateDoc(TenantContext $context, string $docId): Doc
    {
        /** @var Doc|null $doc */
        $doc = Doc::query()->whereKey($docId)->first();

        if (! $doc instanceof Doc || $doc->tenant_id !== $context->activeTenantId) {
            throw new NotFoundHttpException('Doc not found.');
        }

        return $doc;
    }

    private function projectForDoc(Doc $doc): Project
    {
        /** @var Project|null $project */
        $project = $doc->project_id === null
            ? null
            : Project::query()->whereKey($doc->project_id)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Doc not found.');
        }

        return $project;
    }

    private function readableProject(string $projectId, TenantContext $context): ?Project
    {
        /** @var Project|null $project */
        $project = Project::query()->whereKey($projectId)->first();

        if (! $project instanceof Project || $project->tenant_id !== $context->activeTenantId) {
            return null;
        }

        return $project;
    }

    private function authorizeEdit(User $user, TenantContext $context, Doc $doc, Project $project): void
    {
        $canEdit = $this->permits($user, 'docs.edit', $project, $context)
            && app(DocVisibilityPolicy::class)->canEdit($user, $doc, $project, $context);

        if (! $canEdit) {
            // 404, not 403 — do not leak existence of docs the actor cannot edit.
            throw new NotFoundHttpException('Doc not found.');
        }
    }

    private function permits(User $user, string $permission, Project $project, TenantContext $context): bool
    {
        return app(AccessResolver::class)
            ->permitted(new ActorIdentity((string) $user->id), new Permission($permission), $project, $context)
            ->allowed;
    }

    /**
     * @return array<string, string>
     */
    private function creatableProjects(User $user, TenantContext $context): array
    {
        $options = [];

        /** @var Project $project */
        foreach (Project::query()->orderBy('name')->orderBy('id')->get() as $project) {
            if ($this->permits($user, 'docs.create', $project, $context)) {
                $options[$project->id] = $project->name;
            }
        }

        return $options;
    }
}
