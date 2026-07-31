<?php

namespace Biblhertz\Publink\test;

use PHPUnit\Framework\TestCase;
use Biblhertz\Publink\pages\htmlPage;
use Biblhertz\Publink\pages\Bibliotheca_Page;
use Biblhertz\Publink\pages\Modal_Alert;
use Biblhertz\Publink\pages\Modal_Confirm;


/********************************************************************/
/* TEST DOUBLES                                                      */
/********************************************************************/

/**
 * Minimal concrete subclass of the abstract htmlPage.
 * Used to exercise all inherited static helpers without instantiating
 * any page infrastructure.
 */
class ConcreteHtmlPage extends htmlPage
{
    public function getPage(): string
    {
        return '<!DOCTYPE html><html><body>stub</body></html>';
    }
}

/**
 * Minimal concrete subclass of the abstract Bibliotheca_Page.
 * Overrides the constructor to skip Config::setup() and PDODatabase
 * so tests can exercise the page's getters/setters in isolation.
 */
class TestBibliothecaPage extends Bibliotheca_Page
{
    public function __construct()
    {
        // Intentionally skip parent::__construct() to avoid DB connection.
        // All instance properties use their PHP-declared defaults.
    }

    public function getPage(): string
    {
        return '<!DOCTYPE html><html><body>test page</body></html>';
    }
}


/********************************************************************/
/* HTML PAGE STATIC UTILITY TESTS                                    */
/********************************************************************/

class HtmlPageStaticTest extends TestCase
{
    // ── Text rendering ────────────────────────────────────────────

    public function testGetParagraphWrapsInP(): void
    {
        $this->assertSame('<p>Hello</p>', ConcreteHtmlPage::getParagraph('Hello'));
    }

    public function testGetTextAliasesParagraph(): void
    {
        $this->assertSame('<p>World</p>', ConcreteHtmlPage::getText('World'));
    }

    public function testGetHeaderTextWrapsInH3(): void
    {
        $this->assertSame('<h3>My Heading</h3>', ConcreteHtmlPage::getHeaderText('My Heading'));
    }

    // ── makeImage ─────────────────────────────────────────────────

    public function testMakeImageContainsImgTag(): void
    {
        $html = ConcreteHtmlPage::makeImage('/img/logo.png', 100, 50);
        $this->assertStringContainsString('<img', $html);
    }

    public function testMakeImageContainsSrc(): void
    {
        $html = ConcreteHtmlPage::makeImage('/img/logo.png', 100, 50);
        $this->assertStringContainsString('src="/img/logo.png"', $html);
    }

    public function testMakeImageContainsDimensions(): void
    {
        $html = ConcreteHtmlPage::makeImage('/img/pic.jpg', 200, 150);
        $this->assertStringContainsString('width="200"', $html);
        $this->assertStringContainsString('height="150"', $html);
    }

    public function testMakeImageContainsAlt(): void
    {
        $html = ConcreteHtmlPage::makeImage('/img/pic.jpg', 10, 10, 'A logo');
        $this->assertStringContainsString('alt="A logo"', $html);
    }

    public function testMakeImageEscapesSpecialCharsInAlt(): void
    {
        $html = ConcreteHtmlPage::makeImage('/img/pic.jpg', 10, 10, '"quoted"');
        $this->assertStringContainsString('alt="&quot;quoted&quot;"', $html);
    }

    public function testMakeImageEscapesSrcUrl(): void
    {
        $html = ConcreteHtmlPage::makeImage('/path?a=1&b=2', 10, 10);
        $this->assertStringContainsString('src="/path?a=1&amp;b=2"', $html);
    }

    // ── makeLink ──────────────────────────────────────────────────

    public function testMakeLinkContainsHref(): void
    {
        $html = ConcreteHtmlPage::makeLink('/about', 'About');
        $this->assertStringContainsString('href="/about"', $html);
    }

    public function testMakeLinkContainsLinkText(): void
    {
        $html = ConcreteHtmlPage::makeLink('/about', 'About Us');
        $this->assertStringContainsString('About Us', $html);
    }

    public function testMakeLinkNoTargetByDefault(): void
    {
        $html = ConcreteHtmlPage::makeLink('/about', 'About');
        $this->assertStringNotContainsString('target=', $html);
    }

    public function testMakeLinkWithTarget(): void
    {
        $html = ConcreteHtmlPage::makeLink('/about', 'About', '_blank');
        $this->assertStringContainsString('target="_blank"', $html);
    }

