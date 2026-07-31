<?php
namespace Biblhertz\Publink\components;

use Biblhertz\Publink\components\BootstrapFormComponent;
use PDOStatement;


/**
 * BootstrapOption
 *
 * Renders a Bootstrap-styled `<select>` drop-down with an optional label.
 *
 * Options can be supplied as a pre-built 2D array via {@see setOptions()}, or
 * populated directly from a PDO result set via {@see setResultSet()}. The
 * currently selected value is highlighted with the `selected` attribute.
 *
 * @example
 *   $select = new BootstrapOption();
 *   $select->setName('status');
 *   $select->setLabel('Status');
 *   $select->setOptions([['active', 'Active'], ['inactive', 'Inactive']]);
 *   $select->setSelected('active');
 *   echo $select->getComponent();
 *
 * @package    Biblhertz\Publink
 * @subpackage components
 * @author     Chris Tomlinson
 * @since      10th March 2016
 */
class BootstrapOption extends BootstrapFormComponent {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var string The value of the currently selected option. */
    private string $selected = '';

    /**
     * @var array<int, array{0: string, 1: string}> Options as a 2D array of
     *      [value, display_label] pairs, e.g. `[['t', 'Enabled'], ['f', 'Disabled']]`.
     */
    private array $options = [];


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set the value of the option that should be pre-selected.
     *
     * @param string $value The option value to match against the options array.
     */
    public function setSelected(string $value): void {
        $this->selected = $value;
    }

    /**
     * Set the options list from a pre-built 2D array.
     *
     * Each entry must be a two-element indexed array where index 0 is the
     * option value and index 1 is the display label.
     *
     * @param array $arr e.g. `[['t', 'Enabled'], ['f', 'Disabled']]`
     */
    public function setOptions(array $arr): void {
        $this->options = $arr;
    }

    /**
     * Populate the options list from a PDO result set.
     *
     * Iterates the result set and maps two named columns to the internal
     * `[value, label]` option format. The result set cursor is consumed.
     * Rows missing either column are silently skipped.
     *
     * @param PDOStatement $set  Result set from a DB query.
     * @param string       $val  Column name to use as the option value.
     * @param string       $disp Column name to use as the display label.
     */
    public function setResultSet(PDOStatement $set, string $val, string $disp): void {
        $this->options = [];
        while ($row = $set->fetch()) {
            if (!isset($row[$val], $row[$disp])) continue;
            $this->options[] = [(string) $row[$val], (string) $row[$disp]];
        }
    }


    /****************************************************************/
    /* OTHER METHODS                                                */
    /****************************************************************/

    /**
     * Render the select element as an HTML string.
     *
     * Returns an empty string if no options have been set. Wraps the label
     * and `<select>` in a `<div>` pair for Bootstrap form layout. The option
     * matching {@see $selected} receives the `selected` attribute.
     *
     * @return string Rendered HTML markup, or empty string when options are absent.
     */
    public function getComponent(): string {
        if (empty($this->options)) return '';

        $id       = htmlspecialchars($this->getID() ?: $this->getName(), ENT_QUOTES, 'UTF-8');
        $name     = htmlspecialchars($this->getName(), ENT_QUOTES, 'UTF-8');
        $required = $this->required ? ' required="required"' : '';
        $class    = 'form-control' . (!empty($this->componentClass) ? ' ' . htmlspecialchars($this->componentClass, ENT_QUOTES, 'UTF-8') : '');
        $rows     = '';

        foreach ($this->options as $opt) {
            $val   = htmlspecialchars((string) $opt[0], ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string) $opt[1], ENT_QUOTES, 'UTF-8');
            $sel   = ((string) $opt[0] === $this->selected) ? ' selected="selected"' : '';
            $rows .= "<option value=\"{$val}\"{$sel}>{$label}</option>";
        }

        return <<<HTML
            <div>
                {$this->getLabelText()}
                <div>
                    <select id="{$id}" name="{$name}" class="{$class}"{$required}>
                        {$rows}
                    </select>
                </div>
            </div>
            HTML;
    }
}
