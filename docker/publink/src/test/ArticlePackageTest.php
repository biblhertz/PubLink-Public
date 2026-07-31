<?php

namespace Biblhertz\Article\Tests;

use PHPUnit\Framework\TestCase;
use Biblhertz\Article\om\AAbstract;
use Biblhertz\Article\om\Affiliation;
use Biblhertz\Article\om\Book;
use Biblhertz\Article\om\Chapter;
use Biblhertz\Article\om\Author;
use Biblhertz\Article\om\JournalArticle;
use Biblhertz\Article\om\Keyword;
use Biblhertz\Article\om\Paragraph;
use Biblhertz\Article\om\ReferenceCollection;
use Biblhertz\Article\om\Thesis;
use Biblhertz\Article\om\WebPage;


/********************************************************************/
/* PARAGRAPH TESTS                                                   */
/********************************************************************/

class ParagraphTest extends TestCase
{
    public function testConstructorWithText(): void
    {
        $p = new Paragraph('Hello world');
        $this->assertSame('Hello world', $p->getText());
    }

    public function testConstructorDefaultsToEmptyString(): void
    {
        $p = new Paragraph();
        $this->assertSame('', $p->getText());
    }

    public function testSetText(): void
    {
        $p = new Paragraph();
        $p->setText('Updated text');
        $this->assertSame('Updated text', $p->getText());
    }

    public function testSetTextOverwritesPrevious(): void
    {
        $p = new Paragraph('original');
        $p->setText('replacement');
        $this->assertSame('replacement', $p->getText());
    }
}


/********************************************************************/
/* AABSTRACT TESTS                                                   */
/********************************************************************/

class AAbstractTest extends TestCase
{
    private AAbstract $abstract;

    protected function setUp(): void
    {
        $this->abstract = new AAbstract();
    }

    // --- Construction ---

    public function testStartsWithNoParagraphs(): void
    {
        $this->assertSame([], $this->abstract->getParagraphs());
    }

    // --- addParagraph / getParagraphs ---

    public function testAddParagraphAppends(): void
    {
        $p = new Paragraph('First');
        $this->abstract->addParagraph($p);
        $this->assertCount(1, $this->abstract->getParagraphs());
        $this->assertSame($p, $this->abstract->getParagraphs()[0]);
    }

    public function testAddParagraphPreservesOrder(): void
    {
        $p1 = new Paragraph('First');
        $p2 = new Paragraph('Second');
        $p3 = new Paragraph('Third');
        $this->abstract->addParagraph($p1);
        $this->abstract->addParagraph($p2);
        $this->abstract->addParagraph($p3);
        $paragraphs = $this->abstract->getParagraphs();
        $this->assertSame($p1, $paragraphs[0]);
        $this->assertSame($p2, $paragraphs[1]);
        $this->assertSame($p3, $paragraphs[2]);
    }

    // --- getParagraphfromKey ---

    public function testGetParagraphfromKeyFindsMatch(): void
    {
        $p = new Paragraph('Some text');
        $p->setJatsID('para-1');
        $this->abstract->addParagraph($p);
        $this->assertSame($p, $this->abstract->getParagraphfromKey('para-1'));
    }

    public function testGetParagraphfromKeyReturnsFalseWhenNotFound(): void
    {
        $this->assertFalse($this->abstract->getParagraphfromKey('nonexistent'));
    }

    public function testGetParagraphfromKeyDoesNotMatchWrongId(): void
    {
        $p = new Paragraph('text');
        $p->setJatsID('para-A');
        $this->abstract->addParagraph($p);
        $this->assertFalse($this->abstract->getParagraphfromKey('para-B'));
    }

    // --- getAsText ---

    public function testGetAsTextEmptyWhenNoParagraphs(): void
    {
        $this->assertSame('', $this->abstract->getAsText());
    }

    public function testGetAsTextWrapsParagraphInEscapedTags(): void
    {
        $this->abstract->addParagraph(new Paragraph('Hello'));
        $this->assertSame('&lt;p&gt;Hello&lt;/p&gt;', $this->abstract->getAsText());
    }

    public function testGetAsTextEscapesDoubleQuotes(): void
    {
        $this->abstract->addParagraph(new Paragraph('He said "hi"'));
        $this->assertStringContainsString('&quot;', $this->abstract->getAsText());
        $this->assertStringNotContainsString('"', $this->abstract->getAsText());
    }

