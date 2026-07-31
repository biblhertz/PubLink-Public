<?php

namespace Biblhertz\Article\Tests;

use PHPUnit\Framework\TestCase;
use Biblhertz\Article\om\ConferencePaper;
use Biblhertz\Article\om\Manuscript;
use Biblhertz\Article\om\Person;
use Biblhertz\Article\om\JournalArticle;
use Biblhertz\Article\om\Book;
use Biblhertz\Article\om\Chapter;
use Biblhertz\Article\om\GalleyFile;
use Biblhertz\Article\om\ReferenceCollection;
use Biblhertz\Article\om\presentation\ReferencePresentation;
use Biblhertz\Article\om\presentation\ReferenceCollectionPresentation;
use Biblhertz\Article\om\presentation\GalleyFilePresentation;


/********************************************************************/
/* PERSON TESTS                                                      */
/********************************************************************/

class PersonTest extends TestCase
{
    private Person $person;

    protected function setUp(): void
    {
        $this->person = new Person('Jane', 'Smith');
    }

    // --- Constructor ---

    public function testConstructorSetsFirstName(): void
    {
        $this->assertSame('Jane', $this->person->getFirstName());
    }

    public function testConstructorSetsLastName(): void
    {
        $this->assertSame('Smith', $this->person->getLastName());
    }

    // --- Setters / getters ---

    public function testSetAndGetFirstName(): void
    {
        $this->person->setFirstName('Alice');
        $this->assertSame('Alice', $this->person->getFirstName());
    }

    public function testSetAndGetLastName(): void
    {
        $this->person->setLastName('Jones');
        $this->assertSame('Jones', $this->person->getLastName());
    }

    // --- getFullName ---

    public function testGetFullNameCombinesNames(): void
    {
        $this->assertSame('Jane Smith', $this->person->getFullName());
    }

    public function testGetFullNameReflectsChanges(): void
    {
        $this->person->setFirstName('Bob');
        $this->person->setLastName('Brown');
        $this->assertSame('Bob Brown', $this->person->getFullName());
    }

    public function testGetFullNameWithEmptyFirst(): void
    {
        $p = new Person('', 'Only');
        $this->assertSame(' Only', $p->getFullName());
    }

    // --- getCompleteName ---

    public function testGetCompleteNameDelegatesToGetFullName(): void
    {
        $this->assertSame($this->person->getFullName(), $this->person->getCompleteName());
    }
}


/********************************************************************/
/* CONFERENCE PAPER TESTS                                            */
/********************************************************************/

class ConferencePaperTest extends TestCase
{
    private ConferencePaper $paper;

    protected function setUp(): void
    {
        $this->paper = new ConferencePaper();
    }

    // --- Constructor sets publication type ---

    public function testConstructorSetsPublicationType(): void
    {
        $this->assertSame('paper-conference', $this->paper->getPublicationType());
    }

    public function testConstructorSetsBibtexType(): void
    {
        $this->assertSame('inproceedings', $this->paper->getBibtexType());
    }

    // --- firstPage / lastPage ---

