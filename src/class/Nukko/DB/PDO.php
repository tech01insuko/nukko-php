<?php
namespace Nukko\DB;

use PDOException;
use Throwable;

/**
 * PDO専用ラッパークラス
 *
 * 役割:
 * - 安全デフォルトの適用
 * - init_commands の実行
 * - transaction() ラッパ提供
 */
class PDO extends \PDO
{
    /**
     * @param string $dsn
     * @param string $user
     * @param string $pass
     * @param array  $opts
     *   - 'init_commands' => 接続直後に実行するSQL配列
     */
    public function __construct(string $dsn, string $user = '', string $pass = '', array $opts = [])
    {
        $defaults = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        // init_commands 抽出
        $initCommands = [];
        if (isset($opts['init_commands']) && is_array($opts['init_commands'])) {
            $initCommands = array_values(array_filter(
                $opts['init_commands'],
                fn($s) => is_string($s) && $s !== ''
            ));
            unset($opts['init_commands']);
        }

        // PDOに渡すオプションはintキーのみ
        $pdoOpts = array_filter($defaults + $opts, 'is_int', ARRAY_FILTER_USE_KEY);

        parent::__construct($dsn, $user, $pass, $pdoOpts);

        // 初期SQL実行
        foreach ($initCommands as $sql) {
            $this->exec($sql);
        }
    }

    /**
     * トランザクション・ラッパ
     *
     * @param callable $fn function(PDO $db): mixed
     * @return mixed
     * @throws Throwable
     */
    public function transaction(callable $fn): mixed
    {
        $this->beginTransaction();

        try {
            $ret = $fn($this);
            $this->commit();
            return $ret;
        } catch (Throwable $e) {
            if ($this->inTransaction()) {
                $this->rollBack();
            }
            throw $e;
        }
    }
}