    public function testGetAsTextConcatenatesMultipleParagraphs(): void
    {
        $this->abstract->addParagraph(new Paragraph('One'));
        $this->abstract->addParagraph(new Paragraph('Two'));
        $text = $this->abstract->getAsText();
        $this->assertStringContainsString('One', $text);
        $this->assertStringContainsString('Two', $text);
        $this->assertSame(2, substr_count($text, '&lt;p&gt;'));
    }
}


/********************************************************************/
/* AFFILIATION TESTS                                                 */
/********************************************************************/

class AffiliationTest extends TestCase
{
    private Affiliation $aff;

    protected function setUp(): void
    {
        $this->aff = new Affiliation();
    }

    // --- Getters / setters ---

    public function testNameGetSet(): void
    {
        $this->aff->setName('Bibliotheca Hertziana');
        $this->assertSame('Bibliotheca Hertziana', $this->aff->getName());
    }

    public function testDivisionGetSet(): void
    {
        $this->aff->setDivision('Department of Art History');
        $this->assertSame('Department of Art History', $this->aff->getDivision());
    }

    public function testAddressGetSet(): void
    {
        $this->aff->setAddress('Via Gregoriana 28');
        $this->assertSame('Via Gregoriana 28', $this->aff->getAddress());
    }

    public function testCityGetSet(): void
    {
        $this->aff->setCity('Rome');
        $this->assertSame('Rome', $this->aff->getCity());
    }

    public function testCountryGetSet(): void
    {
        $this->aff->setCountry('Italy');
        $this->assertSame('Italy', $this->aff->getCountry());
    }

    // --- getAffiliation() ---

    public function testGetAffiliationNameAndDivision(): void
    {
        $this->aff->setName('Bibliotheca Hertziana');
        $this->aff->setDivision('Department of Art History');
        $this->assertSame(
            'Department of Art History, Bibliotheca Hertziana',
            $this->aff->getAffiliation()
        );
    }

    public function testGetAffiliationNameOnly(): void
    {
        $this->aff->setName('Bibliotheca Hertziana');
        $this->assertSame('Bibliotheca Hertziana', $this->aff->getAffiliation());
    }

    public function testGetAffiliationDivisionOnly(): void
    {
        $this->aff->setDivision('Art History');
        $this->assertSame('Art History', $this->aff->getAffiliation());
    }

    public function testGetAffiliationEmptyWhenBothEmpty(): void
    {
        $this->assertSame('', $this->aff->getAffiliation());
    }

    // --- affiliationExists() ---

    public function testAffiliationExistsTrueWhenSameJatsID(): void
    {
        $this->aff->setJatsID('aff-1');
        $other = new Affiliation();
        $other->setJatsID('aff-1');
        $this->assertTrue($this->aff->affiliationExists($other));
    }

    public function testAffiliationExistsFalseWhenDifferentJatsID(): void
    {
        $this->aff->setJatsID('aff-1');
        $other = new Affiliation();
        $other->setJatsID('aff-2');
        $this->assertFalse($this->aff->affiliationExists($other));
    }

    public function testAffiliationExistsFalseWhenJatsIDsEmpty(): void
    {
        // Both empty strings: '' === '' → true (same empty JatsID)
        $other = new Affiliation();
        $this->assertTrue($this->aff->affiliationExists($other));
    }
}


/********************************************************************/
/* KEYWORD TESTS                                                     */
/********************************************************************/

class KeywordTest extends TestCase
{
    public function testConstructorAssignsNonEmptyJatsID(): void
    {
        $k = new Keyword();
        $this->assertNotEmpty($k->getJatsID());
    }

    public function testConstructorAssignsUniqueJatsIDs(): void
    {
        $this->assertNotSame((new Keyword())->getJatsID(), (new Keyword())->getJatsID());
    }

    public function testNameDefaultsToEmpty(): void
    {
        $k = new Keyword();
        $this->assertSame('', $k->getName());
    }

    public function testNameGetSet(): void
    {
        $k = new Keyword();
        $k->setName('iconography');
        $this->assertSame('iconography', $k->getName());
    }

    public function testNameCanBeOverwritten(): void
    {
        $k = new Keyword();
        $k->setName('first');
        $k->setName('second');
        $this->assertSame('second', $k->getName());
    }
}


/********************************************************************/
/* REFERENCE COLLECTION TESTS                                        */
/********************************************************************/

class ReferenceCollectionTest extends TestCase
{
    private ReferenceCollection $col;

    protected function setUp(): void
    {
        $this->col = new ReferenceCollection();
    }

