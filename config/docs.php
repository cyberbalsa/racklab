<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-link resolution audit sampling
    |--------------------------------------------------------------------------
    |
    | Docs status pills poll the resolver endpoint on an interval, so
    | successful resolutions are high-volume. This rate (0.0–1.0) is the
    | fraction of *successful* resolutions that are written to the audit
    | log. Security-relevant outcomes (redacted / not-found / unsupported)
    | are always audited regardless of this rate.
    |
    */
    'ref_resolve_audit_sample_rate' => (float) env('RACKLAB_DOCS_REF_RESOLVE_AUDIT_SAMPLE_RATE', 0.1),

    /*
    |--------------------------------------------------------------------------
    | Cross-link resolution audit dedup window
    |--------------------------------------------------------------------------
    |
    | Every resolution outcome (including redacted / not-found / unsupported)
    | is de-duplicated per (actor, kind, id, status) within this rolling
    | window of seconds, so a status pill polling a bad or denied ref on an
    | interval records at most one audit row per window instead of one per
    | poll. Set to 0 to disable dedup (record every sampled resolution).
    |
    */
    'ref_resolve_audit_dedup_window_seconds' => (int) env('RACKLAB_DOCS_REF_RESOLVE_AUDIT_DEDUP_WINDOW_SECONDS', 300),
];