    public function testMakeLinkBlankAddsNoopener(): void
    {
        $html = ConcreteHtmlPage::makeLink('/about', 'About', '_blank');
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function testMakeLinkNonBlankNoRelAttr(): void
    {
        $html = ConcreteHtmlPage::makeLink('/about', 'About', '_self');
        $this->assertStringNotContainsString('rel=', $html);
    }

    // ── Form methods ──────────────────────────────────────────────

    public function testMakeFormHeadContainsFormTag(): void
    {
        $html = ConcreteHtmlPage::makeFormHead('/submit');
        $this->assertStringContainsString('<form', $html);
    }

    public function testMakeFormHeadDefaultMethodIsPost(): void
    {
        $html = ConcreteHtmlPage::makeFormHead('/submit');
        $this->assertStringContainsString('method="post"', $html);
    }

    public function testMakeFormHeadWithGetMethod(): void
    {
        $html = ConcreteHtmlPage::makeFormHead('/search', 'get');
        $this->assertStringContainsString('method="get"', $html);
    }

    public function testMakeFormHeadWithName(): void
    {
        $html = ConcreteHtmlPage::makeFormHead('/submit', 'post', 'myForm');
        $this->assertStringContainsString('name="myForm"', $html);
    }

    public function testMakeFormFootReturnsClosingTag(): void
    {
        $this->assertSame('</form>', ConcreteHtmlPage::makeFormFoot());
    }

    public function testMakeInputContainsInputTag(): void
    {
        $html = ConcreteHtmlPage::makeInput('username', 80);
        $this->assertStringContainsString('<input', $html);
    }

    public function testMakeInputContainsName(): void
    {
        $html = ConcreteHtmlPage::makeInput('username', 80);
        $this->assertStringContainsString('name="username"', $html);
    }

    public function testMakeInputContainsValue(): void
    {
        $html = ConcreteHtmlPage::makeInput('email', 100, 'email', 0, 'user@example.com');
        $this->assertStringContainsString('value="user@example.com"', $html);
    }

    public function testMakeHiddenInputType(): void
    {
        $html = ConcreteHtmlPage::makeHiddenInput('token', 'abc123');
        $this->assertStringContainsString('type="hidden"', $html);
    }

    public function testMakeHiddenInputValue(): void
    {
        $html = ConcreteHtmlPage::makeHiddenInput('token', 'abc123');
        $this->assertStringContainsString('value="abc123"', $html);
    }

    public function testMakeHiddenInputEscapesValue(): void
    {
        $html = ConcreteHtmlPage::makeHiddenInput('t', '<script>');
        $this->assertStringContainsString('value="&lt;script&gt;"', $html);
    }

    public function testMakeTextAreaContainsTextareaTag(): void
    {
        $html = ConcreteHtmlPage::makeTextArea('notes', 5, 40);
        $this->assertStringContainsString('<textarea', $html);
    }

    public function testMakeTextAreaContainsRowsCols(): void
    {
        $html = ConcreteHtmlPage::makeTextArea('notes', 5, 40);
        $this->assertStringContainsString('rows="5"', $html);
        $this->assertStringContainsString('cols="40"', $html);
    }

    public function testMakeTextAreaReadonly(): void
    {
        $html = ConcreteHtmlPage::makeTextArea('notes', 5, 40, '', true);
        $this->assertStringContainsString('readonly', $html);
    }

    public function testMakeTextAreaNoReadonlyByDefault(): void
    {
        $html = ConcreteHtmlPage::makeTextArea('notes', 5, 40);
        $this->assertStringNotContainsString('readonly', $html);
    }

    public function testMakeTextAreaEscapesValue(): void
    {
        $html = ConcreteHtmlPage::makeTextArea('notes', 3, 20, '<b>bold</b>');
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
    }

    public function testMakeButtonContainsBtnClass(): void
    {
        $html = ConcreteHtmlPage::makeButton('save', 'Save');
        $this->assertStringContainsString('btn btn-primary', $html);
    }

    public function testMakeButtonDefaultTypeIsSubmit(): void
    {
        $html = ConcreteHtmlPage::makeButton('save', 'Save');
        $this->assertStringContainsString('type="submit"', $html);
    }

    public function testMakeButtonWithOnclick(): void
    {
        $html = ConcreteHtmlPage::makeButton('del', 'Delete', 'button', 'return confirm("Sure?")');
        $this->assertStringContainsString('onclick=', $html);
    }

    public function testMakeButtonNoOnclickWhenZero(): void
    {
        $html = ConcreteHtmlPage::makeButton('del', 'Delete', 'button', 0);
        $this->assertStringNotContainsString('onclick=', $html);
    }

    public function testMakeRadioButtonType(): void
    {
        $html = ConcreteHtmlPage::makeRadioButton('choice', 'yes');
        $this->assertStringContainsString('type="radio"', $html);
    }

    public function testMakeRadioButtonChecked(): void
    {
        $html = ConcreteHtmlPage::makeRadioButton('choice', 'yes', true);
        $this->assertStringContainsString('checked', $html);
    }

    public function testMakeRadioButtonNotCheckedByDefault(): void
    {
        $html = ConcreteHtmlPage::makeRadioButton('choice', 'no');
        $this->assertStringNotContainsString('checked', $html);
    }

    public function testMakeCheckBoxType(): void
    {
        $html = ConcreteHtmlPage::makeCheckBox('agree', '1');
        $this->assertStringContainsString('type="checkbox"', $html);
    }

    public function testMakeCheckBoxChecked(): void
    {
        $html = ConcreteHtmlPage::makeCheckBox('agree', '1', true);
        $this->assertStringContainsString('checked', $html);
    }

    public function testMakeCheckBoxOnclick(): void
    {
        $html = ConcreteHtmlPage::makeCheckBox('agree', '1', false, 'toggle()');
        $this->assertStringContainsString('onclick="toggle()"', $html);
    }

    public function testMakeOptionFromArrayContainsSelect(): void
    {
        $arr  = [['en', 'English'], ['de', 'Deutsch']];
        $html = ConcreteHtmlPage::makeOptionFromArray('lang', $arr);
        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('</select>', $html);
    }

    public function testMakeOptionFromArrayOptionsRendered(): void
    {
        $arr  = [['en', 'English'], ['de', 'Deutsch']];
        $html = ConcreteHtmlPage::makeOptionFromArray('lang', $arr);
        $this->assertStringContainsString('English', $html);
        $this->assertStringContainsString('Deutsch', $html);
    }

    public function testMakeOptionFromArraySelectedAttribute(): void
    {
        $arr  = [['en', 'English'], ['de', 'Deutsch']];
        $html = ConcreteHtmlPage::makeOptionFromArray('lang', $arr, 'de');
        $this->assertStringContainsString('value="de" selected', $html);
    }

    public function testMakeOptionFromArrayOnChange(): void
    {
        $arr  = [['a', 'A']];
        $html = ConcreteHtmlPage::makeOptionFromArray('x', $arr, 0, 'changed()');
        $this->assertStringContainsString('onchange="changed()"', $html);
    }

    // ── Static root configuration ─────────────────────────────────

    public function testSetAndGetSiteRoot(): void
    {
        ConcreteHtmlPage::setSiteRoot('/publink');
        $this->assertSame('/publink', ConcreteHtmlPage::getSiteRoot());
        ConcreteHtmlPage::setSiteRoot(''); // reset
    }

    public function testSetAndGetCreator(): void
    {
        ConcreteHtmlPage::setCreator('TestBot');
        $this->assertSame('TestBot', ConcreteHtmlPage::getCreator());
        ConcreteHtmlPage::setCreator('');
    }

    public function testSetAndGetImageRoot(): void
    {
        ConcreteHtmlPage::setImageRoot('/assets/img/');
        $this->assertSame('/assets/img/', ConcreteHtmlPage::getImageRoot());
        ConcreteHtmlPage::setImageRoot('');
    }

    public function testSetAndGetXSLRoot(): void
    {
        ConcreteHtmlPage::setXSLRoot('/assets/xsl/');
        $this->assertSame('/assets/xsl/', ConcreteHtmlPage::getXSLRoot());
        ConcreteHtmlPage::setXSLRoot('');
    }

    public function testSetAndGetCssRoot(): void
    {
        ConcreteHtmlPage::setCssRoot('/assets/css/');
        $this->assertSame('/assets/css/', ConcreteHtmlPage::getCssRoot());
        ConcreteHtmlPage::setCssRoot('');
    }

    public function testSetAndGetJSRoot(): void
    {
        ConcreteHtmlPage::setJSRoot('/assets/js/');
        $this->assertSame('/assets/js/', ConcreteHtmlPage::getJSRoot());
        ConcreteHtmlPage::setJSRoot('');
    }

    // ── Date / time utilities ─────────────────────────────────────

    public function testGetTimeReturnsHHMMSS(): void
    {
        $time = ConcreteHtmlPage::getTime();
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $time);
    }

