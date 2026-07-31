<?php

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * PHPUnit 12 expects each test file to contain a class named after the file.
 * These files contain multiple test classes with different names, so we define
 * empty global stubs here to satisfy PHPUnit's filename-based discovery check.
 */
class OmTest {}
class ArticlePackageTest {}
class ArticleOmMissingTest {}
class ComponentsTest {}
class PagesTest {}
