<?php

namespace Biblhertz\Article\Tests;

use PHPUnit\Framework\TestCase;
use Biblhertz\Article\om\Article;
use Biblhertz\Article\om\Author;
use Biblhertz\Article\om\AAbstract;
use Biblhertz\Article\om\Reference;
use Biblhertz\Article\om\JournalArticle;
use Biblhertz\Article\om\Book;
use Biblhertz\Article\om\Chapter;
use Biblhertz\Article\om\GalleyFile;
use Biblhertz\Article\om\Keyword;

/********************************************************************/
/* AUTHOR TESTS                                                      */
/********************************************************************/

class AuthorTest extends TestCase
{
    private Author $author;

    protected function setUp(): void
    {
        $this->author = new Author();
    }

    // --- Basic getters/setters ---

    public function testFirstNameGetSet(): void
    {
        $this->author->setFirstName('Jane');
        $this->assertSame('Jane', $this->author->getFirstName());
    }

    public function testLastNameGetSet(): void
    {
        $this->author->setLastName('Doe');
        $this->assertSame('Doe', $this->author->getLastName());
    }

    public function testVonGetSet(): void
    {
        $this->author->setVon('van');
        $this->assertSame('van', $this->author->getVon());
    }

    public function testEmailGetSet(): void
    {
        $this->author->setEmail('jane@example.com');
        $this->assertSame('jane@example.com', $this->author->getEmail());
    }

    public function testOrcIDGetSet(): void
    {
        $this->author->setOrcID('0000-0001-2345-6789');
        $this->assertSame('0000-0001-2345-6789', $this->author->getOrcID());
    }

    public function testBiographyGetSet(): void
    {
        $this->author->setBiography('Art historian based in Rome.');
        $this->assertSame('Art historian based in Rome.', $this->author->getBiography());
    }

    public function testDeceasedDefaultsFalse(): void
    {
        $this->assertFalse($this->author->getDeceased());
    }

    public function testDeceasedGetSet(): void
    {
        $this->author->setDeceased(true);
        $this->assertTrue($this->author->getDeceased());
    }

    public function testCorrespondingAuthorDefaultsFalse(): void
    {
        $this->assertFalse($this->author->getCorrespondingAuthor());
    }

    public function testCorrespondingAuthorGetSet(): void
    {
        $this->author->setCorrespondingAuthor(true);
        $this->assertTrue($this->author->getCorrespondingAuthor());
    }

    public function testEqualContribDefaultsTrue(): void
    {
        $this->assertTrue($this->author->getEqualContrib());
    }

    public function testEqualContribGetSet(): void
    {
        $this->author->setEqualContrib(false);
        $this->assertFalse($this->author->getEqualContrib());
    }

    public function testUniqueIDGetSet(): void
    {
        $this->author->setUniqueID('u-001');
        $this->assertSame('u-001', $this->author->getUniqueID());
    }

    public function testFullNameGetSet(): void
    {
        $this->author->setFullName('Jane von Doe');
        $this->assertSame('Jane von Doe', $this->author->getFullName());
    }

    // --- getCompleteName ---

    public function testCompleteNameFirstAndLast(): void
    {
        $this->author->setFirstName('Jane');
        $this->author->setLastName('Doe');
        $this->assertSame('Jane Doe', $this->author->getCompleteName());
    }

    public function testCompleteNameWithVon(): void
    {
        $this->author->setFirstName('Johann');
        $this->author->setVon('von');
        $this->author->setLastName('Neumann');
        $this->assertSame('Johann von Neumann', $this->author->getCompleteName());
    }

    public function testCompleteNameLastOnly(): void
    {
        $this->author->setLastName('Aristotle');
        $this->assertSame('Aristotle', $this->author->getCompleteName());
    }

    // --- authorExists ---

    public function testAuthorExistsByEmail(): void
    {
        $this->author->setEmail('jane@example.com');
        $other = new Author();
        $other->setEmail('jane@example.com');
        $this->assertTrue($this->author->authorExists($other));
    }

