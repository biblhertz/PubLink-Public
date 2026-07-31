<?php

namespace Biblhertz\Article\Tests;

use PHPUnit\Framework\TestCase;
use Biblhertz\Article\Adapters\JATSXMLValidator;
use Biblhertz\Article\Adapters\OMToCSVAdapter;
use Biblhertz\Article\Adapters\OMToDataCiteAdapter;
use Biblhertz\Article\Adapters\BibtexToReferenceCollectionAdapter;
use Biblhertz\Article\Adapters\CSVToOMAdapter;
use Biblhertz\Article\om\Article;
use Biblhertz\Article\om\JournalArticle;
use Biblhertz\Publink\utilities\Logger;


/********************************************************************/
/* JATS XML VALIDATOR TESTS                                          */
/********************************************************************/

class JATSXMLValidatorTest extends TestCase
{
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = new Logger();
    }

    public function testConstructorAcceptsLogger(): void
    {
        $v = new JATSXMLValidator($this->logger);
        $this->assertInstanceOf(JATSXMLValidator::class, $v);
    }

    public function testSetXMLStringMethodExists(): void
    {
        $v = new JATSXMLValidator($this->logger);
        $this->assertTrue(method_exists($v, 'setXMLString'));
    }

    public function testValidateJATSXMLMethodExists(): void
    {
        $v = new JATSXMLValidator($this->logger);
        $this->assertTrue(method_exists($v, 'validateJATSXML'));
    }

    public function testInvalidXmlStringReturnsFalse(): void
    {
        $v = new JATSXMLValidator($this->logger);
        $v->setXMLString('<notjats><garbage/></notjats>');
        $result = $v->validateJATSXML('');
        // Non-JATS XML will not pass any registered JATS schema
        $this->assertFalse($result);
    }

    public function testMalformedXmlStringReturnsFalse(): void
    {
        $v = new JATSXMLValidator($this->logger);
        $v->setXMLString('<unclosed');
        $result = $v->validateJATSXML('');
        $this->assertFalse($result);
    }

    public function testInvalidXmlLogsMessages(): void
    {
        $v = new JATSXMLValidator($this->logger);
        $v->setXMLString('<notjats/>');
        $v->validateJATSXML('');
        // At least one message should have been logged
        $this->assertNotEmpty($this->logger->messages);
    }
}


/********************************************************************/
/* OM TO CSV ADAPTER TESTS                                           */
/********************************************************************/

class OMToCSVAdapterTest extends TestCase
{
    private Article $article;

    protected function setUp(): void
    {
        $this->article = new Article();
    }

    public function testConstructorAcceptsArticleAndUri(): void
    {
        $adapter = new OMToCSVAdapter($this->article, '/tmp/test.csv');
        $this->assertInstanceOf(OMToCSVAdapter::class, $adapter);
    }

    public function testGetArticleReturnsArticleInstance(): void
    {
        $adapter = new OMToCSVAdapter($this->article, '/tmp/test.csv');
        $this->assertInstanceOf(Article::class, $adapter->getArticle());
    }

    public function testGetArticleReturnsTheSameArticle(): void
    {
        $this->article->setTitle('My Article');
        $adapter = new OMToCSVAdapter($this->article, '/tmp/test.csv');
        $this->assertSame('My Article', $adapter->getArticle()->getTitle());
    }

    public function testSetInputDirMethodExists(): void
    {
        $adapter = new OMToCSVAdapter($this->article, '/tmp/test.csv');
        $this->assertTrue(method_exists($adapter, 'setInputDir'));
    }

    public function testSetOjsUserMethodExists(): void
    {
        $adapter = new OMToCSVAdapter($this->article, '/tmp/test.csv');
        $this->assertTrue(method_exists($adapter, 'setOjsUser'));
    }

    public function testSetVerboseMethodExists(): void
    {
        $adapter = new OMToCSVAdapter($this->article, '/tmp/test.csv');
        $this->assertTrue(method_exists($adapter, 'setVerbose'));
    }

