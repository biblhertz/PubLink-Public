<?php
namespace Biblhertz\Publink\components;

use Biblhertz\Publink\components\BootstrapFormComponent;


/**
 * BootstrapButton
 *
 * Renders a Bootstrap-styled submit button (`<button type="submit">`).
 * Extends {@see BootstrapFormComponent} for name/value/label handling.
 *
 * @example
 *   $btn = new BootstrapButton();
 *   $btn->setName('save');
 *   $btn->setValue('Save');
 *   $btn->setOnClick('return confirm("Are you sure?")');
 *   echo $btn->getComponent();
 *
 * @package    Biblhertz\Publink
 * @subpackage components
 * @author     Chris Tomlinson
 * @since      10th March 2016
 */
class BootstrapButton extends BootstrapFormComponent {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var string JavaScript expression attached to the button's onclick handler. */
    private string $onClick = '';

    /** @var bool When true the button is rendered with the disabled attribute. */
    private bool $disabled = false;


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set the JavaScript onclick handler expression.
     *
     * @param string $s A valid JS expression, e.g. `return confirm("Sure?")`.
     */
    public function setOnClick(string $s): void {
        $this->onClick = $s;
    }

    /**
     * Enable or disable the button.
     *
     * @param bool $disabled Pass true to disable, false to enable.
     */
    public function setDisabled(bool $disabled): void {
        $this->disabled = $disabled;
    }


    /****************************************************************/
    /* OTHER METHODS                                                */
    /****************************************************************/

    /**
     * Render the button as an HTML string.
     *
     * Produces a `<button type="submit">` with Bootstrap's
     * `btn btn-outline-primary` classes. The `disabled` attribute and
     * `onclick` handler are included only when set.
     *
     * @return string HTML button markup.
     */
    public function getComponent(): string {
        $disabled = $this->disabled        ? ' disabled'                                                              : '';
        $onclick  = !empty($this->onClick) ? ' onclick="' . htmlspecialchars($this->onClick, ENT_QUOTES, 'UTF-8') . '"' : '';
        $extra    = !empty($this->componentClass) ? ' ' . htmlspecialchars($this->componentClass, ENT_QUOTES, 'UTF-8') : '';
        $id       = htmlspecialchars($this->getID() ?: $this->getName(), ENT_QUOTES, 'UTF-8');
        $name     = htmlspecialchars($this->getName(),  ENT_QUOTES, 'UTF-8');
        $value    = htmlspecialchars($this->getValue(), ENT_QUOTES, 'UTF-8');

        return "<button type=\"submit\"{$disabled} class=\"btn btn-outline-primary{$extra}\" id=\"{$id}\" name=\"{$name}\"{$onclick}>{$value}</button>";
    }
}
?>