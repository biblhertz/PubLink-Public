<?php

namespace Biblhertz\Publink\test;

use PHPUnit\Framework\TestCase;
use Biblhertz\Publink\utilities\XMLValidator;
use Biblhertz\Publink\utilities\Logger;

/********************************************************************/
/* XML VALIDATOR TESTS                                               */
/********************************************************************/

class XMLValidatorTest extends TestCase
{
    /** Minimal XSD schema used across tests. */
    private const XSD_CONTENT = <<<XSD
    <?xml version="1.0"?>
    <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
      <xs:element name="root">
        <xs:complexType>
          <xs:sequence>
            <xs:element name="title" type="xs:string"/>
          </xs:sequence>
        </xs:complexType>
      </xs:element>
    </xs:schema>
    XSD;

    /** Valid XML that satisfies the schema above. */
    private const VALID_XML = <<<XML
    <?xml version="1.0"?>
    <root>
      <title>Hello World</title>
    </root>
    XML;

    /** Invalid XML: missing required <title> element. */
    private const INVALID_XML = <<<XML
    <?xml version="1.0"?>
    <root>
      <other>Not a title</other>
    </root>
    XML;

    private string $xsdFile;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->xsdFile = tempnam(sys_get_temp_dir(), 'xsd_test_') . '.xsd';
        file_put_contents($this->xsdFile, self::XSD_CONTENT);
        $this->logger = new Logger();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->xsdFile)) {
            unlink($this->xsdFile);
        }
    }

    private function makeXmlFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xml_test_') . '.xml';
        file_put_contents($path, $content);
        return $path;
    }

    private function makeValidator(string $xmlPath): XMLValidator
    {
        $v = new XMLValidator();
        $v->setLogger($this->logger);
        $v->setXSDPath($this->xsdFile);
        $v->setTargetPath($xmlPath);
        return $v;
    }

    // --- Valid XML ---

    public function testValidXmlReturnsTrue(): void
    {
        $xmlFile = $this->makeXmlFile(self::VALID_XML);
        $validator = $this->makeValidator($xmlFile);

        $result = $validator->validateXML();

        unlink($xmlFile);
        $this->assertTrue($result);
    }

    public function testValidXmlLogsSuccessMessage(): void
    {
        $xmlFile = $this->makeXmlFile(self::VALID_XML);
        $validator = $this->makeValidator($xmlFile);
        $validator->validateXML();

        unlink($xmlFile);
        $joined = implode("\n", $this->logger->messages);
        $this->assertStringContainsString('passed validation', $joined);
    }

    // --- Invalid XML ---

    public function testInvalidXmlReturnsFalse(): void
    {
        $xmlFile = $this->makeXmlFile(self::INVALID_XML);
        $validator = $this->makeValidator($xmlFile);

        $result = $validator->validateXML();

        unlink($xmlFile);
        $this->assertFalse($result);
    }

    public function testInvalidXmlLogsFailureMessage(): void
    {
        $xmlFile = $this->makeXmlFile(self::INVALID_XML);
        $validator = $this->makeValidator($xmlFile);
        $validator->validateXML();

        unlink($xmlFile);
        $joined = implode("\n", $this->logger->messages);
        $this->assertStringContainsString('failed validation', $joined);
    }

    public function testInvalidXmlLogsLineNumber(): void
    {
        $xmlFile = $this->makeXmlFile(self::INVALID_XML);
        $validator = $this->makeValidator($xmlFile);
        $validator->validateXML();

        unlink($xmlFile);
        $joined = implode("\n", $this->logger->messages);
        $this->assertStringContainsString('Line ', $joined);
    }

    // --- setters ---

    public function testSettersExist(): void
    {
        $v = new XMLValidator();
        $this->assertTrue(method_exists($v, 'setLogger'));
        $this->assertTrue(method_exists($v, 'setXSDPath'));
        $this->assertTrue(method_exists($v, 'setTargetPath'));
        $this->assertTrue(method_exists($v, 'validateXML'));
    }
}
