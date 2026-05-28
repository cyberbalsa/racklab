<?php

declare(strict_types=1);

use App\Docs\MarkdownRenderer;

it('renders simple Markdown to wrapped paragraphs', function (): void {
    $renderer = new MarkdownRenderer;

    expect(trim($renderer->render('hello world')))
        ->toBe('<p>hello world</p>');
});

it('renders multiple paragraphs separated by blank lines', function (): void {
    $renderer = new MarkdownRenderer;

    $html = $renderer->render("first paragraph\n\nsecond paragraph");

    expect($html)
        ->toContain('<p>first paragraph</p>')
        ->toContain('<p>second paragraph</p>');
});

it('renders headings, lists, and inline code via CommonMark', function (): void {
    $renderer = new MarkdownRenderer;

    $html = $renderer->render("# Heading\n\n- item one\n- item two\n\nuse `racklab` to test.");

    expect($html)
        ->toContain('<h1>Heading</h1>')
        ->toContain('<ul>')
        ->toContain('<li>item one</li>')
        ->toContain('<code>racklab</code>');
});

it('renders GFM tables and task lists', function (): void {
    $renderer = new MarkdownRenderer;

    $html = $renderer->render("| col1 | col2 |\n| --- | --- |\n| a | b |\n\n- [x] done\n- [ ] todo");

    expect($html)
        ->toContain('<table>')
        ->toContain('<td>a</td>')
        ->toContain('disabled="" type="checkbox"');
});

it('escapes raw HTML rather than passing it through', function (): void {
    $renderer = new MarkdownRenderer;

    $html = $renderer->render('<script>alert(1)</script>');

    expect($html)
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>alert');
});

it('blocks javascript: URLs in Markdown links', function (): void {
    $renderer = new MarkdownRenderer;

    $html = $renderer->render('[click](javascript:alert(1))');

    expect($html)->not->toContain('javascript:alert');
});

it('emits a racklab-ref custom element for an inline [[kind:id]] reference', function (): void {
    $renderer = new MarkdownRenderer;

    $html = $renderer->render('See [[deployment:abc-123]] for details.');

    expect($html)
        ->toContain('<racklab-ref')
        ->toContain('data-kind="deployment"')
        ->toContain('data-id="abc-123"')
        ->toContain('[[deployment:abc-123]]');
});

it('leaves [[kind:id]] verbatim inside fenced code blocks', function (): void {
    $renderer = new MarkdownRenderer;

    $html = $renderer->render("```\n[[deployment:abc-123]]\n```");

    expect($html)
        ->toContain('<pre>')
        ->not->toContain('<racklab-ref')
        ->toContain('[[deployment:abc-123]]');
});

it('leaves [[kind:id]] verbatim inside inline code', function (): void {
    $renderer = new MarkdownRenderer;

    $html = $renderer->render('Use `[[deployment:abc-123]]` in your Markdown source.');

    expect($html)
        ->not->toContain('<racklab-ref')
        ->toContain('<code>[[deployment:abc-123]]</code>');
});

it('renders multiple distinct refs in the same paragraph', function (): void {
    $renderer = new MarkdownRenderer;

    $html = $renderer->render('Cross-link [[deployment:dep-1]] and [[project:proj-2]].');

    expect(substr_count($html, '<racklab-ref'))->toBe(2)
        ->and($html)->toContain('data-kind="deployment"')
        ->and($html)->toContain('data-kind="project"');
});

it('ignores malformed refs like uppercase kind or oversized id', function (): void {
    $renderer = new MarkdownRenderer;

    $html = $renderer->render('Bad [[DEPLOYMENT:abc-123]] and good [[deployment:abc-123]].');

    expect(substr_count($html, '<racklab-ref'))->toBe(1)
        ->and($html)->toContain('[[DEPLOYMENT:abc-123]]'); // passes through unchanged
});
