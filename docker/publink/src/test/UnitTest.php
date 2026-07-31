<?php
namespace Biblhertz\Publink\test;

use PHPUnit\Framework\TestCase;
use Biblhertz\Publink\Config;
use Biblhertz\Article\om\Article;
use Biblhertz\Article\om\AAbstract;
use Biblhertz\Article\om\Paragraph;


class UnitTest extends TestCase
{
   
      
/**
 * Put manifest on server
 */
    public function testLoadJATS(){
        Config::setup();

        $this->assertIsInt(Config::$ENVIRONMENT);
        $this->assertNotEmpty(Config::$PUBLINK_ENCRYPTION_KEY);
        $this->assertNotNull(Config::$FILE_STORE_PATH);
        $this->assertNotNull(Config::$LOG_DIR);
        $this->assertNotEmpty(Config::$CSL_LOCATION);
        $this->assertIsArray(Config::$JATS_XSD);
        $this->assertIsArray(Config::$OJS_XSD);
    }

    public function testArticle(){
        $article=new Article();
        $article->setTitle("Test Title");
        $abstract=new AAbstract();
        $paragraph=new Paragraph("Abstract goes in here");
        $abstract->addParagraph($paragraph);
        $article->setAbstract($abstract);
        $this->assertSame('Test Title', $article->getTitle());
        $p=$article->getAbstract()->getParagraphs()[0];
        $this->assertSame($p, $paragraph);
        $this->assertEmpty($article->getJournalName());
        $this->assertEmpty($article->getAuthors());
       
    }

   
}

?>
