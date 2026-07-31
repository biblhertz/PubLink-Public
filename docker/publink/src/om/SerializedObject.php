<?php
namespace Biblhertz\Publink\om;

use ReflectionClass;
use Biblhertz\Publink\utilities\PDODatabase;

/**
 * SerializedObject
 *
 * Wraps a PHP object (typically an Article or ReferenceCollection) that has
 * been serialized and base64-encoded for storage in the `serialized_object`
 * database table. This allows complex domain objects to be persisted and
 * rehydrated without a full relational schema for each object type.
 *
 * Storage format: the object is serialized with PHP's serialize(), then
 * base64-encoded before being written to `serialized_object.object`. On read
 * the reverse is applied — base64_decode() restores the raw serialized string,
 * which callers then pass to unserialize() when they need the live object.
 *
 * Each SerializedObject is owned by a single user and linked to the source
 * {@see File} from which it was built (e.g. a JATS XML upload). The file
 * association is protected: {@see File::canDelete()} checks for a linked
 * serialized object and blocks deletion while the link exists.
 *
 * Reflection-based editing ({@see updateReflectedObject()}) allows scalar
 * properties of the wrapped object to be updated from a POST array without
 * deserializing and re-serializing manually in the caller.
 *
 * @package  Biblhertz\Publink\om
 * @author   Chris Tomlinson
 * @since    August 2023
 */
class SerializedObject extends BHObject {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /**
     * @var string Raw serialized representation of the wrapped object.
     *             Stored in memory as a plain serialize() string (i.e. already
     *             base64-decoded from the database value). Callers use
     *             unserialize($this->getObject()) to obtain the live object.
     */
    private string $object="";

    /** @var int Foreign key referencing the owning user in `user_details` */
    private int $userDetailsID = 0;

    /** @var string Creation timestamp (Y-m-d H:i:s) */
    private string $timestamp = "";

    /**
     * @var string Classname or category label identifying the type of the
     *             wrapped object (e.g. 'Article', 'ReferenceCollection').
     *             Used to determine how to handle the object without
     *             deserializing it first.
     */
    private string $type = "";

    /**
     * @var int Foreign key referencing the `file` record from which this
     *          serialized object was built. The linked file is protected from
     *          deletion while this association exists.
     */
    private int $fileId = 0;


    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                           */
    /****************************************************************/

    /**
     * Constructs a SerializedObject, optionally hydrating it from the database.
     *
     * When $id is supplied the constructor fetches the matching row from
     * `serialized_object`. The stored object string is base64-decoded back to
     * a raw serialized string on load; callers must call unserialize() separately
     * when they need the live PHP object.
     *
     * All fields are conditionally set — a missing column value retains the
     * property's default value (0 or "").
     *
     * @param PDODatabase $objDB Active database connection
     * @param int|null    $id    Primary key of the `serialized_object` row, or null
     */
    public function __construct(PDODatabase $objDB, int $id = null) {
        $this->tableName = "serialized_object";
        $this->objDB     = $objDB;

        if (isset($id)) {
            $this->id = $id;
            $item = $this->fetchItem();

            if (isset($item['name']))           $this->name          = $item['name'];
            if (isset($item['object']))         $this->object        = base64_decode($item['object']);
            if (isset($item['timestamp']))      $this->timestamp     = $item['timestamp'];
            if (isset($item['user_details_id']))$this->userDetailsID = $item['user_details_id'];
            if (isset($item['type']))           $this->type          = $item['type'];
            if (isset($item['file_id']))        $this->fileId        = $item['file_id'];
        }
    }


    /****************************************************************/
    /*  ACCESSOR METHODS                                            */
    /****************************************************************/

    /**
     * Returns the raw serialized string of the wrapped object.
     *
     * The returned string is the output of PHP's serialize() (already
     * base64-decoded from the database value). Pass it to unserialize() to
     * obtain the live PHP object:
     *
     * ```php
     * $article = unserialize($serializedObject->getObject(), ['allowed_classes' => [
     *     \Biblhertz\Article\om\Article::class,
     *     \Biblhertz\Article\om\ReferenceCollection::class,
     * ]]);
     * ```
     *
     * @return string Raw PHP serialized string
     */
    public function getObject(): string {
        return $this->object;
    }

    /**
     * Returns the creation timestamp of this serialized object.
     *
     * @return string Formatted as 'Y-m-d H:i:s'
     */
    public function getTimeStamp(): string {
        return $this->timestamp;
    }

    /**
     * Returns the type label identifying the class of the wrapped object.
     *
     * @return string e.g. 'Article', 'ReferenceCollection'
     */
    public function getType(): string {
        return $this->type;
    }

    /**
     * Returns the ID of the user who owns this serialized object.
     *
     * @return int User ID from `user_details`
     *
     */
    public function getUserDetailsID(): int {
        return $this->userDetailsID;
    }

    /**
     * Returns the primary key of the source File linked to this object.
     *
     * This file is protected from deletion while the association exists;
     * see {@see File::hasLinkedObject()}.
     *
     * @return int `file.id` value
     */
    public function getFileID(): int {
        return $this->fileId;
    }


