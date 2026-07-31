<?php

namespace Biblhertz\Publink\test;

use PHPUnit\Framework\TestCase;
use Biblhertz\Publink\components\BootstrapButton;
use Biblhertz\Publink\components\BootstrapInput;
use Biblhertz\Publink\components\BootstrapOption;
use Biblhertz\Publink\components\BootstrapRadioButton;
use Biblhertz\Publink\components\BootstrapRadioButtonGroup;
use Biblhertz\Publink\components\BootstrapTabbedPanel;


/********************************************************************/
/* BOOTSTRAP BUTTON TESTS                                            */
/********************************************************************/

class BootstrapButtonTest extends TestCase
{
    private BootstrapButton $btn;

    protected function setUp(): void
    {
        $this->btn = new BootstrapButton();
        $this->btn->setName('save');
        $this->btn->setValue('Save');
    }

    public function testGetComponentReturnsString(): void
    {
        $this->assertIsString($this->btn->getComponent());
    }

    public function testGetComponentContainsButtonTag(): void
    {
        $this->assertStringContainsString('<button', $this->btn->getComponent());
    }

    public function testGetComponentContainsSubmitType(): void
    {
        $this->assertStringContainsString('type="submit"', $this->btn->getComponent());
    }

    public function testGetComponentContainsName(): void
    {
        $this->assertStringContainsString('name="save"', $this->btn->getComponent());
    }

    public function testGetComponentContainsValue(): void
    {
        $this->assertStringContainsString('>Save<', $this->btn->getComponent());
    }

    public function testGetComponentContainsBtnClass(): void
    {
        $this->assertStringContainsString('btn btn-outline-primary', $this->btn->getComponent());
    }

    public function testDisabledButtonContainsDisabledAttr(): void
    {
        $this->btn->setDisabled(true);
        $this->assertStringContainsString(' disabled', $this->btn->getComponent());
    }

    public function testEnabledButtonOmitsDisabledAttr(): void
    {
        $this->btn->setDisabled(false);
        $this->assertStringNotContainsString(' disabled', $this->btn->getComponent());
    }

    public function testOnClickRenderedInOutput(): void
    {
        $this->btn->setOnClick('return confirm("Sure?")');
        $this->assertStringContainsString('onclick=', $this->btn->getComponent());
    }

    public function testNoOnClickWhenNotSet(): void
    {
        $this->assertStringNotContainsString('onclick=', $this->btn->getComponent());
    }

    public function testIdFallsBackToName(): void
    {
        // No explicit id set: id should equal name
        $this->assertStringContainsString('id="save"', $this->btn->getComponent());
    }

    public function testExplicitIdUsed(): void
    {
        $this->btn->setID('btn_save_42');
        $this->assertStringContainsString('id="btn_save_42"', $this->btn->getComponent());
    }

    public function testComponentClassAppendedToBtnClass(): void
    {
        $this->btn->setComponentClass('btn-sm');
        $this->assertStringContainsString('btn-outline-primary btn-sm', $this->btn->getComponent());
    }

    // --- getLabelText (from BootstrapFormComponent) ---

    public function testGetLabelTextReturnsLabelElement(): void
    {
        $this->btn->setLabel('Save Button');
        $this->assertStringContainsString('<label', $this->btn->getLabelText());
        $this->assertStringContainsString('Save Button', $this->btn->getLabelText());
    }

    public function testSetShowLabelFalseReturnsEmpty(): void
    {
        $this->btn->setLabel('Save Button');
        $this->btn->setShowLabel(false);
        $this->assertSame('', $this->btn->getLabelText());
    }

    public function testLabelSizeUsedInClass(): void
    {
        $this->btn->setLabel('My Label');
        $this->btn->setLabelSize(4);
        $this->assertStringContainsString('col-sm-4', $this->btn->getLabelText());
    }

    // --- Base setters / getters ---

    public function testNameGetSet(): void
    {
        $this->btn->setName('delete');
        $this->assertSame('delete', $this->btn->getName());
    }

    public function testIdGetSet(): void
    {
        $this->btn->setID('myId');
        $this->assertSame('myId', $this->btn->getID());
    }

