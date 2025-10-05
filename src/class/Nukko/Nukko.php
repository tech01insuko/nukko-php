<?php
namespace Nukko;
use Random\RandomException;

class Nukko
{
    protected static array $config = [];
    protected static bool $initialized = false;

    /**
     * コンストラクタ禁止
     */
    private function __construct()
    {
    }

    /**
     * config を格納
     * @param array $config
     * @return void
     */
    public static function init(array $config): void
    {
        self::$config = $config;
        self::$initialized = true;
    }

    /**
     * 設定値を取得
     * @param string|null $key キー。nullなら全体を返す
     * @param mixed $default デフォルト値
     * @return mixed
     */
    public static function config(?string $key = null, $default = null): mixed
    {
        if ($key === null) {
            return self::$config;
        }
        return self::$config[$key] ?? $default;
    }

    /**
     * 指定されたパスが base の下にあるかどうかを判定
     * @param string $base
     * @param string $path
     * @return bool
     */
    public static function isUnder(string $base, string $path): bool
    {
        $rb = realpath($base);
        if ($rb === false) return false;

        $base = rtrim(str_replace('\\', '/', $rb), '/') . '/';
        $path = str_replace('\\', '/', $path);

        $rp = realpath($path);
        if ($rp !== false) {
            $path = str_replace('\\', '/', $rp);
        }

        $path = rtrim($path, '/');
        return str_starts_with($path . '/', $base);
    }

    /**
     * CSRFトークンを取得
     * @param string $key トークンのキー
     * @return string トークン文字列
     */
    public static function csrfToken(string $key = 'default'): string
    {
        if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (!isset($_SESSION['csrf']) || !is_array($_SESSION['csrf'])) {
            $_SESSION['csrf'] = [];
        }
        if (empty($_SESSION['csrf'][$key])) {
            $_SESSION['csrf'][$key] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'][$key];
    }

    /**
     * CSRFトークンを検証
     * @param string|null $token トークン文字列
     * @param string $key トークンのキー
     * @param bool $rotate 検証成功時にトークンを破棄するかどうか
     * @return bool 検証結果
     */
    public static function csrfCheck(?string $token, string $key = 'default', bool $rotate = true): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return false;
        $ok = isset($_SESSION['csrf'][$key]) && is_string($token) && hash_equals($_SESSION['csrf'][$key], (string)$token);
        if ($ok && $rotate) {
            unset($_SESSION['csrf'][$key]);
        }
        return $ok;
    }
}