    /****************************************************************/
    /*  ACCESS-CONTROL METHODS                                      */
    /****************************************************************/

    /**
     * Determines whether a given user may delete this serialized object.
     *
     * Restricted to the owning user only.
     *
     * @param int $id User ID to check
     * @return bool
     */
    public function canDelete(int $id): bool {
        return $id === $this->userDetailsID;
    }

    /**
     * Determines whether a given user may edit this serialized object.
     *
     * Restricted to the owning user only.
     *
     * @param int $id User ID to check
     * @return bool
     */
    public function canEdit(int $id): bool {
        return $id === $this->userDetailsID;
    }

    /**
     * Determines whether a given user may view this serialized object.
     *
     * Restricted to the owning user only.
     *
     * @param int $id User ID to check
     * @return bool
     */
    public function canView(int $id): bool {
        return $id === $this->userDetailsID;
    }

    /**
     * Determines whether a given user may execute (process) this serialized object.
     *
     * Execution rights cover operations such as submitting the wrapped object
     * as a Job payload. Restricted to the owning user only.
     *
     * @param int $id User ID to check
     * @return bool
     */
    public function canExecute(int $id): bool {
        return $id === $this->userDetailsID;
    }


    /****************************************************************/
    /*  OBJECT MANIPULATION METHODS                                 */
    /****************************************************************/

    /**
     * Updates scalar properties of the wrapped object using PHP Reflection.
     *
     * Deserializes the stored object, iterates over all its properties via
     * ReflectionClass, and overwrites any scalar property whose name appears
     * as a key in $post. Array-typed properties are skipped (the commented-out
     * setArrayValues() handled nested arrays but has been disabled). The
     * modified object is then re-serialized and persisted via {@see updateObject()}.
     *
     * This allows form POST data to update an Article or ReferenceCollection
     * without the caller needing to know the object's internal structure.
     *
     * Note: ReflectionProperty::getValue() and setValue() operate on all
     * visibility levels because the reflection is constructed against the
     * object instance — private/protected properties are accessible.
     *
     * @param array $post Associative array of property names to new scalar values,
     *                    typically from $_POST
     *
     * @todo Re-enable and complete setArrayValues() to support updating
     *       array-typed properties (e.g. author lists, keyword collections).
     */
    public function updateReflectedObject(array $post): void {
        $object  = unserialize($this->object, ['allowed_classes' => true]);
        $reflect = new ReflectionClass($object);
        $props   = $reflect->getProperties();

        foreach ($props as $prop) {
            // Only overwrite scalar (non-array) properties to avoid partial corruption
            if (!is_array($prop->getValue($object))) {
                if (isset($post[$prop->getName()])) {
                    $prop->setValue($object, $post[$prop->getName()]);
                }
            }
            // Array properties (e.g. authors, keywords) are skipped here;
            // setArrayValues() was intended to handle these recursively but
            // is currently disabled — see commented-out code below.
        }

        $this->updateObject($object);
    }

    /*
     * setArrayValues() — recursively updates properties of objects nested inside
     * array-typed properties of the wrapped object. Disabled pending further
     * testing; the logic correctly traverses nested object arrays but was not
     * stable enough for production use.
     *
     * private function setArrayValues(array $array, array $post, int $c): void { ... }
     */

    /**
     * Deletes this serialized object from the database if the requesting user
     * has delete permission.
     *
     * Calls canDelete() before issuing the DELETE statement. Note that the
     * linked File record is NOT removed here — the caller is responsible for
     * managing the file lifecycle separately if required.
     *
     * @param int $uid ID of the user requesting deletion
     */
    public function deleteObject(int $uid): void {
        if ($this->canDelete($uid)) {
            $this->objDB->preparedStatement(
                "delete from serialized_object where id = ?",
                [$this->id]
            );
        }
    }

    /**
     * Replaces the stored object with a new serialized version and persists it.
     *
     * Serializes $object with PHP's serialize(), base64-encodes the result,
     * and writes it to the `serialized_object.object` column. The in-memory
     * $this->object property is updated to match so the instance stays in sync.
     *
     * @param mixed $object The live PHP object to store (typically an Article
     *                      or ReferenceCollection instance)
     */
    public function updateObject(mixed $object): void {
        // Keep in-memory value as the raw serialized string (consistent with constructor)
        $this->object = serialize($object);

        // base64-encode only for DB storage to make binary-safe for text column
        $vals = ['object' => base64_encode($this->object)];
        $this->getObjDB()->update($this->tableName, $vals, "id=" . (int) $this->id);
    }

    /**
     * Updates the file association for this serialized object in the database.
     *
     * Replaces the `file_id` foreign key with $fid, allowing the source file
     * link to be corrected or updated after initial creation.
     *
     * @param int $fid New `file.id` to associate with this object
     *
     */
    public function updateAttachedFile(int $fid): void {
        $vals = ['file_id' => $fid];
        $this->getObjDB()->update($this->tableName, $vals, "id=" . (int) $this->id);
        $this->fileId = $fid;
    }
}
?>