    public function testAuthorExistsByName(): void
    {
        $this->author->setFirstName('Jane');
        $this->author->setLastName('Doe');
        $other = new Author();
        $other->setFirstName('Jane');
        $other->setLastName('Doe');
        $this->assertTrue($this->author->authorExists($other));
    }

    public function testAuthorNotExists(): void
    {
        $this->author->setFirstName('Jane');
        $this->author->setLastName('Doe');
        $other = new Author();
        $other->setFirstName('John');
        $other->setLastName('Smith');
        $this->assertFalse($this->author->authorExists($other));
    }

    // --- parseBibtexAuthors ---

    public function testParseBibtexSimpleLastFirst(): void
    {
        $authors = Author::parseBibtexAuthors('Smith, John');
        $this->assertCount(1, $authors);
        $this->assertSame('John', $authors[0]->getFirstName());
        $this->assertSame('Smith', $authors[0]->getLastName());
    }

    public function testParseBibtexFirstLast(): void
    {
        $authors = Author::parseBibtexAuthors('John Smith');
        $this->assertCount(1, $authors);
        $this->assertSame('John', $authors[0]->getFirstName());
        $this->assertSame('Smith', $authors[0]->getLastName());
    }

    public function testParseBibtexMultipleAuthors(): void
    {
        $authors = Author::parseBibtexAuthors('Smith, John and Doe, Jane');
        $this->assertCount(2, $authors);
        $this->assertSame('Smith', $authors[0]->getLastName());
        $this->assertSame('Doe', $authors[1]->getLastName());
    }

    public function testParseBibtexWithJr(): void
    {
        $authors = Author::parseBibtexAuthors('Smith, Jr, John');
        $this->assertCount(1, $authors);
        $this->assertSame('Smith', $authors[0]->getLastName());
        $this->assertSame('John', $authors[0]->getFirstName());
    }

    public function testParseBibtexEmpty(): void
    {
        $this->assertSame([], Author::parseBibtexAuthors(''));
    }

    public function testParseBibtexSingleName(): void
    {
        $authors = Author::parseBibtexAuthors('Aristotle');
        $this->assertCount(1, $authors);
        $this->assertSame('Aristotle', $authors[0]->getLastName());
    }

    // --- affiliations ---

    public function testAddAffiliation(): void
    {
        $aff = $this->mockAffiliation('aff1');
        $this->author->addAffiliation($aff);
        $this->assertCount(1, $this->author->getAffiliations());
    }

    public function testAddAffiliationNoDuplicates(): void
    {
        $aff = $this->mockAffiliation('aff1');
        $this->author->addAffiliation($aff);
        $this->author->addAffiliation($aff);
        $this->assertCount(1, $this->author->getAffiliations());
    }

    public function testGetFirstAffiliation(): void
    {
        $aff = $this->mockAffiliation('aff1', 'Bibliotheca Hertziana');
        $this->author->addAffiliation($aff);
        $this->assertSame('Bibliotheca Hertziana', $this->author->getFirstAffiliation());
    }

    public function testGetFirstAffiliationReturnsFalseIfEmpty(): void
    {
        $this->assertFalse($this->author->getFirstAffiliation());
    }

    private function mockAffiliation(string $jatsId, string $name = 'Test Inst'): object
    {
        $aff = $this->createMock(\Biblhertz\Article\om\Affiliation::class);
        $aff->method('getJatsID')->willReturn($jatsId);
        $aff->method('getAffiliation')->willReturn($name);
        $aff->method('affiliationExists')->willReturn(false);
        return $aff;
    }
}


/********************************************************************/
/* REFERENCE TESTS                                                   */
/********************************************************************/

class ReferenceTest extends TestCase
{
    // We test via JournalArticle since Reference is abstract.
    // Where behaviour differs per subclass, separate tests are added.

    private JournalArticle $ref;

    protected function setUp(): void
    {
        $this->ref = new JournalArticle();
    }

    // --- Label ---

