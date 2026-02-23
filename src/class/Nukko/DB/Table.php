<?php
namespace Nukko\DB;

abstract class Table
{
    protected DB $db;
    protected Dialect $dialect;
    protected string $table;
    protected array $columns = [];
    protected array $updatable = [];
    protected array $insertable = [];

    public function __construct(?DB $db = null)
    {
        if ($db === null) {
            $db = DB::get(\Nukko\Nukko::config('DB'));
        }
        $this->db = $db;
        $this->dialect = new Dialect($db);

        if (!isset($this->table) || $this->table === '') {
            throw new \LogicException('No table specified');
        }
    }


    public function find(array $where = [], array $options = []): array
    {
        $params = [];

        $sql = "SELECT * FROM {$this->table} ";

        $sql .= $this->buildWhere($where, $params);

        if (isset($options['order'])) {
            $sql .= ' ' . $this->buildOrder($options['order']);
        }

        $st = $this->db->prepare($sql);
        $st->execute($params);

        return $st->fetchAll();
    }

    public function update(
        array $data,
        array $where,
        ?array $methodAllowed = null
    ): int {

        if (empty($where)) {
            throw new \InvalidArgumentException('WHERE clause required for update');
        }

        // 許可カラム解決
        $allowed = $this->resolveAllowed($this->updatable, $methodAllowed);

        if ($allowed !== null) {
            $data = array_intersect_key($data, array_flip($allowed));
        }

        if (empty($data)) {
            throw new \InvalidArgumentException('No valid columns to update');
        }

        $params = [];
        $setClauses = [];

        foreach ($data as $col => $val) {

            // columnsがある場合のみ検証
            if (!empty($this->columns)) {
                if (!in_array($col, $this->columns, true)) {
                    throw new \InvalidArgumentException("Invalid column: $col");
                }
            }

            $setClauses[] = "$col = ?";
            $params[] = $val;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $setClauses);
        $sql .= ' ' . $this->buildWhere($where, $params);

        $st = $this->db->prepare($sql);
        $st->execute($params);

        return $st->rowCount();
    }

