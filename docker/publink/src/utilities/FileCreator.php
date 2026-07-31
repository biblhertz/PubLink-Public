<?php
/**
 * FileCreator.php
 *
 * Simple utility class for creating and writing plain-text (ASCII/UTF-8) files.
 * Handles directory creation, file writing, and recursive directory deletion.
 *
 * Typical usage:
 *   $f = new FileCreator();
 *   $f->setFileName('/var/data/exports/output.xml');
 *   $f->openFile();
 *   $f->write('<root>');
 *   $f->writeLn();
 *   $f->write('</root>');
 *   $f->closeFile();
 *
 * @package Biblhertz\Publink\utilities
 * @author  Chris Tomlinson
 * @since   March 2023
 */

namespace Biblhertz\Publink\utilities;

class FileCreator
{

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var string Absolute path to the target file, set via setFileName(). */
    private string $fileName = '';

    /**
     * @var string Directory portion of $fileName, derived automatically by setFileName().
     *             Created on disk (recursively) by openFile() if it does not already exist.
     */
    private string $dirName = '';

    /**
     * @var resource|false File handle returned by fopen(), or false before openFile() is called.
     *                     Typed as mixed for PHP 7 compatibility; narrowed at runtime.
     */
    private mixed $fileHandle = false;


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Sets the full path of the file to create and derives the directory from it.
     * Does not create the file or directory — call openFile() to do that.
     *
     * @param  string $s Absolute or relative path to the target file.
     * @return void
     */
    public function setFileName(string $s): void
    {
        $this->fileName = $s;
        $this->dirName  = dirname($s);
    }

    /**
     * Returns the full file path as set by setFileName().
     *
     * @return string The configured file path.
     */
    public function getFileName(): string
    {
        return $this->fileName;
    }

    /**
     * Returns the filename without its directory path or extension.
     * Equivalent to getName() — both methods are retained for API compatibility.
     *
     * Example: '/var/data/exports/output.xml' → 'output'
     *
     * @return string Filename stem (no directory, no extension).
     */
    public function getBaseFileName(): string
    {
        return pathinfo($this->fileName, PATHINFO_FILENAME);
    }

    /**
     * Returns the filename without its directory path or extension.
     * Alias of getBaseFileName(), retained for API compatibility.
     *
     * @return string Filename stem (no directory, no extension).
     */
    public function getName(): string
    {
        return pathinfo($this->fileName, PATHINFO_FILENAME);
    }

    /**
     * Opens the file for writing.
     *
     * - If the file already exists it is deleted first so the new file starts empty.
     * - If the target directory does not exist it is created recursively with mode 0755.
     * - Opens the file with mode 'w' (write, truncate) — 'wx' is avoided as it would
     *   fail if the file was just unlinked and recreated by another process; 'w' is safe
     *   after the explicit unlink above.
     *
     * @throws \RuntimeException If the file cannot be opened.
     * @return void
     */
    public function openFile(): void
    {
        if (file_exists($this->fileName) && !unlink($this->fileName)) {
            throw new \RuntimeException("Could not delete existing file: {$this->fileName}");
        }

        if (!is_dir($this->dirName) && !mkdir($this->dirName, 0755, true)) {
            throw new \RuntimeException("Could not create directory: {$this->dirName}");
        }

        $handle = fopen($this->fileName, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Could not open file: {$this->fileName}");
        }

        $this->fileHandle = $handle;
    }

    /**
     * Writes a string to the open file.
     *
     * @param  string $s The string to write.
     * @return void
     */
    public function write(string $s): void
    {
        if ($this->fileHandle === false) {
            throw new \RuntimeException("File is not open. Call openFile() first.");
        }
        if (fwrite($this->fileHandle, $s) === false) {
            throw new \RuntimeException("Write failed for file: {$this->fileName}");
        }
    }

    /**
     * Writes a Unix newline character (\n) to the open file.
     *
     * @return void
     */
    public function writeLn(): void
    {
        if ($this->fileHandle === false) {
            throw new \RuntimeException("File is not open. Call openFile() first.");
        }
        if (fwrite($this->fileHandle, "\n") === false) {
            throw new \RuntimeException("Write failed for file: {$this->fileName}");
        }
    }

    /**
     * Closes the open file handle.
     * Should always be called after all write() / writeLn() calls are complete.
     *
     * @return void
     */
    public function closeFile(): void
    {
        if ($this->fileHandle !== false) {
            fclose($this->fileHandle);
            $this->fileHandle = false;
        }
    }


    /****************************************************************/
    /* STATIC UTILITY METHODS                                       */
    /****************************************************************/

    /**
     * Recursively deletes a directory and all of its contents.
     *
     * Traverses the directory tree depth-first: files are unlinked and
     * subdirectories are deleted recursively before their parent is removed.
     * The '.' and '..' entries are skipped automatically.
     *
     * @param  string $dirname Absolute or relative path to the directory to delete.
     * @return bool True on success, false if $dirname does not exist or is not a directory.
     */
    public static function deleteDirectory(string $dirname): bool
    {
        if (!is_dir($dirname)) {
            return false;
        }

        $handle = opendir($dirname);
        if ($handle === false) {
            return false;
        }

        $success = true;

        while (($file = readdir($handle)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dirname . '/' . $file;
            if (is_dir($path)) {
                if (!self::deleteDirectory($path)) {
                    $success = false;
                }
            } else {
                if (!unlink($path)) {
                    $success = false;
                }
            }
        }

        closedir($handle);

        if ($success && !rmdir($dirname)) {
            $success = false;
        }

        return $success;
    }
}