    public function testLabelGetSet(): void
    {
        $this->ref->setLabel('Smith2023');
        $this->assertSame('Smith2023', $this->ref->getLabel());
    }

    public function testLabelStripsQuotes(): void
    {
        $this->ref->setLabel('Smith"2023');
        $this->assertSame('Smith_2023', $this->ref->getLabel());
    }

    public function testLabelFallsBackToUniqidWhenEmpty(): void
    {
        $this->ref->setLabel('');
        $this->assertNotEmpty($this->ref->getLabel());
    }

    // --- Core scalar fields ---

    public function testTitleGetSet(): void
    {
        $this->ref->setTitle('Art in the Age of Michelangelo');
        $this->assertSame('Art in the Age of Michelangelo', $this->ref->getTitle());
    }

    public function testYearGetSet(): void
    {
        $this->ref->setYear('2023');
        $this->assertSame('2023', $this->ref->getYear());
    }

    public function testPubIdGetSet(): void
    {
        $this->ref->setPubId('10.1234/test');
        $this->assertSame('10.1234/test', $this->ref->getPubId());
    }

    public function testPubIdTypeGetSet(): void
    {
        $this->ref->setPubIdType('doi');
        $this->assertSame('doi', $this->ref->getPubIdType());
    }

    public function testPublicationTypeGetSet(): void
    {
        $this->ref->setPublicationType('journal-article');
        $this->assertSame('journal-article', $this->ref->getPublicationType());
    }

    public function testPublisherGetSet(): void
    {
        $this->ref->setPublisher('De Gruyter');
        $this->assertSame('De Gruyter', $this->ref->getPublisher());
    }

    public function testAddressGetSet(): void
    {
        $this->ref->setAddress('Berlin');
        $this->assertSame('Berlin', $this->ref->getAddress());
    }

    public function testIssnGetSet(): void
    {
        $this->ref->setIssn('1234-5678');
        $this->assertSame('1234-5678', $this->ref->getIssn());
    }

    public function testIsbnGetSet(): void
    {
        $this->ref->setIsbn('978-3-16-148410-0');
        $this->assertSame('978-3-16-148410-0', $this->ref->getIsbn());
    }

    public function testURIGetSet(): void
    {
        $this->ref->setURI('https://example.com/article');
        $this->assertSame('https://example.com/article', $this->ref->getURI());
    }

    public function testSourceGetSet(): void
    {
        $this->ref->setSource('crossref');
        $this->assertSame('crossref', $this->ref->getSource());
    }

    public function testNoteGetSet(): void
    {
        $this->ref->setNote('See also chapter 3.');
        $this->assertSame('See also chapter 3.', $this->ref->getNote());
    }

    public function testKeywordsGetSet(): void
    {
        $this->ref->setKeywords('iconography, fresco');
        $this->assertSame('iconography, fresco', $this->ref->getKeywords());
    }

    public function testLanguageGetSet(): void
    {
        $this->ref->setLanguage('English');
        $this->assertSame('English', $this->ref->getLanguage());
    }

    public function testAbstractGetSet(): void
    {
        $this->ref->setAbstract('A study of...');
        $this->assertSame('A study of...', $this->ref->getAbstract());
    }

    public function testSeriesGetSet(): void
    {
        $this->ref->setSeries('Studies in Art History');
        $this->assertSame('Studies in Art History', $this->ref->getSeries());
    }

    public function testBibtexTypeGetSet(): void
    {
        $this->ref->setBibtexType('article');
        $this->assertSame('article', $this->ref->getBibtexType());
    }

    // --- Authors ---

    public function testSetAndGetAuthors(): void
    {
        $a = $this->makeAuthor('Jane', 'Doe');
        $this->ref->setAuthors([$a]);
        $this->assertCount(1, $this->ref->getAuthors());
    }

