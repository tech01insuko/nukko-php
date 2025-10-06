<?php
namespace Nukko\DB;

use PDO;
use PDOException;
use Throwable;

/**
 * PDO拡張 + マルチトン接続
 *
 * 役割:
 * - 安全デフォルトの適用（ERRMODE、FETCH_MODE等）
 * - 接続設定ごとに同一インスタンスを共有（マルチトン）
 * - 初期SQL（opts['init_commands']）の自動実行
 * - 簡易トランザクションラッパ transaction() を提供
 *
 * 使い方:
 *   // 設定配列を直接渡す（同じ設定なら同じインスタンスを返す）
 *   $db = DB::get(Nukko\Nukko::config('DB'));
 *
 *   // クエリ実行
 *   $st = $db->prepare('SELECT * FROM users WHERE id=?');
 *   $st->execute([$id]);
 *
 *   // トランザクション
 *   $db->transaction(function(DB $db) {
 *       $db->exec("UPDATE ...");
 *       $db->exec("INSERT ...");
 *   });
 *
 *   // 必要ならキャッシュをクリア
 *   DB::flush();
 */
final class DB extends PDO
{
    /** @var array<string, self> 接続プール（key => instance） */
    protected static array $instances = [];

    /**
     * PDOに安全デフォルトを適用して生成
     * @param string     $dsn
     * @param string     $user
     * @param string     $pass
     * @param array<int|string, mixed> $opts
     *   - 'statement_class' => FQCN（PDOStatement拡張）を指定可能
     *   - 'init_commands'   => 接続直後に exec するSQL配列
     */
    public function __construct(string $dsn, string $user = '', string $pass = '', array $opts = [])
    {
        $defaults = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false
        ];

        $initCommands = [];
        if (isset($opts['init_commands']) && is_array($opts['init_commands'])) {
            $initCommands = array_values(array_filter(
                $opts['init_commands'],
                fn($s) => is_string($s) && $s !== ''
            ));
            unset($opts['init_commands']);
        }

        $pdoOpts = array_filter($defaults + $opts, 'is_int', ARRAY_FILTER_USE_KEY);

        parent::__construct($dsn, $user, $pass, $pdoOpts);

        // 初期SQL（必要時のみ）
        foreach ($initCommands as $sql) {
            $this->exec($sql);
        }
    }

    // ---------------------------------------------------------------------
    // マルチトン：設定登録・取得・リセット
    // ---------------------------------------------------------------------


    /**
     * 接続を取得（遅延生成 & 共有）
     * @param array|null $config
     * @return self
     */
    public static function get(array $config = null): self
    {
        if (!is_array($config)) {
            throw new PDOException('DB::get() は配列接続のみを受け付けます（例: DB::get(Nukko::config("DB"))）');
        }

        $cfg = self::normalizeSingleConfig($config);
        $key = 'cfg:' . self::configSignature($cfg);

        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = self::createFromConfig($cfg);
        }
        return self::$instances[$key];    }

    /** 共有インスタンスキャッシュを全てクリア（必要な場合だけ） */
    public static function flush(): void
    {
        foreach (self::$instances as $k => $_) {
            self::$instances[$k] = null; // PDO参照を切る
            unset(self::$instances[$k]);
        }
    }

    // ---------------------------------------------------------------------
    // 低レベル補助（任意）：ワンショットtransaction
    // ---------------------------------------------------------------------

    /**
     * トランザクション・ラッパ（薄い糖衣）
     * 例外時はロールバックして再送出、戻り値を返す
     * @param callable $fn function(PDO $db): mixed
     * @return mixed 戻り値
     * @throws Throwable 例外はそのまま伝播
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

    // ---------------------------------------------------------------------
    // 内部実装
    // ---------------------------------------------------------------------

    /** @param array $cfg ['dsn'=>, 'user'=>, 'pass'=>, 'opts'=>[]] */
    protected static function createFromConfig(array $cfg): self
    {
        $dsn  = (string)($cfg['dsn']  ?? '');
        $user = (string)($cfg['user'] ?? '');
        $pass = (string)($cfg['pass'] ?? '');
        $opts = (array)($cfg['opts'] ?? []);

        // ここで必要なら DSN を調整（例：charset=... が無い場合の初期SQLなど）
        // $opts['init_commands'][] = 'SET NAMES utf8mb4';

        return new self($dsn, $user, $pass, $opts);
    }

    /** 単体設定の正規化 */
    protected static function normalizeSingleConfig(array $cfg): array
    {
        $out = [
            'dsn'  => (string)($cfg['dsn']  ?? ''),
            'user' => (string)($cfg['user'] ?? ''),
            'pass' => (string)($cfg['pass'] ?? ''),
            'opts' => (array)  ($cfg['opts'] ?? []),
        ];
        if ($out['dsn'] === '') {
            throw new PDOException('DB config error: dsn is required');
        }
        return $out;
    }

    /** 同一設定→同一インスタンスを共有するための安定キー */
    protected static function configSignature(array $cfg): string
    {
        $base = [
            'dsn'  => trim((string)($cfg['dsn']  ?? '')),
            'user' => (string)($cfg['user'] ?? ''),
            'pass' => (string)($cfg['pass'] ?? ''),       // 認証が違えば別接続
            'opts' => array_filter((array)($cfg['opts'] ?? []), 'is_int', ARRAY_FILTER_USE_KEY),
        ];

        // 再帰ソートで順序を安定化
        $normalize = function (&$v) use (&$normalize) {
            if (!is_array($v)) return;
            foreach ($v as &$vv) $normalize($vv);
            ksort($v);
        };
        $normalize($base);

        // JSONは順序保持されるのでハッシュの素材に適する
        return sha1(json_encode($base, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}