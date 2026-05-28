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
];
