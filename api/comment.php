<?php
// 评论提交接口
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/helpers.php';

// 确保数据库结构是最新的（包括评论相关表）
if (function_exists('migrate_schema_if_needed')) {
    migrate_schema_if_needed();
}

$auth = new Auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);
}

// CSRF 校验：评论必须来自本站表单
require_csrf();

$db          = Database::getInstance();
$currentUser = $auth->getCurrentUser();

$articleId = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;
$albumId   = isset($_POST['album_id'])   ? intval($_POST['album_id'])   : 0;
$eventId   = isset($_POST['event_id'])   ? intval($_POST['event_id'])   : 0;
$parentId  = isset($_POST['parent_id'])  ? intval($_POST['parent_id'])  : 0;
$content   = trim($_POST['content'] ?? '');

if ($content === '') {
    jsonResponse(['success' => false, 'message' => '评论内容不能为空'], 400);
}

// 限制评论长度：最多 100 个字符，防止异常大请求
if (mb_strlen($content, 'UTF-8') > 100) {
    jsonResponse(['success' => false, 'message' => '评论内容过长'], 400);
}

// 如果是回复，则强制使用父评论所属对象，防止跨文章/相册/事件回复
if ($parentId) {
    $parent = $db->fetch(
        "SELECT article_id, album_id, event_id FROM comments WHERE id = :id LIMIT 1",
        ['id' => $parentId]
    );

    if (!$parent) {
        jsonResponse(['success' => false, 'message' => '要回复的评论不存在'], 404);
    }

    $articleId = (int) ($parent['article_id'] ?? 0);
    $albumId   = (int) ($parent['album_id']   ?? 0);
    $eventId   = (int) ($parent['event_id']   ?? 0);
}

if (!$articleId && !$albumId && !$eventId) {
    jsonResponse(['success' => false, 'message' => '请指定评论对象'], 400);
}

// 文章评论开关启用
if ($articleId) {
    $article = $db->fetch(
        "SELECT comments_enabled FROM articles WHERE id = :id LIMIT 1",
        ['id' => $articleId]
    );

    if (!$article) {
        jsonResponse(['success' => false, 'message' => '文章不存在'], 404);
    }

    if (isset($article['comments_enabled']) && (int)$article['comments_enabled'] === 0) {
        jsonResponse(['success' => false, 'message' => '此文章的评论已关闭'], 403);
    }
}

// 简单节流：限制同一用户/会话/IP 短时间内频繁评论
$now = time();
$ip  = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

// 评论 IP 黑名单：若当前 IP 在黑名单中且未过期，则直接拒绝评论
try {
    if (!empty($ip) && $ip !== '0.0.0.0') {
        $black = $db->fetch(
            "SELECT id, expires_at FROM comment_ip_blacklist WHERE ip = :ip LIMIT 1",
            ['ip' => $ip]
        );
        if ($black) {
            $expiresAt = $black['expires_at'] ?? null;
            if ($expiresAt === null || $expiresAt === '0000-00-00 00:00:00' || strtotime($expiresAt) >= $now) {
                jsonResponse(['success' => false, 'message' => '当前 IP 已被限制评论'], 403);
            }
        }
    }
} catch (Throwable $e) {
    // 黑名单表查询失败时，不影响正常评论逻辑
}

// IP 级节流：限制同一 IP 在一段时间内的评论次数（防止恶意刷评论）
try {
    $windowStart = date('Y-m-d H:i:s', $now - 3600);
    $row = $db->fetch(
        "SELECT COUNT(*) AS c FROM comment_attempts WHERE ip = :ip AND created_at >= :start",
        [
            'ip'    => $ip,
            'start' => $windowStart,
        ]
    );
    $ipCount = $row ? (int) ($row['c'] ?? 0) : 0;
    // 公网环境下收紧为每 IP 每小时最多约 30 条评论
    if ($ipCount >= 30) {
        jsonResponse(['success' => false, 'message' => '当前 IP 评论太频繁，请稍后再试'], 429);
    }
} catch (Throwable $e) {
    // 表查询失败时不影响正常评论逻辑
}

if ($currentUser) {
    $threshold  = $now - 10; // 登录用户 10 秒内只允许一条评论
    $recentTime = date('Y-m-d H:i:s', $threshold);

    $recentComment = $db->fetch(
        "SELECT id FROM comments WHERE user_id = :uid AND created_at >= :recent LIMIT 1",
        [
            'uid'    => $currentUser['id'],
            'recent' => $recentTime,
        ]
    );

    if ($recentComment) {
        jsonResponse(['success' => false, 'message' => '评论太频繁，请稍后再试'], 429);
    }
} else {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $lastGuestTime = $_SESSION['last_comment_time'] ?? 0;
    if ($lastGuestTime && ($now - (int)$lastGuestTime) < 10) {
        jsonResponse(['success' => false, 'message' => '评论太频繁，请稍后再试'], 429);
    }
}

$qq            = trim($_POST['qq'] ?? '');
$guestNickname = trim($_POST['guest_nickname'] ?? '');
$guestAvatar   = trim($_POST['guest_avatar'] ?? '');

if ($currentUser) {
    $userId = $currentUser['id'];
} else {
    // 游客回复：必须同时填写 QQ 和昵称
    if ($qq === '' || $guestNickname === '') {
        jsonResponse(['success' => false, 'message' => '请填写 QQ 号和昵称'], 400);
    }

    // 根据 QQ 自动生成头像（官方 qlogo），昵称以用户填写为准
    if ($qq !== '') {
        if ($guestAvatar === '') {
            $guestAvatar = 'https://q1.qlogo.cn/g?b=qq&nk=' . urlencode($qq) . '&s=100';
        }
    }

    if ($guestNickname === '') {
        $guestNickname = 'QQ 用户';
    }
    if ($guestAvatar === '') {
        $guestAvatar = '/assets/images/default-avatar.svg';
    }

    $userId = 0; // 游客
}

// 通过本地/扩展库解析 IP 归属地（若不可用则返回空字符串）
$location = '';
if (!empty($ip) && function_exists('get_ip_location_local')) {
    $location = trim(get_ip_location_local($ip));
}

$data = [
    'article_id'      => $articleId ?: null,
    'album_id'        => $albumId   ?: null,
    'event_id'        => $eventId   ?: null,
    'user_id'         => $userId,
    'guest_nickname'  => $guestNickname ?: null,
    'guest_avatar'    => $guestAvatar ?: null,
    'guest_qq'        => $qq ?: null,
    'parent_id'       => $parentId ?: null,
    'ip'              => $ip ?: null,
    'location'        => $location ?: null,
    'content'         => $content,
    'created_at'      => date('Y-m-d H:i:s')
];

$commentId = $db->insert('comments', $data);

if ($commentId) {
    if (!$currentUser) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['last_comment_time'] = $now;
    }
    // 记录 IP 级评论尝试，用于后续节流（最佳努力，不影响主流程）
    try {
        if (!empty($ip)) {
            $db->insert('comment_attempts', [
                'ip'         => $ip,
                'created_at' => date('Y-m-d H:i:s', $now),
            ]);
        }
    } catch (Throwable $e) {
        // 记录失败忽略
    }
    jsonResponse(['success' => true, 'message' => '评论发表成功', 'comment_id' => $commentId]);
} else {
    jsonResponse(['success' => false, 'message' => '评论发表失败'], 500);
}
