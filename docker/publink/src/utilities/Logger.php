<?php
/**
 * Logger.php
 *
 * Lightweight in-memory logger that accumulates text messages during a request
 * or background job, then flushes them to a file in one write operation.
 *
 * Each Logger instance generates a unique, timestamp-prefixed filename at
 * construction time so that concurrent jobs never collide on the same log file.
 *
 * Two flush targets are supported:
 *  - writeOutUserLogFile(): writes to the user's personal file store and registers
 *    the log file in the application's `file` database table.
 *  - writeOutLogFile(): writes to an arbitrary filesystem path, useful for
 *    system-level or job-queue logs that are not associated with a specific user.
 *
 * Every message is also mirrored to PHP's error_log() via formatToString(),
 * ensuring log output is visible in server logs even if a file write fails.
 *
 * Typical usage:
 *   $log = new Logger();
 *   $log->print('Starting import...');
 *   $log->printLn();
 *   $log->print('Done.');
 *   $log->writeOutUserLogFile('jats_import', $user);
 *
 * @package Biblhertz\Publink\utilities
 * @author  Chris Tomlinson
 * @since   March 2023
 */

namespace Biblhertz\Publink\utilities;

use DateTime;
use Exception;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\om\User;

class Logger
{

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /**
     * @var array<int,string> Accumulated log message strings, appended via print().
     *                        Flushed to disk by writeOutUserLogFile() or writeOutLogFile().
     *                        Public to allow callers to inspect buffered messages before flushing.
     */
    public array $messages = [];

    /**
     * @var string Auto-generated base filename for this logger instance, in the format:
     *             'DD-MM-YYYY_HH_MM_SS_{uniqid}_log.txt'
     *             Prepended with a command name by writeOutUserLogFile() when written to disk.
     */
    private string $fileName = '';


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Generates a unique, timestamp-based log filename for this instance.
     * The filename encodes the creation time and a unique ID to prevent collisions
     * between concurrent Logger instances.
     */
    public function __construct()
    {
        $dt = new DateTime('now');
        $this->fileName = $dt->format('d-m-Y_H_i_s') . '_' . uniqid() . '_log.txt';
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Returns the auto-generated base filename for this logger instance.
     * Note: this is the base name only, not a full path. The full path is
     * determined at flush time by writeOutUserLogFile() or writeOutLogFile().
     *
     * @return string Base filename string (e.g. '04-02-2024_14_30_00_abc123_log.txt').
     */
    public function getFileName(): string
    {
        return $this->fileName;
    }

    /**
     * Appends a message string to the in-memory message buffer.
     * Messages are not written to disk until one of the writeOut* methods is called.
     * Each message is also echoed to PHP's error_log() when the buffer is flushed.
     *
     * @param  string $message The log message to buffer.
     * @return void
     */
    public function print(string $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * Appends a horizontal rule separator line to the message buffer.
     * Useful for visually dividing log sections when reviewing the output file.
     *
     * @return void
     */
    public function printLn(): void
    {
        $this->print(str_repeat('-', 143));
    }


    /****************************************************************/
    /* FILE OUTPUT METHODS                                          */
    /****************************************************************/

    /**
     * Flushes the message buffer to a file in the user's personal file store
     * and registers the log file as a record in the `file` database table.
     *
     * The file is named "{command}_{timestamp}_{uniqid}_log.txt" and stored
     * under the user's configured file store directory path.
     *
     * If the file cannot be opened, the messages are written to PHP's error_log()
     * as a fallback so nothing is silently lost.
     *
     * @param  string $command A label for the operation that generated this log
     *                         (e.g. 'jats_import'). Prepended to the filename.
     * @param  User   $user    The user whose file store directory will receive the log.
     * @throws Exception If the 'log' file type is not registered in the `file_type` table.
     * @return void
     */
    public function writeOutUserLogFile(string $command, User $user): void
    {
        $baseName = $command . '_' . $this->fileName;
        $path     = $user->getMyFileStoreDirectoryPath() . $baseName;

        $file = fopen($path, 'wx');
        if ($file !== false) {
            $written = fwrite($file, $this->formatToString($this->messages));
            fclose($file);

            if ($written === false) {
                error_log("Write failed for log file :: $path");
                error_log($this->formatToString($this->messages));
                return;
            }

            // Resolve the 'log' file type id from the database
            $typeResult = $user->getObjDB()->preparedSelect(
                'SELECT id, type FROM file_type WHERE name = ?',
                ['log']
            );
            if ($user->getObjDB()->numRows() !== 1) {
                throw new Exception('!!! File type is not recognised by the system');
            }
            $type = $typeResult->fetch();

            // Register the log file in the file table
            $vals = [
                'name'            => $baseName,
                'type'            => 'text/plain',
                'size'            => filesize($path),
                'timestamp'       => date('Y-m-d H:i:s'),
                'user_details_id' => $user->getID(),
                'path'            => $path,
                'file_type_id'    => $type['id'],
            ];
            $user->getObjDB()->insert('file', $vals);

        } else {
            error_log("Cannot write log to file :: $path");
            error_log($this->formatToString($this->messages));
        }
    }

    /**
     * Flushes the message buffer to an arbitrary file path and clears the buffer.
     *
     * Useful for system-level or job-queue logs that are not associated with
     * a specific user and do not need a database record.
     *
     * If the file cannot be opened, the messages are written to PHP's error_log()
     * as a fallback. The buffer is cleared regardless of write success.
     *
     * @param  string $filename Absolute or relative path to the output file.
     * @return void
     */
    public function writeOutLogFile(string $filename): void
    {
        $file = fopen($filename, 'wx');
        if ($file !== false) {
            $written = fwrite($file, $this->formatToString($this->messages));
            fclose($file);
            if ($written === false) {
                error_log("Write failed for log file :: $filename");
                error_log($this->formatToString($this->messages));
            }
        } else {
            error_log("Cannot write log to file :: $filename");
            error_log('Messages as follows:');
            error_log($this->formatToString($this->messages));
        }

        $this->messages = [];
    }


    /****************************************************************/
    /* PRIVATE HELPERS                                              */
    /****************************************************************/

    /**
     * Joins the message array into a single newline-delimited string and
     * simultaneously mirrors each message to PHP's error_log().
     *
     * The error_log() mirroring ensures messages are visible in server logs
     * even during development or when file writes fail.
     *
     * @param  array<int,string> $messages The messages to format.
     * @return string Newline-delimited string of all messages.
     */
    private function formatToString(array $messages): string
    {
        $output = '';
        foreach ($messages as $message) {
            $output .= $message . PHP_EOL;
            error_log("[Logger] $message");
        }
        return $output;
    }
}