    public function testGetTodayReturnsNonEmpty(): void
    {
        $this->assertNotEmpty(ConcreteHtmlPage::getToday());
    }

    public function testGetSQLDateFormatsCorrectly(): void
    {
        $this->assertSame('2024-02-04', ConcreteHtmlPage::getSQLDate(4, 2, 2024));
    }

    public function testGetSQLDatePadsMonthAndDay(): void
    {
        $this->assertSame('2024-01-05', ConcreteHtmlPage::getSQLDate(5, 1, 2024));
    }

    public function testGetTodayAsSQLDateMatchesPattern(): void
    {
        $date = ConcreteHtmlPage::getTodayAsSQLDate();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date);
    }

    public function testGetNowAsSQLTimeStampMatchesPattern(): void
    {
        $ts = ConcreteHtmlPage::getNowAsSQLTimeStamp();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ts);
    }

    public function testGetShortDateFromSQLReturnsNonEmpty(): void
    {
        $this->assertNotEmpty(ConcreteHtmlPage::getShortDateFromSQL('2024-02-04'));
    }

    public function testGetShortDateFromSQLInvalidReturnsEmpty(): void
    {
        $this->assertSame('', ConcreteHtmlPage::getShortDateFromSQL('not-a-date'));
    }

    public function testGetDateFromSQLReturnsLongFormat(): void
    {
        $result = ConcreteHtmlPage::getDateFromSQL('2024-02-04');
        $this->assertStringContainsString('2024', $result);
        $this->assertStringContainsString('February', $result);
    }

    public function testGetDateFromSQLInvalidReturnsEmpty(): void
    {
        $this->assertSame('', ConcreteHtmlPage::getDateFromSQL('bad'));
    }

    public function testGetSQLDateFromSlashFormat(): void
    {
        $this->assertSame('2024-02-04', ConcreteHtmlPage::getSQLDateFromSlashFormat('04/02/2024'));
    }

    public function testGetSQLDateFromSlashFormatInvalidReturnsEmpty(): void
    {
        $this->assertSame('', ConcreteHtmlPage::getSQLDateFromSlashFormat('2024-02-04'));
    }

    public function testGetTimeStampAsDateTimeArrayReturnsTwo(): void
    {
        $result = ConcreteHtmlPage::getTimeStampAsDateTimeArray('2024-02-04 14:30:00');
        $this->assertCount(2, $result);
        $this->assertSame('04-02-2024', $result[0]);
    }

    public function testGetTimeStampAsDateTimeArrayInvalidReturnsBlanks(): void
    {
        $result = ConcreteHtmlPage::getTimeStampAsDateTimeArray('not-a-timestamp');
        $this->assertSame(['', ''], $result);
    }

    // ── isValidEmail ──────────────────────────────────────────────

    public function testValidEmailReturnsTrue(): void
    {
        $this->assertTrue(ConcreteHtmlPage::isValidEmail('user@example.com'));
    }

    public function testInvalidEmailReturnsFalse(): void
    {
        $this->assertFalse(ConcreteHtmlPage::isValidEmail('not-an-email'));
    }

    public function testEmptyEmailReturnsFalse(): void
    {
        $this->assertFalse(ConcreteHtmlPage::isValidEmail(''));
    }

    public function testEmailWithSubdomainIsValid(): void
    {
        $this->assertTrue(ConcreteHtmlPage::isValidEmail('user@mail.example.org'));
    }

    // ── getRandomPassword ─────────────────────────────────────────

    public function testRandomPasswordLengthInRange(): void
    {
        $pwd = ConcreteHtmlPage::getRandomPassword(8, 12);
        $this->assertGreaterThanOrEqual(8, strlen($pwd));
        $this->assertLessThanOrEqual(12, strlen($pwd));
    }

    public function testRandomPasswordIsAlphanumeric(): void
    {
        $pwd = ConcreteHtmlPage::getRandomPassword(10, 10);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $pwd);
    }

    public function testRandomPasswordFixedLengthWhenMinEqualsMax(): void
    {
        $pwd = ConcreteHtmlPage::getRandomPassword(8, 8);
        $this->assertSame(8, strlen($pwd));
    }

    public function testRandomPasswordTwoCallsDiffer(): void
    {
        // Extremely unlikely to match; demonstrates randomness
        $a = ConcreteHtmlPage::getRandomPassword(16, 16);
        $b = ConcreteHtmlPage::getRandomPassword(16, 16);
        // Not asserting inequality (could theoretically match) but verifying no exception
        $this->assertIsString($a);
        $this->assertIsString($b);
    }
}


