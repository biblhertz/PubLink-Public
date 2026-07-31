<?php
namespace Biblhertz\Publink\components;

use Biblhertz\Publink\components\BootstrapFormComponent;


/**
 * BootstrapTabbedPanel
 *
 * Renders a Bootstrap 4 card-based tabbed panel component (AdminLTE style).
 * Extends BootstrapFormComponent to integrate with PubLink's form component system.
 *
 * @example
 *   $panel = new BootstrapTabbedPanel();
 *   $panel->setName('myTabs');
 *   $panel->setTitle('My Panel');
 *   $panel->setTabNames(['Tab One', 'Tab Two']);
 *   $panel->setTabContent(['<p>Content 1</p>', '<p>Content 2</p>']);
 *   $panel->setOpenTab(0);
 *   echo $panel->getComponent();
 *
 * @package    Biblhertz\Publink
 * @subpackage components
 * @author     Chris Tomlinson
 * @since      10th March 2016
 */
class BootstrapTabbedPanel extends BootstrapFormComponent
{

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var array<int,string> Ordered list of tab label strings */
    private array $tabNames = [];

    /** @var array<int,string> Ordered list of HTML content strings, one per tab */
    private array $tabContent = [];

    /**
     * @var array<int,bool> Sparse array of tab indices to disable.
     *                       e.g. [1 => true] disables the second tab.
     */
    private array $disabled = [];

    /** @var int Zero-based index of the tab that should be active on render */
    private int $openTab = 0;

    /** @var string Optional title displayed at the left of the tab bar inside a card-title element */
    private string $title = '';

    /** @var string Bootstrap contextual theme suffix (e.g. 'primary', 'secondary', 'danger') */
    private string $theme = 'primary';

    /** @var string[] Valid Bootstrap contextual theme names */
    private const ALLOWED_THEMES = [
        'primary', 'secondary', 'success', 'danger',
        'warning', 'info', 'light', 'dark',
    ];


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    public function __construct()
    {
        $this->setName('tabPane1');
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Sets the ordered list of tab label strings.
     * The array indices must correspond to those used in setTabContent() and setDisabled().
     *
     * @param array<int,string> $names Tab label strings.
     */
    public function setTabNames(array $names): void
    {
        $this->tabNames = $names;
    }

    /**
     * Sets the ordered list of HTML content blocks, one per tab.
     * The array indices must correspond to those used in setTabNames().
     *
     * @param array<int,string> $content HTML strings to render inside each tab pane.
     */
    public function setTabContent(array $content): void
    {
        $this->tabContent = $content;
    }

    /**
     * Sets which tabs should be rendered as disabled.
     * Pass a sparse array keyed by tab index with a boolean value,
     * e.g. [1 => true, 3 => true] to disable tabs 1 and 3.
     *
     * @param array<int,bool> $disabled Map of tab index => disabled flag.
     */
    public function setDisabled(array $disabled): void
    {
        $this->disabled = $disabled;
    }

    /**
     * Sets the zero-based index of the tab that should be shown as active on initial render.
     * Defaults to 0 (first tab). Clamped to a valid index at render time.
     *
     * @param int $tab Zero-based tab index.
     */
    public function setOpenTab(int $tab): void
    {
        $this->openTab = $tab;
    }

    /**
     * Sets an optional title displayed at the left end of the tab bar.
     * Rendered inside an `<h5 class="card-title">` element.
     * Pass an empty string to suppress the title element.
     *
     * @param string $title Plain text title string.
     */
    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    /**
     * Sets the Bootstrap contextual theme applied to the card.
     * The value is used as the suffix in the CSS class `card-{theme}`
     * (e.g. 'primary' → 'card-primary'). Defaults to 'primary' for
     * unrecognised values.
     *
     * @param string $theme Bootstrap theme name: 'primary', 'secondary', 'success',
     *                       'danger', 'warning', 'info', 'light', 'dark'.
     */
    public function setTheme(string $theme): void
    {
        $this->theme = in_array($theme, self::ALLOWED_THEMES, true) ? $theme : 'primary';
    }


    /****************************************************************/
    /* RENDER                                                       */
    /****************************************************************/

    /**
     * Builds and returns the full HTML markup for the tabbed panel.
     *
     * Renders an AdminLTE/Bootstrap 4 card with a tab list in the card header
     * and corresponding tab panes in the card body. Tab switching relies on
     * Bootstrap's data-toggle="tab" behaviour and requires no additional JS.
     *
     * Only indices present in both tabNames and tabContent are rendered.
     * The openTab index is clamped to the available tab count.
     *
     * The generated id attributes follow these conventions:
     *   - Root card element:   {name}
     *   - Tab list (ul):       {name}-one-tab
     *   - Each tab link (li):  {name}_li_{index}
     *   - Each tab pane (div): {name}_pane_{index}
     *
     * @return string Complete HTML markup for the tabbed panel component.
     */
    public function getComponent(): string
    {
        // Only render indices that exist in both arrays
        $indices = array_keys(array_intersect_key($this->tabNames, $this->tabContent));

        if (empty($indices)) return '';

        // Clamp openTab to a valid index
        $openTab = in_array($this->openTab, $indices, true) ? $this->openTab : $indices[0];

        $name  = htmlspecialchars($this->getName(), ENT_QUOTES, 'UTF-8');
        $theme = $this->theme; // validated in setTheme()
        $title = htmlspecialchars($this->title,     ENT_QUOTES, 'UTF-8');

        // --- Card header and tab navigation bar ---
        $tabs = '';
        foreach ($indices as $index) {
            $label      = $this->tabNames[$index];
            $isDisabled = !empty($this->disabled[$index]);
            $isActive   = ($index === $openTab);

            $linkClass  = 'nav-link';
            if ($isActive)   $linkClass .= ' active';
            if ($isDisabled) $linkClass .= ' disabled';

            $disabledAttr = $isDisabled ? ' aria-disabled="true" tabindex="-1"' : '';

            $tabs .= '<li class="nav-item" id="' . $name . '_li_' . $index . '">'
                   . '<a href="#' . $name . '_pane_' . $index . '" class="' . $linkClass . '"'
                   . ' data-toggle="tab"' . $disabledAttr . '>' . $label . '</a>'
                   . '</li>';
        }

        $titleHtml = $title !== ''
            ? '<li class="pt-2 px-3"><h5 class="card-title">' . $title . '</h5></li>'
            : '';

        // --- Tab panes ---
        $panes = '';
        foreach ($indices as $index) {
            $paneClass = ($index === $openTab) ? 'tab-pane fade show active' : 'tab-pane fade show';
            $panes .= '<div role="tabpanel" class="' . $paneClass . '" id="' . $name . '_pane_' . $index . '">'
                    . $this->tabContent[$index]
                    . '</div>';
        }

        return <<<HTML
            <div id="{$name}" class="card card-{$theme} card-tabs">
                <div class="card-header p-0 pt-1">
                    <ul class="nav nav-tabs" id="{$name}-one-tab" role="tablist">
                        {$titleHtml}{$tabs}
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content">
                        {$panes}
                    </div>
                </div>
            </div>
            HTML;
    }
}