    public function testValueGetSet(): void
    {
        $this->btn->setValue('Delete');
        $this->assertSame('Delete', $this->btn->getValue());
    }

    public function testLabelGetSet(): void
    {
        $this->btn->setLabel('A Label');
        $this->assertSame('A Label', $this->btn->getLabel());
    }

    public function testPlaceholderGetSet(): void
    {
        $this->btn->setPlaceHolder('hint');
        $this->assertSame('hint', $this->btn->getPlaceHolder());
    }

    public function testMakeRequiredSetsFlag(): void
    {
        $this->btn->makeRequired();
        // required flag is internal; verify it doesn't break rendering
        $this->assertIsString($this->btn->getComponent());
    }
}


/********************************************************************/
/* BOOTSTRAP INPUT TESTS                                             */
/********************************************************************/

class BootstrapInputTest extends TestCase
{
    private BootstrapInput $input;

    protected function setUp(): void
    {
        $this->input = new BootstrapInput();
        $this->input->setName('username');
        $this->input->setValue('john');
    }

    public function testGetComponentReturnsString(): void
    {
        $this->assertIsString($this->input->getComponent());
    }

    public function testGetComponentContainsInputTag(): void
    {
        $this->assertStringContainsString('<input', $this->input->getComponent());
    }

    public function testDefaultTypeIsText(): void
    {
        $this->assertStringContainsString('type="text"', $this->input->getComponent());
    }

    public function testSetTypeEmail(): void
    {
        $this->input->setType('email');
        $this->assertStringContainsString('type="email"', $this->input->getComponent());
    }

    public function testInvalidTypeFallsBackToText(): void
    {
        $this->input->setType('nonsense');
        $this->assertStringContainsString('type="text"', $this->input->getComponent());
    }

    public function testNameInOutput(): void
    {
        $this->assertStringContainsString('name="username"', $this->input->getComponent());
    }

    public function testValueInOutput(): void
    {
        $this->assertStringContainsString('value="john"', $this->input->getComponent());
    }

    public function testIdFallsBackToName(): void
    {
        $this->assertStringContainsString('id="username"', $this->input->getComponent());
    }

    public function testExplicitIdInOutput(): void
    {
        $this->input->setID('user_field');
        $this->assertStringContainsString('id="user_field"', $this->input->getComponent());
    }

    public function testRequiredAttributeWhenMadeRequired(): void
    {
        $this->input->makeRequired();
        $this->assertStringContainsString('required="required"', $this->input->getComponent());
    }

    public function testNoRequiredAttributeByDefault(): void
    {
        $this->assertStringNotContainsString('required', $this->input->getComponent());
    }

    public function testReadonlyAttributeWhenSet(): void
    {
        $this->input->setReadOnly(true);
        $this->assertStringContainsString('readonly', $this->input->getComponent());
    }

    public function testNoReadonlyByDefault(): void
    {
        $this->assertStringNotContainsString('readonly', $this->input->getComponent());
    }

    public function testAutoCompleteOffAttribute(): void
    {
        $this->input->setAutoComplete(false);
        $this->assertStringContainsString('autocomplete="off"', $this->input->getComponent());
    }

    public function testNoAutocompleteAttrWhenEnabled(): void
    {
        $this->input->setAutoComplete(true);
        $this->assertStringNotContainsString('autocomplete', $this->input->getComponent());
    }

    public function testPlaceholderInOutput(): void
    {
        $this->input->setPlaceHolder('Enter your name');
        $this->assertStringContainsString('placeholder="Enter your name"', $this->input->getComponent());
    }

    public function testPatternInOutput(): void
    {
        $this->input->setPattern('[A-Za-z]+');
        $this->assertStringContainsString('pattern="[A-Za-z]+"', $this->input->getComponent());
    }

    public function testMaxLengthInOutput(): void
    {
        $this->input->setMaxLength(50);
        $this->assertStringContainsString('maxlength="50"', $this->input->getComponent());
    }

    public function testFormControlClass(): void
    {
        $this->assertStringContainsString('form-control', $this->input->getComponent());
    }

    public function testComponentClassAppended(): void
    {
        $this->input->setComponentClass('my-custom-class');
        $this->assertStringContainsString('form-control my-custom-class', $this->input->getComponent());
    }

