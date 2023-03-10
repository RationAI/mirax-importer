<?php
//todo consider meta deletion on non-existing files...?

class Sessions extends SQLite3
{
    function __construct($session_store) {

        $this->open($session_store, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
        $this->exec('PRAGMA journal_mode = wal;');
        $this->busyTimeout(5000);
        $this->ensure($this->exec("CREATE TABLE IF NOT EXISTS logs (id integer PRIMARY KEY AUTOINCREMENT, file varchar(511), request_id varchar(255), tstamp DATETIME, session TEXT);"));
        //$this->ensure($this->exec("CREATE TABLE IF NOT EXISTS sessions (id integer PRIMARY KEY AUTO_INCREMENT, request_id varchar(255), session TEXT);"));

        $this->ensure($this->exec("CREATE TABLE IF NOT EXISTS lock (id integer UNIQUE);")); //todo temporary
    }

    //todo temporary
    public function lock() {
        $stmt = $this->ensure($this->prepare("INSERT INTO lock VALUES (1)"));
        return $this->ensure($stmt->execute());
    }

    public function unlock() {
        $stmt = $this->ensure($this->prepare("DELETE FROM lock WHERE id=1"));
        return $this->ensure($stmt->execute());
    }

    public function locked() {
        $stmt = $this->ensure($this->prepare("SELECT * FROM lock WHERE id=1"));
        return $this->ensure($stmt->execute())->fetchArray(SQLITE3_ASSOC);
    }

    public function ensure($result) {
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
        $stmt = $this->ensure($this->prepare("UPDATE logs SET tstamp = CURRENT_TIMESTAMP, session = ? WHERE file = ? AND request_id = ? ORDER BY tstamp DESC LIMIT 1"));
        $stmt->bindValue(1, $data, SQLITE3_TEXT);
        $stmt->bindValue(2, $file, SQLITE3_TEXT);
        $stmt->bindValue(3, $request_id, SQLITE3_TEXT);
        return $this->ensure($stmt->execute());
    }

    public function getProgress($file, $request_id) {
        $stmt = $this->ensure($this->prepare("SELECT * FROM logs WHERE file = ? AND request_id = ? ORDER BY tstamp DESC"));
        $stmt->bindValue(1, $file, SQLITE3_TEXT);
        $stmt->bindValue(2, $request_id, SQLITE3_TEXT);
        return $this->ensure($stmt->execute());
    }
}
