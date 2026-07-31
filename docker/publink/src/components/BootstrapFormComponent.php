<?php
namespace Biblhertz\Publink\components;


/**
 * BootstrapFormComponent
 *
 * Abstract base class for all Bootstrap form input components.
 *
 * Provides the common identity (name, id), value, label, placeholder,
 * required-flag, and CSS-class properties shared by every concrete component
 * (e.g. {@see BootstrapButton}, {@see BootstrapFileUpload}). Subclasses must
 * implement {@see getComponent()} to render their specific HTML.
 *
 * @package    Biblhertz\Publink
 * @subpackage components
 * @author     Chris Tomlinson
 * @since      10th March 2016
 */
abstract class BootstrapFormComponent {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var string The `name` attribute used on the rendered input element. */
    protected string $name = '';

    /** @var string The `id` attribute used on the rendered input element. */
    protected string $id = '';

    /** @var string The current value of the component. */
    protected string $value = '';

    /** @var string Text used for the associated `<label>` element. */
    protected string $label = '';

    /** @var string Placeholder text shown inside the input when empty. */
    protected string $placeHolder = '';

    /** @var bool When true, the `required` attribute is added to the input. */
    protected bool $required = false;

    /** @var bool When false, {@see getLabelText()} returns an empty string. */
    protected bool $showLabel = true;

    /**
     * @var int Bootstrap grid column width applied to the `<label>` element,
     *          e.g. `col-sm-{labelSize}`.
     */
    protected int $labelSize = 2;

    /**
     * @var string Additional CSS class(es) appended to the component element.
     *             Set via {@see setComponentClass()}.
     */
    protected string $componentClass = '';


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set the `name` attribute for the input element.
     *
     * @param string $name The name value.
     */
    public function setName(string $name): void {
        $this->name = $name;
    }

    /**
     * Return the `name` attribute of the input element.
     *
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Set the `id` attribute for the input element.
     *
     * @param string $id The id value.
     */
    public function setID(string $id): void {
        $this->id = $id;
    }

    /**
     * Return the `id` attribute of the input element.
     *
     * @return string
     */
    public function getID(): string {
        return $this->id;
    }

    /**
     * Set the current value of the component.
     *
     * @param string $value The value to set.
     */
    public function setValue(string $value): void {
        $this->value = $value;
    }

    /**
     * Return the current value of the component.
     *
     * @return string
     */
    public function getValue(): string {
        return $this->value;
    }

    /**
     * Set the label text displayed alongside the input.
     *
     * @param string $label Label text.
     */
    public function setLabel(string $label): void {
        $this->label = $label;
    }

    /**
     * Return the raw label text (without HTML markup).
     *
     * @return string
     */
    public function getLabel(): string {
        return $this->label;
    }

    /**
     * Set the placeholder text shown inside the input when empty.
     *
     * @param string $placeholder Placeholder text.
     */
    public function setPlaceHolder(string $placeholder): void {
        $this->placeHolder = $placeholder;
    }

    /**
     * Return the placeholder text.
     *
     * @return string
     */
    public function getPlaceHolder(): string {
        return $this->placeHolder;
    }

    /**
     * Render the `<label>` element for this component.
     *
     * Returns a Bootstrap-grid-aware `<label>` using `col-sm-{labelSize}`.
     * Returns an empty string when {@see setShowLabel()} has been called with false.
     *
     * @return string HTML `<label>` markup, or empty string if labels are hidden.
     */
    public function getLabelText(): string {
        if (!$this->showLabel) return '';
        $name  = htmlspecialchars($this->name,  ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($this->label, ENT_QUOTES, 'UTF-8');
        return "<label for=\"{$name}\" class=\"col-sm-{$this->labelSize} control-label\">{$label}</label>";
    }

    /**
     * Show or hide the label element when rendering.
     *
     * @param bool $show Pass false to suppress the label output.
     */
    public function setShowLabel(bool $show): void {
        $this->showLabel = $show;
    }

    /**
     * Set the Bootstrap grid column width for the label element.
     *
     * @param int $size Column width, e.g. 2 produces `col-sm-2`.
     */
    public function setLabelSize(int $size): void {
        $this->labelSize = $size;
    }

    /**
     * Mark this field as required.
     *
     * Concrete components should honour this flag by adding the `required`
     * attribute to their rendered input element.
     */
    public function makeRequired(): void {
        $this->required = true;
    }

    /**
     * Append an extra CSS class to the component element.
     *
     * @param string $class One or more space-separated CSS class names.
     */
    public function setComponentClass(string $class): void {
        $this->componentClass = $class;
    }


    /****************************************************************/
    /* ABSTRACT METHOD                                              */
    /****************************************************************/

    /**
     * Render the component as an HTML string.
     *
     * Subclasses must implement this method to produce their specific markup,
     * taking into account the properties set on this base class
     * (name, id, value, placeholder, required, componentClass, etc.).
     *
     * @return string Rendered HTML markup for the component.
     */
    abstract public function getComponent(): string;
}
