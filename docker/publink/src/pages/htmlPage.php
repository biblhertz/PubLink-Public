<?php
/**
 * htmlPage.php
 *
 * Abstract base class providing a library of static utility methods for rendering
 * HTML page components, form elements, and date/utility helpers used throughout
 * the PubLink publishing system.
 *
 * All path roots (site, images, XSL, CSS, JS) are configured once via static
 * setters and then available to any subclass or static caller.
 *
 * Subclasses are expected to implement their own page-rendering logic while
 * inheriting access to all static helper methods defined here.
 *
 * @package    Biblhertz\Publink\pages
 * @author     Chris Tomlinson
 * @since      March 2023
 */

namespace Biblhertz\Publink\pages;

use Biblhertz\Publink\utilities\PDODatabase;
use PDOStatement;

abstract class htmlPage
{

    /****************************************************************/
    /* STATIC CONFIGURATION VARIABLES                               */
    /****************************************************************/

    /** @var string Absolute or relative URL root of the site (e.g. '/publink') */
    private static string $siteRoot = '';

    /** @var string Name/identifier of the page creator, used in meta or audit contexts */
    private static string $creator = '';

    /** @var string URL root for image assets */
    private static string $imageRoot = '';

    /** @var string URL root for XSL stylesheets used in XML/XSLT transforms */
    private static string $xslRoot = '';

    /** @var string URL root for CSS stylesheets */
    private static string $cssRoot = '';

    /** @var string URL root for JavaScript files */
    private static string $jsRoot = '';


    /****************************************************************/
    /* CONFIGURATION GETTERS / SETTERS                              */
    /****************************************************************/

    /** @param string $r Absolute or relative site root URL. */
    public static function setSiteRoot(string $r): void { self::$siteRoot = $r; }

    /** @return string The configured site root URL. */
    public static function getSiteRoot(): string { return self::$siteRoot; }

    /** @param string $r Creator name or identifier. */
    public static function setCreator(string $r): void { self::$creator = $r; }

    /** @return string The configured creator name. */
    public static function getCreator(): string { return self::$creator; }

    /** @param string $r URL root for XSL stylesheets. */
    public static function setXSLRoot(string $r): void { self::$xslRoot = $r; }

    /** @return string The configured XSL root URL. */
    public static function getXSLRoot(): string { return self::$xslRoot; }

    /** @param string $r URL root for image assets. */
    public static function setImageRoot(string $r): void { self::$imageRoot = $r; }

    /** @return string The configured image root URL. */
    public static function getImageRoot(): string { return self::$imageRoot; }

    /** @param string $r URL root for CSS stylesheets. */
    public static function setCssRoot(string $r): void { self::$cssRoot = $r; }

    /** @return string The configured CSS root URL. */
    public static function getCssRoot(): string { return self::$cssRoot; }

    /** @param string $r URL root for JavaScript files. */
    public static function setJSRoot(string $r): void { self::$jsRoot = $r; }

    /** @return string The configured JavaScript root URL. */
    public static function getJSRoot(): string { return self::$jsRoot; }

    /**
     * Renders the complete HTML document for this page.
     *
     * @return string Complete HTML document as a string.
     */
    abstract public function getPage(): string;


    /****************************************************************/
    /* BASIC TEXT RENDERING                                         */
    /****************************************************************/

    /**
     * Wraps the given text in a <p> tag.
     *
     * @param  string $text Text or HTML content to wrap.
     * @return string HTML paragraph element.
     */
    public static function getParagraph(string $text): string
    {
        return "<p>$text</p>";
    }

    /**
     * Wraps the given text in a <p> tag.
     * Alias of getParagraph() retained for legacy compatibility.
     *
     * @param  string $text Text or HTML content to wrap.
     * @return string HTML paragraph element.
     */
    public static function getText(string $text): string
    {
        return self::getParagraph($text);
    }

    /**
     * Wraps the given text in an <h3> tag for use as a page section heading.
     *
     * @param  string $text Heading text.
     * @return string HTML heading element.
     */
    public static function getHeaderText(string $text): string
    {
        return "<h3>$text</h3>";
    }


    /****************************************************************/
    /* PAGE COMPONENT METHODS                                       */
    /****************************************************************/

