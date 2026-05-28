<?php

declare(strict_types=1);

use App\Docs\MarkdownRenderer;

it('wraps single line markdown in a paragraph', function (): void {
    $renderer = new MarkdownRenderer;

    expect($renderer->render('hello world'))
        ->toBe('<p>hello world</p>');
});

it('splits double-newline separated content into multiple paragraphs', function (): void {
    $renderer = new MarkdownRenderer;

    expect($renderer->render("first paragraph\n\nsecond paragraph"))
        ->toBe("<p>first paragraph</p>\n<p>second paragraph</p>");
});

it('escapes HTML so untrusted markdown cannot inject scripts', function (): void {
    $renderer = new MarkdownRenderer;

    $rendered = $renderer->render('<script>alert(1)</script>');

    expect($rendered)
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>');
});

it('joins soft line breaks within a paragraph by collapsing newlines to spaces', function (): void {
    $renderer = new MarkdownRenderer;

    expect($renderer->render("line one\nline two"))
        ->toBe('<p>line one line two</p>');
});

it('normalizes CRLF line endings to LF before paragraph splitting', function (): void {
    $renderer = new MarkdownRenderer;

    expect($renderer->render("first\r\n\r\nsecond"))
        ->toBe("<p>first</p>\n<p>second</p>");
});

it('returns an empty string for empty input', function (): void {
    $renderer = new MarkdownRenderer;

    expect($renderer->render(''))->toBe('');
});

it('drops paragraphs that contain only whitespace', function (): void {
    $renderer = new MarkdownRenderer;

    expect($renderer->render("alpha\n\n   \n\nbeta"))
        ->toBe("<p>alpha</p>\n<p>beta</p>");
});