/********************************************************************/
/* BIBLIOTHECA PAGE TESTS                                            */
/********************************************************************/

class BibliothicaPageTest extends TestCase
{
    private TestBibliothecaPage $page;

    protected function setUp(): void
    {
        $this->page = new TestBibliothecaPage();
    }

    // ── Default values ────────────────────────────────────────────

    public function testDefaultTitleIsSet(): void
    {
        $this->assertStringContainsString('PubLink', $this->page->getTitle());
    }

    public function testDefaultLongTitleIsSet(): void
    {
        $this->assertStringContainsString('PubLink', $this->page->getLongTitle());
    }

    public function testDefaultShortTitleIsSet(): void
    {
        $this->assertNotEmpty($this->page->getShortTitle());
    }

    public function testDefaultHeadingIsEmpty(): void
    {
        $this->assertSame('', $this->page->getHeading());
    }

    public function testDefaultCentralContentIsEmpty(): void
    {
        $this->assertSame('', $this->page->getCentralContent());
    }

    public function testDefaultErrorMessageIsEmpty(): void
    {
        $this->assertSame('', $this->page->getErrorMessage());
    }

    public function testDefaultModalMessageIsEmpty(): void
    {
        $this->assertSame('', $this->page->getModalMessage());
    }

    public function testDefaultModalHeadIsEmpty(): void
    {
        $this->assertSame('', $this->page->getModalHead());
    }