    /**
     * Renders an HTML <img> element.
     *
     * The legacy 'align' and 'border' attributes have been dropped in HTML5;
     * use CSS classes/styles on the calling side for layout and borders.
     *
     * @param  string $address URL or path of the image.
     * @param  int    $width   Display width in pixels.
     * @param  int    $height  Display height in pixels.
     * @param  string $alt     Alternative text for accessibility. Default: ''.
     * @param  string $align   Ignored — retained for signature compatibility only.
     * @param  int    $border  Ignored — retained for signature compatibility only.
     * @return string HTML5 self-closing image element.
     */
    public static function makeImage(
        string $address,
        int $width,
        int $height,
        string $alt = '',
        string $align = 'baseline',
        int $border = 0
    ): string {
        $alt = htmlspecialchars($alt, ENT_QUOTES);
        $address = htmlspecialchars($address, ENT_QUOTES);
        return "<img src=\"$address\" width=\"$width\" height=\"$height\" alt=\"$alt\">\n";
    }

    /**
     * Renders an HTML anchor (<a>) element.
     *
     * @param  string $address URL for the href attribute.
     * @param  string $text    Link display text or inner HTML.
     * @param  string $target  Optional target attribute value (e.g. '_blank'). Default: ''.
     * @return string HTML anchor element.
     */
    public static function makeLink(string $address, string $text, string $target = ''): string
    {
        $address = htmlspecialchars($address, ENT_QUOTES);
        $targetAttr = $target !== '' ? " target=\"" . htmlspecialchars($target, ENT_QUOTES) . "\"" : '';
        // rel="noopener noreferrer" prevents tab-napping when opening in a new window
        $relAttr = $target === '_blank' ? ' rel="noopener noreferrer"' : '';
        return "<a href=\"$address\"$targetAttr$relAttr>$text</a>";
    }


    /****************************************************************/
    /* FORM METHODS                                                 */
    /****************************************************************/

    /**
     * Renders an opening <form> tag.
     *
     * @param  string $action Form action URL.
     * @param  string $method HTTP method ('post' or 'get'). Default: 'post'.
     * @param  string $name   Optional name attribute for the form. Default: ''.
     * @return string Opening HTML form tag.
     */
    public static function makeFormHead(string $action, string $method = 'post', string $name = ''): string
    {
        $action = htmlspecialchars($action, ENT_QUOTES);
        $method = strtolower($method);
        $nameAttr = $name !== '' ? ' name="' . htmlspecialchars($name, ENT_QUOTES) . '"' : '';
        return "<form{$nameAttr} action=\"$action\" method=\"$method\">";
    }

    /**
     * Renders a closing </form> tag.
     *
     * @return string Closing HTML form tag.
     */
    public static function makeFormFoot(): string
    {
        return '</form>';
    }

    /**
     * Renders a text <input> element.
     *
     * Note: The $size and $maxlength parameters are swapped from their conventional
     * meaning — $size sets maxlength and $maxlength sets the displayed size —
     * retained as-is for backwards compatibility.
     *
     * @param  string $name      The name and id attribute value for the input.
     * @param  int    $size      Maximum number of characters allowed (maxlength).
     * @param  string $type      Input type attribute (e.g. 'text', 'password'). Default: 'text'.
     * @param  int    $maxlength Visible display size in characters (size). Default: 0 (falls back to $size).
     * @param  string $text      Initial value of the input. Default: ''.
     * @return string HTML input element.
     */
    public static function makeInput(
        string $name,
        int $size,
        string $type = 'text',
        int $maxlength = 0,
        string $text = ''
    ): string {
        $name = htmlspecialchars($name, ENT_QUOTES);
        $type = strtolower(htmlspecialchars($type, ENT_QUOTES));
        $text = htmlspecialchars($text, ENT_QUOTES);
        $displaySize = $maxlength ?: $size;
        return "<input type=\"$type\" name=\"$name\" id=\"$name\" size=\"$displaySize\" maxlength=\"$size\" value=\"$text\">";
    }

    /**
     * Renders a hidden <input> element.
     *
     * @param  string $name  The name and id attribute value.
     * @param  string $value The hidden value to submit with the form.
     * @return string HTML hidden input element.
     */
    public static function makeHiddenInput(string $name, string $value): string
    {
        $name  = htmlspecialchars($name, ENT_QUOTES);
        $value = htmlspecialchars($value, ENT_QUOTES);
        return "<input type=\"hidden\" name=\"$name\" id=\"$name\" value=\"$value\">";
    }

    /**
     * Renders a <textarea> element.
     *
     * @param  string $name     The name and id attribute value.
     * @param  int    $rows     Number of visible text rows.
     * @param  int    $cols     Number of visible text columns.
     * @param  string $value    Initial text content. Default: ''.
     * @param  bool   $readonly Whether to render the textarea as read-only. Default: false.
     * @return string HTML textarea element.
     */
    public static function makeTextArea(
        string $name,
        int $rows,
        int $cols,
        string $value = '',
        bool $readonly = false
    ): string {
        $name  = htmlspecialchars($name, ENT_QUOTES);
        $value = htmlspecialchars($value, ENT_QUOTES);
        $readonlyAttr = $readonly ? ' readonly' : '';
        return "<textarea name=\"$name\" id=\"$name\" rows=\"$rows\" cols=\"$cols\"$readonlyAttr>$value</textarea>";
    }