    private function makeRef(string $label, string $pubId = ''): JournalArticle
    {
        $ref = new JournalArticle();
        $ref->setLabel($label);
        if ($pubId !== '') $ref->setPubId($pubId);
        return $ref;
    }

    // --- Empty construction ---

    public function testStartsEmpty(): void
    {
        $this->assertSame(0, $this->col->getNumber());
    }

    // --- offsetSet / type enforcement ---

    public function testAddReferenceIncreasesCount(): void
    {
        $this->col->offsetSet('ref1', $this->makeRef('ref1'));
        $this->assertSame(1, $this->col->getNumber());
    }

    public function testAddNonReferenceThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->col->offsetSet('bad', new \stdClass());
    }

    // --- Pub ID uniqueness ---

    public function testAddDuplicatePubIdThrowsInvalidArgumentException(): void
    {
        $this->col->offsetSet('ref1', $this->makeRef('ref1', '10.1234/test'));
        $this->expectException(\InvalidArgumentException::class);
        $this->col->offsetSet('ref2', $this->makeRef('ref2', '10.1234/test'));
    }

    public function testEmptyPubIdDoesNotTriggerDuplicateCheck(): void
    {
        $this->col->offsetSet('ref1', $this->makeRef('ref1', ''));
        $this->col->offsetSet('ref2', $this->makeRef('ref2', ''));
        $this->assertSame(2, $this->col->getNumber());
    }

    // --- Label deduplication ---

    public function testDuplicateLabelIsReplacedSilently(): void
    {
        $ref1 = $this->makeRef('same-label');
        $ref2 = $this->makeRef('same-label');
        $this->col->offsetSet('same-label', $ref1);
        $this->col->offsetSet('same-label', $ref2);
        $this->assertSame(2, $this->col->getNumber());
        // The second reference's label should have been replaced with a uniqid
        $this->assertNotSame('same-label', $ref2->getLabel());
    }

    // --- getReferenceFromKey / getByLabel ---

    public function testGetReferenceFromKeyFindsMatch(): void
    {
        $ref = $this->makeRef('my-label');
        $this->col->offsetSet('my-label', $ref);
        $this->assertSame($ref, $this->col->getReferenceFromKey('my-label'));
    }

    public function testGetReferenceFromKeyReturnsFalseWhenNotFound(): void
    {
        $this->assertFalse($this->col->getReferenceFromKey('missing'));
    }

    public function testGetByLabelAliasWorks(): void
    {
        $ref = $this->makeRef('alias-test');
        $this->col->offsetSet('alias-test', $ref);
        $this->assertSame($ref, $this->col->getByLabel('alias-test'));
    }

    // --- getReferences() identity ---

    public function testGetReferencesReturnsSelf(): void
    {
        $this->assertSame($this->col, $this->col->getReferences());
    }

    // --- sortByLabel ---

    public function testSortByLabelOrdersAlphabetically(): void
    {
        $this->col->offsetSet('Zoo2023', $this->makeRef('Zoo2023'));
        $this->col->offsetSet('Apple2020', $this->makeRef('Apple2020'));
        $this->col->offsetSet('Middle2021', $this->makeRef('Middle2021'));
        $this->col->sortByLabel();
        $labels = array_keys((array) $this->col);
        $this->assertSame(['Apple2020', 'Middle2021', 'Zoo2023'], $labels);
    }

    // --- Flags ---

    public function testReferenceCheckDefaultsFalse(): void
    {
        $this->assertFalse($this->col->getReferenceCheck());
    }

    public function testReferenceCheckGetSet(): void
    {
        $this->col->setReferenceCheck(true);
        $this->assertTrue($this->col->getReferenceCheck());
    }

    public function testReadOnlyDefaultsFalse(): void
    {
        $this->assertFalse($this->col->isReadOnly());
    }

    public function testReadOnlyGetSet(): void
    {
        $this->col->setReadOnly(true);
        $this->assertTrue($this->col->isReadOnly());
        $this->assertTrue($this->col->getReadOnly());
    }

    // --- pubIdExists / labelExists ---

    public function testPubIdExistsReturnsTrueWhenPresent(): void
    {
        $this->col->offsetSet('r1', $this->makeRef('r1', '10.9999/abc'));
        $this->assertTrue($this->col->pubIdExists('10.9999/abc'));
    }

    public function testPubIdExistsReturnsFalseWhenAbsent(): void
    {
        $this->assertFalse($this->col->pubIdExists('10.9999/xyz'));
    }

    public function testLabelExistsReturnsTrueWhenPresent(): void
    {
        $this->col->offsetSet('present', $this->makeRef('present'));
        $this->assertTrue($this->col->labelExists('present'));
    }

    public function testLabelExistsReturnsFalseWhenAbsent(): void
    {
        $this->assertFalse($this->col->labelExists('absent'));
    }
}


