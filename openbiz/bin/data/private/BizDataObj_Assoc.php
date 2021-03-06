<?php
/**
 * PHPOpenBiz Framework
 *
 * LICENSE
 *
 * This source file is subject to the BSD license that is bundled
 * with this package in the file LICENSE.txt.
 *
 * @package   openbiz.bin.data.private
 * @copyright Copyright &copy; 2005-2009, Rocky Swen
 * @license   http://www.opensource.org/licenses/bsd-license.php
 * @link      http://www.phpopenbiz.org/
 * @version   $Id$
 */

/**
 * BizDataObj_Assoc class takes care of add and remove record according to
 * data object association
 *
 * @package openbiz.bin.data.private
 * @author Rocky Swen
 * @copyright Copyright (c) 2005-2009
 * @access private
 */
class BizDataObj_Assoc
{

    /**
     * Add a new record to current record set
     *
     * @param BizDataObj $dataObj - the instance of BizDataObj
     * @param array $recArr - the record array to be added in the data object
     * @param boolean &$isParentObjUpdated - flag that indicates if the parent form needs to be updated
     * @return boolean
     */
    public static function addRecord($dataObj, $recArr, &$isParentObjUpdated)
    {
        if ($dataObj->m_Association["Relationship"] == "M-M")
        {
            $isParentObjUpdated = false;
            return self::_addRecordMtoM($dataObj, $recArr);
        }
        elseif ($dataObj->m_Association["Relationship"] == "M-1"
                || $dataObj->m_Association["Relationship"] == "1-1")
        {
            $isParentObjUpdated = true;
            return self::_addRecordMto1($dataObj, $recArr);
        }
        else
        {
            throw new BDOException("You cannot add a record in dataobj who doesn't have M-M or M-1 relationship with its parent object");
            return false;
        }
    }

    /**
     * Add record many to many (M-M)
     *
     * @param BizDataObj $dataObj
     * @param array $recArr
     * @return boolean
     */
    private static function _addRecordMtoM($dataObj, $recArr)
    {
        // query on this object to get the corresponding record of this object.
        $searchRule = "[Id] = '".$recArr["Id"]."'";
        $recordList = $dataObj->directFetch($searchRule, 1);
        if (count($recordList) == 1) return true;

        // insert a record on XTable
        $db = $dataObj->getDBConnection();
        $xDataObj = isset($dataObj->m_Association["XDataObj"]) ? $dataObj->m_Association["XDataObj"] : null;
        $val1 = $dataObj->m_Association["FieldRefVal"];
        $val2 = $recArr["Id"];
        if ($xDataObj)
        {   // get new record from XDataObj
            $xObj = BizSystem::getObject($xDataObj);
            $newRecArr = $xObj->newRecord();
            // verify the main table of XDataobj is same as the XTable
            if ($xObj->m_MainTable != $dataObj->m_Association["XTable"])
            {
                throw new BDOException("Unable to create a record in intersection table: XDataObj's main table is not same as XTable.");
                return false;
            }
            $fld1 = $xObj->getFieldNameByColumn($dataObj->m_Association["XColumn1"]);
            $newRecArr[$fld1] = $val1;
            $fld2 = $xObj->getFieldNameByColumn($dataObj->m_Association["XColumn2"]);
            $newRecArr[$fld2] = $val2;
            $ok = $xObj->insertRecord($newRecArr);
            if ($ok === false)
            {
                throw new BDOException($xObj->getErrorMessage());
                return false;
            }
        }
        else
        {
            $sql_col = "(" . $dataObj->m_Association["XColumn1"] . ","
                        . $dataObj->m_Association["XColumn2"].")";

            $sql_val = "('".$val1."','".$val2."')";
            $sql = "INSERT INTO " . $dataObj->m_Association["XTable"] . " "
                    . $sql_col . " VALUES " . $sql_val;

            try
            {
                BizSystem::log(LOG_DEBUG, "DATAOBJ", "Associate Insert Sql = $sql");
                $db->query($sql);
            }
            catch (Exception $e)
            {
                BizSystem::log(LOG_ERR, "DATAOBJ", "Query Error: ".$e->getMessage());
                throw new BDOException("Query Error: " . $e->getMessage());
                return false;
            }
        }

        // add the record to object cache. requery on this object to get the corresponding record of this object.
        $searchRule = "[Id] = '".$recArr["Id"]."'";
        $recordList = $dataObj->directFetch($searchRule, 1);
        if (count($recordList) == 0)
            return false;
        return true;
    }

