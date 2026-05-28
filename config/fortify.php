<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

return [
    'home' => '/dashboard',
    'views' => true,
    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::updatePasswords(),
    ],
];