    public function testSetFileNameMethodExists(): void
    {
        $adapter = new OMToCSVAdapter($this->article, '/tmp/test.csv');
        $this->assertTrue(method_exists($adapter, 'setFileName'));
    }
}


/********************************************************************/
/* OM TO DATACITE ADAPTER TESTS                                      */
/********************************************************************/

class OMToDataCiteAdapterTest extends TestCase
{
    private Article $article;

    protected function setUp(): void
    {
        $this->article = new Article();
    }

    public function testConstructorWithArticleOnly(): void
    {
        $adapter = new OMToDataCiteAdapter($this->article);
        $this->assertInstanceOf(OMToDataCiteAdapter::class, $adapter);
    }

    public function testConstructorWithArticleAndUri(): void
    {
        $adapter = new OMToDataCiteAdapter($this->article, '/tmp/datacite.xml');
        $this->assertInstanceOf(OMToDataCiteAdapter::class, $adapter);
    }

    public function testGenerateXMLInMemoryModeReturnsString(): void
    {
        $adapter = new OMToDataCiteAdapter($this->article);
        $result  = $adapter->generateXML();
        $this->assertIsString($result);
    }

    public function testGenerateXMLInMemoryModeReturnsNonEmptyXml(): void
    {
        $adapter = new OMToDataCiteAdapter($this->article);
        $result  = $adapter->generateXML();
        $this->assertStringContainsString('<?xml', $result);
    }

    public function testSetLoggerMethodExists(): void
    {
        $adapter = new OMToDataCiteAdapter($this->article);
        $this->assertTrue(method_exists($adapter, 'setLogger'));
    }
}


/********************************************************************/
/* BIBTEX TO REFERENCE COLLECTION ADAPTER TESTS                      */
/********************************************************************/

class BibtexToReferenceCollectionAdapterTest extends TestCase
{
    private const BIBTEX_ARTICLE = <<<BIB
    @article{smith2020,
      title   = {A Test Article},
      author  = {Smith, John and Doe, Jane},
      journal = {Test Journal},
      year    = {2020},
      volume  = {5},
      pages   = {10--20},
    }
    BIB;

    public function testConstructorCreatesInstance(): void
    {
        $adapter = new BibtexToReferenceCollectionAdapter();
        $this->assertInstanceOf(BibtexToReferenceCollectionAdapter::class, $adapter);
    }

    public function testGetReferenceCollectionMethodExists(): void
    {
        $adapter = new BibtexToReferenceCollectionAdapter();
        $this->assertTrue(method_exists($adapter, 'getReferenceCollection'));
    }

    public function testTranslateBibtexItemReturnsArray(): void
    {
        $result = BibtexToReferenceCollectionAdapter::translateBibtexItem(self::BIBTEX_ARTICLE);
        $this->assertIsArray($result);
    }

    public function testTranslateBibtexItemExtractsTitle(): void
    {
        $result = BibtexToReferenceCollectionAdapter::translateBibtexItem(self::BIBTEX_ARTICLE);
        $this->assertNotEmpty($result);
        // The parsed entry should contain a title key
        $entry = reset($result);
        $this->assertArrayHasKey('title', $entry);
    }

    public function testMakeReferenceFromBibtexArrayReturnsReference(): void
    {
        $result = BibtexToReferenceCollectionAdapter::translateBibtexItem(self::BIBTEX_ARTICLE);
        $entry  = reset($result);
        $ref    = BibtexToReferenceCollectionAdapter::makeReferenceFromBibtexArray($entry);
        $this->assertInstanceOf(\Biblhertz\Article\om\Reference::class, $ref);
    }

    public function testMakeReferencePreservesTitle(): void
    {
        $result = BibtexToReferenceCollectionAdapter::translateBibtexItem(self::BIBTEX_ARTICLE);
        $entry  = reset($result);
        $ref    = BibtexToReferenceCollectionAdapter::makeReferenceFromBibtexArray($entry);
        $this->assertStringContainsString('Test Article', $ref->getTitle());
    }

