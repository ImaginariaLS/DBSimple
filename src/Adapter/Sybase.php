<?php

namespace DbSimple\Adapter;

use DbSimple\Adapter\SybaseBlob;
use DbSimple\Database;
use DbSimple\AdapterInterface;
use DbSimple\DatabaseInterface;

/**
 * DbSimple_Sybase: Sybase database.
 * (C) Dk Lab, http://en.dklab.ru
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 * See http://www.gnu.org/copyleft/lesser.html
 *
 * Placeholders end blobs are emulated.
 *
 * @author Dmitry Koterov, http://forum.dklab.ru/users/DmitryKoterov/
 * @author Konstantin Zhinko, http://forum.dklab.ru/users/KonstantinGinkoTit/
 * @author Ivan A-R (Mssql => Sybase)
 *
 * @version 2.x $Id: Sybase.php 163 2007-01-10 09:47:49Z dk $
 */

/**
 * Database class for Sybase.
 */
class Sybase extends Database implements AdapterInterface, DatabaseInterface {

    var $link;
    private $_result;
    private $_text_fields;
    // Allow on fly DB encodings
    protected $lcharset = NULL; // Local charset
    protected $rcharset = NULL; // Remote charset

    /**
     * constructor(string $dsn)
     * Connect to Sybase.
     */
    function __construct($dsn) {
        if (!is_callable('sybase_connect')) {
            return $this->_setLastError("-1", "Sybase extension is not loaded", "sybase_connect");
        }

        if (isset($dsn['lcharset'])) {
            $this->lcharset = $dsn['lcharset'];
        }
        if (isset($dsn['rcharset'])) {
            $this->rcharset = $dsn['rcharset'];
        }

        // May be use sybase_connect or sybase_pconnect
        $ok = $this->link = sybase_pconnect($dsn['host'] . (empty($dsn['port']) ? "" : ":" . $dsn['port']), $dsn['user'], $dsn['pass']);
        $this->_resetLastError();
        if (!$ok) {
            return $this->_setDbError('sybase_connect()');
        }
        $ok2 = sybase_select_db(preg_replace('{^/}s', '', $dsn['path']), $this->link);
        if (!$ok2) {
            return $this->_setDbError('sybase_select_db()');
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function _performEscape($s, $isIdent = false) {
        if (!$isIdent) {
            if (is_int($s)) {
                return $s;
            } else {
                return "'" . str_replace("'", "''", $s) . "'";
            }
        } else {
            return str_replace(array('[', ']'), '', $s);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function _performTransaction($parameters = null) {
        return $this->query('BEGIN TRANSACTION');
    }

    /**
     * {@inheritdoc}
     */
    protected function _performNewBlob($blobid = null) {
        $obj = new SybaseBlob($this, $blobid);
        return $obj;
    }

    /**
     * {@inheritdoc}
     */
    protected function _performGetBlobFieldNames($result) {
        $blobFields = array();
        for ($i = sybase_num_fields($result) - 1; $i >= 0; $i--) {
            $type = sybase_fetch_field($result, $i);
            if (strpos($type->type, "BINARY") !== false) {
                $blobFields[] = $type->name;
            }
            unset($type);
        }
        return $blobFields;
    }

    /**
     * {@inheritdoc}
     */
    protected function _performGetPlaceholderIgnoreRe() {
        return '
            "   (?> [^"\\\\]+|\\\\"|\\\\)*    "   |
            \'  (?> [^\'\\\\]+|\\\\\'|\\\\)* \'   |
            `   (?> [^`]+ | ``)*              `   |   # backticks
            /\* .*?                          \*/      # comments
        ';
    }

    /**
     * {@inheritdoc}
     */
    protected function _performCommit() {
        return $this->query('COMMIT TRANSACTION');
    }

    /**
     * {@inheritdoc}
     */
    protected function _performRollback() {
        return $this->query('ROLLBACK TRANSACTION');
    }

    /**
     * {@inheritdoc}
     */
    protected function _performTransformQuery(&$queryMain, $how) {
        // If we also need to calculate total number of found rows...
        switch ($how) {
            // Prepare total calculation (if possible)
            case 'CALC_TOTAL':
                $m = null;
                if (preg_match('/^(\s* SELECT)(.*)/six', $queryMain[0], $m)) {
                    if ($this->_calcFoundRowsAvailable()) {
                        $queryMain[0] = $m[1] . ' SQL_CALC_FOUND_ROWS' . $m[2];
                    }
                }
                return true;

            // Perform total calculation.
            case 'GET_TOTAL':
                // Built-in calculation available?
                if ($this->_calcFoundRowsAvailable()) {
                    $queryMain = array('SELECT FOUND_ROWS()');
                }
                // Else use manual calculation.
                // TODO: GROUP BY ... -> COUNT(DISTINCT ...)
                $re = '/^
                    (?> -- [^\r\n]* | \s+)*
                    (\s* SELECT \s+)                                      #1
                    (.*?)                                                 #2
                    (\s+ FROM \s+ .*?)                                    #3
                        ((?:\s+ ORDER \s+ BY \s+ .*?)?)                   #4
                        ((?:\s+ LIMIT \s+ \S+ \s* (?:, \s* \S+ \s*)? )?)  #5
                $/six';
                $m = null;
                if (preg_match($re, $queryMain[0], $m)) {
                    $query[0] = $m[1] . $this->_fieldList2Count($m[2]) . " AS C" . $m[3];
                    $skipTail = substr_count($m[4] . $m[5], '?');
                    if ($skipTail) {
                        array_splice($query, -$skipTail);
                    }
                }
                return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    protected function _performQuery($queryMain) {
        $this->_lastQuery = $queryMain;
        $this->_expandPlaceholders($queryMain, false);

        // Convert query if allow on fly encodings
        if ($this->lcharset && $this->rcharset) {
            $sql_query = mb_convert_encoding($queryMain[0], $this->rcharset, $this->lcharset);
        } else {
            $sql_query = $queryMain[0];
        }

        $result = sybase_query($sql_query, $this->link);

        if ($result === false) {
            return $this->_setDbError($queryMain[0]);
        }

        if (!is_resource($result)) {
            if (preg_match('/^\s* INSERT \s+/six', $queryMain[0])) {
                // INSERT queries return generated ID.
                $result = sybase_fetch_assoc(sybase_query("SELECT @@identity insert_id", $this->link));
                return isset($result['insert_id']) ? $result['insert_id'] : true;
            }

            // Non-SELECT queries return number of affected rows, SELECT - resource.

            return sybase_affected_rows($this->link);
        }
        return $result;
    }

    private function _getTextFields($result) {
        if ($this->_result == $result) {
            return $this->_text_fields;
        }
        $this->_result = $result;
        $this->_text_fields = array();
        for ($i = sybase_num_fields($result) - 1; $i >= 0; $i--) {
            $type = sybase_fetch_field($result, $i);
            if (!$type->numeric) {
                $this->_text_fields[$type->name] = $type->type;
            }
        }
        return $this->_text_fields;
    }

    /**
     * {@inheritdoc}
     */
    protected function _performFetch($result) {
        $row = sybase_fetch_assoc($result);
        //if (sybase_error()(!!!)) return $this->_setDbError($this->_lastQuery);
        if ($row === false) {
            return null;
        }

        // sybase bugfix - replase ' ' to ''
        // Encoding string fields on fly
        if (is_array($row)) {
            $tf = $this->_getTextFields($result);
            foreach ($tf as $k => $t) {
                $v = $row[$k];
                if (!is_null($v)) {
                    if ($v === ' ') { // Sybase bugfix
                        $v = '';
                    } else {
                        if ($this->lcharset && $this->rcharset) {
                            $v = mb_convert_encoding($v, $this->lcharset, $this->rcharset);
                        }
                    }
                }
                $row[$k] = $v;
            }
        }
        return $row;
    }

    function _setDbError($query, $errors = null) {
        return $this->_setLastError('Error! ', sybase_get_last_message() . strip_tags($errors), $query);
    }

    function _calcFoundRowsAvailable() {
        return false;
    }

}