    public function testGetAuthorListCommas(): void
    {
        $this->ref->setAuthors([
            $this->makeAuthor('Jane', 'Doe'),
            $this->makeAuthor('John', 'Smith'),
        ]);
        $list = $this->ref->getAuthorList(true, false);
        $this->assertStringContainsString('Doe', $list);
        $this->assertStringContainsString('Smith', $list);
    }

    public function testGetAuthorListWithAnd(): void
    {
        $this->ref->setAuthors([
            $this->makeAuthor('Jane', 'Doe'),
            $this->makeAuthor('John', 'Smith'),
        ]);
        $list = $this->ref->getAuthorList(false, true);
        $this->assertStringContainsString('and', $list);
    }

    public function testGetPersonList(): void
    {
        $a = $this->makeAuthor('Jane', 'Doe');
        $a->setFullName('Jane Doe');
        $list = $this->ref->getPersonList([$a]);
        $this->assertStringContainsString('Jane Doe', $list);
    }

    // --- Editors ---

    public function testSetAndGetEditors(): void
    {
        $ed = $this->makeAuthor('Ed', 'Itor');
        $this->ref->setEditors([$ed]);
        $this->assertCount(1, $this->ref->getEditors());
    }

    public function testGetEditorList(): void
    {
        $this->ref->setEditors([$this->makeAuthor('Ed', 'Itor')]);
        $list = $this->ref->getEditorList();
        $this->assertStringContainsString('Itor', $list);
    }

    // --- updateFromBibtex ---

    public function testUpdateFromBibtexSetsTitle(): void
    {
        $this->ref->updateFromBibtex(['title' => 'Bibtex Title', 'label' => 'key1']);
        $this->assertSame('Bibtex Title', $this->ref->getTitle());
    }

    public function testUpdateFromBibtexSetsDOI(): void
    {
        $this->ref->updateFromBibtex(['doi' => '10.9999/test', 'label' => 'key1']);
        $this->assertSame('10.9999/test', $this->ref->getPubId());
        $this->assertSame('doi', $this->ref->getPubIdType());
    }

    public function testUpdateFromBibtexSetsYear(): void
    {
        $this->ref->updateFromBibtex(['year' => '1999', 'label' => 'key1']);
        $this->assertSame('1999', $this->ref->getYear());
    }

    public function testUpdateFromBibtexSetsYearFromDate(): void
    {
        $this->ref->updateFromBibtex(['date' => '2021-06-15', 'label' => 'key1']);
        $this->assertSame('2021', $this->ref->getYear());
    }

    public function testUpdateFromBibtexSetsAuthors(): void
    {
        $this->ref->updateFromBibtex(['author' => 'Smith, John', 'label' => 'key1']);
        $this->assertCount(1, $this->ref->getAuthors());
    }

    public function testUpdateFromBibtexSetsPublisher(): void
    {
        $this->ref->updateFromBibtex(['publisher' => 'MIT Press', 'label' => 'key1']);
        $this->assertSame('Mit Press', $this->ref->getPublisher()); // renderBibtexTitle may titlecase
    }

    public function testUpdateFromBibtexUsesSchoolAsPublisher(): void
    {
        $this->ref->updateFromBibtex(['school' => 'Harvard', 'label' => 'key1']);
        $this->assertSame('Harvard', $this->ref->getPublisher());
    }

    public function testUpdateFromBibtexSetsLanguageFromLangid(): void
    {
        $this->ref->updateFromBibtex(['langid' => 'german', 'label' => 'key1']);
        $this->assertSame('German', $this->ref->getLanguage());
    }

    // --- refCheck ---

    public function testRefCheckEmptyByDefault(): void
    {
        $this->assertEmpty($this->ref->getRefCheck());
    }

    public function testRefCheckSetAndGet(): void
    {
        $this->ref->setRefCheck(['score' => 95, 'match' => 'exact']);
        $this->assertSame(95, $this->ref->getRefCheck('score'));
        $this->assertSame('exact', $this->ref->getRefCheck('match'));
    }

    public function testRefCheckGetNullForMissingKey(): void
    {
        $this->assertNull($this->ref->getRefCheck('nonexistent'));
    }

