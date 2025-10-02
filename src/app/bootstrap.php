<?php
/**
 * bootstrap.php — Nukko PHP Framework
 *
 * public_html 側の各PHPの先頭で
 *   <?php require_once __DIR__.'/../app/bootstrap.php';
 * と書くだけで、ミラーincludeまたはルーティングが動きます。
 *
 * src/
 * ├─ app/bootstrap.php   ←このファイル
 * ├─ app/routes.php      ←ルータを使うときだけ
 * ├─ app/.env.php        ←任意設定
 * ├─ class/              ←クラス群（オートロード対象）
 * ├─ lib/                ←ミラーinclude用
 * ├─ public_html/        ←公開ディレクトリ（view/エンドポイント）
 * └─ var/                ←非公開の可変データ
 */

// ----------------------------------------------------------------------------
// 0) 冪等ガード
// ----------------------------------------------------------------------------
if (defined('NUKKO_BOOTSTRAP_LOADED')) { return; }
const NUKKO_BOOTSTRAP_LOADED = true;

// ----------------------------------------------------------------------------
// 1) 既定設定（src構成に合わせたディレクトリ設定）
// ----------------------------------------------------------------------------
$CONFIG = [
    'APP_ENV'    => 'dev',
    'USE_ROUTER' => false,

    // bootstrap.php は src/app に置く前提
    'BASE_DIR'   => dirname(__DIR__),            // = src
    'LIB_DIR'    => dirname(__DIR__) . '/lib',   // = src/lib
    'CLASS_DIR'  => dirname(__DIR__) . '/class', // = src/class
    'VAR_DIR'    => dirname(__DIR__) . '/var',   // = src/var

    'TZ'         => 'Asia/Tokyo',
    'MB_LANG'    => 'Japanese',
    'MB_INT'     => 'UTF-8',
    'LOG_LEVEL'  => 0,

    'DB' => [
        'dsn'  => '',
        'user' => '',
        'pass' => '',
        'opts' => [],
    ],
];

// .env.php による任意上書き
$envFile = __DIR__ . '/.env.php';
if (is_file($envFile)) {
    $env = require $envFile;
    if (is_array($env)) {
        $CONFIG = array_replace_recursive($CONFIG, $env);
    }
}

// ----------------------------------------------------------------------------
// 2) 基本環境設定
// ----------------------------------------------------------------------------
date_default_timezone_set($CONFIG['TZ']);
mb_language($CONFIG['MB_LANG']);
mb_internal_encoding($CONFIG['MB_INT']);
setlocale(LC_ALL, 'ja_JP.UTF-8');

if ($CONFIG['APP_ENV'] === 'prod') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
} else {
    ini_set('display_errors', '1');
    ini_set('log_errors', '0');
    error_reporting(E_ALL);
}

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
$httpsOn = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if ($httpsOn) {
    ini_set('session.cookie_secure', '1');
}

// ----------------------------------------------------------------------------
// 3) ヘルパ関数
// ----------------------------------------------------------------------------
function nukko_config(?string $key = null, $default = null) {
    static $C = null;
    if ($C === null) { global $CONFIG; $C = $CONFIG; }
    if ($key === null) return $C;
    return $C[$key] ?? $default;
}

function nukko_is_under(string $base, string $path): bool {
    $base = rtrim(str_replace('\\','/', realpath($base)), '/').'/';
    $path = str_replace('\\','/', $path);
    $rp = realpath($path);
    if ($rp !== false) {
        $path = str_replace('\\','/', $rp);
    }
    $path = rtrim($path, '/');
    return (str_starts_with($path . '/', $base));
}

// ----------------------------------------------------------------------------
// 4) オートロード（CLASS_DIR配下限定）
// ----------------------------------------------------------------------------
spl_autoload_register(function ($fqcn) {
    if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $fqcn)) return;
    $classDir = nukko_config('CLASS_DIR');
    $rel = strtr($fqcn, ['\\'=>'/', '_'=>'/']) . '.php';
    $candidate = $classDir . '/' . $rel;
    if (is_file($candidate) && nukko_is_under($classDir, $candidate)) {
        require_once $candidate;
    }
});

// ----------------------------------------------------------------------------
// 5) DB 接続ヘルパ（必要な場合のみ）
// ----------------------------------------------------------------------------
function nukko_pdo(): ?PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $db = nukko_config('DB');
    if (empty($db['dsn'])) return null;
    $pdo = new PDO($db['dsn'], $db['user'] ?? '', $db['pass'] ?? '', $db['opts'] ?? []);
    return $pdo;
}

// ----------------------------------------------------------------------------
// 6) WEB実行時：セッション + ルーティング or ミラーinclude
// ----------------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    if (nukko_config('USE_ROUTER', false)) {
        // ルータモード
        $routes = [];
        $routesFile = __DIR__ . '/routes.php';
        if (is_file($routesFile)) {
            $loaded = require $routesFile;
            if (is_array($loaded)) $routes = $loaded;
        }

        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
        $uri = rtrim($uri, '/') ?: '/';

        $handler = $routes[$method][$uri] ?? null;
        if ($handler === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "404 Not Found";
            return;
        }

        if (is_callable($handler)) {
            $handler(); return;
        }
        if (is_string($handler)) {
            if (str_contains($handler, '@')) {
                [$cls, $act] = explode('@', $handler, 2);
                if (class_exists($cls)) {
                    $obj = new $cls();
                    if (is_callable([$obj, $act])) { $obj->$act(); return; }
                }
            }
            $lib = nukko_config('LIB_DIR');
            $file = $handler[0] !== '/' ? $lib . '/' . ltrim($handler, '/') : $handler;
            if (is_file($file) && nukko_is_under($lib, $file)) { include_once $file; return; }
        }

        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "500 Internal Server Error";
        return;

    } else {
        // 従来互換モード（ミラーinclude）
        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $base   = realpath(nukko_config('BASE_DIR') . '/public_html');
        if ($script && $base && str_starts_with(realpath($script), $base)) {
            $rel = substr(realpath($script), strlen($base)); // 例: /index.php
            $lib = nukko_config('LIB_DIR') . $rel;              // 例: /lib/index.php
            if (is_file($lib) && nukko_is_under(nukko_config('LIB_DIR'), $lib)) {
                include_once $lib;
            }
        }
    }
}

// ----------------------------------------------------------------------------
// 7) CSRFヘルパ（任意で使用）
// ----------------------------------------------------------------------------
function nukko_csrf_token(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['_csrf'];
}
function nukko_csrf_check(?string $token): bool {
    return isset($_SESSION['_csrf']) && is_string($token) && hash_equals($_SESSION['_csrf'], $token);
}