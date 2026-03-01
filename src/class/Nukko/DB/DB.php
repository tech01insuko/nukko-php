<?php
namespace Nukko\DB;

use PDOException;

/**
 * 接続マネージャ（マルチトン管理専用）
 *
 * 役割:
 * - 接続設定の正規化
 * - マルチトン管理
 * - PDOラッパ生成
 */
final class DB
{
    /** @var array<string, PDO> */
    protected static array $instances = [];

    /**
     * 接続取得（既存互換）
     *
     * @param array|null $config
     * @return PDO
     */
    public static function get(?array $config = null): PDO
    {
        if (!is_array($config)) {
            throw new PDOException(
                'DB::get() は配列接続のみを受け付けます（例: DB::get(Nukko::config("DB"))）'
            );
        }

        $cfg = self::normalizeSingleConfig($config);
        $key = 'cfg:' . self::configSignature($cfg);

        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = self::createFromConfig($cfg);
        }

        return self::$instances[$key];
    }

    /**
     * 共有インスタンス全クリア
     */
    public static function flush(): void
    {
        self::$instances = [];
    }

    /**
     * PDOインスタンス生成
     */
    protected static function createFromConfig(array $cfg): PDO
    {
        $dsn  = (string)($cfg['dsn']  ?? '');
        $user = (string)($cfg['user'] ?? '');
        $pass = (string)($cfg['pass'] ?? '');
        $opts = (array)($cfg['opts'] ?? []);

        return new PDO($dsn, $user, $pass, $opts);
    }

    /**
     * 設定正規化
     */
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

    /**
     * 設定署名（安定キー生成）
     */
    protected static function configSignature(array $cfg): string
    {
        $base = [
            'dsn'  => trim((string)($cfg['dsn'] ?? '')),
            'user' => (string)($cfg['user'] ?? ''),
            'pass' => (string)($cfg['pass'] ?? ''),
            'opts' => array_filter(
                (array)($cfg['opts'] ?? []),
                'is_int',
                ARRAY_FILTER_USE_KEY
            ),
        ];

        // 再帰ソート
        $normalize = function (&$v) use (&$normalize) {
            if (!is_array($v)) return;
            foreach ($v as &$vv) $normalize($vv);
            ksort($v);
        };
        $normalize($base);

        return sha1(json_encode($base, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}