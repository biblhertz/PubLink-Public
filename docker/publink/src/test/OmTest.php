<?php

namespace Biblhertz\Publink\test;

use PHPUnit\Framework\TestCase;
use Biblhertz\Publink\om\FileType;
use Biblhertz\Publink\om\Job;
use Biblhertz\Publink\om\JobQueue;
use Biblhertz\Publink\om\SerializedObject;
use Biblhertz\Publink\utilities\PDODatabase;

/********************************************************************/
/* HELPERS                                                           */
/********************************************************************/

/**
 * Returns a PDODatabase mock that accepts any method call and returns null.
 * Used wherever a DB connection is required but no queries should execute.
 */
function makeDbMock(TestCase $t): PDODatabase
{
    return $t->createMock(PDODatabase::class);
}


/********************************************************************/
/* FILE TYPE TESTS                                                   */
/********************************************************************/

class FileTypeTest extends TestCase
{
    // --- Constructor ---

    public function testConstructorSetsId(): void
    {
        $ft = new FileType(7, 'pdf');
        $this->assertSame(7, $ft->getID());
    }

    public function testConstructorSetsName(): void
    {
        $ft = new FileType(3, 'JATS xml');
        $this->assertSame('JATS xml', $ft->getName());
    }

    // --- Access control: all return true ---

    public function testCanEditAlwaysTrue(): void
    {
        $ft = new FileType(1, 'pdf');
        $this->assertTrue($ft->canEdit(0));
        $this->assertTrue($ft->canEdit(99));
    }

    public function testCanDeleteAlwaysTrue(): void
    {
        $ft = new FileType(1, 'pdf');
        $this->assertTrue($ft->canDelete(0));
        $this->assertTrue($ft->canDelete(99));
    }

    public function testCanViewAlwaysTrue(): void
    {
        $ft = new FileType(1, 'pdf');
        $this->assertTrue($ft->canView(0));
        $this->assertTrue($ft->canView(99));
    }

    public function testCanCreateAlwaysTrue(): void
    {
        $ft = new FileType(1, 'pdf');
        $this->assertTrue($ft->canCreate(0));
        $this->assertTrue($ft->canCreate(99));
    }
}


/********************************************************************/
/* BHOBJECT TESTS (exercised via FileType)                           */
/********************************************************************/

class BHObjectTest extends TestCase
{
    private FileType $obj;

    protected function setUp(): void
    {
        $this->obj = new FileType(10, 'jpg');
    }

    // --- ID ---

    public function testGetId(): void
    {
        $this->assertSame(10, $this->obj->getID());
    }

    public function testSetId(): void
    {
        $this->obj->setID(42);
        $this->assertSame(42, $this->obj->getID());
    }

    // --- Name ---

    public function testGetName(): void
    {
        $this->assertSame('jpg', $this->obj->getName());
    }

    public function testSetName(): void
    {
        $this->obj->setName('png');
        $this->assertSame('png', $this->obj->getName());
    }

    // --- isEqualTo ---

    public function testIsEqualToTrueWhenSameId(): void
    {
        $other = new FileType(10, 'anything');
        $this->assertTrue($this->obj->isEqualTo($other));
    }

    public function testIsEqualToFalseWhenDifferentId(): void
    {
        $other = new FileType(99, 'jpg');
        $this->assertFalse($this->obj->isEqualTo($other));
    }

    // --- toString ---

    public function testToStringFormat(): void
    {
        $this->assertSame('10 :: jpg', $this->obj->toString());
    }

    public function testToStringReflectsNameChange(): void
    {
        $this->obj->setName('gif');
        $this->assertStringContainsString('gif', $this->obj->toString());
    }

    // --- getAsTable ---

    public function testGetAsTableReturnsHtmlString(): void
    {
        $html = $this->obj->getAsTable();
        $this->assertIsString($html);
        $this->assertStringContainsString('<table', $html);
    }

