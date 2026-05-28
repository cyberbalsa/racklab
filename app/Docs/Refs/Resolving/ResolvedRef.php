<?php

declare(strict_types=1);

namespace App\Docs\Refs\Resolving;

/**
 * The result of resolving a `[[kind:id]]` cross-link reference.
 *
 * A `Resolved` reference always carries a `label`; its `url` and
 * `detail` may still be null (e.g. a kind whose public detail page does
 * not exist yet, or an object with no lifecycle state). Redacted /
 * not-found / unsupported refs carry the kind (and id, which the client
 * already supplied) but never leak the target's label, URL, or status.
 */
final readonly class ResolvedRef
{
    private function __construct(
        public string $kind,
        public string $id,
        public RefResolutionStatus $status,
        public ?string $label,
        public ?string $url,
        public ?string $detail,
    ) {}

    public static function resolved(
        string $kind,
        string $id,
        string $label,
        ?string $url,
        ?string $detail,
    ): self {
        return new self($kind, $id, RefResolutionStatus::Resolved, $label, $url, $detail);
    }

    public static function redacted(string $kind, string $id): self
    {
        return new self($kind, $id, RefResolutionStatus::Redacted, null, null, null);
    }

    public static function notFound(string $kind, string $id): self
    {
        return new self($kind, $id, RefResolutionStatus::NotFound, null, null, null);
    }

    public static function unsupported(string $kind, string $id): self
    {
        return new self($kind, $id, RefResolutionStatus::Unsupported, null, null, null);
    }

    public function isVisible(): bool
    {
        return $this->status === RefResolutionStatus::Resolved;
    }

    /**
     * @return array{kind: string, id: string, status: string, label: ?string, url: ?string, detail: ?string, rbac_visible: bool}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'id' => $this->id,
            'status' => $this->status->value,
            'label' => $this->label,
            'url' => $this->url,
            'detail' => $this->detail,
            'rbac_visible' => $this->isVisible(),
        ];
    }
}
