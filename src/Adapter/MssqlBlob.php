<?php

namespace DbSimple\Adapter;

use DbSimple\BlobInterface;

class MssqlBlob implements BlobInterface {

    // Mssql does not support separate BLOB fetching.
    var $blobdata = null;
    var $curSeek = 0;

    function __construct(&$database, $blobdata = null) {
        $this->blobdata = $blobdata;
        $this->curSeek = 0;
    }

    /**
     * {@inheritdoc}
     */
    public function read($len) {
        $p = $this->curSeek;
        $this->curSeek = min($this->curSeek + $len, strlen($this->blobdata));
        return substr($this->blobdata, $this->curSeek, $len);
    }

    /**
     * {@inheritdoc}
     */
    public function write($data) {
        $this->blobdata .= $data;
    }

    /**
     * {@inheritdoc}
     */
    public function close() {
        return $this->blobdata;
    }

    /**
     * {@inheritdoc}
     */
    public function length() {
        return strlen($this->blobdata);
    }

}
