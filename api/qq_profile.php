<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/helpers.php';

header('Content-Type: application/json; charset=utf-8');

// 简单 IP 限流：防止被当作 QQ 头像/昵称公共代理接口滥用
// 规则：同一 IP 每小时最多 60 次请求
$ip        = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$now       = time();
$limitPerHour = 60;

try {
    $db = Database::getInstance();

    // 幂等创建限流表
    $db->query("
        CREATE TABLE IF NOT EXISTS `qq_profile_attempts` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `ip` varchar(45) DEFAULT NULL,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ip_time` (`ip`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='QQ 资料查询尝试记录';
    ");

    // 统计最近 1 小时内该 IP 的请求次数
    $windowStart = date('Y-m-d H:i:s', $now - 3600);
    $row = $db->fetch(
        "SELECT COUNT(*) AS c FROM qq_profile_attempts WHERE ip = :ip AND created_at >= :start",
        [
            'ip'    => $ip,
            'start' => $windowStart,
        ]
    );
    $ipCount = $row ? (int) ($row['c'] ?? 0) : 0;

    if ($ipCount >= $limitPerHour) {
        echo json_encode([
            'success' => false,
            'message' => '当前 IP 查询太频繁，请稍后再试',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $e) {
    // 数据库异常时不影响主流程，只是不做限流
    $db = null;
}

$qq = isset($_GET['qq']) ? trim($_GET['qq']) : '';
if ($qq === '') {
    echo json_encode(['success' => false, 'message' => '缺少 QQ 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

$nickname  = '';

// 只使用 mmp.cc 接口获取昵称（JSON，data.name 字段为昵称）
try {
    $mmpUrl = 'https://api.mmp.cc/api/qqname?qq=' . urlencode($qq);
    $resp   = @file_get_contents($mmpUrl);
    if ($resp !== false) {
        $json = json_decode($resp, true);
        if (
            is_array($json)
            && !empty($json['success'])
            && isset($json['data'])
            && is_array($json['data'])
            && !empty($json['data']['name'])
        ) {
            $nickname = (string) $json['data']['name'];
        }
    }
} catch (Throwable $e) {
    // 忽略错误，昵称留空即可
}

// 头像统一使用官方 qlogo 接口，避免 http 协议或尺寸不一致问题
$avatarUrl = 'https://q1.qlogo.cn/g?b=qq&nk=' . urlencode($qq) . '&s=100';

// 记录一次成功的查询尝试（最佳努力，不影响主流程）
if (isset($db) && $db instanceof Database && !empty($ip)) {
    try {
        $db->insert('qq_profile_attempts', [
            'ip'         => $ip,
            'created_at' => date('Y-m-d H:i:s', $now),
        ]);
    } catch (Throwable $e) {
        // 记录失败忽略
    }
}

echo json_encode([
    'success'    => true,
    'avatar_url' => $avatarUrl,
    'nickname'   => $nickname,
], JSON_UNESCAPED_UNICODE);