    // --- getDisallowedFields ---

    public function testGetDisallowedFieldsIsArray(): void
    {
        $this->assertIsArray($this->obj->getDisallowedFields());
    }

    public function testFileTypeHasNoDisallowedFields(): void
    {
        // FileType does not populate $disallowedFields in its constructor
        $this->assertEmpty($this->obj->getDisallowedFields());
    }

    // --- setObjDB / getObjDB ---

    public function testSetAndGetObjDb(): void
    {
        $db = $this->createMock(PDODatabase::class);
        $this->obj->setObjDB($db);
        $this->assertSame($db, $this->obj->getObjDB());
    }
}


/********************************************************************/
/* SERIALIZED OBJECT TESTS                                           */
/********************************************************************/

class SerializedObjectTest extends TestCase
{
    private SerializedObject $so;

    protected function setUp(): void
    {
        // No $id supplied → constructor skips the DB fetch entirely
        $this->so = new SerializedObject(makeDbMock($this));
    }

    // --- Default values ---

    public function testGetObjectDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->so->getObject());
    }

    public function testGetTimestampDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->so->getTimeStamp());
    }

    public function testGetTypeDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->so->getType());
    }

    public function testGetUserDetailsIdDefaultsToZero(): void
    {
        $this->assertSame(0, $this->so->getUserDetailsID());
    }

    public function testGetFileIdDefaultsToZero(): void
    {
        $this->assertSame(0, $this->so->getFileID());
    }

    // --- Access control (userDetailsID = 0 when constructed without $id) ---

    public function testCanEditTrueForUserId0(): void
    {
        // Owner is 0 (default); user 0 should be allowed
        $this->assertTrue($this->so->canEdit(0));
    }

    public function testCanEditFalseForNonOwner(): void
    {
        $this->assertFalse($this->so->canEdit(5));
    }

    public function testCanDeleteTrueForOwner(): void
    {
        $this->assertTrue($this->so->canDelete(0));
    }

    public function testCanDeleteFalseForNonOwner(): void
    {
        $this->assertFalse($this->so->canDelete(99));
    }

    public function testCanViewTrueForOwner(): void
    {
        $this->assertTrue($this->so->canView(0));
    }

    public function testCanViewFalseForNonOwner(): void
    {
        $this->assertFalse($this->so->canView(1));
    }

    public function testCanExecuteTrueForOwner(): void
    {
        $this->assertTrue($this->so->canExecute(0));
    }

    public function testCanExecuteFalseForNonOwner(): void
    {
        $this->assertFalse($this->so->canExecute(7));
    }

    // --- updateObject ---

    public function testUpdateObjectStoresSerializedString(): void
    {
        // Stub the DB so the UPDATE call does nothing
        $db = $this->createMock(PDODatabase::class);
        $db->method('update')->willReturn(null);
        $so = new SerializedObject($db);
        $so->setID(1);

        $payload = (object) ['foo' => 'bar'];
        $so->updateObject($payload);

        $this->assertSame(serialize($payload), $so->getObject());
    }

    public function testUpdateObjectRoundTripsPayload(): void
    {
        $db = $this->createMock(PDODatabase::class);
        $db->method('update')->willReturn(null);
        $so = new SerializedObject($db);
        $so->setID(1);

        $payload = ['key' => 'value', 'num' => 42];
        $so->updateObject($payload);

        $restored = unserialize($so->getObject(), ['allowed_classes' => false]);
        $this->assertSame($payload, $restored);
    }
}


/********************************************************************/
/* JOB TESTS                                                         */
/********************************************************************/

class JobTest extends TestCase
{
    private Job $job;

    protected function setUp(): void
    {
        // No $id → constructor skips DB fetch and sets id=0
        $this->job = new Job(makeDbMock($this));
    }

    // --- Default values ---

    public function testIdDefaultsToZero(): void
    {
        $this->assertSame(0, $this->job->getID());
    }

