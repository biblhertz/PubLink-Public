<?php
namespace Biblhertz\Publink\om;

use Biblhertz\Publink\om\BHObject;

/**
 * FileType
 *
 * Represents a registered file type within the PubLink system, corresponding
 * to a row in the `file_type` table. File types define the categories of files
 * that the system recognises (e.g. 'pdf', 'JATS xml', 'jpg') and are referenced
 * by {@see File} objects to determine MIME handling, thumbnail generation, and
 * XML validation behaviour.
 *
 * Unlike most BHObject subclasses, FileType is a lightweight value object: it
 * is constructed directly from known id/name pairs rather than fetching from the
 * database, and its access-control methods grant unrestricted access to all
 * users — file types are treated as shared, read-only reference data managed by
 * administrators at the database level.
 *
 * @package  Biblhertz\Publink\om
 * @author   Chris Tomlinson
 * @since    August 2023
 */
class FileType extends BHObject {

    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                           */
    /****************************************************************/

    /**
     * Constructs a FileType value object from a known id and name.
     *
     * No database look-up is performed; the caller is expected to supply
     * values retrieved from a prior query (e.g. when iterating over all rows
     * in `file_type`). The inherited $objDB and $tableName fields are not
     * required for this lightweight object.
     *
     * @param int    $id   Primary key from `file_type`
     * @param string $name Extension/type label (e.g. 'pdf', 'JATS xml', 'jpg')
     */
    public function __construct(int $id, string $name) {
        $this->id   = $id;
        $this->name = $name;
    }


    /****************************************************************/
    /*  ACCESS-CONTROL METHODS                                      */
    /****************************************************************/

    /**
     * Returns true unconditionally — file types are shared reference data
     * editable by any authenticated user.
     *
     * @param int $id User ID (unused)
     * @return bool   Always true
     */
    public function canEdit(int $id): bool {
        return true;
    }

    /**
     * Returns true unconditionally — any authenticated user may create
     * a new file type entry.
     *
     * Note: canCreate() is not declared on the BHObject abstract class.
     * It is defined here as an additional interface specific to FileType.
     *
     * @param int $id User ID (unused)
     * @return bool   Always true
     */
    public function canCreate(int $id): bool {
        return true;
    }

    /**
     * Returns true unconditionally — file types may be deleted by any
     * authenticated user.
     *
     * @param int $id User ID (unused)
     * @return bool   Always true
     */
    public function canDelete(int $id): bool {
        return true;
    }

    /**
     * Returns true unconditionally — file type information is public
     * within the application and visible to all authenticated users.
     *
     * @param int $id User ID (unused)
     * @return bool   Always true
     */
    public function canView(int $id): bool {
        return true;
    }
}
?>