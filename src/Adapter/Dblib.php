<?php

namespace DbSimple\Adapter;

use DbSimple\Database;
use DbSimple\AdapterInterface;
use DbSimple\DatabaseInterface;
use Exception;
use \PDO;
use PDOException;

/**
 * Database class for Mssql/Dblib for Unix system.
 */
class Dblib extends Database implements AdapterInterface, DatabaseInterface {

    private $link;

    public function __construct($dsn) {
        $base = preg_replace('{^/}s', '', $dsn['path']);
        $dsn['path'] = $base;
        if (!class_exists('PDO')) {
            return $this->_setLastError("-1", "PDO extension is not loaded", "PDO");
        }

        try {
            $phpNew = true;
            if (!empty($dsn['socket'])) {
                // Socket connection
                $dsnPdo = 'dblib:unix_socket=' . $dsn['socket'] . ';dbname=' . $base;
                if ($phpNew) {
                    $dsnPdo .= ';charset=' . (!empty($dsn['enc']) ? $dsn['enc'] : 'utf8');
                }
                $this->link = new PDO($dsnPdo, $dsn['user'],$dsn['pass']);
            } else if (!empty($dsn['host'])) {
                // Host connection
                $dsnPdo = 'dblib:host=' . $dsn['host'] . (empty($dsn['port']) ? '' : ';port=' . $dsn['port']) . ';dbname=' . $base;
                if ($phpNew) {
                    $dsnPdo .= ';charset=' . (!empty($dsn['enc']) ? $dsn['enc'] : 'utf8');
                }

                $this->link = new PDO($dsnPdo, $dsn['user'], $dsn['pass']);
            } else {
                throw new Exception("Could not find hostname nor socket in DSN string");
            }
        } catch (PDOException $e) {
            return $this->_setLastError($e->getCode(), $e->getMessage(), 'new PDO');
        }
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
    protected function _performEscape($s, $isIdent = false) {
        if (!$isIdent) {
            return $this->link->quote($s);
        } else {
            return "`" . str_replace('`', '``', $s) . "`";
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function _performTransaction($parameters = null) {
        return $this->link->beginTransaction();
    }

    /**
     * {@inheritdoc}
     */
    protected function _performCommit() {
        return $this->link->commit();
    }

    /**
     * {@inheritdoc}
     */
    protected function _performRollback() {
        return $this->link->rollBack();
    }

    /**
     * {@inheritdoc}
     */
    protected function _performQuery($queryMain) {
        $this->_lastQuery = $queryMain;
        $this->_expandPlaceholders($queryMain, false);
        $p = $this->link->query($queryMain[0]);
        if (!$p) {
            return $this->_setDbError($p, $queryMain[0]);
        }
        if ($p->errorCode() != 0) {
            return $this->_setDbError($p, $queryMain[0]);
        }
        if (preg_match('/^\s* INSERT \s+/six', $queryMain[0])) {
            return $this->link->lastInsertId();
        }
        if ($p->columnCount() == 0) {
            return $p->rowCount();
        }
        //Если у нас в запросе есть хотя-бы одна колонка - это по любому будет select
        $p->setFetchMode(PDO::FETCH_ASSOC);
        $res = $p->fetchAll();
        $p->closeCursor();
        return $res;
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
                    $queryMain[0] = $m[1] . ' SQL_CALC_FOUND_ROWS' . $m[2];
                }
                return true;

            // Perform total calculation.
            case 'GET_TOTAL':
                // Built-in calculation available?
                $queryMain = array('SELECT FOUND_ROWS()');
                return true;
        }

        return false;
    }

    protected function _setDbError($obj, $q) {
        $info = $obj ? $obj->errorInfo() : $this->link->errorInfo();
        return $this->_setLastError($info[1], $info[2], $q);
    }

    /**
     * {@inheritdoc}
     */
    protected function _performNewBlob($id = null) {

    }

    /**
     * {@inheritdoc}
     */
    protected function _performGetBlobFieldNames($result) {
        return array();
    }

    /**
     * {@inheritdoc}
     */
    protected function _performFetch($result) {
        return $result;
    }

}
