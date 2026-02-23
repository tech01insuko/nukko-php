<?php
namespace Nukko\DB;

use PDO;

class Dialect
{
    protected string $driver;
    protected PDO $pdo;
    protected array $primaryKeyCache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function isPgsql(): bool
    {
        return $this->driver === 'pgsql';
    }

    public function isMysql(): bool
    {
        return $this->driver === 'mysql';
    }

    public function isSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    public function insert(
        string $table,
        array $cols,
        bool $useReturning = false,
        ?string $primaryKey = null
    ): string {

        $placeholders = implode(', ', array_fill(0, count($cols), '?'));

        $sql = "INSERT INTO $table (" .
            implode(', ', $cols) .
            ") VALUES ($placeholders)";

        if ($this->isPgsql() && $useReturning && $primaryKey) {
            $sql .= " RETURNING $primaryKey";
        }

        return $sql;
    }

    public function upsert(
        string $table,
        array $cols,
        array $conflictCols,
        array $updateCols,
        ?string $primaryKey = null
    ): string {

        $placeholders = implode(', ', array_fill(0, count($cols), '?'));

        switch ($this->driver) {

            // MySQL / MariaDB
            case 'mysql':

                $updates = implode(', ', array_map(
                    fn($c) => "$c = VALUES($c)",
                    $updateCols
                ));

                return "INSERT INTO $table (" .
                    implode(', ', $cols) .
                    ") VALUES ($placeholders)
                       ON DUPLICATE KEY UPDATE $updates";

            // PostgreSQL
            case 'pgsql':

                $updates = implode(', ', array_map(
                    fn($c) => "$c = EXCLUDED.$c",
                    $updateCols
                ));

                return "INSERT INTO $table (" .
                    implode(', ', $cols) .
                    ") VALUES ($placeholders)
                       ON CONFLICT (" .
                    implode(', ', $conflictCols) .
                    ") DO UPDATE SET $updates
                       RETURNING $primaryKey";

            // SQLite（RETURNING前提）
            case 'sqlite':

                $updates = implode(', ', array_map(
                    fn($c) => "$c = excluded.$c",
                    $updateCols
                ));

                return "INSERT INTO $table (" .
                    implode(', ', $cols) .
                    ") VALUES ($placeholders)
                       ON CONFLICT (" .
                    implode(', ', $conflictCols) .
                    ") DO UPDATE SET $updates
                       RETURNING $primaryKey";

            default:
                throw new \RuntimeException('Unsupported driver');
        }
    }

    public function detectPrimaryKey(string $table): ?string
    {
        if (!$this->isPgsql()) {
            return null;
        }

        if (isset($this->primaryKeyCache[$table])) {
            return $this->primaryKeyCache[$table];
        }

        $sql = "
            SELECT a.attname
            FROM pg_index i
            JOIN pg_attribute a
              ON a.attrelid = i.indrelid
             AND a.attnum = ANY(i.indkey)
            WHERE i.indrelid = '{$table}'::regclass
              AND i.indisprimary
        ";

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) === 1) {
            return $this->primaryKeyCache[$table] = $rows[0]['attname'];
        }

        if (count($rows) > 1) {
            throw new \RuntimeException(
                "Composite primary key is not supported: {$table}"
            );
        }

        return $this->primaryKeyCache[$table] = null;
    }
}