<?php

class Sessions extends SQLite3
{

    function __construct($session_store)
    {
        $this->open($session_store, SQLITE3_OPEN_READWRITE);
        $this->try($this->exec("CREATE TABLE IF NOT EXISTS logs (id varchar(255) PRIMARY KEY, tstamp DATE, session TEXT);"));
        $this->try($this->exec("CREATE TABLE IF NOT EXISTS sessions (id varchar(255) PRIMARY KEY, request_id varchar(255), session TEXT);"));
    }

    // public function reporter(...$args) {
    //     echo join(", ", $args);
    // }

    private function try($result)
    {
        if (!$result) {
            //$this->reporter($this->lastErrorMsg());
            throw new Exception($this->lastErrorMsg());
        }
        return $result;
    }

//    public function readOne($id)
//    {
//        return $this->_commonQuery(function ($where) {
//            return "SELECT * FROM sessions WHERE $where";
//        }, array("id" => $id));
//    }
//
//    public function storeOne($id, $content)
//    {
//        $stmt = $this->try($this->prepare("INSERT INTO sessions(id, session) VALUES (?, ?)"));
//        $stmt->bindValue(1, $id, SQLITE3_TEXT);
//        $stmt->bindValue(2, $content, SQLITE3_TEXT);
//        return $stmt->execute();
//    }
}