    public function insert(
        array $data,
        ?array $methodAllowed = null
    ): int|string|null
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('No data to insert');
        }

        $allowed = $this->resolveAllowed($this->insertable, $methodAllowed);

        if ($allowed !== null) {
            $data = array_intersect_key($data, array_flip($allowed));
        }

        if (empty($data)) {
            throw new \InvalidArgumentException('No valid columns to insert');
        }

        $cols = array_keys($data);

        $pkey = null;
        $useReturning = false;

        if ($this->dialect->isPgsql()) {
            $pkey = $this->dialect->detectPrimaryKey($this->table);
            $useReturning = $pkey !== null;
        }

        $sql = $this->dialect->insert(
            $this->table,
            $cols,
            $useReturning,
            $pkey
        );

        $st = $this->db->prepare($sql);
        $st->execute(array_values($data));

        if ($useReturning) {
            return $st->fetchColumn();
        }

        $id = $this->db->lastInsertId();
        return $id !== '' ? $id : null;
    }

    public function delete(array $where): int
    {
        if (empty($where)) {
            throw new \InvalidArgumentException(
                'WHERE condition required for delete'
            );
        }

        $params = [];
        $clauses = [];

        foreach ($where as $col => $val) {

            // カラム制限（任意）
            if (!empty($this->columns) &&
                !in_array($col, $this->columns, true)) {
                throw new \InvalidArgumentException(
                    "Invalid column: {$col}"
                );
            }

            if ($val === null) {
                $clauses[] = "{$col} IS NULL";
            } else {
                $clauses[] = "{$col} = ?";
                $params[] = $val;
            }
        }

        $sql = "DELETE FROM {$this->table} WHERE " .
            implode(' AND ', $clauses);

        $st = $this->db->prepare($sql);
        $st->execute($params);

        return $st->rowCount();
    }

    public function upsert(
        array $data,
        ?array $conflictColumns = null,
        ?array $methodAllowed = null
    ): int|string|null {

        if (empty($data)) {
            throw new \InvalidArgumentException('No data for upsert');
        }

        // INSERT許可
        $insertAllowed = $this->resolveAllowed(
            $this->insertable ?? null,
            $methodAllowed
        );

        if ($insertAllowed !== null) {
            $data = array_intersect_key($data, array_flip($insertAllowed));
        }

        if (empty($data)) {
            throw new \InvalidArgumentException(
                'No valid columns for upsert'
            );
        }

        $cols = array_keys($data);

        // UPDATE許可
        $updateAllowed = $this->resolveAllowed(
            $this->updatable ?? null,
            $methodAllowed
        );

        if ($updateAllowed !== null) {
            $updateCols = array_values(
                array_intersect($cols, $updateAllowed)
            );
        } else {
            $updateCols = $cols;
        }

        if (empty($updateCols)) {
            throw new \RuntimeException(
                'No columns to update in upsert'
            );
        }

        $primaryKey = null;

        // PostgreSQL / SQLite は主キー必須
        if ($this->dialect->isPgsql() || $this->dialect->isSqlite()) {

            $primaryKey = $this->dialect
                ->detectPrimaryKey($this->table);

            if (!$primaryKey) {
                throw new \RuntimeException(
                    'Primary key required for upsert'
                );
            }

            if (empty($conflictColumns)) {
                $conflictColumns = [$primaryKey];
            }
        } else {
            // MySQL / MariaDB は不要
            $conflictColumns = $conflictColumns ?? [];
        }

        $sql = $this->dialect->upsert(
            $this->table,
            $cols,
            $conflictColumns,
            $updateCols,
            $primaryKey
        );

        $st = $this->db->prepare($sql);
        $st->execute(array_values($data));

        // PostgreSQL / SQLite は RETURNING
        if ($this->dialect->isPgsql() || $this->dialect->isSqlite()) {
            return $st->fetchColumn();
        }

        // MySQL / MariaDB
        $id = $this->db->lastInsertId();
        return $id !== '' ? $id : null;
    }

    protected function buildWhere(array $where, array &$params): string
    {
        $clauses = [];

        foreach ($where as $col => $val) {

            // columnsが定義されている場合のみチェック
            if (!empty($this->columns)) {
                if (!in_array($col, $this->columns, true)) {
                    throw new \InvalidArgumentException("Invalid column: $col");
                }
            }

            if ($val === null) {
                $clauses[] = "$col IS NULL";
            } else {
                $clauses[] = "$col = ?";
                $params[] = $val;
            }
        }

        return $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
    }

    protected function buildOrder(array $order): string
    {
        $clauses = [];

        foreach ($order as $col => $dir) {

            // columnsが定義されている場合のみ検証
            if (!empty($this->columns)) {
                if (!in_array($col, $this->columns, true)) {
                    throw new \InvalidArgumentException("Invalid order column: $col");
                }
            }

            $dir = strtoupper($dir);
            if (!in_array($dir, ['ASC', 'DESC'], true)) {
                throw new \InvalidArgumentException("Invalid order direction");
            }

            $clauses[] = "$col $dir";
        }

        return $clauses ? 'ORDER BY ' . implode(', ', $clauses) : '';
    }

    protected function resolveAllowed(
        ?array $baseAllowed,
        ?array $methodAllowed = null
    ): ?array {

        if (!empty($baseAllowed)) {
            $allowed = $baseAllowed;
        } elseif (!empty($this->columns)) {
            $allowed = $this->columns;
        } else {
            $allowed = null; // 制限なし
        }

        if ($allowed !== null && $methodAllowed !== null) {
            $allowed = array_values(array_intersect($allowed, $methodAllowed));
        }

        return $allowed;
    }
}