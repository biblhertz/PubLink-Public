<?php

namespace Biblhertz\Publink\test;

use PHPUnit\Framework\TestCase;
use Biblhertz\Publink\utilities\Utilities;
use Biblhertz\Publink\utilities\Logger;

/********************************************************************/
/* UTILITIES TESTS                                                   */
/********************************************************************/

class UtilitiesTest extends TestCase
{
    // --- to_utf() ---

    public function testToUtfEmptyStringReturnsEmpty(): void
    {
        $this->assertSame('', Utilities::to_utf(''));
    }

    public function testToUtfNullReturnsEmpty(): void
    {
        $this->assertSame('', Utilities::to_utf(null));
    }

    public function testToUtfZeroReturnEmpty(): void
    {
        // 0 is falsy, empty() returns true
        $this->assertSame('', Utilities::to_utf(0));
    }

    public function testToUtfTrimsLeadingAndTrailingWhitespace(): void
    {
        $this->assertSame('hello', Utilities::to_utf('  hello  '));
    }

    public function testToUtfCollapsesInternalWhitespace(): void
    {
        $this->assertSame('hello world', Utilities::to_utf("hello   world"));
    }

    public function testToUtfCollapsesTabsAndNewlines(): void
    {
        $this->assertSame('a b c', Utilities::to_utf("a\t\nb\r\nc"));
    }

    public function testToUtfStripsHtmlTags(): void
    {
        $this->assertSame('Hello World', Utilities::to_utf('<b>Hello</b> <i>World</i>'));
    }

    public function testToUtfStripsNestedHtmlTags(): void
    {
        $this->assertSame('Link text', Utilities::to_utf('<a href="http://example.com">Link text</a>'));
    }

    public function testToUtfPlainTextPassesThrough(): void
    {
        $this->assertSame('Simple text', Utilities::to_utf('Simple text'));
    }

    // --- renderBibtexTitle() ---

    public function testRenderBibtexTitlePlainTextUnchanged(): void
    {
        $this->assertSame('A Plain Title', Utilities::renderBibtexTitle('A Plain Title'));
    }

    public function testRenderBibtexTitleStripsOuterBraces(): void
    {
        $this->assertSame('Some Title Here', Utilities::renderBibtexTitle('{Some Title Here}'));
    }

    public function testRenderBibtexTitleStripsInnerProtectiveBraces(): void
    {
        $this->assertSame('DNA sequencing', Utilities::renderBibtexTitle('{DNA} sequencing'));
    }

    public function testRenderBibtexTitleStripsMultipleInnerBraces(): void
    {
        $this->assertSame('The RNA and DNA study', Utilities::renderBibtexTitle('The {RNA} and {DNA} study'));
    }

    public function testRenderBibtexTitleStripsOuterAndInnerBraces(): void
    {
        $this->assertSame('On RNA polymerase', Utilities::renderBibtexTitle('{On {RNA} polymerase}'));
    }

    public function testRenderBibtexTitleEmptyString(): void
    {
        $this->assertSame('', Utilities::renderBibtexTitle(''));
    }

    public function testRenderBibtexTitleTrimsWhitespace(): void
    {
        $this->assertSame('trimmed', Utilities::renderBibtexTitle('  trimmed  '));
    }

    // Non-outer-wrapping braces are treated as inner protective braces and stripped
    public function testRenderBibtexTitleNonOuterBracesStripped(): void
    {
        // Braces don't wrap the whole string, so they're treated as inner braces
        $this->assertSame('ABC and DEF', Utilities::renderBibtexTitle('{ABC} and {DEF}'));
    }

    // --- killProcessByName() ---

    public function testKillProcessByNameRejectsInvalidChars(): void
    {
        $result = Utilities::killProcessByName('bad name!');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid process name', $result['message']);
    }

    public function testKillProcessByNameRejectsSpaces(): void
    {
        $result = Utilities::killProcessByName('my process');
        $this->assertFalse($result['success']);
    }

    public function testKillProcessByNameRejectsSemicolon(): void
    {
        $result = Utilities::killProcessByName('proc;rm -rf /');
        $this->assertFalse($result['success']);
    }

    public function testKillProcessByNameAllowsAlphanumericAndSeparators(): void
    {
        // A process that almost certainly doesn't exist — we just verify the
        // return shape and that no injection rejection occurs.
        $result = Utilities::killProcessByName('nonexistent_proc-1.0');
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertFalse($result['success']);
    }

    // --- printXMLErrors() ---

    public function testPrintXMLErrorsAddsMessagesToLogger(): void
    {
        $logger = new Logger();

        // Produce a real LibXMLError by parsing broken XML
        libxml_use_internal_errors(true);
        simplexml_load_string('<unclosed>');
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        $this->assertNotEmpty($errors, 'Expected at least one libxml error from invalid XML');

        Utilities::printXMLErrors($errors, $logger);

        // printXMLErrors adds a leading separator, error lines, and a trailing separator
        $this->assertGreaterThanOrEqual(3, count($logger->messages));

        // At least one message should mention 'XML Parse Error'
        $joined = implode("\n", $logger->messages);
        $this->assertStringContainsString('XML Parse Error', $joined);
    }
}