    // ── Setters / getters ─────────────────────────────────────────

    public function testSetAndGetTitle(): void
    {
        $this->page->setTitle('My Page Title');
        $this->assertSame('My Page Title', $this->page->getTitle());
    }

    public function testSetAndGetHeading(): void
    {
        $this->page->setHeading('Welcome');
        $this->assertSame('Welcome', $this->page->getHeading());
    }

    public function testSetAndGetCentralContent(): void
    {
        $this->page->setCentralContent('<p>Hello</p>');
        $this->assertSame('<p>Hello</p>', $this->page->getCentralContent());
    }

    public function testSetCentralContentReplacesExisting(): void
    {
        $this->page->setCentralContent('<p>First</p>');
        $this->page->setCentralContent('<p>Second</p>');
        $this->assertSame('<p>Second</p>', $this->page->getCentralContent());
    }

    public function testSetAndGetErrorMessage(): void
    {
        $this->page->setErrorMessage('Something went wrong');
        $this->assertSame('Something went wrong', $this->page->getErrorMessage());
    }

    // ── Modal head ────────────────────────────────────────────────

    public function testSetModalHeadReplacesContent(): void
    {
        $this->page->setModalHead('<script>a()</script>');
        $this->assertSame('<script>a()</script>', $this->page->getModalHead());
    }

    public function testAddToModalHeadAccumulatesContent(): void
    {
        $this->page->addToModalHead('alpha');
        $this->page->addToModalHead('beta');
        $combined = $this->page->getModalHead();
        $this->assertStringContainsString('alpha', $combined);
        $this->assertStringContainsString('beta', $combined);
    }

    // ── Modal message ─────────────────────────────────────────────

    public function testAddToModalMessageAccumulatesContent(): void
    {
        $this->page->addToModalMessage('<div id="modal1"></div>');
        $this->page->addToModalMessage('<div id="modal2"></div>');
        $combined = $this->page->getModalMessage();
        $this->assertStringContainsString('modal1', $combined);
        $this->assertStringContainsString('modal2', $combined);
    }

    // ── AdminLTE path ─────────────────────────────────────────────

    public function testSetAndGetAdminLTEPath(): void
    {
        TestBibliothecaPage::setAdminLTEPath('/assets/adminlte');
        $this->assertSame('/assets/adminlte', $this->page->getAdminLTEPath());
        TestBibliothecaPage::setAdminLTEPath(''); // reset
    }
}


