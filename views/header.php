<?php
// 确保顶部使用的变量已定义，避免未定义变量报错
if (!isset($currentUser)) {
    $currentUser = null;
}
if (!isset($partner)) {
    $partner = null;
}
if (!isset($albumHeaderMood)) {
    $albumHeaderMood = '';
}

// 头部展示用情侣头像（即使未登录也尽量显示）
$headerUser1 = null;
$headerUser2 = null;

// 优先使用当前登录用户与其伴侣
if ($currentUser && $partner) {
    $headerUser1 = $currentUser;
    $headerUser2 = $partner;
} else {
    // 未登录或未能获取伴侣时，从数据库中直接读取 user1 / user2
    $headerDb = null;
    if (isset($db) && is_object($db)) {
        $headerDb = $db;
    } elseif (class_exists('Database')) {
        try {
            $headerDb = Database::getInstance();
        } catch (Exception $e) {
            $headerDb = null;
        }
    }

    if ($headerDb) {
        try {
            $users = $headerDb->fetchAll("SELECT * FROM users WHERE role IN ('user1','user2') AND status = 'active'");
            if ($users) {
                $roleMap = [];
                foreach ($users as $u) {
                    if (!empty($u['role'])) {
                        $roleMap[$u['role']] = $u;
                    }
                }
                if (isset($roleMap['user1']) && isset($roleMap['user2'])) {
                    $headerUser1 = $roleMap['user1'];
                    $headerUser2 = $roleMap['user2'];
                }
            }
        } catch (Exception $e) {
            // 忽略头像对读取失败
        }
    }
}

// 获取数据库连接
$headerDb = null;
if (isset($db) && is_object($db)) {
    $headerDb = $db;
} elseif (class_exists('Database')) {
    try {
        $headerDb = Database::getInstance();
    } catch (Exception $e) {
        $headerDb = null;
    }
}

// 从设置表读取网站标题与网站描述
$siteTitle = SITE_NAME; // 默认值
$siteDescription = '';
if ($headerDb) {
    try {
        $row = $headerDb->fetch("SELECT value FROM settings WHERE `key` = 'site_title'");
        if ($row && !empty($row['value'])) {
            $siteTitle = $row['value'];
        }

        $row = $headerDb->fetch("SELECT value FROM settings WHERE `key` = 'site_description'");
        if ($row && !empty($row['value'])) {
            $siteDescription = $row['value'];
        }
    } catch (Exception $e) {
        // 忽略读取失败的异常，使用默认值
    }
}

// 首页顶部大图：默认从设置表读取；如果外部已经传入 $homeBannerImage（例如相册详情页），则不再覆盖
if (!isset($homeBannerImage)) {
    $homeBannerImage = '';
    if ($headerDb) {
        try {
            $row = $headerDb->fetch("SELECT value FROM settings WHERE `key` = 'home_banner_image'");
            if ($row) {
                // 只要存在这一条设置记录，就不再使用默认图片；
                // 用户可以通过清空该设置来实现“无大图”效果
                if (!empty($row['value'])) {
                    $homeBannerImage = $row['value'];
                }
            } else {
                // 完全没有 home_banner_image 记录时（全新安装且未保存设置），使用预设默认大图（静态资源）
                $homeBannerImage = '/assets/images/default_hero.jpg';
            }
        } catch (Exception $e) {
            // 忽略顶部图片读取失败的异常
        }
    }

    // 新版：根据不同形式的路径补全为可直接在前端使用的地址
    if ($homeBannerImage !== '') {
        // 已经是绝对 URL 或协议相对 URL，原样使用
        if (strpos($homeBannerImage, 'http://') === 0 ||
            strpos($homeBannerImage, 'https://') === 0 ||
            strpos($homeBannerImage, '//') === 0) {
            // do nothing
        // 以 / 开头：视为站点根路径，例如 /assets/images/default_hero.jpg
        } elseif (strpos($homeBannerImage, '/') === 0) {
            // 保留为根路径，前端将相对当前域名加载
            // 如有需要，也可以改为 BASE_URL . $homeBannerImage
        // 其它情况：视为 uploads 下面的相对路径
        } else {
            $homeBannerImage = UPLOAD_URL . ltrim($homeBannerImage, '/');
        }
    }
}

// 页面标题：如果未设置，则只显示网站标题；如果设置了，则显示"页面标题 - 网站标题"
$pageTitle = isset($pageTitle) ? $pageTitle : '';
$fullTitle = $pageTitle ? $pageTitle . ' - ' . $siteTitle : $siteTitle;

// 页面描述：允许单页通过 $pageDescription 覆盖，未设置则使用全站网站描述
$pageDescription = isset($pageDescription) ? (string) $pageDescription : '';
if ($pageDescription === '') {
    $pageDescription = $siteDescription;
}

