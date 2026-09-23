<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PublicPathMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PublicPathMatcher — path pattern parsing and matching.
 *
 * @covers \App\Service\PublicPathMatcher
 */
final class PublicPathMatcherTest extends TestCase
{
    /* ── empty / disabled ──────────────────────────────────────────────── */

    public function test_empty_string_results_in_no_patterns(): void
    {
        $matcher = new PublicPathMatcher('');
        self::assertTrue($matcher->isEmpty());
        self::assertFalse($matcher->matches('example.com', '/public'));
    }

    public function test_whitespace_only_string_results_in_no_patterns(): void
    {
        $matcher = new PublicPathMatcher('   ');
        self::assertTrue($matcher->isEmpty());
    }

    /* ── exact path matching ───────────────────────────────────────────── */

    public function test_exact_path_match(): void
    {
        $matcher = new PublicPathMatcher('/public');
        self::assertTrue($matcher->matches('example.com', '/public'));
    }

    public function test_exact_path_does_not_match_subpath(): void
    {
        $matcher = new PublicPathMatcher('/public');
        self::assertFalse($matcher->matches('example.com', '/public/'));
        self::assertFalse($matcher->matches('example.com', '/public/repo'));
    }

    public function test_exact_path_does_not_match_different_path(): void
    {
        $matcher = new PublicPathMatcher('/public');
        self::assertFalse($matcher->matches('example.com', '/private'));
        self::assertFalse($matcher->matches('example.com', '/'));
    }

    /* ── single wildcard * ─────────────────────────────────────────────── */

    public function test_single_wildcard_matches_one_segment(): void
    {
        $matcher = new PublicPathMatcher('/public/*');
        self::assertTrue($matcher->matches('example.com', '/public/repo'));
        self::assertTrue($matcher->matches('example.com', '/public/xyz'));
    }

    public function test_single_wildcard_does_not_match_base_path(): void
    {
        $matcher = new PublicPathMatcher('/public/*');
        self::assertFalse($matcher->matches('example.com', '/public'));
    }

    public function test_single_wildcard_does_not_cross_segments(): void
    {
        $matcher = new PublicPathMatcher('/public/*');
        self::assertFalse($matcher->matches('example.com', '/public/a/b'));
    }

    public function test_single_wildcard_does_not_match_empty_segment(): void
    {
        $matcher = new PublicPathMatcher('/public/*');
        self::assertFalse($matcher->matches('example.com', '/public/'));
    }

    /* ── double wildcard ** ────────────────────────────────────────────── */

    public function test_double_wildcard_matches_multiple_segments(): void
    {
        $matcher = new PublicPathMatcher('/public/**');
        self::assertTrue($matcher->matches('example.com', '/public/a'));
        self::assertTrue($matcher->matches('example.com', '/public/a/b/c'));
    }

    public function test_double_wildcard_does_not_match_base_path(): void
    {
        $matcher = new PublicPathMatcher('/public/**');
        self::assertFalse($matcher->matches('example.com', '/public'));
    }

    public function test_double_wildcard_matches_trailing_slash(): void
    {
        $matcher = new PublicPathMatcher('/public/**');
        self::assertTrue($matcher->matches('example.com', '/public/'));
    }

    /* ── mid-path wildcards ────────────────────────────────────────────── */

    public function test_mid_path_single_wildcard(): void
    {
        $matcher = new PublicPathMatcher('/api/*/status');
        self::assertTrue($matcher->matches('example.com', '/api/v1/status'));
        self::assertTrue($matcher->matches('example.com', '/api/v2/status'));
        self::assertFalse($matcher->matches('example.com', '/api/v1/v2/status'));
        self::assertFalse($matcher->matches('example.com', '/api/status'));
    }

    public function test_mid_path_double_wildcard(): void
    {
        $matcher = new PublicPathMatcher('/api/**/status');
        self::assertTrue($matcher->matches('example.com', '/api/v1/status'));
        self::assertTrue($matcher->matches('example.com', '/api/v1/v2/status'));
        self::assertTrue($matcher->matches('example.com', '/api/status'));
    }

    /* ── multiple patterns ─────────────────────────────────────────────── */

    public function test_multiple_patterns_comma_separated(): void
    {
        $matcher = new PublicPathMatcher('/public/**,/api/status,/health');
        self::assertTrue($matcher->matches('example.com', '/public/repo'));
        self::assertTrue($matcher->matches('example.com', '/api/status'));
        self::assertTrue($matcher->matches('example.com', '/health'));
        self::assertFalse($matcher->matches('example.com', '/private'));
    }

    public function test_multiple_patterns_with_whitespace(): void
    {
        $matcher = new PublicPathMatcher('/public/**, /api/status, /health');
        self::assertTrue($matcher->matches('example.com', '/public/repo'));
        self::assertTrue($matcher->matches('example.com', '/api/status'));
        self::assertTrue($matcher->matches('example.com', '/health'));
    }