    /**
     * Renders a Bootstrap-styled submit or button input.
     *
     * @param  string     $name    The name attribute value.
     * @param  string     $text    The label text displayed on the button (value attribute).
     * @param  string     $type    Input type: 'submit', 'button', or 'reset'. Default: 'submit'.
     * @param  mixed      $onclick JavaScript expression for the onclick handler, or 0 for none. Default: 0.
     * @return string HTML input element styled with btn btn-primary btn-sm classes.
     */
    public static function makeButton(string $name, string $text, string $type = 'submit', mixed $onclick = 0): string
    {
        $name = htmlspecialchars($name, ENT_QUOTES);
        $text = htmlspecialchars($text, ENT_QUOTES);
        $type = strtolower(htmlspecialchars($type, ENT_QUOTES));
        $onclickAttr = $onclick ? ' onclick="' . htmlspecialchars((string)$onclick, ENT_QUOTES) . '"' : '';
        return "<input class=\"btn btn-primary btn-sm\" type=\"$type\" name=\"$name\" value=\"$text\"$onclickAttr>";
    }

    /**
     * Renders a radio button <input> element.
     *
     * @param  string $name    The name attribute, shared across all buttons in the group.
     * @param  string $value   The value submitted when this button is selected.
     * @param  bool   $checked Whether to pre-select this button. Default: false.
     * @return string HTML radio input element.
     */
    public static function makeRadioButton(string $name, string $value, bool $checked = false): string
    {
        $name  = htmlspecialchars($name, ENT_QUOTES);
        $value = htmlspecialchars($value, ENT_QUOTES);
        $checkedAttr = $checked ? ' checked' : '';
        return "<input type=\"radio\" name=\"$name\" value=\"$value\"$checkedAttr>";
    }

    /**
     * Renders a checkbox <input> element.
     *
     * @param  string $name    The name and id attribute value.
     * @param  string $value   The value submitted when this checkbox is checked.
     * @param  bool   $checked Whether to pre-check this box. Default: false.
     * @param  string $onclick Optional JavaScript expression for the onclick handler. Default: ''.
     * @return string HTML checkbox input element.
     */
    public static function makeCheckBox(string $name, string $value, bool $checked = false, string $onclick = ''): string
    {
        $name  = htmlspecialchars($name, ENT_QUOTES);
        $value = htmlspecialchars($value, ENT_QUOTES);
        $checkedAttr  = $checked ? ' checked' : '';
        $onclickAttr  = $onclick !== '' ? ' onclick="' . htmlspecialchars($onclick, ENT_QUOTES) . '"' : '';
        return "<input type=\"checkbox\" name=\"$name\" id=\"$name\" value=\"$value\"$checkedAttr$onclickAttr>";
    }

    /**
     * Renders a <select> dropdown populated from a PDO result set.
     *
     * Iterates through all rows in $resultSet, using $key as the option value
     * and $display as the visible label. The row matching $selected will be
     * pre-selected.
     *
     * @param  string       $name      The name and id attribute value.
     * @param  PDOStatement $resultSet An executed PDO statement ready for fetching.
     * @param  string       $key       Column name in the result set to use as option value.
     * @param  string       $display   Column name in the result set to use as option label.
     * @param  mixed        $selected  Value of the row that should be pre-selected. Default: 0.
     * @return string HTML select element with option elements.
     */
    public static function makeOption(
        string $name,
        PDOStatement $resultSet,
        string $key,
        string $display,
        mixed $selected = 0
    ): string {
        $name = htmlspecialchars($name, ENT_QUOTES);
        $str  = "<select name=\"$name\" id=\"$name\">";
        while ($row = $resultSet->fetch()) {
            $val   = htmlspecialchars($row[$key], ENT_QUOTES);
            $label = htmlspecialchars($row[$display], ENT_QUOTES);
            $sel   = (string)$row[$key] === (string)$selected ? ' selected' : '';
            $str  .= "<option value=\"$val\"$sel>$label</option>";
        }
        return $str . '</select>';
    }