    public function testMouseOverRendersPopoverAttributes(): void
    {
        $this->input->setOnMouseOver('Help text');
        $this->input->setMouseOverTitle('Hint');
        $html = $this->input->getComponent();
        $this->assertStringContainsString('rel="popover"', $html);
        $this->assertStringContainsString('data-content=', $html);
    }
}


/********************************************************************/
/* BOOTSTRAP OPTION (SELECT) TESTS                                   */
/********************************************************************/

class BootstrapOptionTest extends TestCase
{
    private BootstrapOption $select;

    protected function setUp(): void
    {
        $this->select = new BootstrapOption();
        $this->select->setName('status');
    }

    public function testEmptyOptionsReturnsEmptyString(): void
    {
        $this->assertSame('', $this->select->getComponent());
    }

    public function testWithOptionsReturnsString(): void
    {
        $this->select->setOptions([['active', 'Active'], ['inactive', 'Inactive']]);
        $this->assertIsString($this->select->getComponent());
    }

    public function testWithOptionsContainsSelectTag(): void
    {
        $this->select->setOptions([['t', 'True'], ['f', 'False']]);
        $this->assertStringContainsString('<select', $this->select->getComponent());
    }

    public function testOptionsAreRendered(): void
    {
        $this->select->setOptions([['t', 'Yes'], ['f', 'No']]);
        $html = $this->select->getComponent();
        $this->assertStringContainsString('<option', $html);
        $this->assertStringContainsString('Yes', $html);
        $this->assertStringContainsString('No', $html);
    }

    public function testSelectedOptionHasSelectedAttribute(): void
    {
        $this->select->setOptions([['a', 'Alpha'], ['b', 'Beta']]);
        $this->select->setSelected('b');
        $html = $this->select->getComponent();
        $this->assertStringContainsString('value="b" selected="selected"', $html);
    }

    public function testNonSelectedOptionLacksSelectedAttribute(): void
    {
        $this->select->setOptions([['a', 'Alpha'], ['b', 'Beta']]);
        $this->select->setSelected('b');
        $html = $this->select->getComponent();
        // value="a" should NOT have selected
        $this->assertStringNotContainsString('value="a" selected', $html);
    }

    public function testNameAttributeInSelect(): void
    {
        $this->select->setOptions([['x', 'X']]);
        $this->assertStringContainsString('name="status"', $this->select->getComponent());
    }

    public function testRequiredAttributeWhenMadeRequired(): void
    {
        $this->select->setOptions([['x', 'X']]);
        $this->select->makeRequired();
        $this->assertStringContainsString('required="required"', $this->select->getComponent());
    }

    public function testLabelRenderedWithOptions(): void
    {
        $this->select->setOptions([['x', 'X']]);
        $this->select->setLabel('My Select');
        $html = $this->select->getComponent();
        $this->assertStringContainsString('My Select', $html);
    }
}


/********************************************************************/
/* BOOTSTRAP RADIO BUTTON TESTS                                      */
/********************************************************************/

class BootstrapRadioButtonTest extends TestCase
{
    private BootstrapRadioButton $radio;

    protected function setUp(): void
    {
        $this->radio = new BootstrapRadioButton();
        $this->radio->setName('format_pdf');
        $this->radio->setGroupName('format');
        $this->radio->setValue('pdf');
        $this->radio->setLabel('PDF');
    }

    public function testGetComponentReturnsString(): void
    {
        $this->assertIsString($this->radio->getComponent());
    }

    public function testGetComponentContainsRadioInput(): void
    {
        $this->assertStringContainsString('type="radio"', $this->radio->getComponent());
    }

    public function testGroupNameUsedAsNameAttribute(): void
    {
        $this->assertStringContainsString('name="format"', $this->radio->getComponent());
    }

    public function testIdUsedFromSetName(): void
    {
        $this->assertStringContainsString('id="format_pdf"', $this->radio->getComponent());
    }

    public function testValueInOutput(): void
    {
        $this->assertStringContainsString('value="pdf"', $this->radio->getComponent());
    }

    public function testLabelTextInOutput(): void
    {
        $this->assertStringContainsString('PDF', $this->radio->getComponent());
    }