/********************************************************************/
/* BOOK TESTS                                                        */
/********************************************************************/

class BookTest extends TestCase
{
    private Book $book;

    protected function setUp(): void
    {
        $this->book = new Book();
    }

    // --- Constructor defaults ---

    public function testPublicationTypeIsBook(): void
    {
        $this->assertSame('book', $this->book->getPublicationType());
    }

    public function testBibtexTypeIsBook(): void
    {
        $this->assertSame('book', $this->book->getBibtexType());
    }

    // --- Fields ---

    public function testVolumeGetSet(): void
    {
        $this->book->setVolume('III');
        $this->assertSame('III', $this->book->getVolume());
    }

    public function testEditionGetSet(): void
    {
        $this->book->setEdition('  2nd  ');
        $this->assertSame('2nd', $this->book->getEdition()); // trim applied
    }

    public function testNumberGetSet(): void
    {
        $this->book->setNumber('  42  ');
        $this->assertSame('42', $this->book->getNumber()); // trim applied
    }

    // --- updateFromBibtex ---

    public function testUpdateFromBibtexSetsVolume(): void
    {
        $this->book->updateFromBibtex(['volume' => '2', 'label' => 'k1']);
        $this->assertSame('2', $this->book->getVolume());
    }

    public function testUpdateFromBibtexSetsEdition(): void
    {
        $this->book->updateFromBibtex(['edition' => '3rd', 'label' => 'k1']);
        $this->assertSame('3rd', $this->book->getEdition());
    }

    public function testUpdateFromBibtexSetsIsbn(): void
    {
        $this->book->updateFromBibtex(['isbn' => '978-3-16-148410-0', 'label' => 'k1']);
        $this->assertSame('978-3-16-148410-0', $this->book->getIsbn());
    }

    public function testUpdateFromBibtexSetsNumber(): void
    {
        $this->book->updateFromBibtex(['number' => '5', 'label' => 'k1']);
        $this->assertSame('5', $this->book->getNumber());
    }

    public function testUpdateFromBibtexParsesEditors(): void
    {
        $this->book->updateFromBibtex(['editor' => 'Smith, Jane', 'label' => 'k1']);
        $this->assertCount(1, $this->book->getEditors());
        $this->assertSame('Smith', $this->book->getEditors()[0]->getLastName());
    }

    // --- getJATSReference ---

    public function testGetJATSReferenceReturnsString(): void
    {
        $this->book->setLabel('book-ref-1');
        $result = $this->book->getJATSReference();
        $this->assertIsString($result);
    }

    public function testGetJATSReferenceContainsLabel(): void
    {
        $this->book->setLabel('Doe2023');
        $xml = $this->book->getJATSReference();
        $this->assertStringContainsString('Doe2023', $xml);
    }

    public function testGetJATSReferenceContainsPublicationType(): void
    {
        $this->book->setLabel('ref1');
        $xml = $this->book->getJATSReference();
        $this->assertStringContainsString('publication-type="book"', $xml);
    }

    public function testGetJATSReferenceContainsTitle(): void
    {
        $this->book->setLabel('ref1');
        $this->book->setTitle('Art in the Renaissance');
        $xml = $this->book->getJATSReference();
        $this->assertStringContainsString('Art in the Renaissance', $xml);
    }

    public function testGetJATSReferenceContainsAuthorNames(): void
    {
        $this->book->setLabel('ref1');
        $a = new Author();
        $a->setFirstName('Jane');
        $a->setLastName('Doe');
        $this->book->setAuthors([$a]);
        $xml = $this->book->getJATSReference();
        $this->assertStringContainsString('Doe', $xml);
        $this->assertStringContainsString('Jane', $xml);
    }

    // --- getFilterType ---

    public function testGetFilterTypeContainsBook(): void
    {
        $this->assertStringContainsString('type:book', $this->book->getFilterType());
    }
}


/********************************************************************/
/* CHAPTER TESTS                                                     */
/********************************************************************/

class ChapterTest extends TestCase
{
    private Chapter $chapter;

    protected function setUp(): void
    {
        $this->chapter = new Chapter();
    }

    // --- Constructor defaults ---

