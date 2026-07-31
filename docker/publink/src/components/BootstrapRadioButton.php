<?php
namespace Biblhertz\Publink\components;

use Biblhertz\Publink\components\BootstrapFormComponent;


/**
 * BootstrapRadioButton
 *
 * Renders a single Bootstrap radio button within a named group.
 *
 * Each instance represents one `<input type="radio">` wrapped in a `<label>`
 * with an optional Bootstrap tooltip on the label text and an optional onclick
 * handler. Multiple instances sharing the same group name form a radio group.
 *
 * Note: the inherited {@see setName()} sets the `id` attribute on the input;
 * use {@see setGroupName()} to set the `name` attribute (the radio group).
 *
 * @example
 *   $radio = new BootstrapRadioButton();
 *   $radio->setName('format_pdf');
 *   $radio->setGroupName('format');
 *   $radio->setValue('pdf');
 *   $radio->setLabel('PDF');
 *   $radio->setMouseOver('Download as PDF');
 *   $radio->setOnClick('formatChanged()');
 *   $radio->makeChecked();
 *   echo $radio->getComponent();
 *
 * @package    Biblhertz\Publink
 * @subpackage components
 * @author     Chris Tomlinson
 * @since      10th March 2016
 */
class BootstrapRadioButton extends BootstrapFormComponent {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /**
     * @var string The shared `name` attribute that groups related radio buttons.
     *             Only one button within a group can be selected at a time.
     */
    private string $groupName = '';

    /** @var bool When true, renders `checked="checked"` on the input. */
    private bool $checked = false;

    /** @var string Bootstrap tooltip text shown when hovering over the label. Empty = no tooltip. */
    private string $mouseOver = '';

    /** @var string Auxiliary text associated with this button (not rendered directly). */
    private string $text = '';

    /** @var string JavaScript expression attached to the input's onclick handler. Empty = omitted. */
    private string $onClick = '';


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set the radio group name.
     *
     * All radio buttons sharing the same group name are mutually exclusive.
     * Maps to the `name` attribute on the `<input>` element.
     *
     * @param string $groupName The group name, e.g. `'format'` or `'status'`.
     */
    public function setGroupName(string $groupName): void {
        $this->groupName = $groupName;
    }

    /**
     * Pre-select this radio button.
     *
     * Causes `checked="checked"` to be rendered on the input element.
     */
    public function makeChecked(): void {
        $this->checked = true;
    }

    /**
     * Set the Bootstrap tooltip text displayed when hovering over the label.
     *
     * Rendered as a `data-toggle="tooltip"` span wrapping the label text.
     *
     * @param string $mouseOver Tooltip content (plain text).
     */
    public function setMouseOver(string $mouseOver): void {
        $this->mouseOver = $mouseOver;
    }

    /**
     * Set auxiliary text associated with this button.
     *
     * Stored for use by calling code; not rendered by {@see getComponent()}.
     *
     * @param string $text The text value.
     */
    public function setText(string $text): void {
        $this->text = $text;
    }

    /**
     * Return the auxiliary text associated with this button.
     *
     * @return string
     */
    public function getText(): string {
        return $this->text;
    }

    /**
     * Set the JavaScript onclick handler expression.
     *
     * @param string $onClick A valid JS expression, e.g. `'formatChanged()'`.
     */
    public function setOnClick(string $onClick): void {
        $this->onClick = $onClick;
    }


    /****************************************************************/
    /* OTHER METHODS                                                */
    /****************************************************************/

    /**
     * Render the radio button as an HTML string.
     *
     * Produces a block-level `<div>` containing a `<label>` that wraps
     * the `<input type="radio">` and a bold, tooltip-enabled span for
     * the label text. Conditionally includes `checked` and `onclick`
     * attributes when set.
     *
     * @return string Rendered HTML markup.
     */
    public function getComponent(): string {
        $id        = htmlspecialchars($this->getName(),    ENT_QUOTES, 'UTF-8');
        $groupName = htmlspecialchars($this->groupName,   ENT_QUOTES, 'UTF-8');
        $value     = htmlspecialchars($this->getValue(),  ENT_QUOTES, 'UTF-8');
        $label     = htmlspecialchars($this->getLabel(),  ENT_QUOTES, 'UTF-8');
        $mouseOver = htmlspecialchars($this->mouseOver,   ENT_QUOTES, 'UTF-8');
        $class     = !empty($this->componentClass)
                        ? ' class="' . htmlspecialchars($this->componentClass, ENT_QUOTES, 'UTF-8') . '"'
                        : '';

        $attrs = [];
        if ($this->checked)            $attrs[] = 'checked="checked"';
        if (!empty($this->onClick))    $attrs[] = 'onclick="' . htmlspecialchars($this->onClick, ENT_QUOTES, 'UTF-8') . '"';

        $extra = $attrs ? ' ' . implode(' ', $attrs) : '';

        return <<<HTML
            <div style="display:block">
                <label>
                    <input type="radio" name="{$groupName}" id="{$id}" value="{$value}"{$class}{$extra} />
                    <br/><b><span data-toggle="tooltip" title="{$mouseOver}">{$label}</span></b>
                </label>
            </div>
            HTML;
    }
}
