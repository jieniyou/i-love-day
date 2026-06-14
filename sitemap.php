<?php
// 简单 Sitemap 生成器
header('Content-Type: application/xml; charset=UTF-8');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/Database.php';

$db = Database::getInstance();

$urls = [];
$now  = date('Y-m-d');

// 首页
$urls[] = [
    'loc'        => BASE_URL . '/',
    'changefreq' => 'daily',
    'priority'   => '1.0',
    'lastmod'    => $now,
];

// 文章列表
$urls[] = [
    'loc'        => BASE_URL . '/articles.php',
    'changefreq' => 'daily',
    'priority'   => '0.9',
    'lastmod'    => $now,
];

// 相册列表
$urls[] = [
    'loc'        => BASE_URL . '/albums.php',
    'changefreq' => 'weekly',
    'priority'   => '0.8',
    'lastmod'    => $now,
];

// 事件时间轴
$urls[] = [
    'loc'        => BASE_URL . '/events.php',
    'changefreq' => 'weekly',
    'priority'   => '0.6',
    'lastmod'    => $now,
];

// 留言板
$urls[] = [
    'loc'        => BASE_URL . '/messages.php',
    'changefreq' => 'weekly',
    'priority'   => '0.5',
    'lastmod'    => $now,
];

// 文章详情
try {
    $articles = $db->fetchAll(
        "SELECT id, updated_at, created_at FROM articles WHERE status = 'published' ORDER BY id DESC"
    );
    foreach ($articles as $a) {
        $lastmod = $a['updated_at'] ?: $a['created_at'] ?: $now;
        $urls[] = [
            'loc'        => BASE_URL . '/article.php?id=' . $a['id'],
            'changefreq' => 'weekly',
            'priority'   => '0.8',
            'lastmod'    => substr($lastmod, 0, 10),
        ];
    }
} catch (Exception $e) {
    // 忽略文章拉取错误，避免 sitemap 整体报 500
}

// 相册详情
try {
    $albums = $db->fetchAll(
        "SELECT id, updated_at, created_at FROM albums ORDER BY id DESC"
    );
    foreach ($albums as $al) {
        $lastmod = $al['updated_at'] ?: $al['created_at'] ?: $now;
        $urls[] = [
            'loc'        => BASE_URL . '/album.php?id=' . $al['id'],
            'changefreq' => 'weekly',
            'priority'   => '0.7',
            'lastmod'    => substr($lastmod, 0, 10),
        ];
    }
} catch (Exception $e) {
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($urls as $u) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
    if (!empty($u['lastmod'])) {
        echo '    <lastmod>' . $u['lastmod'] . "</lastmod>\n";
    }
    if (!empty($u['changefreq'])) {
        echo '    <changefreq>' . $u['changefreq'] . "</changefreq>\n";
    }
    if (!empty($u['priority'])) {
        echo '    <priority>' . $u['priority'] . "</priority>\n";
    }
    echo "  </url>\n";
}

echo "</urlset>\n";