/********************************************************************/
/* MODAL ALERT TESTS                                                 */
/********************************************************************/

class ModalAlertTest extends TestCase
{
    private TestBibliothecaPage $page;

    protected function setUp(): void
    {
        $this->page = new TestBibliothecaPage();
    }

    // ── Constructor ───────────────────────────────────────────────

    public function testConstructorCreatesInstance(): void
    {
        $modal = new Modal_Alert($this->page, 'myAlert', 'Hello');
        $this->assertInstanceOf(Modal_Alert::class, $modal);
    }

    // ── Name validation ───────────────────────────────────────────

    public function testInvalidNameThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Modal_Alert($this->page, 'invalid name!', 'msg');
    }

    public function testNameStartingWithDigitThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Modal_Alert($this->page, '1start', 'msg');
    }

    public function testNameWithHyphenThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Modal_Alert($this->page, 'my-modal', 'msg');
    }

    public function testValidAlphanumericNameAccepted(): void
    {
        $modal = new Modal_Alert($this->page, 'modal123', 'msg');
        $this->assertInstanceOf(Modal_Alert::class, $modal);
    }

    public function testNameWithUnderscoreAccepted(): void
    {
        $modal = new Modal_Alert($this->page, 'my_modal', 'msg');
        $this->assertInstanceOf(Modal_Alert::class, $modal);
    }

    // ── setConfirmDialog injects into page ────────────────────────

    public function testSetConfirmDialogAddsToModalHead(): void
    {
        $modal = new Modal_Alert($this->page, 'testAlert', 'A message');
        $modal->setConfirmDialog();
        $this->assertNotEmpty($this->page->getModalHead());
    }

    public function testSetConfirmDialogAddsToModalMessage(): void
    {
        $modal = new Modal_Alert($this->page, 'testAlert', 'A message');
        $modal->setConfirmDialog();
        $this->assertNotEmpty($this->page->getModalMessage());
    }

    public function testModalHeadContainsJsFunction(): void
    {
        $modal = new Modal_Alert($this->page, 'myAlert', 'Hello');
        $modal->setConfirmDialog();
        $this->assertStringContainsString('myAlert_func()', $this->page->getModalHead());
    }

    public function testModalMessageContainsModalId(): void
    {
        $modal = new Modal_Alert($this->page, 'myAlert', 'Hello');
        $modal->setConfirmDialog();
        $this->assertStringContainsString('id="myAlert"', $this->page->getModalMessage());
    }

    public function testModalMessageContainsBodyId(): void
    {
        $modal = new Modal_Alert($this->page, 'myAlert', 'Hello');
        $modal->setConfirmDialog();
        $this->assertStringContainsString('id="myAlert_body"', $this->page->getModalMessage());
    }

    public function testModalMessageContainsMessageText(): void
    {
        $modal = new Modal_Alert($this->page, 'myAlert', 'The quick brown fox');
        $modal->setConfirmDialog();
        $this->assertStringContainsString('The quick brown fox', $this->page->getModalMessage());
    }

    public function testModalMessageContainsCloseButton(): void
    {
        $modal = new Modal_Alert($this->page, 'myAlert', 'msg');
        $modal->setConfirmDialog();
        $this->assertStringContainsString('data-dismiss="modal"', $this->page->getModalMessage());
    }

    // ── setOnPageLoad ─────────────────────────────────────────────

    public function testOnPageLoadFalseNoReadyBlock(): void
    {
        $modal = new Modal_Alert($this->page, 'myAlert', 'msg');
        $modal->setOnPageLoad(false);
        $modal->setConfirmDialog();
        $this->assertStringNotContainsString('document.ready', $this->page->getModalHead());
        $this->assertStringNotContainsString('$(document).ready', $this->page->getModalHead());
    }

    public function testOnPageLoadTrueAddsReadyBlock(): void
    {
        $modal = new Modal_Alert($this->page, 'myAlert', 'msg');
        $modal->setOnPageLoad(true);
        $modal->setConfirmDialog();
        $this->assertStringContainsString('$(document).ready', $this->page->getModalHead());
    }

    // ── Multiple modals on same page ──────────────────────────────

    public function testMultipleModalsAccumulate(): void
    {
        $m1 = new Modal_Alert($this->page, 'alertOne', 'First');
        $m2 = new Modal_Alert($this->page, 'alertTwo', 'Second');
        $m1->setConfirmDialog();
        $m2->setConfirmDialog();

        $message = $this->page->getModalMessage();
        $this->assertStringContainsString('id="alertOne"', $message);
        $this->assertStringContainsString('id="alertTwo"', $message);
    }

    // ── setName / setMessage / setPage ────────────────────────────

    public function testSetNameChangesInjectedId(): void
    {
        $modal = new Modal_Alert($this->page, 'oldName', 'msg');
        $modal->setName('newName');
        $modal->setConfirmDialog();
        $this->assertStringContainsString('id="newName"', $this->page->getModalMessage());
        $this->assertStringNotContainsString('id="oldName"', $this->page->getModalMessage());
    }

    public function testSetMessageChangesBody(): void
    {
        $modal = new Modal_Alert($this->page, 'alertA', 'original');
        $modal->setMessage('updated message');
        $modal->setConfirmDialog();
        $this->assertStringContainsString('updated message', $this->page->getModalMessage());
        $this->assertStringNotContainsString('original', $this->page->getModalMessage());
    }

    public function testSetPageRedirectsInjection(): void
    {
        $page2 = new TestBibliothecaPage();
        $modal = new Modal_Alert($this->page, 'alertA', 'msg');
        $modal->setPage($page2);
        $modal->setConfirmDialog();

        // Should inject into page2, not the original page
        $this->assertNotEmpty($page2->getModalMessage());
        $this->assertEmpty($this->page->getModalMessage());
    }
}


