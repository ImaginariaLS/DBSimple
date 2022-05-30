<?php

namespace DbSimple\Adapter;

use DbSimple\BlobInterface;

class Blob implements BlobInterface {

    var $blob; // resourse link
    var $id;
    var $database;

    function __construct(&$database, $id = null) {
        $this->database = & $database;
        $this->id = $id;
        $this->blob = null;
    }

    /**
     * {@inheritdoc}
     */
    public function read($len) {
        if ($this->id === false) {
            return ''; // wr-only blob
        }
        if (!($e = $this->_firstUse())) {
            return $e;
        }
        $data = ibase_blob_get($this->blob, $len);
        if ($data === false) {
            return $this->_setDbError('read');
        }
        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function write($data) {
        if (!($e = $this->_firstUse())) {
            return $e;
        }
        $ok = ibase_blob_add($this->blob, $data);
        if ($ok === false) {
            return $this->_setDbError('add data to');
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function close() {
        if (!($e = $this->_firstUse())) {
            return $e;
        }
        if ($this->blob) {
            $id = ibase_blob_close($this->blob);
            if ($id === false) {
                return $this->_setDbError('close');
            }
            $this->blob = null;
        } else {
            $id = null;
        }
        return $this->id ? $this->id : $id;
    }

    /**
     * {@inheritdoc}
     */
    public function length() {
        if ($this->id === false) {
            return 0; // wr-only blob
        }
        if (!($e = $this->_firstUse())) {
            return $e;
        }
        $info = ibase_blob_info($this->id);
        if (!$info) {
            return $this->_setDbError('get length of');
        }
        return $info[0];
    }

    function _setDbError($query) {
        $hId = $this->id === null ? "null" : ($this->id === false ? "false" : $this->id);
        $query = "-- $query BLOB $hId";
        $this->database->_setDbError($query);
    }

    // Called on each blob use (reading or writing).
    function _firstUse() {
        // BLOB is opened - nothing to do.
        if (is_resource($this->blob)) {
            return true;
        }
        // Open or create blob.
        if ($this->id !== null) {
            $this->blob = ibase_blob_open($this->id);
            if ($this->blob === false) {
                return $this->_setDbError('open');
            }
        } else {
            $this->blob = ibase_blob_create($this->database->link);
            if ($this->blob === false) {
                return $this->_setDbError('create');
            }
        }

        return true;
    }

}
