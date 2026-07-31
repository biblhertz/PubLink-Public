<?php

namespace Biblhertz\Article\om\presentation;

use Biblhertz\Publink\om\Task;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\pages\htmlPage;
use Biblhertz\Article\om\ReferenceCollection;
use Biblhertz\Article\om\presentation\ReferencePresentation;
use Biblhertz\Publink\utilities\PDODatabase;


/********************************************************************/
/*  ObjectPresentation                                              */
/*                                                                  */
/*  Author  :   Chris Tomlinson                                     */
/*  Date    :   10th July 2023                                      */
/*                                                                  */
/*  Abstract base class for all presentation classes in the         */
/*  PubLink editorial interface. Provides shared state (a           */
/*  ReferenceCollection) and common rendering methods available     */
/*  to all concrete subclasses (e.g. ArticlePresentation).          */
/********************************************************************/

/**
 * Abstract superclass for PubLink presentation classes.
 *
 * Defines shared infrastructure used by concrete presenters:
 *  - A protected $referenceCollection populated by the subclass constructor.
 *  - getTaskPanel() — renders executable tasks as Bootstrap button forms.
 *
 * The commented-out getReferencesPanel() method is retained for reference;
 * it provided a two-column reference/DOI-match table and may be reinstated
 * in a future revision.
 *
 * @package Biblhertz\Article\om\presentation
 */
abstract class ObjectPresentation {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /**
     * @var ReferenceCollection The reference collection associated with the
     *                          presented object. Populated by the subclass
     *                          constructor before any presentation methods
     *                          are called.
     */
    protected ReferenceCollection $referenceCollection;


    /****************************************************************/
    /*  PRESENTATION METHODS                                        */
    /****************************************************************/

    /**
     * Renders all tasks applicable to the current object as a row of
     * Bootstrap button forms inside an HTML table.
     *
     * Tasks are looked up via the "object" file type. Only tasks for which
     * the given user has execute permission (Task::canExecute()) are rendered.
     * Each task produces a single-cell form containing:
     *  - A hidden `task_id` field.
     *  - A hidden field named after the task's code name, carrying $oid.
     *  - A submit button labelled with the task name.
     *  - The task description displayed alongside the button.
     *
     * @param User        $user   The current user; used to gate task visibility.
     * @param int         $oid    The serialized object ID passed as the task's
     *                            target payload in the hidden input.
     * @param PDODatabase $objDB  Database handle used to query tasks and
     *                            instantiate Task objects.
     *
     * @return string HTML <table> markup containing one <td> per executable task.
     */
    public function getTaskPanel(User $user, int $oid, PDODatabase $objDB): string {
        $tasks = $objDB->preparedSelect(
            "select task_id from task_file_type where file_type_id = (select id from file_type where name = ?)",
            array("object")
        );

        $tcontent = "<table class=\"table table-bordered\"><tr>";

        while ($t = $tasks->fetch()) {
            $task = new Task($objDB, $t['task_id']);
            if ($task->canExecute($user)) {
                $buttonid  = $task->getCodeName() . "_button";
                $safeAction      = htmlspecialchars($task->getActionHandler(), ENT_QUOTES, 'UTF-8');
                $safeName        = htmlspecialchars($task->getName(),          ENT_QUOTES, 'UTF-8');
                $safeDescription = htmlspecialchars($task->getDescription(),   ENT_QUOTES, 'UTF-8');

                $tcontent .= "<td><form action=\"$safeAction\" method=\"POST\"><table><tr><td>"
                           . htmlPage::makeHiddenInput("task_id", $task->getID())
                           . htmlPage::makeHiddenInput($task->getCodeName(), $oid)
                           . "<button class=\"btn btn-outline-primary\" name=\"$buttonid\" id=\"$buttonid\" type=\"SUBMIT\">"
                           . $safeName
                           . "</button>"
                           . "</td><td>$safeDescription</td></tr></table></form></td>";
            }
        }

        return $tcontent . "</tr></table>";
    }


    /**
     * Returns a two-element array containing a reference count and an HTML table
     * of reference rows, each showing the parsed reference alongside its DOI
     * match result (or a "no check carried out" message).
     *
     * Rows are filtered by $doi:
     *  - $doi = false  → include only references whose pub-id type is "unset"
     *                    (i.e. not yet resolved to a DOI).
     *  - $doi = true   → include only references that have a resolved pub-id type.
     *
     * When the collection's reference check flag is set, the DOI column is
     * rendered via ReferencePresentation::getDOIMatchAsTableNew(); otherwise a
     * placeholder message is shown.
     *
     * @param bool        $doi    Filtering mode: false = unresolved refs, true = resolved refs.
     * @param string|bool $target Form post-back URL passed to getDOIMatchAsTableNew(), or false.
     * @param int|bool    $id     Serialized object ID passed to getDOIMatchAsTableNew(), or false.
     * @param int|bool    $tab    Active tab index passed to getDOIMatchAsTableNew(), or false.
     *
     * @return array{0: int, 1: string} [count of matching rows, HTML table markup].
     *
     * @todo Uncomment and integrate once the DOI matching workflow is reinstated.
     */
    /*
    protected function getReferencesPanel(bool $doi = false, string|bool $target = false, int|bool $id = false, int|bool $tab = false): array {
        $num      = 0;
        $doiTable = "<table class=\"table table-bordered table-striped table-sm\" width=\"100%\">";

        foreach ($this->referenceCollection as $ref) {
            $presentation = new ReferencePresentation($ref);

            if ($this->referenceCollection->getReferenceCheck()) {
                $line = "<tr>"
                      . "<td width=\"50%\">" . $presentation->getAsTable() . "</td>"
                      . "<td width=\"50%\">" . $presentation->getDOIMatchAsTableNew($target, $id, $tab) . "</td>"
                      . "</tr>";
            } else {
                $line = "<tr>"
                      . "<td width=\"50%\">" . $presentation->getAsTable() . "</td>"
                      . "<td width=\"50%\">No reference check has been carried out</td>"
                      . "</tr>";
            }

            // Include row only if it matches the requested DOI resolution state
            if ((!$doi && $ref->getPubIdType() === "unset") ||
                ($doi  && $ref->getPubIdType() !== "unset")) {
                $num++;
                $doiTable .= $line;
            }
        }

        $doiTable .= "</table>";
        return array($num, $doiTable);
    }
    */
}
?>