    public function testCheckedAttributeWhenMadeChecked(): void
    {
        $this->radio->makeChecked();
        $this->assertStringContainsString('checked="checked"', $this->radio->getComponent());
    }

    public function testNoCheckedAttributeByDefault(): void
    {
        $this->assertStringNotContainsString('checked', $this->radio->getComponent());
    }

    public function testOnClickInOutput(): void
    {
        $this->radio->setOnClick('formatChanged()');
        $this->assertStringContainsString('onclick="formatChanged()"', $this->radio->getComponent());
    }

    public function testMouseOverTitleAttribute(): void
    {
        $this->radio->setMouseOver('Download as PDF');
        $this->assertStringContainsString('Download as PDF', $this->radio->getComponent());
    }

    public function testTextGetSet(): void
    {
        $this->radio->setText('extra info');
        $this->assertSame('extra info', $this->radio->getText());
    }
}


/********************************************************************/
/* BOOTSTRAP RADIO BUTTON GROUP TESTS                                */
/********************************************************************/

class BootstrapRadioButtonGroupTest extends TestCase
{
    private BootstrapRadioButtonGroup $group;

    protected function setUp(): void
    {
        $this->group = new BootstrapRadioButtonGroup();
        $this->group->setName('format');
        $this->group->setLabel('Output Format');
    }

    public function testEmptyGroupReturnsEmptyString(): void
    {
        $this->assertSame('', $this->group->getComponent());
    }

    public function testWithMembersReturnsString(): void
    {
        $r = new BootstrapRadioButton();
        $r->setValue('pdf');
        $r->setLabel('PDF');
        $this->group->addMember($r);
        $this->assertIsString($this->group->getComponent());
    }

    public function testGetComponentContainsFormGroup(): void
    {
        $r = new BootstrapRadioButton();
        $r->setValue('csv');
        $r->setLabel('CSV');
        $this->group->addMember($r);
        $this->assertStringContainsString('form-group', $this->group->getComponent());
    }

    public function testGetComponentContainsTable(): void
    {
        $r = new BootstrapRadioButton();
        $r->setValue('csv');
        $r->setLabel('CSV');
        $this->group->addMember($r);
        $this->assertStringContainsString('<table', $this->group->getComponent());
    }

    public function testGroupNameStampedOnMember(): void
    {
        $r = new BootstrapRadioButton();
        $r->setName('format_pdf');
        $r->setValue('pdf');
        $r->setLabel('PDF');
        $this->group->addMember($r);
        $html = $this->group->getComponent();
        // The group stamps its own name as the radio group
        $this->assertStringContainsString('name="format"', $html);
    }

    public function testColWidthInOutput(): void
    {
        $r = new BootstrapRadioButton();
        $r->setValue('pdf');
        $r->setLabel('PDF');
        $this->group->addMember($r);
        $this->group->setColWidth(6);
        $this->assertStringContainsString('col-lg-6', $this->group->getComponent());
    }

    public function testMultipleMembersRendered(): void
    {
        foreach (['pdf', 'csv', 'xml'] as $val) {
            $r = new BootstrapRadioButton();
            $r->setValue($val);
            $r->setLabel(strtoupper($val));
            $this->group->addMember($r);
        }
        $html = $this->group->getComponent();
        $this->assertStringContainsString('PDF', $html);
        $this->assertStringContainsString('CSV', $html);
        $this->assertStringContainsString('XML', $html);
    }
}


/********************************************************************/
/* BOOTSTRAP TABBED PANEL TESTS                                      */
/********************************************************************/

class BootstrapTabbedPanelTest extends TestCase
{
    private BootstrapTabbedPanel $panel;

    protected function setUp(): void
    {
        $this->panel = new BootstrapTabbedPanel();
        $this->panel->setName('myTabs');
    }

    public function testEmptyTabsReturnEmptyString(): void
    {
        $this->assertSame('', $this->panel->getComponent());
    }

    public function testWithTabsReturnsString(): void
    {
        $this->panel->setTabNames([0 => 'Tab One', 1 => 'Tab Two']);
        $this->panel->setTabContent([0 => '<p>One</p>', 1 => '<p>Two</p>']);
        $this->assertIsString($this->panel->getComponent());
    }