    public function testStatusDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->job->getStatus());
    }

    public function testParametersDefaultToEmptyArray(): void
    {
        $this->assertSame([], $this->job->getParameters());
    }

    public function testSubmittedAtDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->job->getSubmittedAt());
    }

    public function testFinishedAtDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->job->getFinishedAt());
    }

    public function testTimeTakenDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->job->getTimeTaken());
    }

    public function testErrorMessageDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->job->getErrorMessage());
    }

    public function testLogFileNameDefaultsToEmpty(): void
    {
        $this->assertSame('', $this->job->getLogFileName());
    }

    public function testOutputFileIdDefaultsToZero(): void
    {
        $this->assertSame(0, $this->job->getOutputFileID());
    }

    // --- Setters / getters ---

    public function testStatusGetSet(): void
    {
        $this->job->setStatus('PROCESSING');
        $this->assertSame('PROCESSING', $this->job->getStatus());
    }

    public function testParametersGetSet(): void
    {
        $this->job->setParameters(['file_id' => 7, 'mode' => 'fast']);
        $this->assertSame(['file_id' => 7, 'mode' => 'fast'], $this->job->getParameters());
    }

    public function testSubmittedAtGetSet(): void
    {
        $this->job->setSubmittedAt('2024-01-15 10:30:00');
        $this->assertSame('2024-01-15 10:30:00', $this->job->getSubmittedAt());
    }

    public function testFinishedAtGetSet(): void
    {
        $this->job->setFinishedAt('2024-01-15 10:35:42');
        $this->assertSame('2024-01-15 10:35:42', $this->job->getFinishedAt());
    }

    public function testTimeTakenGetSet(): void
    {
        $this->job->setTimeTaken('312000');
        $this->assertSame('312000', $this->job->getTimeTaken());
    }

    public function testErrorMessageGetSet(): void
    {
        $this->job->setErrorMessage('Import failed: missing title element');
        $this->assertSame('Import failed: missing title element', $this->job->getErrorMessage());
    }

    public function testLogFileNameGetSet(): void
    {
        $this->job->setLogFileName('/var/log/publink/job_abc.log');
        $this->assertSame('/var/log/publink/job_abc.log', $this->job->getLogFileName());
    }

    // --- Access control: all return false ---

    public function testCanEditAlwaysFalse(): void
    {
        $this->assertFalse($this->job->canEdit(0));
        $this->assertFalse($this->job->canEdit(99));
    }

    public function testCanDeleteAlwaysFalse(): void
    {
        $this->assertFalse($this->job->canDelete(0));
        $this->assertFalse($this->job->canDelete(99));
    }

    public function testCanViewAlwaysFalse(): void
    {
        $this->assertFalse($this->job->canView(0));
        $this->assertFalse($this->job->canView(99));
    }

    // --- getAsTable ---

    public function testGetAsTableReturnsHtmlTable(): void
    {
        $html = $this->job->getAsTable();
        $this->assertStringContainsString('<table', $html);
    }
}


/********************************************************************/
/* JOB QUEUE TESTS                                                   */
/********************************************************************/

class JobQueueTest extends TestCase
{
    private JobQueue $queue;

    protected function setUp(): void
    {
        $this->queue = new JobQueue(makeDbMock($this));
    }

    // --- Access control: all return false ---

    public function testCanEditAlwaysFalse(): void
    {
        $this->assertFalse($this->queue->canEdit(0));
        $this->assertFalse($this->queue->canEdit(99));
    }

    public function testCanDeleteAlwaysFalse(): void
    {
        $this->assertFalse($this->queue->canDelete(0));
        $this->assertFalse($this->queue->canDelete(99));
    }

    public function testCanViewAlwaysFalse(): void
    {
        $this->assertFalse($this->queue->canView(0));
        $this->assertFalse($this->queue->canView(99));
    }
}