    public function test_empty_segments_in_comma_list_are_ignored(): void
    {
        $matcher = new PublicPathMatcher('/public,,/health,');
        self::assertFalse($matcher->isEmpty());
        self::assertTrue($matcher->matches('example.com', '/public'));
        self::assertTrue($matcher->matches('example.com', '/health'));
    }

    /* ── domain-prefixed patterns ──────────────────────────────────────── */

    public function test_domain_prefixed_pattern_matches_on_that_host(): void
    {
        $matcher = new PublicPathMatcher('code.example.com/public/**');
        self::assertTrue($matcher->matches('code.example.com', '/public/repo'));
    }

    public function test_domain_prefixed_pattern_does_not_match_other_host(): void
    {
        $matcher = new PublicPathMatcher('code.example.com/public/**');
        self::assertFalse($matcher->matches('other.example.com', '/public/repo'));
        self::assertFalse($matcher->matches('example.com', '/public/repo'));
    }

    public function test_path_without_domain_prefix_matches_any_host(): void
    {
        $matcher = new PublicPathMatcher('/public/**');
        self::assertTrue($matcher->matches('code.example.com', '/public/repo'));
        self::assertTrue($matcher->matches('other.example.com', '/public/repo'));
        self::assertTrue($matcher->matches('localhost', '/public/repo'));
    }

    public function test_mixed_domain_prefixed_and_plain_patterns(): void
    {
        $matcher = new PublicPathMatcher('/health,code.example.com/public/**');
        self::assertTrue($matcher->matches('any.host', '/health'));
        self::assertTrue($matcher->matches('code.example.com', '/public/repo'));
        self::assertFalse($matcher->matches('other.host', '/public/repo'));
    }

    public function test_domain_prefixed_root_path_matches_root(): void
    {
        // host/  — the trailing slash is the entire path, nothing after it
        $matcher = new PublicPathMatcher('code.example.com/');
        self::assertTrue($matcher->matches('code.example.com', '/'));
        self::assertFalse($matcher->matches('code.example.com', '/public'));
        self::assertFalse($matcher->matches('other.example.com', '/'));
    }

    public function test_domain_prefixed_root_with_other_patterns(): void
    {
        // The exact scenario from the bug report
        $matcher = new PublicPathMatcher('code.example.com/,code.example.com/public/**');
        self::assertTrue($matcher->matches('code.example.com', '/'));
        self::assertTrue($matcher->matches('code.example.com', '/public/repo'));
        self::assertFalse($matcher->matches('code.example.com', '/private'));
        self::assertFalse($matcher->matches('other.example.com', '/'));
    }

    public function test_domain_prefix_is_case_insensitive(): void
    {
        $matcher = new PublicPathMatcher('Code.Example.COM/public/**');
        self::assertTrue($matcher->matches('code.example.com', '/public/repo'));
        self::assertTrue($matcher->matches('CODE.EXAMPLE.COM', '/public/repo'));
    }

    /* ── invalid patterns ──────────────────────────────────────────────── */

    public function test_pattern_without_leading_slash_is_ignored(): void
    {
        $matcher = new PublicPathMatcher('public');
        self::assertTrue($matcher->isEmpty());
    }

    public function test_invalid_pattern_among_valid_ones_is_ignored(): void
    {
        $matcher = new PublicPathMatcher('invalid,/public');
        self::assertFalse($matcher->isEmpty());
        self::assertTrue($matcher->matches('example.com', '/public'));
    }

    /* ── special regex characters in paths ─────────────────────────────── */

    public function test_special_regex_characters_are_escaped(): void
    {
        $matcher = new PublicPathMatcher('/path.with.dots');
        self::assertTrue($matcher->matches('example.com', '/path.with.dots'));
        self::assertFalse($matcher->matches('example.com', '/pathXwithXdots'));
    }

    public function test_plus_character_is_literal(): void
    {
        $matcher = new PublicPathMatcher('/a+b');
        self::assertTrue($matcher->matches('example.com', '/a+b'));
        self::assertFalse($matcher->matches('example.com', '/aaab'));
    }

    /* ── root path ─────────────────────────────────────────────────────── */

    public function test_root_path_match(): void
    {
        $matcher = new PublicPathMatcher('/');
        self::assertTrue($matcher->matches('example.com', '/'));
        self::assertFalse($matcher->matches('example.com', '/anything'));
    }

    public function test_wildcard_at_root(): void
    {
        $matcher = new PublicPathMatcher('/*');
        self::assertTrue($matcher->matches('example.com', '/anything'));
        self::assertFalse($matcher->matches('example.com', '/a/b'));
        self::assertFalse($matcher->matches('example.com', '/'));
    }

    public function test_double_wildcard_at_root(): void
    {
        $matcher = new PublicPathMatcher('/**');
        self::assertTrue($matcher->matches('example.com', '/'));
        self::assertTrue($matcher->matches('example.com', '/anything'));
        self::assertTrue($matcher->matches('example.com', '/a/b/c'));
    }
}