    public function testGetComponentContainsCard(): void
    {
        $this->panel->setTabNames([0 => 'Tab One']);
        $this->panel->setTabContent([0 => '<p>Content</p>']);
        $this->assertStringContainsString('card', $this->panel->getComponent());
    }

    public function testGetComponentContainsTabLabels(): void
    {
        $this->panel->setTabNames([0 => 'First Tab', 1 => 'Second Tab']);
        $this->panel->setTabContent([0 => 'A', 1 => 'B']);
        $html = $this->panel->getComponent();
        $this->assertStringContainsString('First Tab', $html);
        $this->assertStringContainsString('Second Tab', $html);
    }

    public function testGetComponentContainsTabContent(): void
    {
        $this->panel->setTabNames([0 => 'Tab']);
        $this->panel->setTabContent([0 => '<p>My Content</p>']);
        $this->assertStringContainsString('My Content', $this->panel->getComponent());
    }

    public function testDefaultConstructorSetsName(): void
    {
        // Default name set in constructor
        $fresh = new BootstrapTabbedPanel();
        $this->assertSame('tabPane1', $fresh->getName());
    }

    public function testOpenTabIndexRenderedAsActive(): void
    {
        $this->panel->setTabNames([0 => 'A', 1 => 'B']);
        $this->panel->setTabContent([0 => 'AA', 1 => 'BB']);
        $this->panel->setOpenTab(1);
        $html = $this->panel->getComponent();
        // Tab 1 pane should have "active"
        $this->assertStringContainsString('myTabs_pane_1', $html);
    }

    public function testDefaultThemeIsPrimary(): void
    {
        $this->panel->setTabNames([0 => 'Tab']);
        $this->panel->setTabContent([0 => 'Content']);
        $this->assertStringContainsString('card-primary', $this->panel->getComponent());
    }

    public function testSetThemeChangesCardClass(): void
    {
        $this->panel->setTabNames([0 => 'Tab']);
        $this->panel->setTabContent([0 => 'Content']);
        $this->panel->setTheme('danger');
        $this->assertStringContainsString('card-danger', $this->panel->getComponent());
    }

    public function testInvalidThemeFallsBackToPrimary(): void
    {
        $this->panel->setTabNames([0 => 'Tab']);
        $this->panel->setTabContent([0 => 'Content']);
        $this->panel->setTheme('invalid-theme');
        $this->assertStringContainsString('card-primary', $this->panel->getComponent());
    }

    public function testTitleRenderedInHeader(): void
    {
        $this->panel->setTabNames([0 => 'Tab']);
        $this->panel->setTabContent([0 => 'Content']);
        $this->panel->setTitle('My Panel Title');
        $this->assertStringContainsString('My Panel Title', $this->panel->getComponent());
    }

    public function testDisabledTabHasDisabledClass(): void
    {
        $this->panel->setTabNames([0 => 'Active', 1 => 'Disabled']);
        $this->panel->setTabContent([0 => 'A', 1 => 'B']);
        $this->panel->setDisabled([1 => true]);
        $this->assertStringContainsString('disabled', $this->panel->getComponent());
    }

    public function testMismatchedNameContentCountRendersOnlyIntersection(): void
    {
        // Only indices present in both arrays are rendered
        $this->panel->setTabNames([0 => 'Zero', 1 => 'One', 2 => 'Two']);
        $this->panel->setTabContent([0 => 'C0', 2 => 'C2']); // index 1 missing from content
        $html = $this->panel->getComponent();
        $this->assertStringContainsString('Zero', $html);
        $this->assertStringContainsString('C2', $html);
        $this->assertStringNotContainsString('One', $html);
    }

    public function testIdConventionsInOutput(): void
    {
        $this->panel->setName('testPanel');
        $this->panel->setTabNames([0 => 'Tab']);
        $this->panel->setTabContent([0 => 'Content']);
        $html = $this->panel->getComponent();
        $this->assertStringContainsString('id="testPanel"', $html);
        $this->assertStringContainsString('testPanel-one-tab', $html);
        $this->assertStringContainsString('testPanel_pane_0', $html);
        $this->assertStringContainsString('testPanel_li_0', $html);
    }
}
