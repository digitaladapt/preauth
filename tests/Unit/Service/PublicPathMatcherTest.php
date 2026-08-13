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

    public function testEmptyStringResultsInNoPatterns(): void
    {
        $matcher = new PublicPathMatcher('');
        self::assertTrue($matcher->isEmpty());
        self::assertFalse($matcher->matches('example.com', '/public'));
    }

    public function testWhitespaceOnlyStringResultsInNoPatterns(): void
    {
        $matcher = new PublicPathMatcher('   ');
        self::assertTrue($matcher->isEmpty());
    }

    /* ── exact path matching ───────────────────────────────────────────── */

    public function testExactPathMatch(): void
    {
        $matcher = new PublicPathMatcher('/public');
        self::assertTrue($matcher->matches('example.com', '/public'));
    }

    public function testExactPathDoesNotMatchSubpath(): void
    {
        $matcher = new PublicPathMatcher('/public');
        self::assertFalse($matcher->matches('example.com', '/public/'));
        self::assertFalse($matcher->matches('example.com', '/public/repo'));
    }

    public function testExactPathDoesNotMatchDifferentPath(): void
    {
        $matcher = new PublicPathMatcher('/public');
        self::assertFalse($matcher->matches('example.com', '/private'));
        self::assertFalse($matcher->matches('example.com', '/'));
    }

    /* ── single wildcard * ─────────────────────────────────────────────── */

    public function testSingleWildcardMatchesOneSegment(): void
    {
        $matcher = new PublicPathMatcher('/public/*');
        self::assertTrue($matcher->matches('example.com', '/public/repo'));
        self::assertTrue($matcher->matches('example.com', '/public/xyz'));
    }

    public function testSingleWildcardDoesNotMatchBasePath(): void
    {
        $matcher = new PublicPathMatcher('/public/*');
        self::assertFalse($matcher->matches('example.com', '/public'));
    }

    public function testSingleWildcardDoesNotCrossSegments(): void
    {
        $matcher = new PublicPathMatcher('/public/*');
        self::assertFalse($matcher->matches('example.com', '/public/a/b'));
    }

    public function testSingleWildcardDoesNotMatchEmptySegment(): void
    {
        $matcher = new PublicPathMatcher('/public/*');
        self::assertFalse($matcher->matches('example.com', '/public/'));
    }

    /* ── double wildcard ** ────────────────────────────────────────────── */

    public function testDoubleWildcardMatchesMultipleSegments(): void
    {
        $matcher = new PublicPathMatcher('/public/**');
        self::assertTrue($matcher->matches('example.com', '/public/a'));
        self::assertTrue($matcher->matches('example.com', '/public/a/b/c'));
    }

    public function testDoubleWildcardDoesNotMatchBasePath(): void
    {
        $matcher = new PublicPathMatcher('/public/**');
        self::assertFalse($matcher->matches('example.com', '/public'));
    }

    public function testDoubleWildcardMatchesTrailingSlash(): void
    {
        $matcher = new PublicPathMatcher('/public/**');
        self::assertTrue($matcher->matches('example.com', '/public/'));
    }

    /* ── mid-path wildcards ────────────────────────────────────────────── */

    public function testMidPathSingleWildcard(): void
    {
        $matcher = new PublicPathMatcher('/api/*/status');
        self::assertTrue($matcher->matches('example.com', '/api/v1/status'));
        self::assertTrue($matcher->matches('example.com', '/api/v2/status'));
        self::assertFalse($matcher->matches('example.com', '/api/v1/v2/status'));
        self::assertFalse($matcher->matches('example.com', '/api/status'));
    }

    public function testMidPathDoubleWildcard(): void
    {
        $matcher = new PublicPathMatcher('/api/**/status');
        self::assertTrue($matcher->matches('example.com', '/api/v1/status'));
        self::assertTrue($matcher->matches('example.com', '/api/v1/v2/status'));
        self::assertTrue($matcher->matches('example.com', '/api/status'));
    }

    /* ── multiple patterns ─────────────────────────────────────────────── */

    public function testMultiplePatternsCommaSeparated(): void
    {
        $matcher = new PublicPathMatcher('/public/**,/api/status,/health');
        self::assertTrue($matcher->matches('example.com', '/public/repo'));
        self::assertTrue($matcher->matches('example.com', '/api/status'));
        self::assertTrue($matcher->matches('example.com', '/health'));
        self::assertFalse($matcher->matches('example.com', '/private'));
    }

    public function testMultiplePatternsWithWhitespace(): void
    {
        $matcher = new PublicPathMatcher('/public/**, /api/status, /health');
        self::assertTrue($matcher->matches('example.com', '/public/repo'));
        self::assertTrue($matcher->matches('example.com', '/api/status'));
        self::assertTrue($matcher->matches('example.com', '/health'));
    }

    public function testEmptySegmentsInCommaListAreIgnored(): void
    {
        $matcher = new PublicPathMatcher('/public,,/health,');
        self::assertFalse($matcher->isEmpty());
        self::assertTrue($matcher->matches('example.com', '/public'));
        self::assertTrue($matcher->matches('example.com', '/health'));
    }

    /* ── domain-prefixed patterns ──────────────────────────────────────── */

    public function testDomainPrefixedPatternMatchesOnThatHost(): void
    {
        $matcher = new PublicPathMatcher('code.example.com/public/**');
        self::assertTrue($matcher->matches('code.example.com', '/public/repo'));
    }

    public function testDomainPrefixedPatternDoesNotMatchOtherHost(): void
    {
        $matcher = new PublicPathMatcher('code.example.com/public/**');
        self::assertFalse($matcher->matches('other.example.com', '/public/repo'));
        self::assertFalse($matcher->matches('example.com', '/public/repo'));
    }

    public function testPathWithoutDomainPrefixMatchesAnyHost(): void
    {
        $matcher = new PublicPathMatcher('/public/**');
        self::assertTrue($matcher->matches('code.example.com', '/public/repo'));
        self::assertTrue($matcher->matches('other.example.com', '/public/repo'));
        self::assertTrue($matcher->matches('localhost', '/public/repo'));
    }

    public function testMixedDomainPrefixedAndPlainPatterns(): void
    {
        $matcher = new PublicPathMatcher('/health,code.example.com/public/**');
        self::assertTrue($matcher->matches('any.host', '/health'));
        self::assertTrue($matcher->matches('code.example.com', '/public/repo'));
        self::assertFalse($matcher->matches('other.host', '/public/repo'));
    }

    public function testDomainPrefixedRootPathMatchesRoot(): void
    {
        // host/  — the trailing slash is the entire path, nothing after it
        $matcher = new PublicPathMatcher('code.example.com/');
        self::assertTrue($matcher->matches('code.example.com', '/'));
        self::assertFalse($matcher->matches('code.example.com', '/public'));
        self::assertFalse($matcher->matches('other.example.com', '/'));
    }

    public function testDomainPrefixedRootWithOtherPatterns(): void
    {
        // The exact scenario from the bug report
        $matcher = new PublicPathMatcher('code.example.com/,code.example.com/public/**');
        self::assertTrue($matcher->matches('code.example.com', '/'));
        self::assertTrue($matcher->matches('code.example.com', '/public/repo'));
        self::assertFalse($matcher->matches('code.example.com', '/private'));
        self::assertFalse($matcher->matches('other.example.com', '/'));
    }

    public function testDomainPrefixIsCaseInsensitive(): void
    {
        $matcher = new PublicPathMatcher('Code.Example.COM/public/**');
        self::assertTrue($matcher->matches('code.example.com', '/public/repo'));
        self::assertTrue($matcher->matches('CODE.EXAMPLE.COM', '/public/repo'));
    }

    /* ── invalid patterns ──────────────────────────────────────────────── */

    public function testPatternWithoutLeadingSlashIsIgnored(): void
    {
        $matcher = new PublicPathMatcher('public');
        self::assertTrue($matcher->isEmpty());
    }

    public function testInvalidPatternAmongValidOnesIsIgnored(): void
    {
        $matcher = new PublicPathMatcher('invalid,/public');
        self::assertFalse($matcher->isEmpty());
        self::assertTrue($matcher->matches('example.com', '/public'));
    }

    /* ── special regex characters in paths ─────────────────────────────── */

    public function testSpecialRegexCharactersAreEscaped(): void
    {
        $matcher = new PublicPathMatcher('/path.with.dots');
        self::assertTrue($matcher->matches('example.com', '/path.with.dots'));
        self::assertFalse($matcher->matches('example.com', '/pathXwithXdots'));
    }

    public function testPlusCharacterIsLiteral(): void
    {
        $matcher = new PublicPathMatcher('/a+b');
        self::assertTrue($matcher->matches('example.com', '/a+b'));
        self::assertFalse($matcher->matches('example.com', '/aaab'));
    }

    /* ── root path ─────────────────────────────────────────────────────── */

    public function testRootPathMatch(): void
    {
        $matcher = new PublicPathMatcher('/');
        self::assertTrue($matcher->matches('example.com', '/'));
        self::assertFalse($matcher->matches('example.com', '/anything'));
    }

    public function testWildcardAtRoot(): void
    {
        $matcher = new PublicPathMatcher('/*');
        self::assertTrue($matcher->matches('example.com', '/anything'));
        self::assertFalse($matcher->matches('example.com', '/a/b'));
        self::assertFalse($matcher->matches('example.com', '/'));
    }

    public function testDoubleWildcardAtRoot(): void
    {
        $matcher = new PublicPathMatcher('/**');
        self::assertTrue($matcher->matches('example.com', '/'));
        self::assertTrue($matcher->matches('example.com', '/anything'));
        self::assertTrue($matcher->matches('example.com', '/a/b/c'));
    }
}
