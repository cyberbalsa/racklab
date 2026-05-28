<?php

declare(strict_types=1);

namespace App\Docs;

use App\Models\Doc;
use App\Models\DocVersion;

final readonly class DocPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Doc $doc): array
    {
        /** @var DocVersion|null $current */
        $current = $doc->currentVersion;

        return [
            'id' => $doc->getKey(),
            'tenant_id' => $doc->tenant_id,
            'project_id' => $doc->project_id,
            'course_id' => $doc->course_id,
            'owner_user_id' => $doc->owner_user_id,
            'slug' => $doc->slug,
            'title' => $doc->title,
            'sharing_scope' => $doc->sharing_scope,
            'shared_with_tenants' => $doc->shared_with_tenants ?? [],
            'published_at' => $doc->published_at?->toIso8601String(),
            'created_at' => $doc->created_at?->toIso8601String(),
            'updated_at' => $doc->updated_at?->toIso8601String(),
            'current_version' => $current instanceof DocVersion ? self::version($current) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function version(DocVersion $version): array
    {
        return [
            'id' => $version->getKey(),
            'doc_id' => $version->doc_id,
            'version_number' => $version->version_number,
            'markdown_source' => $version->markdown_source,
            'html_cache' => $version->html_cache,
            'author_user_id' => $version->author_user_id,
            'editor_message' => $version->editor_message,
            'created_at' => $version->created_at?->toIso8601String(),
        ];
    }
}
