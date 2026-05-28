<?php

declare(strict_types=1);

namespace App\Docs;

use App\Audit\AuditEventWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\Course;
use App\Models\Doc;
use App\Models\DocVersion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns Doc lifecycle:
 * - create()  — persist new Doc + initial DocVersion + html cache.
 * - update()  — bump version_number, persist new DocVersion, advance
 *               current_version_id; old versions retained.
 * - publish() — stamp published_at.
 *
 * Audit emissions land on event_type=`docs.page` with action
 * `create` / `update` / `publish`. PRD §22 retention (default 50
 * versions or 1 year, whichever is larger) is enforced in M8 S4 by a
 * background reaper; S1/S2 simply append-only.
 */
final readonly class DocService
{
    public const string AUDIT_EVENT = 'docs.page';

    public function __construct(
        private AuditEventWriter $auditEvents,
        private MarkdownRenderer $renderer,
    ) {}

    public function create(
        User $actor,
        TenantContext $context,
        string $title,
        string $markdown,
        ?Project $project = null,
        ?Course $course = null,
        ?string $editorMessage = null,
    ): Doc {
        return DB::transaction(function () use ($actor, $context, $title, $markdown, $project, $course, $editorMessage): Doc {
            /** @var Doc $doc */
            $doc = Doc::query()->create([
                'tenant_id' => $context->activeTenantId,
                'project_id' => $project?->getKey(),
                'course_id' => $course?->getKey(),
                'owner_user_id' => $actor->getKey(),
                'slug' => $this->uniqueSlug($context, $title),
                'title' => $title,
                'sharing_scope' => 'tenant_local',
                'shared_with_tenants' => [],
                'published_at' => null,
            ]);

            $version = $this->persistVersion($doc, $actor, $markdown, 1, $editorMessage ?? 'initial version');
            $doc->forceFill(['current_version_id' => $version->getKey()])->save();

            $this->audit($actor, $context, $doc, 'create', [
                'doc_id' => $doc->resourceId(),
                'version_id' => $version->resourceId(),
                'project_id' => $project?->getKey(),
                'course_id' => $course?->getKey(),
            ]);

            return $doc;
        });
    }

    public function update(
        User $actor,
        TenantContext $context,
        Doc $doc,
        string $title,
        string $markdown,
        ?string $editorMessage = null,
    ): Doc {
        return DB::transaction(function () use ($actor, $context, $doc, $title, $markdown, $editorMessage): Doc {
            // Codex M8 S2 P2-2: serialize concurrent edits. The
            // `(doc_id, version_number)` unique constraint already protects
            // against corruption, but two concurrent updates without a row
            // lock both compute `max+1 = N` and one hits a 500 on insert.
            // `lockForUpdate` makes them sequential cleanly.
            Doc::query()->whereKey($doc->getKey())->lockForUpdate()->first();

            $nextVersion = $this->nextVersionNumber($doc);
            $version = $this->persistVersion($doc, $actor, $markdown, $nextVersion, $editorMessage);

            $doc->forceFill([
                'title' => $title,
                'current_version_id' => $version->getKey(),
            ])->save();

            $this->audit($actor, $context, $doc, 'update', [
                'doc_id' => $doc->resourceId(),
                'version_id' => $version->resourceId(),
                'version_number' => $nextVersion,
            ]);

            return $doc;
        });
    }

    public function publish(User $actor, TenantContext $context, Doc $doc): Doc
    {
        // Idempotent: re-publishing an already-published doc is a no-op
        // and does NOT emit a fresh audit row.
        if ($doc->published_at !== null) {
            return $doc;
        }

        // Codex M8 S2 P2-1: publish was non-atomic — the doc could land
        // published before the audit row was inserted. Wrap both in a
        // transaction so an audit-emitter failure rolls back the publish.
        return DB::transaction(function () use ($actor, $context, $doc): Doc {
            $doc->forceFill(['published_at' => now()])->save();

            $this->audit($actor, $context, $doc, 'publish', [
                'doc_id' => $doc->resourceId(),
            ]);

            return $doc;
        });
    }

    private function persistVersion(Doc $doc, User $author, string $markdown, int $versionNumber, ?string $editorMessage): DocVersion
    {
        $html = $this->renderer->render($markdown);

        /** @var DocVersion $version */
        $version = DocVersion::query()->create([
            'tenant_id' => $doc->tenant_id,
            'doc_id' => $doc->getKey(),
            'version_number' => $versionNumber,
            'markdown_source' => $markdown,
            'html_cache' => $html,
            'author_user_id' => $author->getKey(),
            'editor_message' => $editorMessage,
        ]);

        return $version;
    }

    private function nextVersionNumber(Doc $doc): int
    {
        /** @var int $max */
        $max = DocVersion::query()
            ->where('doc_id', $doc->getKey())
            ->max('version_number') ?? 0;

        return $max + 1;
    }

    private function uniqueSlug(TenantContext $context, string $title): string
    {
        $base = Str::slug($title);
        if ($base === '') {
            $base = 'doc-'.Str::lower(Str::random(8));
        }

        $candidate = $base;
        $suffix = 2;

        while (Doc::query()->where('tenant_id', $context->activeTenantId)->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(User $actor, TenantContext $context, Doc $doc, string $action, array $metadata): void
    {
        $this->auditEvents->append([
            'event_type' => self::AUDIT_EVENT,
            'action' => $action,
            'result' => 'allowed',
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $doc->resourceType(),
            'resource_id' => $doc->resourceId(),
            'resource_tenant' => $doc->tenant_id,
            'target_tenant_set' => [$context->activeTenantId, $doc->tenant_id],
            'effective_permissions' => match ($action) {
                'create' => ['docs.create'],
                'update' => ['docs.edit'],
                'publish' => ['docs.publish'],
                default => [],
            },
            'metadata' => $metadata,
        ]);
    }
}
