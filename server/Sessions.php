<?php

class Sessions extends SQLite3
{

    function __construct($session_store) {
        $this->open($session_store, SQLITE3_OPEN_READWRITE);
        $this->try($this->exec("CREATE TABLE IF NOT EXISTS logs (id integer PRIMARY KEY AUTO_INCREMENT, request_id varchar(255), tstamp DATETIME, session TEXT);"));
        $this->try($this->exec("CREATE TABLE IF NOT EXISTS sessions (id integer PRIMARY KEY AUTO_INCREMENT, request_id varchar(255), session TEXT);"));
    }

    // public function reporter(...$args) {
    //     echo join(", ", $args);
    // }

    private function try($result) {
        if (!$result) {
            //$this->reporter($this->lastErrorMsg());
            throw new Exception($this->lastErrorMsg());
        }
        return $result;
    }

    public function saveJobLog($request_id, $data) {
        $stmt = $this->try($this->prepare("INSERT INTO logs(request_id, tstamp, session) VALUES (?, CURRENT_TIMESTAMP, ?)"));
        $stmt->bindValue(1, $request_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, json_encode($data), SQLITE3_TEXT);
        return $stmt->execute();
    }

    //todo
    public function setProgress($session_id, $request_id, $data=null) {
        if ($data === null) {

        } else {
            $stmt = $this->try($this->prepare("INSERT INTO logs(request_id, tstamp, session) VALUES (?, CURRENT_TIMESTAMP, ?)"));
            $stmt->bindValue(1, $request_id, SQLITE3_INTEGER);
            $stmt->bindValue(2, json_encode($data), SQLITE3_TEXT);
            return $stmt->execute();
        }

    }
    public function getProgress($session_id, $request_id, $data) {
        $stmt = $this->try($this->prepare("INSERT INTO logs(request_id, tstamp, session) VALUES (?, CURRENT_TIMESTAMP, ?)"));
        $stmt->bindValue(1, $request_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, json_encode($data), SQLITE3_TEXT);
        return $stmt->execute();
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