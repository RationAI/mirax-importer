<?php

class Sessions extends SQLite3
{
    function __construct($session_store) {
        $this->open($session_store, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
        $this->ensure($this->exec("CREATE TABLE IF NOT EXISTS logs (id integer PRIMARY KEY AUTOINCREMENT, file varchar(511), request_id varchar(255), tstamp DATETIME, session TEXT);"));
        //$this->ensure($this->exec("CREATE TABLE IF NOT EXISTS sessions (id integer PRIMARY KEY AUTO_INCREMENT, request_id varchar(255), session TEXT);"));
    }

    private function ensure($result) {
        if (!$result) {
            //$this->reporter($this->lastErrorMsg());
            throw new Exception($this->lastErrorMsg());
        }
        return $result;
    }

    public function saveJobLog($file, $request_id, $data) {
        $stmt = $this->ensure($this->prepare("INSERT INTO logs(file, request_id, tstamp, session) VALUES (?, ?, CURRENT_TIMESTAMP, ?)"));
        $stmt->bindValue(1, $file, SQLITE3_TEXT);
        $stmt->bindValue(2, $request_id, SQLITE3_TEXT);
        $stmt->bindValue(3, $data, SQLITE3_TEXT);
        return $this->ensure($stmt->execute());
    }

    public function setProgress($file, $request_id, $data) {
        $stmt = $this->ensure($this->prepare("UPDATE logs SET tstamp = CURRENT_TIMESTAMP, session = ? WHERE file = ? AND request_id = ?"));
        $stmt->bindValue(1, $data, SQLITE3_TEXT);
        $stmt->bindValue(2, $file, SQLITE3_TEXT);
        $stmt->bindValue(3, $request_id, SQLITE3_TEXT);
        return $this->ensure($stmt->execute());
    }

    public function getProgress($file, $request_id) {
        $stmt = $this->ensure($this->prepare("SELECT * FROM logs WHERE file = ? AND request_id = ?"));
        $stmt->bindValue(1, $file, SQLITE3_TEXT);
        $stmt->bindValue(2, $request_id, SQLITE3_TEXT);
        return $this->ensure($stmt->execute());
    }
}