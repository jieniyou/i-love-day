<?php
/**
 * 应用全局配置文件（前台和后台共用）
 */

// 调试模式：上线环境请保持为 false
define('DEBUG_MODE', false);

// 错误报告：开发环境显示错误，上线后仅记录到日志
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');

// 时区设置
date_default_timezone_set('Asia/Shanghai');

// 应用根目录
define('ROOT_PATH', dirname(__DIR__));

// 应用 URL：根据当前请求自动生成（包含协议）
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('BASE_URL', $scheme . '://' . $host);

// 安全相关 HTTP 响应头（仅在非 CLI 环境下设置）
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // HTTPS 环境下启用 HSTS，增强传输安全
    if ($scheme === 'https') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Session 设置
ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
// HTTPS 下开启 Secure 标记
if ($scheme === 'https') {
    ini_set('session.cookie_secure', '1');
}
// 尝试设置 SameSite=Lax，减少 CSRF 风险（低版本 PHP 可忽略）
ini_set('session.cookie_samesite', 'Lax');

// 文件上传设置（MAX_FILE_SIZE 作为服务器级硬上限，前台可在系统设置中配置具体数值，默认 15MB）
define('UPLOAD_DIR', ROOT_PATH . '/uploads/');
define('UPLOAD_URL', BASE_URL . '/uploads/');
define('MAX_FILE_SIZE', 15 * 1024 * 1024); // 15MB 硬上限

// 安全密钥（请在生产环境中改为随机字符串）
define('SECRET_KEY', 'vK9z2f4QnB1sL8yC3wT7hP5mR0xG6dJ');

// 登录防爆破配置
define('LOGIN_MAX_ATTEMPTS', 5);          // 同一账号+IP 在窗口期内最大尝试次数
define('LOGIN_ATTEMPT_WINDOW', 900);      // 统计窗口（秒）15 分钟
define('LOGIN_LOCKOUT_SECONDS', 900);     // 超限后锁定时间（秒）15 分钟

// 站点名称
define('SITE_NAME', 'I Love Day');
