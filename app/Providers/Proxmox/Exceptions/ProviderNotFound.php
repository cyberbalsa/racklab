<?php

declare(strict_types=1);

namespace App\Providers\Proxmox\Exceptions;

use RuntimeException;

final class ProviderNotFound extends RuntimeException {}
