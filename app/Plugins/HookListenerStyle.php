<?php

declare(strict_types=1);

namespace App\Plugins;

enum HookListenerStyle: string
{
    case Notification = 'notification';
    case Filter = 'filter';
    case Contributor = 'contributor';
    case Resolver = 'resolver';
}
