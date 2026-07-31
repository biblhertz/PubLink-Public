<?php
/**
 * Utilities.php
 *
 * General-purpose static utility methods used across the PubLink system.
 *
 * Provides:
 *  - Text sanitisation (to_utf)
 *  - XML parse error reporting (printXMLErrors)
 *  - Linux process management (killProcessByName)
 *  - BibTeX string normalisation (renderBibtexTitle)
 *
 * @package Biblhertz\Publink\utilities
 * @author  Chris Tomlinson
 */

namespace Biblhertz\Publink\utilities;

use Biblhertz\Publink\Config;

class Utilities
{

    /**
     * Sanitises a text string for safe storage and display.
     *
     * Trims leading/trailing whitespace, collapses internal whitespace runs to a
     * single space, and strips HTML tags. The result is returned as a UTF-8 string.
     *
     * @param  mixed  $text Input value. Non-string types and empty values return ''.
     * @return string Sanitised UTF-8 string, or '' if the input was empty.
     */
    public static function to_utf(mixed $text): string
    {
        if (empty($text)) {
            return '';
        }

        $text = strip_tags($text);
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return $text;
    }

    /**
     * Writes XML parse errors from libxml to the Logger.
     *
     * Logs a separator line, then for each error logs the line number and the
     * error message, followed by a closing separator. Typically called after
     * simplexml_load_string() or DOMDocument::loadXML() with
     * LIBXML_NOERROR | LIBXML_NOWARNING suppression disabled.
     *
     * Example:
     *   libxml_use_internal_errors(true);
     *   $xml = simplexml_load_string($raw);
     *   Utilities::printXMLErrors(libxml_get_errors());
     *   libxml_clear_errors();
     *
     * @param  array<int, \LibXMLError> $errors Array of LibXMLError objects from libxml_get_errors().
     * @param  Logger                   $logger Logger instance to write errors to.
     * @return void
     */
    public static function printXMLErrors(array $errors, Logger $logger): void
    {
        $logger->printLn();
        foreach ($errors as $error) {
            $logger->print('!!! XML Parse Error :: Line ' . $error->line);
            $logger->print(trim($error->message));
        }
        $logger->printLn();
    }

    /**
     * Kills a Linux process (or set of processes) matching the given name.
     *
     * Uses pgrep to find process IDs matching $processName, then sends either
     * SIGTERM (default) or SIGKILL (-9) to each. The process name is validated
     * against a strict allowlist pattern before use to prevent shell injection.
     *
     * Note: this method executes shell commands and should only be called from
     * trusted, server-side administrative contexts. Never pass user-supplied input
     * as $processName without additional validation.
     *
     * @param  string $processName Name of the process to kill. Allowed characters:
     *                             alphanumeric, underscore, hyphen, dot.
     * @param  bool   $forceful    When true sends SIGKILL (-9) instead of SIGTERM.
     *                             Default: false.
     * @return array{success: bool, message: string} Result array with:
     *                             - 'success': true if at least one process was killed.
     *                             - 'message': human-readable outcome or error description.
     */
    public static function killProcessByName(string $processName, bool $forceful = false): array
    {
        // Validate process name to prevent shell injection
        if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $processName)) {
            return [
                'success' => false,
                'message' => 'Invalid process name. Only alphanumeric characters, dots, underscores, and hyphens are allowed.',
            ];
        }

        // Use pgrep to safely resolve process name to a list of PIDs
        $pids = shell_exec('pgrep -f ' . escapeshellarg($processName));

        if (empty($pids)) {
            return [
                'success' => false,
                'message' => "No processes found with name: $processName",
            ];
        }

        $signal     = $forceful ? '-9' : '-15';
        $pidArray   = array_filter(explode("\n", trim($pids)), 'is_numeric');
        $killedPids = [];
        $failedPids = [];

        foreach ($pidArray as $pid) {
            $output = shell_exec("kill $signal $pid 2>&1");

            if (empty($output)) {
                $killedPids[] = $pid;
            } else {
                error_log("killProcessByName: error killing PID $pid: $output");
                $failedPids[] = $pid;
            }
        }

        if (count($killedPids) > 0) {
            $message = 'Successfully killed ' . count($killedPids) . ' process(es): ' . implode(', ', $killedPids);
            if (count($failedPids) > 0) {
                $message .= '. Failed to kill ' . count($failedPids) . ' process(es): ' . implode(', ', $failedPids);
            }
            return ['success' => true, 'message' => $message];
        }

        return [
            'success' => false,
            'message' => 'No processes were killed.',
        ];
    }

    /**
     * Normalises a BibTeX field string by removing BibTeX brace markup.
     *
     * BibTeX uses curly braces for two purposes:
     *  1. Outer braces wrapping an entire field value: {Some Title Here}
     *  2. Inner protective braces preserving capitalisation: {DNA} sequencing
     *
     * This method strips both layers of braces while preserving the text they
     * contained, and also removes characters listed in Config::$BADCHARS.
     *
     * @param  string $title Raw BibTeX field string, potentially containing
     *                       outer and/or inner brace markup.
     * @return string Plain text with all BibTeX brace markup removed.
     */
    public static function renderBibtexTitle(string $title): string
    {
        $title = trim(stripslashes($title));
        $title = str_replace(Config::$BADCHARS, '', $title);

        // Strip outer braces if they wrap the entire string
        if (strlen($title) >= 2 && $title[0] === '{' && $title[-1] === '}') {
            $depth        = 0;
            $isOuterBrace = true;

            $len = strlen($title);
            for ($i = 0; $i < $len - 1; $i++) {
                if ($title[$i] === '{') {
                    $depth++;
                } elseif ($title[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $isOuterBrace = false;
                        break;
                    }
                }
            }

            if ($isOuterBrace) {
                $title = substr($title, 1, -1);
            }
        }

        // Strip all remaining inner protective braces, keeping their content
        return str_replace(['{', '}'], '', $title);
    }
}