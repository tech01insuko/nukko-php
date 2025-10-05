<?php
/**
 * bootstrap.php — Nukko PHP Framework
 *
 * public_html 側の各PHPの先頭で
 *   <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/../app/bootstrap.php'; ?>
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

use Nukko\Nukko as Nukko;

/**
 * 冪等ガード
 */
if (defined('NUKKO_BOOTSTRAP_LOADED')) {
    return;
}
const NUKKO_BOOTSTRAP_LOADED = true;

/**
 * 規定設定
 */
$CONFIG = [
    'APP_ENV'    => 'dev',
    'USE_ROUTER' => false,

    // bootstrap.php は public_htmlと同階層のapp に置く前提
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

/**
 * 基本環境設定
 */
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

/**
 * オートロード設定
 */
$classDir = $CONFIG['CLASS_DIR'];
spl_autoload_register(function (string $className) use ($classDir) {
    if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $className)) return;

    $rel = strtr($className, ['\\'=>'/', '_'=>'/']) . '.php';
    $candidate = $classDir . '/' . $rel;

    if (is_file($candidate)) {
        $rb = realpath($classDir);
        $rc = realpath($candidate);
        if ($rb !== false && $rc !== false &&
            str_starts_with(str_replace('\\','/',$rc).'/', rtrim(str_replace('\\','/',$rb),'/').'/')) {
            require_once $candidate;
        }
    }
});

Nukko::init($CONFIG);

/**
 * WEBモードの場合はセッション開始とルーティング or ミラーinclude
 */
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    if (Nukko::config('USE_ROUTER', false)) {
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
        $lib  = Nukko::config('LIB_DIR');
        if (is_string($handler)) {
            if (str_contains($handler, '@')) {
                [$cls, $act] = explode('@', $handler, 2);
                if (class_exists($cls)) {
                    $obj = new $cls();
                    if (is_callable([$obj, $act])) { $obj->$act(); return; }
                }
            }
            $file = ($handler !== '' && $handler[0] !== '/')
                ? $lib . '/' . ltrim($handler, '/')
                : $handler;
            if (is_string($file) && $file !== '' && is_file($file) && Nukko::isUnder($lib, $file)) {
                include_once $file; return;
            }
        }

        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "500 Internal Server Error";
        return;

    } else {
        // ミラーinclude
        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $base   = realpath(Nukko::config('BASE_DIR') . '/public_html');
        $sReal  = $script ? realpath($script) : false;
        if ($sReal && $base && str_starts_with($sReal, $base)) {
            $rel = substr($sReal, strlen($base));
            $lib = Nukko::config('LIB_DIR') . $rel;
            if (is_file($lib) && Nukko::isUnder(Nukko::config('LIB_DIR'), $lib)) {
                include_once $lib;
            }
        }
    }
}
