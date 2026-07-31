<?php

namespace Biblhertz\Publink\test;

use PHPUnit\Framework\TestCase;
use Biblhertz\Publink\annotation\SVGPathFixer;
use Biblhertz\Publink\annotation\ImageAnnotation;
use Biblhertz\Publink\annotation\ImageCanvas;
use Biblhertz\Publink\utilities\PDODatabase;


/********************************************************************/
/* SVG PATH FIXER TESTS                                              */
/********************************************************************/

class SVGPathFixerTest extends TestCase
{
    private SVGPathFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new SVGPathFixer();
    }


    // ----------------------------------------------------------------
    // fixPath — normalize (absolute passthrough)
    // ----------------------------------------------------------------

    public function testNormalizeAbsoluteMAndL(): void
    {
        $result = $this->fixer->fixPath('M100,200L300,400', 'normalize');
        $this->assertSame('M100,200L300,400', $result);
    }

    public function testNormalizeAbsoluteM(): void
    {
        $result = $this->fixer->fixPath('M50,75', 'normalize');
        $this->assertSame('M50,75', $result);
    }

    public function testNormalizeRelativeMToAbsolute(): void
    {
        // Relative m from origin → same as absolute M
        $result = $this->fixer->fixPath('m100,200', 'normalize');
        $this->assertSame('M100,200', $result);
    }

    public function testNormalizeRelativeLToAbsolute(): void
    {
        // M sets pen to (100,200); relative l(50,50) → L(150,250)
        $result = $this->fixer->fixPath('M100,200l50,50', 'normalize');
        $this->assertSame('M100,200L150,250', $result);
    }

    public function testNormalizeChainedRelativeL(): void
    {
        // M(0,0) → l(10,0) → L(10,0), then l(0,10) → L(10,10)
        $result = $this->fixer->fixPath('M0,0l10,0l0,10', 'normalize');
        $this->assertSame('M0,0L10,0L10,10', $result);
    }

    public function testNormalizeHCommandToL(): void
    {
        // H200 from (100,100) → L(200,100)
        $result = $this->fixer->fixPath('M100,100H200', 'normalize');
        $this->assertSame('M100,100L200,100', $result);
    }

    public function testNormalizeRelativeHCommandToL(): void
    {
        // h50 from (100,100) → L(150,100)
        $result = $this->fixer->fixPath('M100,100h50', 'normalize');
        $this->assertSame('M100,100L150,100', $result);
    }

    public function testNormalizeVCommandToL(): void
    {
        // V200 from (100,100) → L(100,200)
        $result = $this->fixer->fixPath('M100,100V200', 'normalize');
        $this->assertSame('M100,100L100,200', $result);
    }

    public function testNormalizeRelativeVCommandToL(): void
    {
        // v50 from (100,100) → L(100,150)
        $result = $this->fixer->fixPath('M100,100v50', 'normalize');
        $this->assertSame('M100,100L100,150', $result);
    }

    public function testNormalizeZPreserved(): void
    {
        $result = $this->fixer->fixPath('M10,20L30,40Z', 'normalize');
        $this->assertStringContainsString('Z', $result);
    }

    public function testNormalizeZResetsPenToStart(): void
    {
        // After Z, pen returns to start (10,20). Next L100,100 should be absolute.
        $result = $this->fixer->fixPath('M10,20L30,40ZL100,100', 'normalize');
        // L100,100 is already absolute and should be preserved as-is
        $this->assertStringContainsString('L100,100', $result);
    }

    public function testNormalizeAbsoluteCubicBezier(): void
    {
        $result = $this->fixer->fixPath('M0,0C10,20,30,40,50,60', 'normalize');
        $this->assertStringStartsWith('M0,0C', $result);
        $this->assertStringContainsString('50,60', $result);
    }

    public function testNormalizeRelativeCubicBezier(): void
    {
        // c10,20,30,40,50,60 from (0,0) → C10,20,30,40,50,60
        $result = $this->fixer->fixPath('M0,0c10,20,30,40,50,60', 'normalize');
        $this->assertStringStartsWith('M0,0C', $result);
        $this->assertStringContainsString('50,60', $result);
    }

    public function testNormalizeCubicBezierWithOffset(): void
    {
        // c10,0,20,0,30,0 from (100,200) → control1=(110,200), control2=(120,200), end=(130,200)
        $result = $this->fixer->fixPath('M100,200c10,0,20,0,30,0', 'normalize');
        $this->assertStringContainsString('130,200', $result);
    }

    public function testNormalizeAbsoluteQuadraticBezier(): void
    {
        $result = $this->fixer->fixPath('M0,0Q25,50,50,0', 'normalize');
        $this->assertStringStartsWith('M0,0Q', $result);
        $this->assertStringContainsString('50,0', $result);
    }

    public function testNormalizeRelativeQuadraticBezier(): void
    {
        // q25,50,50,0 from (0,0) → Q25,50,50,0
        $result = $this->fixer->fixPath('M0,0q25,50,50,0', 'normalize');
        $this->assertStringStartsWith('M0,0Q', $result);
        $this->assertStringContainsString('50,0', $result);
    }

    public function testNormalizeAbsoluteArc(): void
    {
        // A rx,ry,x-rot,large-arc,sweep,x,y
        $result = $this->fixer->fixPath('M0,0A10,10,0,0,1,50,50', 'normalize');
        $this->assertStringStartsWith('M0,0A', $result);
        $this->assertStringContainsString('50,50', $result);
    }

    public function testNormalizeRelativeArc(): void
    {
        // a10,10,0,0,1,50,50 from (0,0) → endpoint becomes (50,50)
        $result = $this->fixer->fixPath('M0,0a10,10,0,0,1,50,50', 'normalize');
        $this->assertStringContainsString('50,50', $result);
    }


    // ----------------------------------------------------------------
    // fixPath — unknown method returns original unchanged
    // ----------------------------------------------------------------

    public function testUnknownMethodReturnsOriginal(): void
    {
        $original = 'M10,20L30,40';
        $this->assertSame($original, $this->fixer->fixPath($original, 'unknown_method'));
    }

    public function testDefaultMethodIsNormalize(): void
    {
        // Default should behave the same as 'normalize'
        $this->assertSame(
            $this->fixer->fixPath('M100,200l50,50', 'normalize'),
            $this->fixer->fixPath('M100,200l50,50')
        );
    }


    // ----------------------------------------------------------------
    // fixPath — simplify strategy
    // ----------------------------------------------------------------

    public function testSimplifySimplePath(): void
    {
        // A simple M+L+Z path — simplify should produce the same polygon points
        $result = $this->fixer->fixPath('M0,0L100,0L100,100Z', 'simplify');
        $this->assertNotEmpty($result);
        $this->assertStringStartsWith('M', $result);
        $this->assertStringEndsWith('Z', $result);
    }

    public function testSimplifyCubicBezierProducesFourPoints(): void
    {
        // M(0,0) C(10,20,30,40,50,60): cubic sampled at t=0.25,0.5,0.75,1.0 → 4 L points after M
        $result = $this->fixer->fixPath('M0,0C10,20,30,40,50,60', 'simplify');
        // Count L commands: 4 for the cubic
        $lCount = substr_count($result, 'L');
        $this->assertSame(4, $lCount);
    }

    public function testSimplifyQuadraticBezierProducesThreePoints(): void
    {
        // M(0,0) Q(25,50,50,0): quadratic sampled at t=1/3,2/3,1.0 → 3 L points after M
        $result = $this->fixer->fixPath('M0,0Q25,50,50,0', 'simplify');
        $lCount = substr_count($result, 'L');
        $this->assertSame(3, $lCount);
    }

    public function testSimplifyArcUsesEndpointOnly(): void
    {
        // Arc simplified to its endpoint only
        $result = $this->fixer->fixPath('M0,0A10,10,0,0,1,50,50', 'simplify');
        $this->assertStringContainsString('50,50', $result);
    }


    // ----------------------------------------------------------------
    // fixPath — extract strategy
    // ----------------------------------------------------------------

    public function testExtractSimplePath(): void
    {
        $result = $this->fixer->fixPath('M0,0L100,0L100,100Z', 'extract');
        $this->assertStringStartsWith('M', $result);
        $this->assertStringEndsWith('Z', $result);
    }

    public function testExtractCubicTakesEndpointOnly(): void
    {
        // Cubic C(10,20,30,40,50,60): only endpoint (50,60) is kept → 1 L after M
        $result = $this->fixer->fixPath('M0,0C10,20,30,40,50,60', 'extract');
        $this->assertSame(1, substr_count($result, 'L'));
        $this->assertStringContainsString('50,60', $result);
    }

    public function testExtractQuadraticTakesEndpointOnly(): void
    {
        // Quadratic Q(25,50,50,0): only endpoint (50,0) is kept → 1 L after M
        $result = $this->fixer->fixPath('M0,0Q25,50,50,0', 'extract');
        $this->assertSame(1, substr_count($result, 'L'));
        $this->assertStringContainsString('50,0', $result);
    }

    public function testExtractArcTakesEndpointOnly(): void
    {
        $result = $this->fixer->fixPath('M0,0A10,10,0,0,1,60,70', 'extract');
        $this->assertStringContainsString('60,70', $result);
    }


    // ----------------------------------------------------------------
    // pathFromFragmentSelector
    // ----------------------------------------------------------------

    public function testPathFromFragmentSelectorBasic(): void
    {
        $result = $this->fixer->pathFromFragmentSelector('xywh=100,200,300,400');
        $this->assertSame('M100,200h300v400h-300Z', $result);
    }

    public function testPathFromFragmentSelectorZeroOrigin(): void
    {
        $result = $this->fixer->pathFromFragmentSelector('xywh=0,0,50,75');
        $this->assertSame('M0,0h50v75h-50Z', $result);
    }

    public function testPathFromFragmentSelectorStartsWithM(): void
    {
        $result = $this->fixer->pathFromFragmentSelector('xywh=10,20,30,40');
        $this->assertStringStartsWith('M10,20', $result);
    }

    public function testPathFromFragmentSelectorEndsWithZ(): void
    {
        $result = $this->fixer->pathFromFragmentSelector('xywh=10,20,30,40');
        $this->assertStringEndsWith('Z', $result);
    }

    public function testPathFromFragmentSelectorReturnsEmptyOnInvalidInput(): void
    {
        $this->assertSame('', $this->fixer->pathFromFragmentSelector('not-a-selector'));
    }

    public function testPathFromFragmentSelectorReturnsEmptyOnEmptyInput(): void
    {
        $this->assertSame('', $this->fixer->pathFromFragmentSelector(''));
    }

    public function testPathFromFragmentSelectorReturnsEmptyForMissingXywh(): void
    {
        $this->assertSame('', $this->fixer->pathFromFragmentSelector('region=100,200,300,400'));
    }


    // ----------------------------------------------------------------
    // processIIIFAnnotations
    // ----------------------------------------------------------------

    public function testProcessAnnotationsReturnsEmptyForMissingStructure(): void
    {
        $result = $this->fixer->processIIIFAnnotations([]);
        $this->assertSame([], $result);
    }

    public function testProcessAnnotationsReturnsEmptyForMissingItems(): void
    {
        $result = $this->fixer->processIIIFAnnotations(['items' => []]);
        $this->assertSame([], $result);
    }

    public function testProcessAnnotationsWithFragmentSelector(): void
    {
        $manifest = [
            'items' => [
                [
                    'annotations' => [
                        [
                            'items' => [
                                [
                                    'id'     => 'https://example.com/ann/1',
                                    'body'   => ['value' => 'Test annotation'],
                                    'target' => [
                                        'selector' => [
                                            [
                                                'type'  => 'FragmentSelector',
                                                'value' => 'xywh=100,200,300,400'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $results = $this->fixer->processIIIFAnnotations($manifest);

        $this->assertCount(1, $results);
        $this->assertSame('xywh=100,200,300,400', $results[0]['fragment_selector']);
        $this->assertSame('M100,200h300v400h-300Z', $results[0]['fixed_paths']['from_fragment']);
        $this->assertSame(100.0, $results[0]['bounding_box']['x']);
        $this->assertSame(200.0, $results[0]['bounding_box']['y']);
        $this->assertSame(300.0, $results[0]['bounding_box']['width']);
        $this->assertSame(400.0, $results[0]['bounding_box']['height']);
    }

    public function testProcessAnnotationsWithSvgSelector(): void
    {
        $svgPath = 'M10,20L30,40Z';
        $manifest = [
            'items' => [
                [
                    'annotations' => [
                        [
                            'items' => [
                                [
                                    'id'     => 'https://example.com/ann/2',
                                    'body'   => ['value' => 'SVG annotation'],
                                    'target' => [
                                        'selector' => [
                                            [
                                                'type'  => 'SvgSelector',
                                                'value' => '<svg><path d="' . $svgPath . '"/></svg>'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $results = $this->fixer->processIIIFAnnotations($manifest);

        $this->assertCount(1, $results);
        $this->assertContains($svgPath, $results[0]['original_paths']);
        $this->assertArrayHasKey('normalize', $results[0]['fixed_paths']);
        $this->assertArrayHasKey('simplify',  $results[0]['fixed_paths']);
        $this->assertArrayHasKey('extract',   $results[0]['fixed_paths']);
    }

    public function testProcessAnnotationsNullBoundingBoxForSvgSelector(): void
    {
        $manifest = [
            'items' => [
                [
                    'annotations' => [
                        [
                            'items' => [
                                [
                                    'id'     => 'ann/3',
                                    'body'   => ['value' => ''],
                                    'target' => [
                                        'selector' => [
                                            [
                                                'type'  => 'SvgSelector',
                                                'value' => '<svg><path d="M0,0L10,10Z"/></svg>'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $results = $this->fixer->processIIIFAnnotations($manifest);
        $this->assertNull($results[0]['bounding_box']);
    }

    public function testProcessAnnotationsMultipleAnnotations(): void
    {
        $items = [];
        for ($i = 0; $i < 3; $i++) {
            $items[] = [
                'id'     => "ann/$i",
                'body'   => ['value' => "text $i"],
                'target' => [
                    'selector' => [
                        [
                            'type'  => 'FragmentSelector',
                            'value' => "xywh={$i}0,{$i}0,50,50"
                        ]
                    ]
                ]
            ];
        }

        $manifest = ['items' => [['annotations' => [['items' => $items]]]]];
        $results  = $this->fixer->processIIIFAnnotations($manifest);

        $this->assertCount(3, $results);
    }
}


/********************************************************************/
/* IMAGE CANVAS TESTS                                                */
/********************************************************************/

class ImageCanvasTest extends TestCase
{
    private ImageCanvas $canvas;

    protected function setUp(): void
    {
        $db = $this->createMock(PDODatabase::class);
        $this->canvas = new ImageCanvas($db);
    }

    // --- Defaults ---

    public function testCanvasDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->canvas->getCanvas());
    }

    public function testManifestDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->canvas->getManifest());
    }

    // --- Setters / getters ---

    public function testCanvasGetSet(): void
    {
        $this->canvas->setCanvas('https://example.org/canvas/1');
        $this->assertSame('https://example.org/canvas/1', $this->canvas->getCanvas());
    }

    public function testManifestGetSet(): void
    {
        $this->canvas->setManifest('https://example.org/manifest.json');
        $this->assertSame('https://example.org/manifest.json', $this->canvas->getManifest());
    }

    // --- canView: always true (no DB call required) ---

    public function testCanViewAlwaysTrue(): void
    {
        $this->assertTrue($this->canvas->canView(0));
        $this->assertTrue($this->canvas->canView(99));
    }
}


/********************************************************************/
/* IMAGE ANNOTATION TESTS                                            */
/********************************************************************/

class ImageAnnotationTest extends TestCase
{
    private ImageAnnotation $ann;

    protected function setUp(): void
    {
        // id=0 → constructor skips DB fetch entirely
        $db = $this->createMock(PDODatabase::class);
        $this->ann = new ImageAnnotation($db, 0);
    }

    // --- Defaults when constructed without a record ---

    public function testIdIsZeroOnEmptyConstruction(): void
    {
        $this->assertSame(0, $this->ann->getID());
    }

    public function testCanvasDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->ann->getCanvas());
    }

    public function testAnnotationDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->ann->getAnnotation());
    }

    public function testAnnotationIdDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->ann->getAnnotationID());
    }

    public function testManifestDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->ann->getManifest());
    }

    public function testEditedDefaultsToZero(): void
    {
        $this->assertSame(0, $this->ann->getEdited());
    }

    public function testUserIdDefaultsToZero(): void
    {
        $this->assertSame(0, $this->ann->getUserID());
    }

    public function testUsernameDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->ann->getUserName());
    }

    public function testAnnotationTextDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->ann->getAnnotationText());
    }

    public function testThumbnailURLDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->ann->getThumbnailURL());
    }

    public function testSmallThumbnailURLDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->ann->getSmallThumbnailURL());
    }

    public function testFragmentURLDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->ann->getFragmentURL());
    }

    // --- Setters / getters ---

    public function testCanvasGetSet(): void
    {
        $this->ann->setCanvas('https://example.org/canvas/1');
        $this->assertSame('https://example.org/canvas/1', $this->ann->getCanvas());
    }

    public function testAnnotationGetSet(): void
    {
        $json = '{"type":"Annotation"}';
        $this->ann->setAnnotation($json);
        $this->assertSame($json, $this->ann->getAnnotation());
    }

    public function testAnnotationIdGetSet(): void
    {
        $this->ann->setAnnotationID('uuid-1234-5678');
        $this->assertSame('uuid-1234-5678', $this->ann->getAnnotationID());
    }

    public function testManifestGetSet(): void
    {
        $this->ann->setManifest('https://example.org/manifest.json');
        $this->assertSame('https://example.org/manifest.json', $this->ann->getManifest());
    }

    public function testFragmentSelectorGetSet(): void
    {
        $this->ann->setFragmentSelector('xywh=10,20,100,150');
        $this->assertSame('xywh=10,20,100,150', $this->ann->getFragmentSelector());
    }

    public function testEditedGetSet(): void
    {
        $this->ann->setEdited(1);
        $this->assertSame(1, $this->ann->getEdited());
    }

    public function testUsernameGetSet(): void
    {
        $this->ann->setUserName('jane.doe');
        $this->assertSame('jane.doe', $this->ann->getUserName());
    }

    // --- canView: always true (no DB call required) ---

    public function testCanViewAlwaysTrue(): void
    {
        $this->assertTrue($this->ann->canView(0));
        $this->assertTrue($this->ann->canView(99));
    }
}
