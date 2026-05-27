<?php

declare(strict_types=1);

it('defines Pa11y coverage for the scaffold browser routes', function (): void {
    $config = (string) file_get_contents(base_path('.pa11yci.cjs'));

    expect($config)->toContain(
        "standard: 'WCAG2AA'",
        "'--no-sandbox'",
        "'--disable-dev-shm-usage'",
        "'/usr/bin/chromium-browser'",
        "'http://127.0.0.1:8000/'",
        "'http://127.0.0.1:8000/hello'",
    );
});