    public function testMakeReferencePreservesYear(): void
    {
        $result = BibtexToReferenceCollectionAdapter::translateBibtexItem(self::BIBTEX_ARTICLE);
        $entry  = reset($result);
        $ref    = BibtexToReferenceCollectionAdapter::makeReferenceFromBibtexArray($entry);
        $this->assertSame('2020', $ref->getYear());
    }

    public function testSetLoggerMethodExists(): void
    {
        $adapter = new BibtexToReferenceCollectionAdapter();
        $this->assertTrue(method_exists($adapter, 'setLogger'));
    }
}


/********************************************************************/
/* CSV TO OM ADAPTER TESTS                                           */
/********************************************************************/

class CSVToOMAdapterTest extends TestCase
{
    public function testConstructorCreatesInstance(): void
    {
        $adapter = new CSVToOMAdapter();
        $this->assertInstanceOf(CSVToOMAdapter::class, $adapter);
    }

    public function testGetArticleReturnsArticleInstance(): void
    {
        $adapter = new CSVToOMAdapter();
        $this->assertInstanceOf(Article::class, $adapter->getArticle());
    }

    public function testSetCSVArrayMethodExists(): void
    {
        $adapter = new CSVToOMAdapter();
        $this->assertTrue(method_exists($adapter, 'setCSVArray'));
    }

    public function testSetInputDirMethodExists(): void
    {
        $adapter = new CSVToOMAdapter();
        $this->assertTrue(method_exists($adapter, 'setInputDir'));
    }

    public function testSetOJSUserMethodExists(): void
    {
        $adapter = new CSVToOMAdapter();
        $this->assertTrue(method_exists($adapter, 'setOJSUser'));
    }

    public function testSetVerboseMethodExists(): void
    {
        $adapter = new CSVToOMAdapter();
        $this->assertTrue(method_exists($adapter, 'setVerbose'));
    }

    public function testGenerateObjectModelPopulatesTitle(): void
    {
        $adapter = new CSVToOMAdapter();
        $adapter->setOJSUser('testuser');
        $adapter->setInputDir('/tmp');
        $adapter->setVerbose(false);
        $adapter->setCSVArray([
            'Copyright Holder'  => 'Test Publisher',
            'Copyright Year'    => '2024',
            'License Url'       => 'https://creativecommons.org/licenses/by/4.0/',
            'Section reference' => 'ART',
            'Start Page'        => '1',
            'End Page'          => '20',
            'Date'              => '2024-01-15',
            'Year'              => '2024',
            'Article Title'     => 'CSV Imported Article',
            'Article Subtitle'  => '',
            'DOI'               => '10.9999/test.2024',
            'Abstract'          => 'Test abstract.',
            'Keywords'          => 'keyword1;keyword2',
        ]);

        $adapter->generateObjectModel();

        $this->assertSame('CSV Imported Article', $adapter->getArticle()->getTitle());
    }

    public function testGenerateObjectModelPopulatesDOI(): void
    {
        $adapter = new CSVToOMAdapter();
        $adapter->setOJSUser('testuser');
        $adapter->setInputDir('/tmp');
        $adapter->setVerbose(false);
        $adapter->setCSVArray([
            'Copyright Holder'  => 'Publisher',
            'Copyright Year'    => '2024',
            'License Url'       => '',
            'Section reference' => 'ART',
            'Start Page'        => '5',
            'End Page'          => '15',
            'Date'              => '2024-06-01',
            'Year'              => '2024',
            'Article Title'     => 'DOI Article',
            'Article Subtitle'  => '',
            'DOI'               => '10.1234/doi.test',
            'Abstract'          => '',
            'Keywords'          => '',
        ]);

        $adapter->generateObjectModel();

        $this->assertSame('10.1234/doi.test', $adapter->getArticle()->getDOI());
    }
}
