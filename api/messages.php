<?php
// 留言分页加载接口
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

$auth        = new Auth();
$db          = Database::getInstance();
$currentUser = $auth->getCurrentUser();

$page    = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 6;

if ($perPage <= 0) {
    $perPage = 6;
} elseif ($perPage > 50) {
    $perPage = 50;
}

$offset    = ($page - 1) * $perPage;
$limitPlus = $perPage + 1; // 多取一条用于判断是否还有更多

try {
    if ($currentUser) {
        $sql = "
            SELECT m.*,
                   COALESCE(u.nickname, m.guest_nickname, '匿名用户') AS nickname,
                   COALESCE(u.avatar, m.guest_avatar, '/assets/images/default-avatar.svg') AS avatar
            FROM messages m
            LEFT JOIN users u ON m.user_id = u.id
            WHERE m.status = 'published'
              AND (m.is_public = 1 OR m.user_id = :user_id)
            ORDER BY m.created_at DESC
            LIMIT {$limitPlus} OFFSET {$offset}
        ";
        $rows = $db->fetchAll($sql, ['user_id' => $currentUser['id']]);
    } else {
        $sql = "
            SELECT m.*,
                   COALESCE(u.nickname, m.guest_nickname, '匿名用户') AS nickname,
                   COALESCE(u.avatar, m.guest_avatar, '/assets/images/default-avatar.svg') AS avatar
            FROM messages m
            LEFT JOIN users u ON m.user_id = u.id
            WHERE m.status = 'published'
              AND m.is_public = 1
            ORDER BY m.created_at DESC
            LIMIT {$limitPlus} OFFSET {$offset}
        ";
        $rows = $db->fetchAll($sql);
    }
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => '加载留言失败',
    ], 500);
}

$hasMore = false;
if (count($rows) > $perPage) {
    $hasMore = true;
    $rows    = array_slice($rows, 0, $perPage);
}

$items = [];
foreach ($rows as $row) {
    $location = isset($row['location']) ? (string) $row['location'] : '';
    $items[] = [
        'id'           => (int) ($row['id'] ?? 0),
        'nickname'     => (string) ($row['nickname'] ?? '匿名用户'),
        'avatar'       => (string) ($row['avatar'] ?: '/assets/images/default-avatar.svg'),
        'content_html' => nl2br(e($row['content'] ?? '')),
        'time_ago'     => timeAgo($row['created_at'] ?? date('Y-m-d H:i:s')),
        'location'     => $location,
    ];
}

jsonResponse([
    'success'  => true,
    'page'     => $page,
    'per_page' => $perPage,
    'has_more' => $hasMore,
    'items'    => $items,
]);