    /**
     * Add record Many to One (M-1)
     * @param BizDataObj $dataObj
     * @param array $recArr
     * @return boolean
     */
    private static function _addRecordMto1($dataObj, $recArr)
    {
        // set the $recArr[Id] to the parent table foriegn key column
        // get parent/association dataobj
        $asscObj = BizSystem::getObject($dataObj->m_Association["AsscObjName"]);
        // call parent dataobj's updateRecord
        $updateRecArr["Id"] = $asscObj->getFieldValue("Id");
        $updateRecArr[$dataObj->m_Association["FieldRef"]] = $recArr["Id"];
        $ok = $asscObj->updateRecord($updateRecArr);
        if ($ok == false)
            return false;
        // requery on this object
        $dataObj->m_Association["FieldRefVal"] = $recArr["Id"];
        return $dataObj->runSearch();
    }

    /**
     * Remove a record from current record set of current association relationship
     *
     * @param BizDataObj $dataObj - the instance of BizDataObj
     * @param array $recArr - the record array to be removed from the data object
     * @param boolean &$isParentObjUpdated - flag that indicates if the parent form needs to be updated
     * @return boolean
     */
    public static function removeRecord($dataObj, $recArr, &$isParentObjUpdated)
    {
        if ($dataObj->m_Association["Relationship"] == "M-M")
        {
            $isParentObjUpdated = false;
            return self::_removeRecordMtoM($dataObj, $recArr);
        }
        elseif ($dataObj->m_Association["Relationship"] == "M-1" || $dataObj->m_Association["Relationship"] == "1-1")
        {
            $isParentObjUpdated = true;
            return self::_removeRecordMto1($dataObj, $recArr);
        }
        else
        {
            throw new BDOException("You cannot add a record in dataobj who doesn't have M-M or M-1 relationship with its parent object");
            return false;
        }
    }

    /**
     * Remove record many to many
     *
     * @param BizDataObj $dataObj
     * @param array $recArr
     * @return boolean
     */
    private static function _removeRecordMtoM($dataObj, $recArr)
    {
        // delete a record on XTable
        $db = $dataObj->getDBConnection();

        //TODO: delete using XDataObj if XDataObj is defined

        $where = $dataObj->m_Association["XColumn1"] . "='" . $dataObj->m_Association["FieldRefVal"] . "'";
        $where .= " AND " . $dataObj->m_Association["XColumn2"] . "='" . $recArr["Id"] . "'";
        $sql = "DELETE FROM " . $dataObj->m_Association["XTable"] . " WHERE " . $where;

        try
        {
            BizSystem::log(LOG_DEBUG, "DATAOBJ", "Associate Delete Sql = $sql");
            $db->query($sql);
        }
        catch (Exception $e)
        {
            BizSystem::log(LOG_ERR, "DATAOBJ", "Query Error: ".$e->getMessage());
            throw new BDOException("Query Error: " . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Remove record many to one
     *
     * @global BizSystem $g_BizSystem
     * @param BizDataObj $dataObj
     * @param array $recArr
     * @return boolean
     */
    private static function _removeRecordMto1($dataObj, $recArr)
    {
        // set the $recArr[Id] to the parent table foriegn key column
        // get parent/association dataobj
        $asscObj = BizSystem::getObject($dataObj->m_Association["AsscObjName"]);
        // call parent dataobj's updateRecord
        $updateRecArr["Id"] = $asscObj->getFieldValue("Id");
        $updateRecArr[$dataObj->m_Association["FieldRef"]] = "";
        $ok = $asscObj->updateRecord($updateRecArr);
        if ($ok == false)
            return false;
        // requery on this object
        $dataObj->m_Association["FieldRefVal"] = "";
        return $dataObj->runSearch();
    }

}