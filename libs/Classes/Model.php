<?php
class Model {

    public $db;

    public function __construct(){
        $this->db = new Database();
    }
    
    public function sql($sql, $array = array(), $fetchMode = PDO::FETCH_ASSOC)
    {
        $sth = $this->db->prepare($sql);
        if (count($array) > 0) {
            foreach ($array as $key => $value) {
                $sth->bindValue("$key", $value);
            }
        }
        return $sth->execute();
    }
    
    public function select($sql, $array = array(), $fetchMode = PDO::FETCH_ASSOC)
    {
        $sth = $this->db->prepare($sql);
        if (count($array) > 0) {
            foreach ($array as $key => $value) {
                $param = (is_string($key) && strpos($key, ':') !== 0) ? ":$key" : $key;
                $sth->bindValue($param, $value);
            }
        }
        $sth->execute();
        return $sth->fetchAll($fetchMode);
    }
    
    public function insert($table, $data)
    {
        ksort($data);
        $columns = array();
        $placeholders = array();
        foreach (array_keys($data) as $key) {
            $columns[] = $this->quoteColumn($key);
            $placeholders[] = ':' . $key;
        }
        $sql = 'INSERT INTO ' . $this->quoteIdentifier($table)
             . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $sth = $this->db->prepare($sql);
        foreach ($data as $key => $value) {
            $sth->bindValue(":$key", $value);
        }
        $sth->execute();
        return $this->db->lastInsertId();
    }

    private function bindWhereParams($sth, array $whereParams): void
    {
        foreach ($whereParams as $key => $value) {
            $param = (is_string($key) && strpos($key, ':') !== 0) ? ":$key" : $key;
            $sth->bindValue($param, $value);
        }
    }

    // NOTE: $where is raw SQL written by the caller. NEVER concatenate user
    // input into it — pass values through $whereParams (bound) instead.
    public function update($table, $data, $where, $whereParams = array())
    {
        ksort($data);
        $assignments = array();
        foreach (array_keys($data) as $key) {
            $assignments[] = $this->quoteColumn($key) . '=:' . $key;
        }
        $sql = 'UPDATE ' . $this->quoteIdentifier($table)
             . ' SET ' . implode(', ', $assignments) . ' WHERE ' . $where;
        $sth = $this->db->prepare($sql);
        foreach ($data as $key => $value) {
            $sth->bindValue(":$key", $value);
        }
        $this->bindWhereParams($sth, $whereParams);
        $sth->execute();
        return $sth->rowCount();
    }

    // NOTE: $where is raw SQL — pass values via $whereParams (bound), never concatenated.
    public function delete($table, $where, $limit = 1, $whereParams = array())
    {
        $limit = (int) $limit;
        $sth = $this->db->prepare('DELETE FROM ' . $this->quoteIdentifier($table) . " WHERE $where LIMIT $limit");
        $this->bindWhereParams($sth, $whereParams);
        $sth->execute();
        return $sth->rowCount();
    }

    // NOTE: $where is raw SQL — pass values via $whereParams (bound), never concatenated.
    public function delete_all($table, $where, $whereParams = array())
    {
        $sth = $this->db->prepare('DELETE FROM ' . $this->quoteIdentifier($table) . " WHERE $where");
        $this->bindWhereParams($sth, $whereParams);
        $sth->execute();
        return $sth->rowCount();
    }

    /**
     * Validate and backtick-quote a table identifier (optionally schema-qualified,
     * e.g. `schema`.`table`). This guards the framework's only raw-identifier
     * surface: a caller that passes a user-derived table name cannot inject SQL.
     */
    private function quoteIdentifier(string $name): string
    {
        $parts = explode('.', $name);
        foreach ($parts as $part) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $part)) {
                throw new InvalidArgumentException('Invalid SQL identifier: ' . $name);
            }
        }
        return '`' . implode('`.`', $parts) . '`';
    }

    /**
     * Validate and backtick-quote a single column name. Column names double as
     * bound-parameter placeholders (":$key"), so dots are disallowed here.
     */
    private function quoteColumn(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Invalid SQL column: ' . $name);
        }
        return '`' . $name . '`';
    }

}
