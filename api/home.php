<?php
// 首页聚合数据接口
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Invalid request'], 405);
}

try {
    $auth        = new Auth();
    $db          = Database::getInstance();
    $currentUser = $auth->getCurrentUser();
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => '初始化失败',
    ], 500);
}

// 恋爱开始日期
$loveStartDate = null;
try {
    $loveDateRow = $db->fetch("SELECT value FROM settings WHERE `key` = 'love_date'");
    if ($loveDateRow && !empty($loveDateRow['value'])) {
        $loveStartDate = $loveDateRow['value'];
    }
} catch (Throwable $e) {
    $loveStartDate = null;
}

// 统计数据
$stats = [
    'articles' => 0,
    'events'   => 0,
    'albums'   => 0,
    'messages' => 0,
];

try {
    $articleTotalRow = $db->fetch("SELECT COUNT(*) AS c FROM articles WHERE status = 'published'");
    $eventTotalRow   = $db->fetch("SELECT COUNT(*) AS c FROM events");
    $albumTotalRow   = $db->fetch("SELECT COUNT(*) AS c FROM albums");
    $messageTotalRow = $db->fetch("SELECT COUNT(*) AS c FROM messages WHERE status = 'published'");

    $stats = [
        'articles' => $articleTotalRow ? (int) $articleTotalRow['c'] : 0,
        'events'   => $eventTotalRow   ? (int) $eventTotalRow['c']   : 0,
        'albums'   => $albumTotalRow   ? (int) $albumTotalRow['c']   : 0,
        'messages' => $messageTotalRow ? (int) $messageTotalRow['c'] : 0,
    ];
} catch (Throwable $e) {
    // 统计失败时保持默认 0，不中断整个接口
}

// 最新文章
$articles = [];
try {
    $rows = $db->fetchAll(
        "SELECT a.*, u.nickname, u.avatar
         FROM articles a
         LEFT JOIN users u ON a.user_id = u.id
         WHERE a.status = 'published'
         ORDER BY a.created_at DESC
         LIMIT 3"
    );

    foreach ($rows as $row) {
        $id          = (int) ($row['id'] ?? 0);
        $isEncrypted = !empty($row['is_encrypted']);
        $canView     = $currentUser || !$isEncrypted;

        $content = (string) ($row['content'] ?? '');
        $excerpt = $canView
            ? mb_substr(strip_tags($content), 0, 140)
            : '';

        $articles[] = [
            'id'               => $id,
            'title'            => (string) ($row['title'] ?? ''),
            'nickname'         => (string) ($row['nickname'] ?? ''),
            'avatar'           => (string) ($row['avatar'] ?? '/assets/images/default-avatar.svg'),
            'created_at_text'  => formatDate($row['created_at'] ?? date('Y-m-d H:i:s'), 'Y-m-d H:i'),
            'is_encrypted'     => $isEncrypted ? 1 : 0,
            'can_view_content' => $canView ? 1 : 0,
            'excerpt'          => $excerpt,
        ];
    }
} catch (Throwable $e) {
    $articles = [];
}

// 首页相册预览（两行左右的卡片）
$albums = [];
try {
    $rows = $db->fetchAll(
        "SELECT a.*, u.nickname, u.avatar,
                (SELECT COUNT(*) FROM album_images WHERE album_id = a.id) AS image_count
         FROM albums a
         LEFT JOIN users u ON a.user_id = u.id
         ORDER BY a.created_at DESC
         LIMIT 6"
    );

    $albumIds = array_column($rows, 'id');
    $covers   = [];

    if ($albumIds) {
        $placeholders = implode(',', array_fill(0, count($albumIds), '?'));
        $imageRows    = $db->fetchAll(
            "SELECT album_id, image_path, thumbnail_path
             FROM album_images
             WHERE album_id IN ($placeholders)
             ORDER BY album_id ASC, created_at DESC, id DESC",
            $albumIds
        );

        foreach ($imageRows as $imgRow) {
            $aid = (int) $imgRow['album_id'];
            if (!isset($covers[$aid])) {
                $covers[$aid] = [];
            }
            if (count($covers[$aid]) < 9) {
                $path = $imgRow['thumbnail_path'] ?: $imgRow['image_path'];
                $covers[$aid][] = upload_url($path);
            }
        }
    }

    foreach ($rows as $row) {
        $aid         = (int) ($row['id'] ?? 0);
        $isEncrypted = !empty($row['is_encrypted']);
        $imageCount  = (int) ($row['image_count'] ?? 0);

        $previewImages = array_values($covers[$aid] ?? []);
        if ($isEncrypted && !$currentUser) {
            // 未登录时访问加密相册，不返回真实预览图列表
            $previewImages = [];
        }

        $displayName = $isEncrypted && !$currentUser
            ? '加密相册'
            : (string) ($row['name'] ?? '');

        $albums[] = [
            'id'           => $aid,
            'name'         => (string) ($row['name'] ?? ''),
            'display_name' => $displayName,
            'is_encrypted' => $isEncrypted ? 1 : 0,
            'created_at_text' => formatDate($row['created_at'] ?? date('Y-m-d H:i:s'), 'Y-m-d'),
            'description'  => (string) ($row['description'] ?? ''),
            'image_count'  => $imageCount,
            'nickname'     => (string) ($row['nickname'] ?? ''),
            'avatar'       => (string) ($row['avatar'] ?? '/assets/images/default-avatar.svg'),
            'images'       => $previewImages,
        ];
    }
} catch (Throwable $e) {
    $albums = [];
}

// 最新公开留言（首页预览）
$latestMessages = [];
try {
    $rows = $db->fetchAll(
        "SELECT m.*,
                COALESCE(u.nickname, m.guest_nickname, '匿名用户') AS nickname,
                COALESCE(u.avatar, m.guest_avatar, '/assets/images/default-avatar.svg') AS avatar
         FROM messages m
         LEFT JOIN users u ON m.user_id = u.id
         WHERE m.status = 'published' AND m.is_public = 1
         ORDER BY m.created_at DESC
         LIMIT 100"
    );

    foreach ($rows as $row) {
        $contentText = trim(strip_tags($row['content'] ?? ''));
        $latestMessages[] = [
            'id'         => (int) ($row['id'] ?? 0),
            'nickname'   => (string) ($row['nickname'] ?? '匿名用户'),
            'avatar'     => (string) ($row['avatar'] ?: '/assets/images/default-avatar.svg'),
            'content'    => mb_substr($contentText, 0, 60),
            'time_ago'   => timeAgo($row['created_at'] ?? date('Y-m-d H:i:s')),
        ];
    }
} catch (Throwable $e) {
    $latestMessages = [];
}

jsonResponse([
    'success'        => true,
    'love_start_date'=> $loveStartDate,
    'stats'          => $stats,
    'articles'       => $articles,
    'albums'         => $albums,
    'latest_messages'=> $latestMessages,
]);
