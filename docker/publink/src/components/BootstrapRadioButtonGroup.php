<?php
namespace Biblhertz\Publink\components;

use Biblhertz\Publink\components\BootstrapFormComponent;
use Biblhertz\Publink\components\BootstrapRadioButton;


/**
 * BootstrapRadioButtonGroup
 *
 * Renders a labelled group of {@see BootstrapRadioButton} instances as a
 * Bootstrap form-group with each button displayed in a table column.
 *
 * Buttons added via {@see addMember()} are automatically assigned the group's
 * own name at render time so that only one option can be selected at a time.
 * The group label is rendered via the inherited {@see getLabelText()} method.
 *
 * @example
 *   $group = new BootstrapRadioButtonGroup();
 *   $group->setName('format');
 *   $group->setLabel('Output Format');
 *   $group->setColWidth(6);
 *
 *   $pdf = new BootstrapRadioButton();
 *   $pdf->setValue('pdf');
 *   $pdf->setLabel('PDF');
 *   $pdf->makeChecked();
 *
 *   $csv = new BootstrapRadioButton();
 *   $csv->setValue('csv');
 *   $csv->setLabel('CSV');
 *
 *   $group->addMember($pdf);
 *   $group->addMember($csv);
 *   echo $group->getComponent();
 *
 * @package    Biblhertz\Publink
 * @subpackage components
 * @author     Chris Tomlinson
 * @since      10th March 2016
 */
class BootstrapRadioButtonGroup extends BootstrapFormComponent {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /**
     * @var BootstrapRadioButton[] Radio buttons belonging to this group.
     *      The group name is stamped onto each member at render time.
     */
    private array $groupMembers = [];

    /**
     * @var int Bootstrap grid column width for the table wrapper,
     *          e.g. 6 produces `col-lg-6`. Defaults to 12 (full width).
     */
    private int $colWidth = 12;


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Add a radio button to this group.
     *
     * The group name is applied to the button at render time, so
     * {@see setName()} may be called before or after adding members.
     *
     * @param BootstrapRadioButton $button The radio button to add.
     */
    public function addMember(BootstrapRadioButton $button): void {
        $this->groupMembers[] = $button;
    }

    /**
     * Set the Bootstrap grid column width for the table wrapper.
     *
     * @param int $colWidth Column width integer, e.g. 6 produces `col-lg-6`.
     */
    public function setColWidth(int $colWidth): void {
        $this->colWidth = $colWidth;
    }


    /****************************************************************/
    /* OTHER METHODS                                                */
    /****************************************************************/

    /**
     * Render the radio button group as an HTML string.
     *
     * Produces a Bootstrap `form-group` containing the group label and a
     * bordered table with one column per button. Each column renders the
     * button's input and its auxiliary text via {@see BootstrapRadioButton::getText()}.
     *
     * Returns an empty string if no members have been added.
     *
     * @return string Rendered HTML markup, or empty string when group is empty.
     */
    public function getComponent(): string {
        if (empty($this->groupMembers)) return '';

        $cols = '';
        foreach ($this->groupMembers as $member) {
            $member->setGroupName($this->getName());
            $text  = htmlspecialchars($member->getText(), ENT_QUOTES, 'UTF-8');
            $cols .= <<<HTML
                <td style="width:100px;text-align:center;font-size:9px;">
                    {$member->getComponent()}{$text}
                </td>
                HTML;
        }

        $label    = $this->getLabelText();
        $colWidth = $this->colWidth;

        return <<<HTML
            <div class="form-group">
                {$label}
                <div class="col-lg-{$colWidth}">
                    <table class="table table-bordered">
                        <tr>{$cols}</tr>
                    </table>
                </div>
            </div>
            HTML;
    }
}