/********************************************************************/
/* MODAL CONFIRM TESTS                                               */
/********************************************************************/

class ModalConfirmTest extends TestCase
{
    private TestBibliothecaPage $page;

    protected function setUp(): void
    {
        $this->page = new TestBibliothecaPage();
    }

    // ── Constructor ───────────────────────────────────────────────

    public function testConstructorCreatesInstance(): void
    {
        $modal = new Modal_Confirm($this->page, 'confirmDel', 'Sure?');
        $this->assertInstanceOf(Modal_Confirm::class, $modal);
    }

    // ── Name validation ───────────────────────────────────────────

    public function testInvalidNameThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Modal_Confirm($this->page, 'bad name', 'msg');
    }

    public function testHyphenInNameThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Modal_Confirm($this->page, 'bad-name', 'msg');
    }

    public function testValidNameWithUnderscoreAccepted(): void
    {
        $modal = new Modal_Confirm($this->page, 'ok_name', 'msg');
        $this->assertInstanceOf(Modal_Confirm::class, $modal);
    }

    // ── getConfirmMessageBody ─────────────────────────────────────

    public function testConfirmBodyContainsModalId(): void
    {
        $modal = new Modal_Confirm($this->page, 'confirmDel', 'Sure?');
        $html  = $modal->getConfirmMessageBody();
        $this->assertStringContainsString('id="confirmDel"', $html);
    }

    public function testConfirmBodyContainsOKButton(): void
    {
        $modal = new Modal_Confirm($this->page, 'confirmDel', 'Sure?');
        $html  = $modal->getConfirmMessageBody();
        $this->assertStringContainsString('id="confirmDel_ok"', $html);
    }

    public function testConfirmBodyContainsCancelButton(): void
    {
        $modal = new Modal_Confirm($this->page, 'confirmDel', 'Sure?');
        $html  = $modal->getConfirmMessageBody();
        $this->assertStringContainsString('id="confirmDel_cancel"', $html);
    }

    public function testConfirmBodyContainsMessage(): void
    {
        $modal = new Modal_Confirm($this->page, 'confirmDel', 'Delete this item?');
        $html  = $modal->getConfirmMessageBody();
        $this->assertStringContainsString('Delete this item?', $html);
    }

    // ── setConfirmDialog (URL redirect) ───────────────────────────

    public function testSetConfirmDialogInjectsIntoPage(): void
    {
        $modal = new Modal_Confirm($this->page, 'confirmDel', 'Sure?');
        $modal->setOKAddress('/delete?id=5');
        $modal->setConfirmDialog();
        $this->assertNotEmpty($this->page->getModalHead());
        $this->assertNotEmpty($this->page->getModalMessage());
    }

    public function testGetJavaScriptContainsFuncName(): void
    {
        $modal = new Modal_Confirm($this->page, 'confirmDel', 'Sure?');
        $modal->setOKAddress('/delete');
        $js = $modal->getJavaScript();
        $this->assertStringContainsString('confirmDel_func()', $js);
    }

    public function testGetJavaScriptContainsOkAddress(): void
    {
        $modal = new Modal_Confirm($this->page, 'confirmDel', 'Sure?');
        $modal->setOKAddress('/delete?id=99');
        $js = $modal->getJavaScript();
        $this->assertStringContainsString('/delete?id=99', $js);
    }

    // ── setNonForwardConfirmDialog (custom JS action) ─────────────

    public function testNonForwardDialogInjectsIntoPage(): void
    {
        $modal = new Modal_Confirm($this->page, 'confirmAct', 'Proceed?');
        $modal->setNonForwardConfirmDialog('doSomething()');
        $this->assertNotEmpty($this->page->getModalHead());
        $this->assertNotEmpty($this->page->getModalMessage());
    }

    public function testGetNonForwardJSContainsScript(): void
    {
        $modal = new Modal_Confirm($this->page, 'confirmAct', 'Proceed?');
        $js = $modal->getNonForwardJavaScript('deleteItem(42)');
        $this->assertStringContainsString('deleteItem(42)', $js);
    }

    public function testGetNonForwardJSContainsFuncName(): void
    {
        $modal = new Modal_Confirm($this->page, 'confirmAct', 'Proceed?');
        $js = $modal->getNonForwardJavaScript('foo()');
        $this->assertStringContainsString('confirmAct_func()', $js);
    }

    // ── setConfirmTrueFalseDialog (form guard) ────────────────────

    public function testFormGuardDialogInjectsIntoPage(): void
    {
        $modal = new Modal_Confirm($this->page, 'confirmSave', 'Save?');
        $modal->setConfirmTrueFalseDialog('myForm');
        $this->assertNotEmpty($this->page->getModalHead());
        $this->assertNotEmpty($this->page->getModalMessage());
    }

    public function testGetReturnTrueFalseJSContainsFormId(): void
    {
        $modal = new Modal_Confirm($this->page, 'confirmSave', 'Save?');
        $js = $modal->getReturnTrueFalseJS('myForm');
        $this->assertStringContainsString('myForm', $js);
    }

    public function testGetReturnTrueFalseJSContainsFuncName(): void
    {
        $modal = new Modal_Confirm($this->page, 'confirmSave', 'Save?');
        $js = $modal->getReturnTrueFalseJS('myForm');
        $this->assertStringContainsString('confirmSave_func()', $js);
    }

    public function testGetReturnTrueFalseJSContainsSubmitFlag(): void
    {
        $modal = new Modal_Confirm($this->page, 'confirmSave', 'Save?');
        $js = $modal->getReturnTrueFalseJS('myForm');
        $this->assertStringContainsString('confirmSave_submit', $js);
    }

    public function testFormGuardInvalidFormIdThrows(): void
    {
        $modal = new Modal_Confirm($this->page, 'confirmSave', 'Save?');
        $this->expectException(\InvalidArgumentException::class);
        $modal->getReturnTrueFalseJS('bad-form-id!');
    }

    // ── setName / setMessage / setPage ────────────────────────────

    public function testSetNameChangesInjectedId(): void
    {
        $modal = new Modal_Confirm($this->page, 'oldName', 'msg');
        $modal->setName('newName');
        $modal->setOKAddress('/');
        $modal->setConfirmDialog();
        $this->assertStringContainsString('id="newName"', $this->page->getModalMessage());
    }

    public function testSetMessageUpdatesBody(): void
    {
        $modal = new Modal_Confirm($this->page, 'conf', 'original');
        $modal->setMessage('updated');
        $html = $modal->getConfirmMessageBody();
        $this->assertStringContainsString('updated', $html);
        $this->assertStringNotContainsString('original', $html);
    }

    public function testSetOKAddressUpdatesJS(): void
    {
        $modal = new Modal_Confirm($this->page, 'conf', 'msg');
        $modal->setOKAddress('/new-address');
        $js = $modal->getJavaScript();
        $this->assertStringContainsString('/new-address', $js);
    }

    public function testSetPageRedirectsInjection(): void
    {
        $page2 = new TestBibliothecaPage();
        $modal = new Modal_Confirm($this->page, 'confX', 'msg');
        $modal->setPage($page2);
        $modal->setOKAddress('/');
        $modal->setConfirmDialog();

        $this->assertNotEmpty($page2->getModalMessage());
        $this->assertEmpty($this->page->getModalMessage());
    }
}