    /**
     * Renders a multi-select <select> element populated from a PDO result set.
     *
     * Renders a scrollable list with the 'multiple' attribute, allowing zero or
     * more options to be selected simultaneously. Append '[]' to $name for PHP
     * to receive the selections as an array.
     *
     * @param  string       $name      The name attribute value.
     * @param  PDOStatement $resultSet An executed PDO statement ready for fetching.
     * @param  string       $key       Column name in the result set to use as option value.
     * @param  string       $display   Column name in the result set to use as option label.
     * @param  int          $size      Number of visible rows in the list box.
     * @return string HTML multi-select element with option elements.
     */
    public static function makeOptionMultiple(
        string $name,
        PDOStatement $resultSet,
        string $key,
        string $display,
        int $size
    ): string {
        $name = htmlspecialchars($name, ENT_QUOTES);
        $str  = "<select name=\"$name\" id=\"$name\" size=\"$size\" multiple>";
        while ($row = $resultSet->fetch()) {
            $val   = htmlspecialchars($row[$key], ENT_QUOTES);
            $label = htmlspecialchars($row[$display], ENT_QUOTES);
            $str  .= "<option value=\"$val\">$label</option>";
        }
        return $str . '</select>';
    }

    /**
     * Renders a <select> dropdown populated from a two-dimensional array.
     *
     * Each element of $arr must be a two-element array where index 0 is the
     * option value and index 1 is the visible label.
     *
     * Example:
     *   $arr = [['en', 'English'], ['de', 'Deutsch'], ['it', 'Italiano']];
     *   echo htmlPage::makeOptionFromArray('lang', $arr, 'de');
     *
     * @param  string $name     The name and id attribute value.
     * @param  array  $arr      2D array of [value, label] pairs.
     * @param  mixed  $selected Value of the option to pre-select. Default: 0.
     * @param  mixed  $onChange JavaScript expression for the onchange handler, or 0 for none. Default: 0.
     * @return string HTML select element with option elements.
     */
    public static function makeOptionFromArray(string $name, array $arr, mixed $selected = 0, mixed $onChange = 0): string
    {
        $name = htmlspecialchars($name, ENT_QUOTES);
        $onchangeAttr = $onChange ? ' onchange="' . htmlspecialchars((string)$onChange, ENT_QUOTES) . '"' : '';
        $str = "<select name=\"$name\" id=\"$name\"$onchangeAttr>";
        foreach ($arr as $item) {
            $val   = htmlspecialchars((string)$item[0], ENT_QUOTES);
            $label = htmlspecialchars((string)$item[1], ENT_QUOTES);
            $sel   = (string)$item[0] === (string)$selected ? ' selected' : '';
            $str  .= "<option value=\"$val\"$sel>$label</option>";
        }
        return $str . '</select>';
    }


    /****************************************************************/
    /* DATE AND TIME UTILITY METHODS                                */
    /****************************************************************/

