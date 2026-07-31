<?php

namespace Biblhertz\Article\Tests;

use PHPUnit\Framework\TestCase;
use Biblhertz\Article\reference_api_adapters\ReferenceAPIAdapter;
use Biblhertz\Article\reference_api_adapters\CrossRefAdapter;
use Biblhertz\Article\reference_api_adapters\GoogleBooksAdapter;
use Biblhertz\Article\reference_api_adapters\PrimoAPIAdapter;
use Biblhertz\Article\om\Reference;
use Biblhertz\Article\om\ReferenceCollection;
use Biblhertz\Article\om\JournalArticle;
use Biblhertz\Article\om\Book;


/********************************************************************/
/* REFERENCE API ADAPTER BASE TESTS                                  */
/********************************************************************/

/**
 * Minimal concrete subclass so we can instantiate the abstract base.
 */
class ConcreteReferenceAdapter extends ReferenceAPIAdapter
{
    public function resolve(): mixed
    {
        return null;
    }
}

class ReferenceAPIAdapterTest extends TestCase
{
    private ConcreteReferenceAdapter $adapter;
    private JournalArticle $ref;

    protected function setUp(): void
    {
        $this->adapter = new ConcreteReferenceAdapter();
        $this->ref     = new JournalArticle();
        $this->ref->setLabel('test2024');
    }

    // --- setReference / getReference ---

    public function testSetAndGetReference(): void
    {
        $this->adapter->setReference($this->ref);
        $this->assertSame($this->ref, $this->adapter->getReference());
    }

    // --- putReferenceinCollection ---

    public function testPutReferenceCollectionPassesThrough(): void
    {
        $collection = new ReferenceCollection();
        $result     = ReferenceAPIAdapter::putReferenceinCollection($collection);
        $this->assertSame($collection, $result);
    }

    public function testPutReferenceWrapsReferenceInCollection(): void
    {
        $this->ref->setLabel('smith2020');
        $result = ReferenceAPIAdapter::putReferenceinCollection($this->ref);
        $this->assertInstanceOf(ReferenceCollection::class, $result);
    }

    public function testPutReferenceCollectionContainsRef(): void
    {
        $this->ref->setLabel('doe2021');
        $collection = ReferenceAPIAdapter::putReferenceinCollection($this->ref);
        $this->assertTrue($collection->exists('doe2021', false));
    }

    public function testPutNonReferenceReturnsFalse(): void
    {
        $result = ReferenceAPIAdapter::putReferenceinCollection('not a reference');
        $this->assertFalse($result);
    }

    public function testPutNullReturnsFalse(): void
    {
        $result = ReferenceAPIAdapter::putReferenceinCollection(null);
        $this->assertFalse($result);
    }

    public function testPutArrayReturnsFalse(): void
    {
        $result = ReferenceAPIAdapter::putReferenceinCollection(['a', 'b']);
        $this->assertFalse($result);
    }
}


/********************************************************************/
/* CROSS REF ADAPTER TESTS                                           */
/********************************************************************/

class CrossRefAdapterTest extends TestCase
{
    public function testConstructorCreatesInstance(): void
    {
        $adapter = new CrossRefAdapter();
        $this->assertInstanceOf(CrossRefAdapter::class, $adapter);
    }

    public function testSetAndGetReference(): void
    {
        $adapter = new CrossRefAdapter();
        $ref     = new JournalArticle();
        $ref->setLabel('crossref2023');

        $adapter->setReference($ref);
        $this->assertSame($ref, $adapter->getReference());
    }

    public function testResolveMethodExists(): void
    {
        $adapter = new CrossRefAdapter();
        $this->assertTrue(method_exists($adapter, 'resolve'));
    }

    public function testGetFromDOIMethodExists(): void
    {
        $adapter = new CrossRefAdapter();
        $this->assertTrue(method_exists($adapter, 'getFromDOI'));
    }

    public function testGetFromPMIDMethodExists(): void
    {
        $adapter = new CrossRefAdapter();
        $this->assertTrue(method_exists($adapter, 'getFromPMID'));
    }

    public function testGetFromTitleMethodExists(): void
    {
        $adapter = new CrossRefAdapter();
        $this->assertTrue(method_exists($adapter, 'getFromTitle'));
    }
}


/********************************************************************/
/* GOOGLE BOOKS ADAPTER TESTS                                        */
/********************************************************************/

class GoogleBooksAdapterTest extends TestCase
{
    public function testConstructorCreatesInstance(): void
    {
        $adapter = new GoogleBooksAdapter();
        $this->assertInstanceOf(GoogleBooksAdapter::class, $adapter);
    }

    public function testSetAndGetReference(): void
    {
        $adapter = new GoogleBooksAdapter();
        $ref     = new JournalArticle();
        $adapter->setReference($ref);
        $this->assertSame($ref, $adapter->getReference());
    }

    public function testResolveReturnsNullForNonBookReference(): void
    {
        // JournalArticle is not a Book/Manuscript — resolve should return null
        // without making any network call
        $adapter = new GoogleBooksAdapter();
        $ref     = new JournalArticle();
        $ref->setTitle('Some Article Title');
        $adapter->setReference($ref);

        $result = $adapter->resolve();
        $this->assertNull($result);
    }

    public function testResolveMethodExists(): void
    {
        $adapter = new GoogleBooksAdapter();
        $this->assertTrue(method_exists($adapter, 'resolve'));
    }
}


/********************************************************************/
/* PRIMO API ADAPTER TESTS                                           */
/********************************************************************/

class PrimoAPIAdapterTest extends TestCase
{
    public function testConstructorCreatesInstance(): void
    {
        $adapter = new PrimoAPIAdapter();
        $this->assertInstanceOf(PrimoAPIAdapter::class, $adapter);
    }

    public function testSetAndGetReference(): void
    {
        $adapter = new PrimoAPIAdapter();
        $ref     = new JournalArticle();
        $ref->setLabel('primo2023');

        $adapter->setReference($ref);
        $this->assertSame($ref, $adapter->getReference());
    }

    public function testResolveMethodExists(): void
    {
        $adapter = new PrimoAPIAdapter();
        $this->assertTrue(method_exists($adapter, 'resolve'));
    }

    // --- putReferenceinCollection (inherited static method) ---

    public function testPutReferenceWrapsBookInCollection(): void
    {
        $book = new Book();
        $book->setLabel('myBook');

        $result = PrimoAPIAdapter::putReferenceinCollection($book);
        $this->assertInstanceOf(ReferenceCollection::class, $result);
    }
}
