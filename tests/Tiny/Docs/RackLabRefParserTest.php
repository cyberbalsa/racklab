<?php

declare(strict_types=1);

use App\Docs\Refs\RackLabRef;
use App\Docs\Refs\RackLabRefParser;

it('returns an empty list when the source has no refs', function (): void {
    $parser = new RackLabRefParser;

    expect($parser->extractAll('plain markdown text'))->toBe([]);
    expect($parser->extractAll(''))->toBe([]);
});

it('extracts a single inline ref', function (): void {
    $parser = new RackLabRefParser;
    $refs = $parser->extractAll('see [[deployment:abc-123]] for context');

    expect($refs)->toHaveCount(1)
        ->and($refs[0])->toBeInstanceOf(RackLabRef::class)
        ->and($refs[0]->kind)->toBe('deployment')
        ->and($refs[0]->id)->toBe('abc-123');
});

it('extracts multiple refs from a single source string', function (): void {
    $parser = new RackLabRefParser;
    $refs = $parser->extractAll('cross [[deployment:dep-1]] and [[network:lab-mgmt]] both apply.');

    expect($refs)->toHaveCount(2)
        ->and(array_map(fn (RackLabRef $r): string => $r->kind, $refs))->toBe(['deployment', 'network'])
        ->and(array_map(fn (RackLabRef $r): string => $r->id, $refs))->toBe(['dep-1', 'lab-mgmt']);
});

it('deduplicates extractUnique by (kind, id)', function (): void {
    $parser = new RackLabRefParser;
    $refs = $parser->extractUnique('[[deployment:dep-1]] then [[deployment:dep-1]] then [[deployment:dep-2]].');

    expect($refs)->toHaveCount(2)
        ->and($refs[0]->id)->toBe('dep-1')
        ->and($refs[1]->id)->toBe('dep-2');
});

it('ignores malformed refs that do not match the grammar', function (): void {
    $parser = new RackLabRefParser;

    expect($parser->extractAll('[[DEPLOYMENT:abc-123]]'))->toBe([])  // uppercase kind
        ->and($parser->extractAll('[[1deployment:abc-123]]'))->toBe([])  // kind must start with a letter
        ->and($parser->extractAll('[[deployment:]]'))->toBe([])  // empty id
        ->and($parser->extractAll('[[:abc-123]]'))->toBe([])  // empty kind
        ->and($parser->extractAll('[[deployment:abc!]]'))->toBe([]); // disallowed id char
});

it('extracts refs even when they sit inside otherwise valid Markdown', function (): void {
    $parser = new RackLabRefParser;
    $refs = $parser->extractAll("- See [[deployment:abc-123]].\n- See [[project:proj-2]].");

    expect($refs)->toHaveCount(2);
});

it('extracts plugin-contributed kinds with no kind allowlist', function (): void {
    // The parser is liberal about `kind` — extending RackLab via the
    // RefResolving hookspec must work without a centralized list.
    $parser = new RackLabRefParser;
    $refs = $parser->extractAll('[[cluster:pve-edu-1]] and [[domain:lab-local]].');

    expect($refs)->toHaveCount(2)
        ->and($refs[0]->kind)->toBe('cluster')
        ->and($refs[1]->kind)->toBe('domain');
});

it('rejects RackLabRef construction with an invalid kind or id', function (): void {
    expect(fn (): RackLabRef => new RackLabRef('Bad-Kind', 'abc-123'))->toThrow(InvalidArgumentException::class);
    expect(fn (): RackLabRef => new RackLabRef('deployment', ''))->toThrow(InvalidArgumentException::class);
    expect(fn (): RackLabRef => new RackLabRef('deployment', str_repeat('a', 65)))->toThrow(InvalidArgumentException::class);
});

it('round-trips through toSourceSyntax', function (): void {
    $ref = new RackLabRef('deployment', 'abc-123');

    expect($ref->toSourceSyntax())->toBe('[[deployment:abc-123]]');

    $parser = new RackLabRefParser;
    $extracted = $parser->extractAll($ref->toSourceSyntax());

    expect($extracted)->toHaveCount(1)
        ->and($extracted[0]->equals($ref))->toBeTrue();
});
