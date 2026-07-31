<?php

namespace Biblhertz\Publink\test;

use PHPUnit\Framework\TestCase;
use Biblhertz\Publink\utilities\Logger;

/********************************************************************/
/* LOGGER TESTS                                                      */
/********************************************************************/

class LoggerTest extends TestCase
{
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = new Logger();
    }

    // --- Construction ---

    public function testMessagesStartEmpty(): void
    {
        $this->assertSame([], $this->logger->messages);
    }

    public function testGetFileNameIsNonEmpty(): void
    {
        $this->assertNotEmpty($this->logger->getFileName());
    }

    public function testGetFileNameMatchesExpectedPattern(): void
    {
        // Expected format: DD-MM-YYYY_HH_MM_SS_<uniqid>_log.txt
        $this->assertMatchesRegularExpression(
            '/^\d{2}-\d{2}-\d{4}_\d{2}_\d{2}_\d{2}_[0-9a-f]+_log\.txt$/',
            $this->logger->getFileName()
        );
    }

    public function testTwoLoggersHaveDifferentFileNames(): void
    {
        $other = new Logger();
        $this->assertNotSame($this->logger->getFileName(), $other->getFileName());
    }

    // --- print() ---

    public function testPrintAppendsMessage(): void
    {
        $this->logger->print('Hello');
        $this->assertSame(['Hello'], $this->logger->messages);
    }

    public function testPrintAccumulatesMultipleMessages(): void
    {
        $this->logger->print('first');
        $this->logger->print('second');
        $this->logger->print('third');
        $this->assertSame(['first', 'second', 'third'], $this->logger->messages);
    }

    public function testPrintAcceptsEmptyString(): void
    {
        $this->logger->print('');
        $this->assertCount(1, $this->logger->messages);
        $this->assertSame('', $this->logger->messages[0]);
    }

    // --- printLn() ---

    public function testPrintLnAppendsSeparator(): void
    {
        $this->logger->printLn();
        $this->assertCount(1, $this->logger->messages);
        $this->assertSame(str_repeat('-', 143), $this->logger->messages[0]);
    }

    public function testPrintLnSeparatorIsCorrectLength(): void
    {
        $this->logger->printLn();
        $this->assertSame(143, strlen($this->logger->messages[0]));
    }

    // --- writeOutLogFile() ---

    public function testWriteOutLogFileWritesContentToFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'logger_test_');
        unlink($tmpFile); // writeOutLogFile uses 'wx' mode, so file must not exist

        $this->logger->print('line one');
        $this->logger->print('line two');
        $this->logger->writeOutLogFile($tmpFile);

        $this->assertFileExists($tmpFile);
        $content = file_get_contents($tmpFile);
        $this->assertStringContainsString('line one', $content);
        $this->assertStringContainsString('line two', $content);

        unlink($tmpFile);
    }

    public function testWriteOutLogFileClearsMessages(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'logger_test_');
        unlink($tmpFile);

        $this->logger->print('will be cleared');
        $this->logger->writeOutLogFile($tmpFile);

        $this->assertSame([], $this->logger->messages);

        unlink($tmpFile);
    }

    public function testWriteOutLogFileClearsMessagesEvenOnBadPath(): void
    {
        $this->logger->print('a message');
        // Write to a path that cannot be opened (wx mode on existing file)
        $tmpFile = tempnam(sys_get_temp_dir(), 'logger_existing_');
        $this->logger->writeOutLogFile($tmpFile); // file already exists, wx will fail
        $this->assertSame([], $this->logger->messages);

        unlink($tmpFile);
    }
}