    public function testFirstPageDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->paper->getFirstPage());
    }

    public function testLastPageDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->paper->getLastPage());
    }

    public function testSetAndGetFirstPage(): void
    {
        $this->paper->setFirstPage('10');
        $this->assertSame('10', $this->paper->getFirstPage());
    }

    public function testSetAndGetLastPage(): void
    {
        $this->paper->setLastPage('25');
        $this->assertSame('25', $this->paper->getLastPage());
    }

    // --- conference ---

    public function testConferenceDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->paper->getConference());
    }

    public function testSetAndGetConference(): void
    {
        $this->paper->setConference('ICML 2024');
        $this->assertSame('ICML 2024', $this->paper->getConference());
    }

    public function testSetConferenceTrimsWhitespace(): void
    {
        $this->paper->setConference('  NeurIPS  ');
        $this->assertSame('NeurIPS', $this->paper->getConference());
    }

    // --- bookTitle ---

    public function testBookTitleDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->paper->getBookTitle());
    }

    public function testSetAndGetBookTitle(): void
    {
        $this->paper->setBookTitle('Proceedings of ICLR');
        $this->assertSame('Proceedings of ICLR', $this->paper->getBookTitle());
    }

    public function testSetBookTitleTrimsWhitespace(): void
    {
        $this->paper->setBookTitle('  Proceedings  ');
        $this->assertSame('Proceedings', $this->paper->getBookTitle());
    }

    // --- getFilterType ---

    public function testGetFilterTypeContainsProceedingsArticle(): void
    {
        $this->assertStringContainsString('proceedings-article', $this->paper->getFilterType());
    }

    // --- Inherited Reference fields ---

    public function testSetAndGetTitle(): void
    {
        $this->paper->setTitle('A Novel Approach');
        $this->assertSame('A Novel Approach', $this->paper->getTitle());
    }

    public function testSetAndGetYear(): void
    {
        $this->paper->setYear('2024');
        $this->assertSame('2024', $this->paper->getYear());
    }

    public function testSetAndGetLabel(): void
    {
        $this->paper->setLabel('smith2024');
        $this->assertSame('smith2024', $this->paper->getLabel());
    }

    // --- createFromJatsXMLFragment ---

    public function testCreateFromJatsXMLFragmentSetsTitle(): void
    {
        $xml = simplexml_load_string('<element-citation>
            <article-title>My Conference Paper</article-title>
            <source>Best Conference</source>
        </element-citation>');
        $this->paper->createFromJatsXMLFragment($xml);
        $this->assertSame('My Conference Paper', $this->paper->getTitle());
    }

    public function testCreateFromJatsXMLFragmentPrefersPartTitle(): void
    {
        $xml = simplexml_load_string('<element-citation>
            <part-title>Part Title Wins</part-title>
            <article-title>Article Title Loses</article-title>
        </element-citation>');
        $this->paper->createFromJatsXMLFragment($xml);
        $this->assertSame('Part Title Wins', $this->paper->getTitle());
    }

    public function testCreateFromJatsXMLFragmentSetsConference(): void
    {
        $xml = simplexml_load_string('<element-citation>
            <source>International Symposium</source>
        </element-citation>');
        $this->paper->createFromJatsXMLFragment($xml);
        $this->assertSame('International Symposium', $this->paper->getConference());
    }

    public function testCreateFromJatsXMLFragmentSetsPages(): void
    {
        $xml = simplexml_load_string('<element-citation>
            <fpage>5</fpage>
            <lpage>15</lpage>
        </element-citation>');
        $this->paper->createFromJatsXMLFragment($xml);
        $this->assertSame('5', $this->paper->getFirstPage());
        $this->assertSame('15', $this->paper->getLastPage());
    }

    // --- updateFromBibtex ---

    public function testUpdateFromBibtexSetsConferenceFromConferenceField(): void
    {
        $this->paper->updateFromBibtex(['conference' => 'SIGIR 2023']);
        $this->assertSame('SIGIR 2023', $this->paper->getConference());
    }

    public function testUpdateFromBibtexSetsBookTitle(): void
    {
        $this->paper->updateFromBibtex(['booktitle' => 'Proceedings of SIGIR']);
        $this->assertSame('Proceedings of SIGIR', $this->paper->getBookTitle());
    }

    public function testUpdateFromBibtexUsesBookTitleAsConferenceFallback(): void
    {
        // No conference field — booktitle should fill in conference
        $this->paper->updateFromBibtex(['booktitle' => 'ACL Proceedings']);
        $this->assertSame('ACL Proceedings', $this->paper->getConference());
    }

    public function testUpdateFromBibtexConferenceNotOverriddenByBookTitle(): void
    {
        // conference field set — booktitle should not overwrite it
        $this->paper->updateFromBibtex([
            'conference' => 'ACL 2024',
            'booktitle'  => 'Proceedings of ACL',
        ]);
        $this->assertSame('ACL 2024', $this->paper->getConference());
    }

    // --- getJATSReference ---

    public function testGetJATSReferenceReturnsString(): void
    {
        $this->paper->setLabel('cp2024');
        $result = $this->paper->getJATSReference();
        $this->assertIsString($result);
    }

    public function testGetJATSReferenceContainsElementCitation(): void
    {
        $this->paper->setLabel('cp2024');
        $xml = $this->paper->getJATSReference();
        $this->assertStringContainsString('element-citation', $xml);
    }

    public function testGetJATSReferencePublicationTypeIsPaperConference(): void
    {
        $this->paper->setLabel('cp2024');
        $xml = $this->paper->getJATSReference();
        $this->assertStringContainsString('publication-type="paper-conference"', $xml);
    }

    public function testGetJATSReferenceIncludesConference(): void
    {
        $this->paper->setLabel('cp2024');
        $this->paper->setTitle('AI Paper');
        $this->paper->setConference('ICML 2024');
        $xml = $this->paper->getJATSReference();
        $this->assertStringContainsString('ICML 2024', $xml);
        $this->assertStringContainsString('<source>', $xml);
    }

    public function testGetJATSReferenceOmitsEmptyYear(): void
    {
        $this->paper->setLabel('cp2024');
        // year is empty by default — <year> element should not appear
        $xml = $this->paper->getJATSReference();
        $this->assertStringNotContainsString('<year>', $xml);
    }
}


/********************************************************************/
/* MANUSCRIPT TESTS                                                  */
/********************************************************************/

class ManuscriptTest extends TestCase
{
    private Manuscript $ms;

    protected function setUp(): void
    {
        $this->ms = new Manuscript();
    }

    // --- Constructor overrides ---

    public function testConstructorSetsPublicationType(): void
    {
        $this->assertSame('manuscript', $this->ms->getPublicationType());
    }

    public function testConstructorSetsBibtexType(): void
    {
        $this->assertSame('unpublished', $this->ms->getBibtexType());
    }

    // --- Inherited JournalArticle / Reference fields ---

    public function testSetAndGetTitle(): void
    {
        $this->ms->setTitle('Unpublished Work');
        $this->assertSame('Unpublished Work', $this->ms->getTitle());
    }

    public function testSetAndGetYear(): void
    {
        $this->ms->setYear('2023');
        $this->assertSame('2023', $this->ms->getYear());
    }

    // --- getFilterType ---

    public function testGetFilterTypeIsMonograph(): void
    {
        $this->assertSame('type:monograph', $this->ms->getFilterType());
    }

    // --- createFromJatsXMLFragment ---

    public function testCreateFromJatsXMLFragmentSetsArticleTitle(): void
    {
        $xml = simplexml_load_string('<element-citation>
            <article-title>Draft Manuscript</article-title>
        </element-citation>');
        $this->ms->createFromJatsXMLFragment($xml);
        $this->assertSame('Draft Manuscript', $this->ms->getTitle());
    }

    public function testCreateFromJatsXMLFragmentFallsBackToSource(): void
    {
        $xml = simplexml_load_string('<element-citation>
            <source>Fallback Source Title</source>
        </element-citation>');
        $this->ms->createFromJatsXMLFragment($xml);
        $this->assertSame('Fallback Source Title', $this->ms->getTitle());
    }

    public function testCreateFromJatsXMLFragmentFallsBackToPubId(): void
    {
        $xml = simplexml_load_string('<element-citation>
            <pub-id>some-id-as-title</pub-id>
        </element-citation>');
        $this->ms->createFromJatsXMLFragment($xml);
        $this->assertSame('some-id-as-title', $this->ms->getTitle());
    }

    public function testCreateFromJatsXMLFragmentSetsYear(): void
    {
        $xml = simplexml_load_string('<element-citation>
            <year>2022</year>
        </element-citation>');
        $this->ms->createFromJatsXMLFragment($xml);
        $this->assertSame('2022', $this->ms->getYear());
    }

    public function testCreateFromJatsXMLFragmentYearFallbackFromDateInCitation(): void
    {
        $xml = simplexml_load_string('<element-citation>
            <date-in-citation><year>2021</year></date-in-citation>
        </element-citation>');
        $this->ms->createFromJatsXMLFragment($xml);
        $this->assertSame('2021', $this->ms->getYear());
    }

    public function testCreateFromJatsXMLFragmentEmptyFragmentSetsEmpty(): void
    {
        $xml = simplexml_load_string('<element-citation/>');
        $this->ms->createFromJatsXMLFragment($xml);
        $this->assertSame('', $this->ms->getTitle());
        $this->assertSame('', $this->ms->getYear());
    }

    // --- getJATSReference delegates to JournalArticle ---

    public function testGetJATSReferenceReturnsString(): void
    {
        $this->ms->setLabel('ms2023');
        $result = $this->ms->getJATSReference();
        $this->assertIsString($result);
    }

    public function testGetJATSReferencePublicationTypeIsManuscript(): void
    {
        $this->ms->setLabel('ms2023');
        $xml = $this->ms->getJATSReference();
        $this->assertStringContainsString('publication-type="manuscript"', $xml);
    }
}


/********************************************************************/
/* REFERENCE PRESENTATION TESTS                                      */
/********************************************************************/

class ReferencePresentationTest extends TestCase
{
    private JournalArticle $ref;
    private ReferencePresentation $presentation;

    protected function setUp(): void
    {
        $this->ref = new JournalArticle();
        $this->ref->setLabel('smith2024');
        $this->ref->setTitle('A Great Article');
        $this->ref->setYear('2024');
        $this->presentation = new ReferencePresentation($this->ref);
    }

    // --- getShortReference ---

    public function testGetShortReferenceReturnsString(): void
    {
        $this->assertIsString($this->presentation->getShortReference());
    }

    public function testGetShortReferenceContainsTitle(): void
    {
        $html = $this->presentation->getShortReference();
        $this->assertStringContainsString('A Great Article', $html);
    }

    public function testGetShortReferenceContainsYear(): void
    {
        $html = $this->presentation->getShortReference();
        $this->assertStringContainsString('2024', $html);
    }

    public function testGetShortReferenceWrapsInSpan(): void
    {
        $html = $this->presentation->getShortReference();
        $this->assertStringContainsString('<span', $html);
        $this->assertStringContainsString('</span>', $html);
    }

    public function testGetShortReferenceOmitsEmptyFields(): void
    {
        $ref = new JournalArticle();
        $ref->setLabel('empty');
        // year and title both empty — span should contain nothing meaningful
        $pres = new ReferencePresentation($ref);
        $html = $pres->getShortReference();
        $this->assertStringNotContainsString('<b>', $html); // no title bold
    }

    public function testGetShortReferenceEscapesTitle(): void
    {
        $this->ref->setTitle('<script>xss</script>');
        $html = $this->presentation->getShortReference();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testGetShortReferenceForChapterUsesChapterTitle(): void
    {
        $chapter = new Chapter();
        $chapter->setLabel('ch1');
        $chapter->setChapterTitle('My Chapter Title');
        $chapter->setTitle('The Book Title');
        $pres = new ReferencePresentation($chapter);
        $html = $pres->getShortReference();
        $this->assertStringContainsString('My Chapter Title', $html);
    }

    // --- getAsTable ---

    public function testGetAsTableReturnsString(): void
    {
        $this->assertIsString($this->presentation->getAsTable());
    }

    public function testGetAsTableContainsTableTag(): void
    {
        $this->assertStringContainsString('<table', $this->presentation->getAsTable());
    }

    public function testGetAsTableWrappedInDiv(): void
    {
        $html = $this->presentation->getAsTable();
        $this->assertStringContainsString('<div', $html);
    }

    // --- getAsCheckBoxTable ---

    public function testGetAsCheckBoxTableReturnsString(): void
    {
        $this->assertIsString($this->presentation->getAsCheckBoxTable());
    }

    public function testGetAsCheckBoxTableContainsTableTag(): void
    {
        $this->assertStringContainsString('<table', $this->presentation->getAsCheckBoxTable());
    }

    public function testGetAsCheckBoxTableContainsCheckboxes(): void
    {
        $this->ref->setTitle('Test Title');
        $html = $this->presentation->getAsCheckBoxTable();
        $this->assertStringContainsString('type="checkbox"', $html);
    }

    public function testGetAsCheckBoxTableExcludesEmptyFields(): void
    {
        // A freshly constructed ref with only label set — most fields empty
        $ref  = new JournalArticle();
        $ref->setLabel('empty_ref');
        $pres = new ReferencePresentation($ref);
        $html = $pres->getAsCheckBoxTable();
        // Should still return a table (even if empty)
        $this->assertStringContainsString('<table', $html);
    }

    public function testGetAsCheckBoxTableLinkifiesDOIPubId(): void
    {
        $this->ref->setPubIdType('doi');
        $this->ref->setPubId('10.1234/test');
        $html = $this->presentation->getAsCheckBoxTable();
        $this->assertStringContainsString('doi.org', $html);
    }

    public function testGetAsCheckBoxTableLinkifiesPMIDPubId(): void
    {
        $this->ref->setPubIdType('pmid');
        $this->ref->setPubId('12345678');
        $html = $this->presentation->getAsCheckBoxTable();
        $this->assertStringContainsString('pubmed', $html);
    }

    // --- getAsTableRow ---

    public function testGetAsTableRowReturnsString(): void
    {
        $this->assertIsString($this->presentation->getAsTableRow());
    }

    public function testGetAsTableRowContainsTrElements(): void
    {
        $this->assertStringContainsString('<tr>', $this->presentation->getAsTableRow());
    }

    public function testGetAsTableRowContainsYear(): void
    {
        $html = $this->presentation->getAsTableRow();
        $this->assertStringContainsString('2024', $html);
    }

    public function testGetAsTableRowContainsTitle(): void
    {
        $html = $this->presentation->getAsTableRow();
        $this->assertStringContainsString('A Great Article', $html);
    }

    public function testGetAsTableRowContainsExpandToggle(): void
    {
        $html = $this->presentation->getAsTableRow();
        $this->assertStringContainsString('openClose', $html);
    }

    public function testGetAsTableRowContainsHiddenDetailRow(): void
    {
        $html = $this->presentation->getAsTableRow();
        $this->assertStringContainsString('display:none', $html);
    }

    public function testGetAsTableRowEscapesTitle(): void
    {
        $this->ref->setTitle('<b>Bold & Danger</b>');
        $html = $this->presentation->getAsTableRow();
        $this->assertStringNotContainsString('<b>Bold', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
    }
}


/********************************************************************/
/* REFERENCE COLLECTION PRESENTATION TESTS                           */
/********************************************************************/

class ReferenceCollectionPresentationTest extends TestCase
{
    private ReferenceCollection $collection;
    private ReferenceCollectionPresentation $pres;

    protected function setUp(): void
    {
        $this->collection = new ReferenceCollection();
        $this->pres       = new ReferenceCollectionPresentation($this->collection);
    }

    public function testConstructorCreatesInstance(): void
    {
        $this->assertInstanceOf(ReferenceCollectionPresentation::class, $this->pres);
    }

    // --- getAsTableList (empty collection) ---

    public function testGetAsTableListReturnsString(): void
    {
        $this->assertIsString($this->pres->getAsTableList());
    }

    public function testGetAsTableListContainsTableTag(): void
    {
        $this->assertStringContainsString('<table', $this->pres->getAsTableList());
    }

    public function testGetAsTableListEmptyCollectionHasNoRows(): void
    {
        $html = $this->pres->getAsTableList();
        // Empty collection — no <tr> rows inside
        $this->assertStringNotContainsString('<tr>', $html);
    }

    // --- getAsTableList (populated collection) ---

    public function testGetAsTableListWithRefsRendersRows(): void
    {
        $ref = new JournalArticle();
        $ref->setLabel('ref1');
        $ref->setTitle('Article One');
        $ref->setYear('2023');
        $this->collection->offsetSet('ref1', $ref);

        $html = $this->pres->getAsTableList();
        $this->assertStringContainsString('Article One', $html);
        $this->assertStringContainsString('2023', $html);
    }

    public function testGetAsTableListRendersMultipleRefs(): void
    {
        $r1 = new JournalArticle();
        $r1->setLabel('r1');
        $r1->setTitle('First');
        $this->collection->offsetSet('r1', $r1);

        $r2 = new JournalArticle();
        $r2->setLabel('r2');
        $r2->setTitle('Second');
        $this->collection->offsetSet('r2', $r2);

        $html = $this->pres->getAsTableList();
        $this->assertStringContainsString('First', $html);
        $this->assertStringContainsString('Second', $html);
    }
}


/********************************************************************/
/* GALLEY FILE PRESENTATION TESTS                                    */
/********************************************************************/

class GalleyFilePresentationTest extends TestCase
{
    private GalleyFile $galley;
    private GalleyFilePresentation $pres;

    protected function setUp(): void
    {
        $this->galley = new GalleyFile();
        $this->galley->setGalleyFileName('article.pdf');
        $this->galley->setGalleyFileAltText('PDF version');
        $this->galley->setGalleyFileType('application/pdf');
        $this->galley->setGenre('Article Text');
        $this->pres = new GalleyFilePresentation($this->galley);
    }

    public function testConstructorCreatesInstance(): void
    {
        $this->assertInstanceOf(GalleyFilePresentation::class, $this->pres);
    }

    // --- getAsTable ---

    public function testGetAsTableReturnsString(): void
    {
        $this->assertIsString($this->pres->getAsTable());
    }

    public function testGetAsTableContainsTableTag(): void
    {
        $this->assertStringContainsString('<table', $this->pres->getAsTable());
    }

    public function testGetAsTableContainsFileName(): void
    {
        $this->assertStringContainsString('article.pdf', $this->pres->getAsTable());
    }

    public function testGetAsTableContainsAltText(): void
    {
        $this->assertStringContainsString('PDF version', $this->pres->getAsTable());
    }

    public function testGetAsTableContainsType(): void
    {
        $this->assertStringContainsString('application/pdf', $this->pres->getAsTable());
    }

    public function testGetAsTableContainsGenre(): void
    {
        $this->assertStringContainsString('Article Text', $this->pres->getAsTable());
    }

    public function testGetAsTableEscapesSpecialChars(): void
    {
        $this->galley->setGalleyFileName('<script>xss</script>');
        $html = $this->pres->getAsTable();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testGetAsTableLabelsPresent(): void
    {
        $html = $this->pres->getAsTable();
        $this->assertStringContainsString('Name', $html);
        $this->assertStringContainsString('Alt Text', $html);
        $this->assertStringContainsString('Type', $html);
        $this->assertStringContainsString('Genre', $html);
    }
}
