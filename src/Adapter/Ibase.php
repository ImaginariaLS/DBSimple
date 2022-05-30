<?php

namespace DbSimple\Adapter;

use DbSimple\Adapter\IbaseBlob;
use DbSimple\Database;
use DbSimple\AdapterInterface;
use DbSimple\DatabaseInterface;

/**
 * DbSimple_Ibase: Interbase/Firebird database.
 * (C) Dk Lab, http://en.dklab.ru
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 * See http://www.gnu.org/copyleft/lesser.html
 *
 * Placeholders are emulated because of logging purposes.
 *
 * @author Dmitry Koterov, http://forum.dklab.ru/users/DmitryKoterov/
 * @author Konstantin Zhinko, http://forum.dklab.ru/users/KonstantinGinkoTit/
 *
 * @version 2.x $Id$
 */
/**
 * Best transaction parameters for script queries.
 * They never give us update conflicts (unlike others)!
 * Used by default.
 */
define('IBASE_BEST_TRANSACTION', IBASE_COMMITTED + IBASE_WAIT + IBASE_REC_VERSION);
define('IBASE_BEST_FETCH', IBASE_UNIXTIME);

/**
 * Database class for Interbase/Firebird.
 */
class Ibase extends Database implements AdapterInterface, DatabaseInterface {

    var $DbSimple_Ibase_BEST_TRANSACTION = IBASE_BEST_TRANSACTION;
    var $DbSimple_Ibase_USE_NATIVE_PHOLDERS = true;
    var $fetchFlags = IBASE_BEST_FETCH;
    var $link;
    var $trans;
    var $prepareCache = array();

    /**
     * constructor(string $dsn)
     * Connect to Interbase/Firebird.
     */
    function __construct($dsn) {
        $p = Database::parseDSN($dsn);
        if (!is_callable('ibase_connect')) {
            return $this->_setLastError("-1", "Interbase/Firebird extension is not loaded", "ibase_connect");
        }
        $ok = $this->link = ibase_connect(
            $p['host'] . (empty($p['port']) ? "" : ":" . $p['port']) . ':' . preg_replace('{^/}s', '', $p['path']), $p['user'], $p['pass'], isset($p['CHARSET']) ? $p['CHARSET'] : 'win1251', isset($p['BUFFERS']) ? $p['BUFFERS'] : 0, isset($p['DIALECT']) ? $p['DIALECT'] : 3, isset($p['ROLE']) ? $p['ROLE'] : ''
        );
        if (isset($p['TRANSACTION'])) {
            $this->DbSimple_Ibase_BEST_TRANSACTION = eval($p['TRANSACTION'] . ";");
        }
        $this->_resetLastError();
        if (!$ok) {
            return $this->_setDbError('ibase_connect()');
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function _performEscape($s, $isIdent = false) {
        if (!$isIdent) {
            return "'" . str_replace("'", "''", $s) . "'";
        } else {
            return '"' . str_replace('"', '_', $s) . '"';
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function _performTransaction($parameters = null) {
        if ($parameters === null) {
            $parameters = $this->DbSimple_Ibase_BEST_TRANSACTION;
        }
        $this->trans = ibase_trans($parameters, $this->link);
    }

    /**
     * {@inheritdoc}
     */
    protected function _performNewBlob($blobid = null) {
        return new IbaseBlob($this, $blobid);
    }

    /**
     * {@inheritdoc}
     */
    protected function _performGetBlobFieldNames($result) {
        $blobFields = array();
        for ($i = ibase_num_fields($result) - 1; $i >= 0; $i--) {
            $info = ibase_field_info($result, $i);
            if ($info['type'] === "BLOB") {
                $blobFields[] = $info['name'];
            }
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
        if (!is_resource($this->trans)) {
            return false;
        }
        $result = ibase_commit($this->trans);
        if (true === $result) {
            $this->trans = null;
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function _performRollback() {
        if (!is_resource($this->trans)) {
            return false;
        }
        $result = ibase_rollback($this->trans);
        if (true === $result) {
            $this->trans = null;
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function _performTransformQuery(&$queryMain, $how) {
        // If we also need to calculate total number of found rows...
        switch ($how) {
            // Prepare total calculation (if possible)
            case 'CALC_TOTAL':
                // Not possible
                return true;

            // Perform total calculation.
            case 'GET_TOTAL':
                // TODO: GROUP BY ... -> COUNT(DISTINCT ...)
                $re = '/^
                    (?> -- [^\r\n]* | \s+)*
                    (\s* SELECT \s+)                                      #1
                        ((?:FIRST \s+ \S+ \s+ (?:SKIP \s+ \S+ \s+)? )?)   #2
                    (.*?)                                                 #3
                    (\s+ FROM \s+ .*?)                                    #4
                        ((?:\s+ ORDER \s+ BY \s+ .*)?)                    #5
                $/six';
                $m = null;
                if (preg_match($re, $queryMain[0], $m)) {
                    $queryMain[0] = $m[1] . $this->_fieldList2Count($m[3]) . " AS C" . $m[4];
                    $skipHead = substr_count($m[2], '?');
                    if ($skipHead) {
                        array_splice($queryMain, 1, $skipHead);
                    }
                    $skipTail = substr_count($m[5], '?');
                    if ($skipTail) {
                        array_splice($queryMain, -$skipTail);
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
        $this->_expandPlaceholders($queryMain, $this->DbSimple_Ibase_USE_NATIVE_PHOLDERS);

        $hash = $queryMain[0];

        if (!isset($this->prepareCache[$hash])) {
            $this->prepareCache[$hash] = ibase_prepare((is_resource($this->trans) ? $this->trans : $this->link), $queryMain[0]);
        } else {
            // Prepare cache hit!
        }

        $prepared = $this->prepareCache[$hash];
        if (!$prepared) {
            return $this->_setDbError($queryMain[0]);
        }
        $queryMain[0] = $prepared;
        $result = call_user_func_array('ibase_execute', $queryMain);
        // ATTENTION!!!
        // WE MUST save prepared ID (stored in $prepared variable) somewhere
        // before returning $result because of ibase destructor. Now it is done
        // by $this->prepareCache. When variable $prepared goes out of scope, it
        // is destroyed, and memory for result also freed by PHP. Totally we
        // got "Invalud statement handle" error message.

        if ($result === false) {
            return $this->_setDbError($queryMain[0]);
        }
        if (!is_resource($result)) {
            // Non-SELECT queries return number of affected rows, SELECT - resource.
            return ibase_affected_rows((is_resource($this->trans) ? $this->trans : $this->link));
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function _performFetch($result) {
        // Select fetch mode.
        $flags = $this->fetchFlags;
        if (empty($this->attributes['BLOB_OBJ'])) {
            $flags = $flags | IBASE_TEXT;
        } else {
            $flags = $flags & ~IBASE_TEXT;
        }

        $row = ibase_fetch_assoc($result, $flags);
        if (ibase_errmsg()) {
            return $this->_setDbError($this->_lastQuery);
        }

        return $row;
    }

    protected function _setDbError($query) {
        return $this->_setLastError(ibase_errcode(), ibase_errmsg(), $query);
    }

}
