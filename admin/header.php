<?php
// 新版后台公用头部（移动端优先）
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/helpers.php';

$auth = $auth ?? new Auth();
// 后台仅允许情侣双方账号访问（user1 / user2），禁止其他角色误入
$auth->requireLogin();
$auth->requireRole(['user1', 'user2']);
$db          = $db ?? Database::getInstance();
$currentUser = $currentUser ?? $auth->getCurrentUser();

// 当前页面用于高亮底部导航/抽屉菜单
$adminPage = $adminPage ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台 - <?php echo e(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin_v2.css">
    <link rel="stylesheet"
          href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css"
          onerror="this.onerror=null;this.href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';">
</head>
<body class="admin-v2">
<div class="admin-drawer-backdrop"></div>
<aside class="admin-drawer">
    <div class="admin-drawer-header">
        <div>
            <div class="admin-drawer-title"><?php echo e(SITE_NAME); ?></div>
            <div style="font-size:0.8rem;color:var(--text-light);margin-top:0.15rem;">
                管理中心
            </div>
        </div>
        <button class="admin-icon-btn" type="button" data-admin-toggle="drawer">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <div class="admin-drawer-menu">
        <div class="admin-drawer-section-title">常用</div>
        <a href="/admin/index.php"
           class="admin-drawer-link <?php echo $adminPage === 'dashboard' ? 'admin-drawer-link-active' : ''; ?>">
            <i class="fas fa-home"></i><span>仪表盘</span>
        </a>
        <a href="/admin/articles.php"
           class="admin-drawer-link <?php echo $adminPage === 'articles' ? 'admin-drawer-link-active' : ''; ?>">
            <i class="fas fa-book-open"></i><span>文章 · 日记</span>
        </a>
        <a href="/admin/albums.php"
           class="admin-drawer-link <?php echo $adminPage === 'albums' ? 'admin-drawer-link-active' : ''; ?>">
            <i class="fas fa-images"></i><span>相册管理</span>
        </a>
        <a href="/admin/messages.php"
           class="admin-drawer-link <?php echo $adminPage === 'messages' ? 'admin-drawer-link-active' : ''; ?>">
            <i class="fas fa-comment-dots"></i><span>留言管理</span>
        </a>

        <div class="admin-drawer-section-title">更多</div>
        <a href="/admin/comment_ip_blacklist.php"
           class="admin-drawer-link <?php echo $adminPage === 'comment_ip_blacklist' ? 'admin-drawer-link-active' : ''; ?>">
            <i class="fas fa-user-slash"></i><span>评论黑名单</span>
        </a>
        <a href="/admin/events.php"
           class="admin-drawer-link <?php echo $adminPage === 'events' ? 'admin-drawer-link-active' : ''; ?>">
            <i class="fas fa-calendar-heart"></i><span>纪念事件</span>
        </a>
        <a href="/admin/profile.php"
           class="admin-drawer-link <?php echo $adminPage === 'profile' ? 'admin-drawer-link-active' : ''; ?>">
            <i class="fas fa-user"></i><span>个人资料</span>
        </a>
        <a href="/admin/settings.php"
           class="admin-drawer-link <?php echo $adminPage === 'settings' ? 'admin-drawer-link-active' : ''; ?>">
            <i class="fas fa-sliders-h"></i><span>系统设置</span>
        </a>
        <?php $toolsTab = $_GET['tab'] ?? ''; ?>
        <a href="/admin/tools_image_stats.php?tab=optimize"
           class="admin-drawer-link <?php echo ($adminPage === 'tools_stats' && $toolsTab === 'optimize') ? 'admin-drawer-link-active' : ''; ?>">
            <i class="fas fa-compress-arrows-alt"></i><span>图片补齐</span>
        </a>
        <a href="/admin/tools_image_stats.php"
           class="admin-drawer-link <?php echo ($adminPage === 'tools_stats' && $toolsTab !== 'optimize') ? 'admin-drawer-link-active' : ''; ?>">
            <i class="fas fa-chart-bar"></i><span>图片统计</span>
        </a>
        <a href="/logout.php" class="admin-drawer-link">
            <i class="fas fa-sign-out-alt"></i><span>退出登录</span>
        </a>
    </div>

    <div class="admin-drawer-footer">
        <div>当前用户：<?php echo e($currentUser['nickname'] ?? $currentUser['username']); ?></div>
    </div>
</aside>

<header class="admin-appbar">
    <div class="admin-appbar-inner">
        <div class="admin-appbar-left">
            <button class="admin-icon-btn" type="button" data-admin-toggle="drawer" aria-label="打开菜单">
                <i class="fas fa-bars"></i>
            </button>
            <a href="/admin/index.php" class="admin-logo">
                <i class="fas fa-heart"></i>
                <span class="admin-title-text"><?php echo e(SITE_NAME); ?></span>
            </a>
        </div>
        <div class="admin-appbar-actions">
            <a href="/" class="admin-icon-btn" title="前台">
                <i class="fas fa-globe"></i>
            </a>
            <img src="<?php echo e($currentUser['avatar']); ?>"
                 alt="<?php echo e($currentUser['nickname']); ?>"
                 class="admin-user-avatar">
        </div>
    </div>
</header>

<div class="admin-shell">
    <main class="admin-main">