    /**
     * Returns the current UTC time as a formatted string.
     *
     * @return string Current time in 'H:i:s' format (UTC).
     */
    public static function getTime(): string
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->format('H:i:s');
    }

    /**
     * Returns today's date as a human-readable string.
     *
     * @return string Today's date in 'jS M Y' format (e.g. '4th Feb 2024').
     */
    public static function getToday(): string
    {
        return date('jS M Y');
    }

    /**
     * Formats a given day/month/year as a MySQL-compatible date string.
     *
     * @param  int $days   Day of the month.
     * @param  int $months Month number (1–12).
     * @param  int $years  Four-digit year.
     * @return string Date in 'YYYY-MM-DD' format.
     */
    public static function getSQLDate(int $days, int $months, int $years): string
    {
        return sprintf('%04d-%02d-%02d', $years, $months, $days);
    }

    /**
     * Returns today's date in MySQL 'YYYY-MM-DD' format.
     *
     * @return string Today's date as a SQL date string.
     */
    public static function getTodayAsSQLDate(): string
    {
        return date('Y-m-d');
    }

    /**
     * Returns the current date and time as a MySQL DATETIME string.
     *
     * @return string Current datetime in 'YYYY-MM-DD HH:MM:SS' format.
     */
    public static function getNowAsSQLTimeStamp(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Converts a MySQL date string to a short display format.
     *
     * @param  string $date Date in 'YYYY-MM-DD' format.
     * @return string Date in 'j/m/y' format (e.g. '4/02/24').
     */
    public static function getShortDateFromSQL(string $date): string
    {
        $parts = explode('-', $date);
        if (count($parts) < 3) return '';
        return date('j/m/y', mktime(0, 0, 0, (int)$parts[1], (int)$parts[2], (int)$parts[0]));
    }

    /**
     * Converts a MySQL date string to a long human-readable display format.
     * Returns an empty string if the date string cannot be parsed into three parts.
     *
     * @param  string $date Date in 'YYYY-MM-DD' format.
     * @return string Date in 'jS F Y' format (e.g. '4th February 2024'), or '' on failure.
     */
    public static function getDateFromSQL(string $date): string
    {
        $parts = explode('-', $date);
        if (count($parts) < 3) return '';
        return date('jS F Y', mktime(0, 0, 0, (int)$parts[1], (int)$parts[2], (int)$parts[0]));
    }

    /**
     * Converts a date in 'DD/MM/YYYY' slash format to MySQL 'YYYY-MM-DD' format.
     *
     * @param  string $date Date in 'DD/MM/YYYY' format.
     * @return string Date in 'YYYY-MM-DD' format, or '' if input cannot be parsed.
     */
    public static function getSQLDateFromSlashFormat(string $date): string
    {
        $parts = explode('/', $date);
        if (count($parts) < 3) return '';
        return sprintf('%04d-%02d-%02d', (int)$parts[2], (int)$parts[1], (int)$parts[0]);
    }

    /**
     * Splits a MySQL DATETIME timestamp into separate date and time strings.
     *
     * @param  string $timestamp Datetime string parseable by strtotime() (e.g. '2024-02-04 14:30:00').
     * @return array{0: string, 1: string} Two-element array: [0] => 'DD-MM-YYYY', [1] => 'H:MM:SS'.
     */
    public static function getTimeStampAsDateTimeArray(string $timestamp): array
    {
        $ts = strtotime($timestamp);
        if ($ts === false) return ['', ''];
        return [date('d-m-Y', $ts), date('G:i:s', $ts)];
    }


    /****************************************************************/
    /* GENERAL UTILITY METHODS                                      */
    /****************************************************************/

    /**
     * Validates an email address using PHP's built-in FILTER_VALIDATE_EMAIL filter.
     *
     * @param  string $email The email address to validate.
     * @return bool True if the address passes validation, false otherwise.
     */
    public static function isValidEmail(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Generates a random alphanumeric password string of variable length.
     *
     * The returned string contains only upper- and lower-case ASCII letters
     * and digits 0–9. Length is chosen randomly in the range [$min, $max].
     *
     * @param  int $min Minimum password length.
     * @param  int $max Maximum password length.
     * @return string Random alphanumeric string.
     */
    public static function getRandomPassword(int $min, int $max): string
    {
        $chars = array_merge(range('a', 'z'), range('A', 'Z'), range('0', '9'));
        $str   = '';
        $len   = $min + random_int(0, $max - $min);
        for ($i = 0; $i < $len; $i++) {
            $str .= $chars[random_int(0, count($chars) - 1)];
        }
        return $str;
    }


    /****************************************************************/
    /* ENUM / DATABASE UTILITY METHODS                              */
    /****************************************************************/

    /**
     * Renders a <select> dropdown populated from a MySQL ENUM column definition.
     *
     * Retrieves the allowed values for the specified ENUM field via getEnumVals(),
     * then delegates to makeOptionFromArray() to produce the HTML.
     *
     * @param  PDODatabase $objDB    Active database connection.
     * @param  string      $name     The name and id attribute of the resulting select element.
     * @param  string      $table    Table name containing the ENUM column.
     * @param  string      $field    Column name of the ENUM field.
     * @param  mixed       $selected The ENUM value to pre-select. Default: 0 (no selection).
     * @return string HTML select element containing one option per ENUM value.
     */
    public static function getEnumAsPullDown(
        PDODatabase $objDB,
        string $name,
        string $table,
        string $field,
        mixed $selected = 0
    ): string {
        $vals = self::getEnumVals($objDB, $table, $field);
        $opts = array_map(fn($v) => [$v, $v], $vals);
        return self::makeOptionFromArray($name, $opts, $selected);
    }

    /**
     * Retrieves the allowed values from a MySQL ENUM column using SHOW COLUMNS.
     *
     * Parses the Type string returned by MySQL (e.g. "enum('draft','published','archived')")
     * and extracts each quoted value using a regex.
     *
     * @param  PDODatabase $objDB Active database connection.
     * @param  string      $table Table name containing the ENUM column.
     * @param  string      $field Column name of the ENUM field.
     * @return array<int,string> Ordered array of ENUM value strings.
     */
    public static function getEnumVals(PDODatabase $objDB, string $table, string $field): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $field)) return [];
        $sql    = "SHOW COLUMNS FROM $table LIKE '$field'";
        $fields = $objDB->select($sql);
        $row    = $fields->fetch();
        if (!$row) return [];

        preg_match_all("/'(.*?)'/", $row['Type'], $matches);

        return array_map(fn($v) => str_replace("'", '', $v), $matches[1]);
    }
}