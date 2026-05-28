<?php

declare(strict_types=1);

use App\Deployments\LabelNormalizer;

it('normalizes free-text labels: trims, lowercases, dedupes, drops blanks', function (): void {
    expect((new LabelNormalizer)->normalize('Week 1,  week 1 , Exam-Prep , '))
        ->toBe(['week 1', 'exam-prep']);
});

it('caps label count and length', function (): void {
    $raw = implode(',', array_map(static fn (int $i): string => 'label-'.$i, range(1, 50)));
    $result = (new LabelNormalizer)->normalize($raw);
    expect(count($result))->toBe(LabelNormalizer::MAX_LABELS);

    $long = str_repeat('x', 100);
    expect((new LabelNormalizer)->normalize($long)[0])->toHaveLength(LabelNormalizer::MAX_LENGTH);
});
