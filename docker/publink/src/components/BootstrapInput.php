<?php
namespace Biblhertz\Publink\components;

use Biblhertz\Publink\components\BootstrapFormComponent;


/**
 * BootstrapInput
 *
 * Renders a Bootstrap `form-control` `<input>` element.
 *
 * Supports all common input types (text, email, password, etc.), optional
 * Bootstrap popover tooltips on mouseover, autocomplete control, read-only
 * mode, HTML5 pattern validation, and the required flag inherited from
 * {@see BootstrapFormComponent}.
 *
 * @example
 *   $input = new BootstrapInput();
 *   $input->setName('email');
 *   $input->setType('email');
 *   $input->setPlaceHolder('Enter your email');
 *   $input->makeRequired();
 *   echo $input->getComponent();
 *
 * @package    Biblhertz\Publink
 * @subpackage components
 * @author     Chris Tomlinson
 * @since      10th March 2016
 */
class BootstrapInput extends BootstrapFormComponent {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var string HTML input `type` attribute, e.g. `text`, `email`, `password`. */
    private string $type = 'text';

    /** @var string[] Allowed values for the `type` attribute. */
    private const ALLOWED_TYPES = [
        'text', 'email', 'password', 'number', 'date', 'datetime-local',
        'time', 'month', 'week', 'tel', 'url', 'search', 'color', 'hidden',
        'range',
    ];

    /** @var string HTML5 `pattern` attribute for client-side validation. Empty = omitted. */
    private string $pattern = '';

    /** @var int `maxlength` attribute value. */
    private int $maxLength = 80;

    /** @var int `length` attribute value. */
    private int $length = 80;

    /** @var string Popover body text shown on mouseover. Empty = no popover. */
    private string $onMouseOver = '';

    /** @var string Popover title shown on mouseover alongside {@see $onMouseOver}. */
    private string $onMouseOverTitle = '';

    /** @var bool When false, renders `autocomplete="off"` on the input. */
    private bool $autoComplete = true;

    /** @var bool When true, renders the `readonly` attribute on the input. */
    private bool $readOnly = false;


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set the HTML `type` attribute of the input.
     *
     * Must be one of the allowed input types. Defaults to `text` if an
     * unrecognised type is provided.
     *
     * @param string $type e.g. `'text'`, `'email'`, `'password'`, `'number'`.
     */
    public function setType(string $type): void {
        $this->type = in_array($type, self::ALLOWED_TYPES, true) ? $type : 'text';
    }

    /**
     * Set an HTML5 `pattern` attribute for client-side format validation.
     *
     * @param string $pattern A valid regular expression pattern, e.g. `'[A-Za-z]{3}'`.
     */
    public function setPattern(string $pattern): void {
        $this->pattern = $pattern;
    }

    /**
     * Set the maximum number of characters the input will accept.
     *
     * @param int $maxLength Maximum character count.
     */
    public function setMaxLength(int $maxLength): void {
        $this->maxLength = $maxLength;
    }

     /**
     * Set the maximum number of characters the input will accept.
     *
     * @param int $maxLength Maximum character count.
     */
    public function setLength(int $length): void {
        $this->length = $length;
    }

    /**
     * Set the Bootstrap popover body text shown when the user hovers over the input.
     *
     * Requires {@see setMouseOverTitle()} to also be set for the popover to display
     * correctly. Renders `data-content`, `rel="popover"`, and related attributes.
     *
     * @param string $text Popover body content (plain text).
     */
    public function setOnMouseOver(string $text): void {
        $this->onMouseOver = $text;
    }

    /**
     * Set the Bootstrap popover title shown alongside the mouseover body text.
     *
     * @param string $title Popover title text.
     */
    public function setMouseOverTitle(string $title): void {
        $this->onMouseOverTitle = $title;
    }

    /**
     * Enable or disable browser autocomplete on this input.
     *
     * Defaults to true. Pass false to render `autocomplete="off"`.
     *
     * @param bool $autoComplete False to disable autocomplete.
     */
    public function setAutoComplete(bool $autoComplete): void {
        $this->autoComplete = $autoComplete;
    }

    /**
     * Make the input read-only.
     *
     * @param bool $readOnly True to add the `readonly` attribute.
     */
    public function setReadOnly(bool $readOnly): void {
        $this->readOnly = $readOnly;
    }


    /****************************************************************/
    /* OTHER METHODS                                                */
    /****************************************************************/

    /**
     * Render the input element as an HTML string.
     *
     * Conditionally includes:
     * - Bootstrap popover attributes when {@see setOnMouseOver()} has been set.
     * - `autocomplete="off"` when autocomplete is disabled.
     * - `readonly` when the field is read-only.
     * - `placeholder` when set on the base class.
     * - `pattern` when a validation pattern has been set.
     * - `required="required"` when {@see makeRequired()} has been called.
     *
     * @return string Rendered `<input>` HTML markup.
     */
    public function getComponent(): string {
        $id    = htmlspecialchars($this->getID() ?: $this->getName(), ENT_QUOTES, 'UTF-8');
        $name  = htmlspecialchars($this->getName(),  ENT_QUOTES, 'UTF-8');
        $value = htmlspecialchars($this->getValue(), ENT_QUOTES, 'UTF-8');
        $type  = htmlspecialchars($this->type,       ENT_QUOTES, 'UTF-8');
        $class = 'form-control' . (!empty($this->componentClass) ? ' ' . htmlspecialchars($this->componentClass, ENT_QUOTES, 'UTF-8') : '');

        $attrs = [];

        if (!empty($this->onMouseOver)) {
            $content = htmlspecialchars($this->onMouseOver,      ENT_QUOTES, 'UTF-8');
            $title   = htmlspecialchars($this->onMouseOverTitle, ENT_QUOTES, 'UTF-8');
            $attrs[] = 'html="true"';
            $attrs[] = 'data-placement="top"';
            $attrs[] = 'rel="popover"';
            $attrs[] = "data-content=\"<p>{$content}</p>\"";
            $attrs[] = "data-original-title=\"{$title}\"";
        }

        if (!$this->autoComplete)          $attrs[] = 'autocomplete="off"';
        if ($this->readOnly)               $attrs[] = 'readonly';
        if (!empty($this->placeHolder))    $attrs[] = 'placeholder="' . htmlspecialchars($this->placeHolder, ENT_QUOTES, 'UTF-8') . '"';
        if (!empty($this->pattern))        $attrs[] = 'pattern="'     . htmlspecialchars($this->pattern,     ENT_QUOTES, 'UTF-8') . '"';
        if ($this->required)               $attrs[] = 'required="required"';

        $extra = $attrs ? ' ' . implode(' ', $attrs) : '';

        return "<input class=\"{$class}\" type=\"{$type}\" id=\"{$id}\" name=\"{$name}\""
             . " maxlength=\"{$this->maxLength}\" value=\"{$value}\"{$extra} />";
    }
}