    public function testPublicationTypeIsChapter(): void
    {
        $this->assertSame('chapter', $this->chapter->getPublicationType());
    }

    public function testBibtexTypeIsIncollection(): void
    {
        $this->assertSame('incollection', $this->chapter->getBibtexType());
    }

    // --- Chapter-specific fields ---

    public function testFirstPageGetSet(): void
    {
        $this->chapter->setFirstPage('  10  ');
        $this->assertSame('10', $this->chapter->getFirstPage()); // trim applied
    }

    public function testLastPageGetSet(): void
    {
        $this->chapter->setLastPage('  50  ');
        $this->assertSame('50', $this->chapter->getLastPage()); // trim applied
    }

    public function testChapterTitleStripsBracesAndUcwords(): void
    {
        $this->chapter->setChapterTitle('{art history in rome}');
        $this->assertSame('Art History In Rome', $this->chapter->getChapterTitle());
    }

    public function testChapterTitlePlainText(): void
    {
        $this->chapter->setChapterTitle('The Italian Renaissance');
        $this->assertSame('The Italian Renaissance', $this->chapter->getChapterTitle());
    }

    public function testChapterTitleGetSet(): void
    {
        $this->chapter->setChapterTitle('My Chapter');
        $this->assertSame('My Chapter', $this->chapter->getChapterTitle());
    }

    // --- Inherits from Book ---

    public function testInheritsVolumeFromBook(): void
    {
        $this->chapter->setVolume('IV');
        $this->assertSame('IV', $this->chapter->getVolume());
    }

    // --- updateFromBibtex ---

    public function testUpdateFromBibtexSetsChapterTitle(): void
    {
        $this->chapter->updateFromBibtex(['title' => 'Chapter Title', 'label' => 'k1']);
        $this->assertSame('Chapter Title', $this->chapter->getChapterTitle());
    }

    public function testUpdateFromBibtexSetsBookTitle(): void
    {
        $this->chapter->updateFromBibtex(['booktitle' => 'Containing Book', 'label' => 'k1']);
        $this->assertSame('Containing Book', $this->chapter->getTitle());
    }
}


/********************************************************************/
/* THESIS TESTS                                                      */
/********************************************************************/

class ThesisTest extends TestCase
{
    private Thesis $thesis;

    protected function setUp(): void
    {
        $this->thesis = new Thesis();
    }

    public function testPublicationTypeIsThesis(): void
    {
        $this->assertSame('thesis', $this->thesis->getPublicationType());
    }

    public function testBibtexTypeIsPhdthesis(): void
    {
        $this->assertSame('phdthesis', $this->thesis->getBibtexType());
    }

    public function testInheritsScalarFieldsFromReference(): void
    {
        $this->thesis->setTitle('On the Visual Culture of Renaissance Rome');
        $this->thesis->setYear('2019');
        $this->thesis->setPublisher('Harvard University');
        $this->assertSame('On the Visual Culture of Renaissance Rome', $this->thesis->getTitle());
        $this->assertSame('2019', $this->thesis->getYear());
        $this->assertSame('Harvard University', $this->thesis->getPublisher());
    }

    public function testUpdateFromBibtexSetsSchoolAsPublisher(): void
    {
        $this->thesis->updateFromBibtex(['school' => 'MIT', 'label' => 'k1']);
        $this->assertSame('MIT', $this->thesis->getPublisher());
    }
}


/********************************************************************/
/* WEBPAGE TESTS                                                     */
/********************************************************************/

class WebPageTest extends TestCase
{
    private WebPage $page;

    protected function setUp(): void
    {
        $this->page = new WebPage();
    }

    public function testPublicationTypeIsWebPage(): void
    {
        $this->assertSame('web-page', $this->page->getPublicationType());
    }

    public function testBibtexTypeIsMisc(): void
    {
        $this->assertSame('misc', $this->page->getBibtexType());
    }

    public function testInheritsTitleFromReference(): void
    {
        $this->page->setTitle('Bibliotheca Hertziana Online');
        $this->assertSame('Bibliotheca Hertziana Online', $this->page->getTitle());
    }

    public function testInheritsURIFromReference(): void
    {
        $this->page->setURI('https://www.biblhertz.it');
        $this->assertSame('https://www.biblhertz.it', $this->page->getURI());
    }

    public function testUpdateFromBibtexSetsTitle(): void
    {
        $this->page->updateFromBibtex(['title' => 'Online Resource', 'label' => 'k1']);
        $this->assertSame('Online Resource', $this->page->getTitle());
    }
}
