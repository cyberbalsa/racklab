<?php

declare(strict_types=1);

it('boots the Laravel app with the integration test profile', function (): void {
    expect(app()->environment())->toBe('testing')
        ->and(config('database.default'))->toBe('sqlite');
});