// 根据页面类型设置 body class
$bodyClass = '';
if (!empty($isAlbumDetail)) {
    $bodyClass = 'page-album-detail';
} elseif (!empty($isArticleDetail)) {
    $bodyClass = 'page-article-detail';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($fullTitle); ?></title>
    <?php if (!empty($pageDescription)): ?>
    <meta name="description" content="<?php echo e($pageDescription); ?>">
    <meta property="og:description" content="<?php echo e($pageDescription); ?>">
    <?php endif; ?>
    <meta property="og:title" content="<?php echo e($fullTitle); ?>">
    <link rel="stylesheet" href="/assets/css/style.css">
    <?php if (!empty($isArticleDetail)): ?>
    <link rel="stylesheet" href="/assets/css/article-detail.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css"
          onerror="this.onerror=null;this.href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';">
</head>
<body<?php if (!empty($bodyClass)): ?> class="<?php echo e($bodyClass); ?>"<?php endif; ?>>
    <div class="top-nav">
        <div class="top-nav-inner">
            <a href="/" class="top-nav-logo">
                <span class="top-nav-logo-main"><?php echo e($siteTitle); ?></span>
                <span class="top-nav-logo-sub">LOVE STORY</span>
            </a>
            <div class="top-nav-user">
                <?php if ($currentUser): ?>
                    <a href="/admin/" class="top-nav-link">管理后台</a>
                    <a href="/logout.php" class="top-nav-link">退出登录</a>
                <?php else: ?>
                    <a href="/login.php" class="top-nav-link">登录</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <header class="main-header">
        <div class="header-background"<?php if (!empty($homeBannerImage)): ?> data-bg="<?php echo e($homeBannerImage); ?>"<?php endif; ?>>
            <div class="header-overlay"></div>
            <div class="header-content">
                <?php if (!empty($isAlbumDetail)): ?>
                    <div class="welcome-text album-header-text">
                        <h1><?php echo e($albumHeaderTitle ?? $siteTitle); ?></h1>
                        <div class="album-header-tags">
                            <?php if (!empty($albumHeaderDate)): ?>
                                <span class="album-header-tag">
                                    <i class="fas fa-calendar-alt"></i>
                                    <?php echo e(is_string($albumHeaderDate) ? date('Y-m-d', strtotime($albumHeaderDate)) : date('Y-m-d', $albumHeaderDate)); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($albumHeaderAuthor)): ?>
                                <span class="album-header-tag">
                                    <i class="fas fa-user"></i>
                                    <?php echo e($albumHeaderAuthor); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($albumHeaderMood)): ?>
                                <span class="album-header-tag album-header-tag-mood">
                                    <i class="fas fa-heart"></i>
                                    <?php echo e($albumHeaderMood); ?>
                                </span>
                            <?php endif; ?>
                            <?php
                            // 相册详情页：加密且未登录时，用锁图标替代“已上传 X 张照片”
                            $showLockForEncryptedAlbum = isset($albumIsEncryptedForGuest) && $albumIsEncryptedForGuest;
                            ?>
                            <?php if ($showLockForEncryptedAlbum): ?>
                                <span class="album-header-tag">
                                    <i class="fas fa-lock"></i>
                                    加密相册
                                </span>
                            <?php else: ?>
                                <?php
                                $imgCount   = isset($albumHeaderImageCount)
                                    ? (int) $albumHeaderImageCount
                                    : (isset($albumHeaderCount) ? (int) $albumHeaderCount : 0);
                                $videoCount = isset($albumHeaderVideoCount) ? (int) $albumHeaderVideoCount : 0;
                                ?>
                                <?php if ($imgCount > 0): ?>
                                    <span class="album-header-tag">
                                        <i class="fas fa-images"></i>
                                        已上传 <?php echo $imgCount; ?> 张照片
                                    </span>
                                <?php else: ?>
                                    <span class="album-header-tag">
                                        <i class="fas fa-images"></i>
                                        未上传图片
                                    </span>
                                <?php endif; ?>
                                <?php if ($videoCount > 0): ?>
                                    <span class="album-header-tag">
                                        <i class="fas fa-video"></i>
                                        已上传 <?php echo $videoCount; ?> 个视频
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($headerUser1 && $headerUser2): ?>
                <div class="avatar-pair">
                    <div class="avatar-container">
                        <img src="<?php echo e($headerUser1['avatar']); ?>" alt="<?php echo e($headerUser1['nickname']); ?>" class="avatar">
                        <div class="avatar-label"><?php echo e($headerUser1['nickname']); ?></div>
                    </div>
                    <div class="heart-icon">
                        <i class="fas fa-heart"></i>
                    </div>
                    <div class="avatar-container">
                        <img src="<?php echo e($headerUser2['avatar']); ?>" alt="<?php echo e($headerUser2['nickname']); ?>" class="avatar">
                        <div class="avatar-label"><?php echo e($headerUser2['nickname']); ?></div>
                    </div>
                </div>
                <?php else: ?>
                <div class="welcome-text">
                    <h1><?php echo e($siteTitle); ?></h1>
                    <p>记录我们的小小点滴</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="header-wave">
            <svg class="waves" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
                 viewBox="0 24 150 28" preserveAspectRatio="none" shape-rendering="auto">
                <defs>
                    <path id="gentle-wave"
                          d="M-160 44c30 0 58-18 88-18s58 18 88 18 58-18 88-18 58 18 88 18v44h-352z"></path>
                </defs>
                <g class="parallax">
                    <use xlink:href="#gentle-wave" x="48" y="0" fill="rgba(255,255,255,0.7)"></use>
                    <use xlink:href="#gentle-wave" x="48" y="3" fill="rgba(255,255,255,0.5)"></use>
                    <use xlink:href="#gentle-wave" x="48" y="5" fill="rgba(255,255,255,0.3)"></use>
                    <use xlink:href="#gentle-wave" x="48" y="7" fill="#ffffff"></use>
                </g>
            </svg>
        </div>
    </header>

    <nav class="main-nav">
        <div class="nav-buttons">
            <a href="/articles.php" class="nav-button gradient-green">
                <i class="fas fa-book"></i>
                <span>点点滴滴</span>
            </a>
            <a href="/messages.php" class="nav-button gradient-pink">
                <i class="fas fa-comment"></i>
                <span>留言墙</span>
            </a>
            <a href="/albums.php" class="nav-button gradient-blue">
                <i class="fas fa-images"></i>
                <span>爱情相册</span>
            </a>
            <a href="/events.php" class="nav-button gradient-purple">
                <i class="fas fa-calendar-days"></i>
                <span>纪念事件</span>
            </a>
        </div>
    </nav>

    <main class="page-main">
