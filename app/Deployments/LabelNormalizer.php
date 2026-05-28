<?php

declare(strict_types=1);

namespace App\Deployments;

/**
 * Normalizes a free-text label input ("Week 1, exam-prep, ...") into a clean,
 * de-duplicated list of user labels: comma-separated, trimmed, lower-cased,
 * blanks dropped, length-capped, and bounded in count. Pure + tiny-testable.
 */
final readonly class LabelNormalizer
{
    public const int MAX_LABELS = 20;

    public const int MAX_LENGTH = 40;

    /**
     * @return list<string>
     */
    public function normalize(string $raw): array
    {
        $labels = [];

        foreach (explode(',', $raw) as $part) {
            $label = mb_strtolower(trim($part));

            if ($label === '') {
                continue;
            }

            if (mb_strlen($label) > self::MAX_LENGTH) {
                $label = mb_substr($label, 0, self::MAX_LENGTH);
            }

            if (! in_array($label, $labels, strict: true)) {
                $labels[] = $label;
            }

            if (count($labels) >= self::MAX_LABELS) {
                break;
            }
        }

        return $labels;
    }
}