    // --- getAsJson (JournalArticle) ---

    public function testGetAsJsonReturnsValidJson(): void
    {
        $this->ref->setLabel('test-ref');
        $this->ref->setTitle('Test Article');
        $this->ref->setYear('2020');
        $this->ref->setPublicationType('article-journal');
        $decoded = json_decode($this->ref->getAsJson(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('Test Article', $decoded[0]['title']);
        $this->assertSame('test-ref', $decoded[0]['id']);
    }

    public function testGetAsJsonIncludesDOI(): void
    {
        $this->ref->setLabel('r1');
        $this->ref->setPubId('10.1234/x');
        $this->ref->setPubIdType('doi');
        $decoded = json_decode($this->ref->getAsJson(), true);
        $this->assertSame('10.1234/x', $decoded[0]['DOI']);
    }

    public function testGetAsJsonIncludesAuthor(): void
    {
        $this->ref->setLabel('r1');
        $a = $this->makeAuthor('Jane', 'Doe');
        $this->ref->setAuthors([$a]);
        $decoded = json_decode($this->ref->getAsJson(), true);
        $this->assertSame('Doe', $decoded[0]['author'][0]['family']);
    }

    // --- pages (JournalArticle) ---

    public function testUpdatePagesAndGetPages(): void
    {
        $this->ref->updatePages(['pages' => '10-20']);
        $this->assertSame('10 - 20', $this->ref->getPages());
    }

    public function testUpdatePagesWithEnDash(): void
    {
        $this->ref->updatePages(['pages' => '10–20']);
        $this->assertSame('10 - 20', $this->ref->getPages());
    }

    public function testGetPagesNullWhenEmpty(): void
    {
        $this->assertNull($this->ref->getPages());
    }

    // --- helper ---

    private function makeAuthor(string $first, string $last): Author
    {
        $a = new Author();
        $a->setFirstName($first);
        $a->setLastName($last);
        return $a;
    }
}


/********************************************************************/
/* GALLEY FILE TESTS                                                 */
/********************************************************************/

class GalleyFileTest extends TestCase
{
    private GalleyFile $galley;

    protected function setUp(): void
    {
        $this->galley = new GalleyFile();
    }

    // --- Constructor ---

    public function testConstructorAssignsUniqueJatsIDs(): void
    {
        $this->assertNotSame((new GalleyFile())->getJatsID(), (new GalleyFile())->getJatsID());
    }

    public function testDefaultGenreIsArticleText(): void
    {
        $this->assertSame('Article Text', $this->galley->getGenre());
    }

    public function testDefaultLocaleIsEn(): void
    {
        $this->assertSame('en', $this->galley->getLocale());
    }

    // --- Basic getters/setters ---

    public function testNameGetSet(): void
    {
        $this->galley->setName('article.pdf');
        $this->assertSame('article.pdf', $this->galley->getName());
    }

    public function testTypeGetSet(): void
    {
        $this->galley->setType(GalleyFile::$PDF);
        $this->assertSame(GalleyFile::$PDF, $this->galley->getType());
    }

    public function testGenreGetSet(): void
    {
        $this->galley->setGenre('Figure');
        $this->assertSame('Figure', $this->galley->getGenre());
    }

    public function testLocaleGetSet(): void
    {
        $this->galley->setLocale('de');
        $this->assertSame('de', $this->galley->getLocale());
    }

    public function testAltTextGetSet(): void
    {
        $this->galley->setGalleyFileAltText('PDF version of article');
        $this->assertSame('PDF version of article', $this->galley->getGalleyFileAltText());
    }

    public function testFilePathGetSet(): void
    {
        $this->galley->setGalleyFilePath('/var/www/files/article.pdf');
        $this->assertSame('/var/www/files/article.pdf', $this->galley->getGalleyFilePath());
    }

    public function testParentGetSet(): void
    {
        $this->galley->setParent('parent-jats-id');
        $this->assertSame('parent-jats-id', $this->galley->getParent());
    }

    public function testFileIDGetSet(): void
    {
        $this->galley->setFileID(42);
        $this->assertSame(42, $this->galley->getFileID());
    }

    // --- Type constants are distinct ---

    public function testTypeConstantsAreDistinct(): void
    {
        $types = [
            GalleyFile::$XML,
            GalleyFile::$PDF,
            GalleyFile::$HTML,
            GalleyFile::$COVER_IMAGE,
            GalleyFile::$IMAGE,
            GalleyFile::$JATSXML,
        ];
        $this->assertSame(count($types), count(array_unique($types)));
    }

    // --- getAllowedGenres ---

    public function testGetAllowedGenresReturnsArray(): void
    {
        $genres = GalleyFile::getAllowedGenres();
        $this->assertIsArray($genres);
        $this->assertNotEmpty($genres);
    }

    public function testGetAllowedGenresContainsExpected(): void
    {
        $genres = GalleyFile::getAllowedGenres();
        $this->assertContains('Article Text', $genres);
        $this->assertContains('Cover Image', $genres);
    }

    // --- getGalleyFileName / getGalleyFileType from path ---

    public function testGetGalleyFileName(): void
    {
        $this->galley->setGalleyFilePath('/some/path/article.pdf');
        $this->assertSame('article.pdf', $this->galley->getGalleyFileName());
    }

    public function testGetGalleyFileTypeExtension(): void
    {
        $this->galley->setGalleyFilePath('/some/path/article.pdf');
        $this->assertSame('pdf', $this->galley->getGalleyFileType());
    }

    // --- updateGalley ---

    public function testUpdateGalleySetsAltTextAndName(): void
    {
        $this->galley->setType(GalleyFile::$PDF);
        $this->galley->updateGalley([
            'alt_text' => 'Updated alt',
            'name'     => 'updated.pdf',
            'genre'    => 'Article Text',
            'locale'   => 'en',
        ]);
        $this->assertSame('Updated alt', $this->galley->getGalleyFileAltText());
        $this->assertSame('updated.pdf', $this->galley->getName());
    }

    public function testUpdateGalleryPromotesImageToCoverImage(): void
    {
        $this->galley->setType(GalleyFile::$IMAGE);
        $this->galley->updateGalley([
            'alt_text' => '',
            'name'     => 'cover.jpg',
            'genre'    => 'Cover Image',
            'locale'   => 'en',
        ]);
        $this->assertSame(GalleyFile::$COVER_IMAGE, $this->galley->getType());
    }

    public function testUpdateGalleryDemotesCoverImageToImage(): void
    {
        $this->galley->setType(GalleyFile::$COVER_IMAGE);
        $this->galley->updateGalley([
            'alt_text' => '',
            'name'     => 'figure.jpg',
            'genre'    => 'Figure',
            'locale'   => 'en',
        ]);
        $this->assertSame(GalleyFile::$IMAGE, $this->galley->getType());
    }
}


/********************************************************************/
/* ARTICLE TESTS                                                     */
/********************************************************************/

class ArticleTest extends TestCase
{
    private Article $article;

    protected function setUp(): void
    {
        $this->article = new Article();
    }

    // --- Scalar properties ---

    public function testTitleGetSet(): void
    {
        $this->article->setTitle('A Study of Renaissance Painting');
        $this->assertSame('A Study of Renaissance Painting', $this->article->getTitle());
    }

    public function testSubTitleGetSet(): void
    {
        $this->article->setSubTitle('Focus on Florentine Masters');
        $this->assertSame('Focus on Florentine Masters', $this->article->getSubTitle());
    }

    public function testDOIGetSet(): void
    {
        $this->article->setDOI('10.1234/example.2023.001');
        $this->assertSame('10.1234/example.2023.001', $this->article->getDOI());
    }

    public function testVolumeIssueGetSet(): void
    {
        $this->article->setVolume('12');
        $this->article->setIssue('3');
        $this->assertSame('12', $this->article->getVolume());
        $this->assertSame('3', $this->article->getIssue());
    }

    public function testDateYearMonthDayGetSet(): void
    {
        $this->article->setDate('2023-07-10');
        $this->article->setYear(2023);
        $this->article->setMonth('7');
        $this->article->setDay(10);
        $this->assertSame('2023-07-10', $this->article->getDate());
        $this->assertSame(2023, $this->article->getYear());
    }

    public function testPagesGetSet(): void
    {
        $this->article->setStartPage('42');
        $this->article->setEndPage('67');
        $this->assertSame('42', $this->article->getStartPage());
        $this->assertSame('67', $this->article->getEndPage());
    }

    public function testLicenseGetSet(): void
    {
        $this->article->setLicenseUrl('https://creativecommons.org/licenses/by/4.0/');
        $this->article->setLicenseType('CC BY 4.0');
        $this->assertSame('https://creativecommons.org/licenses/by/4.0/', $this->article->getLicenseUrl());
        $this->assertSame('CC BY 4.0', $this->article->getLicenseType());
    }

    public function testCopyrightGetSet(): void
    {
        $this->article->setCopyRightHolder('Bibliotheca Hertziana');
        $this->article->setCopyRightYear('2023');
        $this->assertSame('Bibliotheca Hertziana', $this->article->getCopyRightHolder());
        $this->assertSame('2023', $this->article->getCopyRightYear());
    }

    // --- Read-only ---

    public function testReadOnlyDefaultsFalse(): void
    {
        $this->assertFalse($this->article->isReadOnly());
    }

    public function testSetReadOnly(): void
    {
        $this->article->setReadOnly(true);
        $this->assertTrue($this->article->isReadOnly());
    }

    // --- Keywords ---

    public function testAddKeyword(): void
    {
        $this->article->setKeyword('iconography');
        $this->assertCount(1, $this->article->getKeywords());
    }

    public function testAddMultipleKeywords(): void
    {
        $this->article->setKeyword('iconography');
        $this->article->setKeyword('Renaissance');
        $this->assertCount(2, $this->article->getKeywords());
    }

    public function testRemoveKeyword(): void
    {
        $this->article->setKeyword('iconography');
        $key = array_key_first($this->article->getKeywords());
        $this->article->removeKeyword($key);
        $this->assertCount(0, $this->article->getKeywords());
    }

    public function testAddItemKeyword(): void
    {
        $this->assertTrue($this->article->addItem('Keyword', ['name' => 'fresco']));
        $this->assertCount(1, $this->article->getKeywords());
    }

    // --- Authors ---

    public function testAuthorsEmptyByDefault(): void
    {
        $this->assertEmpty($this->article->getAuthors());
    }

    public function testAddAuthor(): void
    {
        $this->article->addAuthor($this->makeAuthor('jane@test.com', 'Jane', 'Doe'));
        $this->assertCount(1, $this->article->getAuthors());
    }

    public function testAddAuthorNoDuplicatesByEmail(): void
    {
        $a = $this->makeAuthor('jane@test.com', 'Jane', 'Doe');
        $this->article->addAuthor($a);
        $this->article->addAuthor($a);
        $this->assertCount(1, $this->article->getAuthors());
    }

    public function testAddAuthorNoDuplicatesByName(): void
    {
        $this->article->addAuthor($this->makeAuthor('', 'Jane', 'Doe'));
        $this->article->addAuthor($this->makeAuthor('', 'Jane', 'Doe'));
        $this->assertCount(1, $this->article->getAuthors());
    }

    public function testGetAuthorByJatsID(): void
    {
        $author = $this->makeAuthor('jane@test.com', 'Jane', 'Doe', 'A1');
        $this->article->addAuthor($author);
        $this->assertSame($author, $this->article->getAuthorByJatsID('A1'));
    }

    public function testGetAuthorByJatsIDNotFound(): void
    {
        $this->assertFalse($this->article->getAuthorByJatsID('nonexistent'));
    }

    public function testGetCorrespondingAuthor(): void
    {
        $author = $this->makeAuthor('jane@test.com', 'Jane', 'Doe');
        $author->setCorrespondingAuthor(true);
        $this->article->addAuthor($author);
        $this->assertSame($author, $this->article->getCorrespondingAuthor());
    }

    public function testGetCorrespondingAuthorReturnsFalseIfNone(): void
    {
        $this->article->addAuthor($this->makeAuthor('jane@test.com', 'Jane', 'Doe'));
        $this->assertFalse($this->article->getCorrespondingAuthor());
    }

    // --- References (real JournalArticle objects) ---

    public function testAddReference(): void
    {
        $ref = $this->makeRef('ref1');
        $this->article->addReference($ref);
        $this->assertSame($ref, $this->article->getReferencefromKey('ref1'));
    }

    public function testGetReferencefromKeyNotFound(): void
    {
        $this->assertFalse($this->article->getReferencefromKey('missing'));
    }

    public function testSetReferencesReplacesCollection(): void
    {
        $ref1 = $this->makeRef('ref1');
        $ref2 = $this->makeRef('ref2');
        $this->article->addReference($ref1);
        $this->article->setReferences([$ref2]);
        $this->assertFalse($this->article->getReferencefromKey('ref1'));
        $this->assertSame($ref2, $this->article->getReferencefromKey('ref2'));
    }

    // --- Galley files ---

    public function testGalleyFilesEmptyByDefault(): void
    {
        $this->assertEmpty($this->article->getGalleyFiles());
    }

    public function testAddGalleyFile(): void
    {
        $this->article->addGalleyFile($this->makeGalley('G1', GalleyFile::$PDF));
        $this->assertCount(1, $this->article->getGalleyFiles());
    }

    public function testGetCoverImageFile(): void
    {
        $cover = $this->makeGalley('COV1', GalleyFile::$COVER_IMAGE);
        $this->article->addGalleyFile($cover);
        $this->assertSame($cover, $this->article->getCoverImageFile());
    }

    public function testGetJATSXMLFile(): void
    {
        $jats = $this->makeGalley('JATS1', GalleyFile::$JATSXML);
        $this->article->addGalleyFile($jats);
        $this->assertSame($jats, $this->article->getJATSXMLFile());
    }

    public function testRemoveItemGalleyFile(): void
    {
        $this->article->addGalleyFile($this->makeGalley('JATS1', GalleyFile::$JATSXML));
        $this->article->addGalleyFile($this->makeGalley('G1', GalleyFile::$PDF));
        $this->assertTrue($this->article->removeItem('GalleyFile', 'G1'));
        $this->assertFalse($this->article->getGalleyFileByJatsID('G1'));
    }

    // --- Constructor ---

    public function testConstructorSetsEmptyDefaults(): void
    {
        $a = new Article();
        $this->assertSame('', $a->getTitle());
        $this->assertEmpty($a->getAuthors());
        $this->assertFalse($a->isReadOnly());
    }

    public function testConstructorAssignsUniqueJatsIDs(): void
    {
        $this->assertNotSame((new Article())->getJatsID(), (new Article())->getJatsID());
    }

    /****************************************************************/
    /* HELPERS                                                       */
    /****************************************************************/

    private function makeAuthor(string $email, string $first, string $last, string $jatsId = ''): Author
    {
        $a = new Author();
        $a->setEmail($email);
        $a->setFirstName($first);
        $a->setLastName($last);
        if ($jatsId) $a->setJatsID($jatsId);
        return $a;
    }

    private function makeRef(string $label): JournalArticle
    {
        $ref = new JournalArticle();
        $ref->setLabel($label);
        return $ref;
    }

    private function makeGalley(string $jatsId, string $type, string $genre = 'Article Text'): GalleyFile
    {
        $g = new GalleyFile();
        $g->setJatsID($jatsId);
        $g->setType($type);
        $g->setGenre($genre);
        return $g;
    }
}
?>