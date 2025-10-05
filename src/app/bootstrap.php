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
    'ROUTER_MODE' => 'mirror', // 'mirror' or 'auto'

    'BASE_DIR'   => dirname(__DIR__),                   // = src
    'PUB_DIR'    => dirname(__DIR__) . '/public_html',  // = src/public_html
    'LIB_DIR'    => dirname(__DIR__) . '/lib',          // = src/lib
    'CLASS_DIR'  => dirname(__DIR__) . '/class',        // = src/class
    'VAR_DIR'    => dirname(__DIR__) . '/var',          // = src/var

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
$classDir = realpath($CONFIG['CLASS_DIR']) ?: $CONFIG['CLASS_DIR'];
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
 * WEBモードの場合はセッション開始と自動マッピング or ミラーinclude
 */
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $mode = Nukko::config('ROUTER_MODE', 'auto');

    if ($mode === 'auto') {
        // 自動マッピングモード
        $raw  = $_GET['path'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $path = trim($raw, "/ \t\n\r\0\x0B");
        if ($path === '') $path = 'index';

        if (!preg_match('#^[A-Za-z0-9_/\-]+$#', $path)) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "400 Bad Request"; return;
        }

        $candidates = [$path . '.php', $path . '/index.php'];

        $lib     = Nukko::config('LIB_DIR');
        $pub     = Nukko::config('PUB_DIR');
        $self    = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
        $relHit  = null;

        $relSelf = '';
        if ($self && str_starts_with($self, $pub)) {
            $relSelf = substr($self, strlen($pub));
        }

        foreach ($candidates as $rel) {
            // lib側にファイルがある場合は先にinclude
            $file = $lib . '/' . $rel;
            if ($relSelf !== '' && $rel === ltrim($relSelf, '/')) {
                // 自分自身ガード
                continue;
            }
            if (is_file($file) && Nukko::isUnder($lib, $file)) {
                include_once $file;
                $relHit = $rel;
                break;
            }
        }

        if ($relHit !== null) {
            // lib側にファイルがあった場合はpub側は同じ相対パスのものだけを探す
            $file = $pub . '/' . $relHit;
            if ($self && realpath($file) === $self) return; // 自分自身ガード
            if (is_file($file) && Nukko::isUnder($pub, $file)) {
                include_once $file;
            }
            return;
        } else {
            // lib が無かった場合は pub 単独で候補探索
            foreach ($candidates as $rel) {
                $file = $pub . '/' . $rel;
                if ($self && realpath($file) === $self) continue;
                if (is_file($file) && Nukko::isUnder($pub, $file)) {
                    include_once $file; return;
                }
            }
        }

        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "404 Not Found"; return;

    } else {
        // mirrorモード
        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $base   = realpath(Nukko::config('PUB_DIR'));
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
