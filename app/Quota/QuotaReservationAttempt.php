<?php

declare(strict_types=1);

namespace App\Quota;

use App\Models\QuotaReservation;

final readonly class QuotaReservationAttempt
{
    /**
     * @param  list<QuotaReservation>  $reservations
     */
    private function __construct(
        public array $reservations,
        public ?string $deniedMessage,
    ) {}

    /**
     * @param  list<QuotaReservation>  $reservations
     */
    public static function reserved(array $reservations): self
    {
        return new self($reservations, null);
    }

    public static function denied(string $message): self
    {
        return new self([], $message);
    }
}
