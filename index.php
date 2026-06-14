<?php
// 设置 UTF-8 编码
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// 如果尚未安装或缺少数据库配置，则引导到安装向导
$rootPath = __DIR__;
if (!file_exists($rootPath . '/config/database.php') || !file_exists($rootPath . '/.installed')) {
    header('Location: /install.php');
    exit;
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/helpers.php';

$auth = new Auth();
$db   = Database::getInstance();

// 设置页面标题
$pageTitle = '首页';

// 当前用户与另一半
$currentUser = $auth->getCurrentUser();
$partner     = $currentUser ? $auth->getPartner() : null;

// 获取恋爱开始日期（允许为空：未设置）
$loveDateRow   = $db->fetch("SELECT value FROM settings WHERE `key` = 'love_date'");
$loveStartDate = ($loveDateRow && !empty($loveDateRow['value']))
    ? $loveDateRow['value']
    : null;
$loveDateSet   = $loveStartDate !== null;

// 仅在已设置恋爱开始日期时，计算统计信息；未设置时前端显示「未设置」提示
if ($loveDateSet) {
    // 计算在一起的天数
    $daysTogether = daysBetween($loveStartDate, date('Y-m-d'));

    // 计算距离下一次周年纪念日的天数
    $loveStart = new DateTime($loveStartDate);
    $today     = new DateTime('today');

    $yearsTogether = $loveStart->diff($today)->y;
    $nextAnniversary = (clone $loveStart)->modify('+' . ($yearsTogether + 1) . ' years');
    if ($nextAnniversary <= $today) {
        $nextAnniversary = (clone $loveStart)->modify('+' . ($yearsTogether + 2) . ' years');
    }
    $daysToNextAnniversary = $today->diff($nextAnniversary)->days;
} else {
    $daysTogether          = 0;
    $daysToNextAnniversary = null;
}

// 最新文章
$articles = $db->fetchAll(
    "SELECT a.*, u.nickname, u.avatar 
     FROM articles a 
     LEFT JOIN users u ON a.user_id = u.id 
     WHERE a.status = 'published' 
     ORDER BY a.created_at DESC 
     LIMIT 3"
);

// 最新事件
$events = $db->fetchAll(
    "SELECT e.*, u.nickname 
     FROM events e 
     LEFT JOIN users u ON e.user_id = u.id 
     ORDER BY e.sort_order ASC, e.event_date DESC, e.created_at DESC 
     LIMIT 14"
);

// 最新相册（首页显示两行左右的相册卡片）
$albums = $db->fetchAll(
    "SELECT a.*, u.nickname, u.avatar,
            (
                (SELECT COUNT(*) FROM album_images WHERE album_id = a.id) +
                (SELECT COUNT(*) FROM album_videos  WHERE album_id = a.id)
            ) AS image_count
     FROM albums a 
     LEFT JOIN users u ON a.user_id = u.id 
     ORDER BY a.created_at DESC 
     LIMIT 6"
);

// 统计数据（总数）
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

// 最新公开留言（首页预览）
$latestMessages = $db->fetchAll(
    "SELECT m.*, 
            COALESCE(u.nickname, m.guest_nickname, '匿名用户') AS nickname,
            COALESCE(u.avatar, m.guest_avatar, '/assets/images/default-avatar.svg') AS avatar
     FROM messages m 
     LEFT JOIN users u ON m.user_id = u.id 
     WHERE m.status = 'published' AND m.is_public = 1
     ORDER BY m.created_at DESC 
     LIMIT 100"
);

include __DIR__ . '/views/header.php';
include __DIR__ . '/views/home.php';
include __DIR__ . '/views/footer.php';
