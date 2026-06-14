<?php
// 设置 UTF-8 编码
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/helpers.php';

// 尝试为老版本补充新字段（edit_mode / speaker）
migrate_schema_if_needed();

// 内部使用的小工具：根据头像路径生成可用的 URL（仅对 uploads 下的路径做补全）
if (!function_exists('resolve_avatar_url')) {
    function resolve_avatar_url(?string $path): string {
        if ($path === null) {
            return '';
        }
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        // QQ 头像优先升级为 HTTPS，避免混合内容
        if (preg_match('#^http://([^/]+\.)?qlogo\.cn/#i', $path)) {
            return 'https://' . substr($path, 7);
        }
        // 其它完整 URL 原样返回
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        // uploads 目录下的路径走 upload_url 统一处理
        if (strpos($path, '/uploads/') === 0 || strpos($path, 'uploads/') === 0) {
            return upload_url($path);
        }
        // 其它情况（如 /assets/...）保持原样
        return $path;
    }
}

// 页面标题（会在加载文章后更新）
$pageTitle = '文章详情';

$auth = new Auth();
$db   = Database::getInstance();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 获取文章详情
$article = $db->fetch(
    "SELECT a.*, u.nickname, u.avatar 
     FROM articles a 
     LEFT JOIN users u ON a.user_id = u.id 
     WHERE a.id = :id AND a.status = 'published'",
    ['id' => $id]
);

if (!$article) {
    header('HTTP/1.0 404 Not Found');
    die('文章不存在');
}

// 当前登录用户（后续多处会用到）
$currentUser = $auth->getCurrentUser();

// 加密文章且未登录时，不暴露真实标题
if (!empty($article['is_encrypted']) && empty($currentUser)) {
    $pageTitle = '加密文章';
} else {
    // 更新页面标题为文章标题
    $pageTitle = $article['title'];
}

// 增加浏览次数
$db->update('articles', ['views' => $article['views'] + 1], 'id = :id', ['id' => $id]);

// 评论开关（1=开启，0=关闭），默认开启
$commentsEnabled = isset($article['comments_enabled']) ? (int)$article['comments_enabled'] : 1;

// 文章内容改为直接使用存储的 HTML 渲染
$articleHtml = (string)($article['content'] ?? '');

// 工具函数：将正文中的站内图片改为懒加载 + WebP 优先
if (!function_exists('article_transform_images_webp_lazy')) {
    function article_transform_images_webp_lazy(string $html): string {
        return preg_replace_callback(
            // 匹配包含 uploads 路径的站内图片（支持相对路径和完整 URL）
            '#<img\s+([^>]*?)src=("|\')([^"\']*uploads/[^"\']+\.(?:jpg|jpeg|png))\2([^>]*)>#i',
            function ($m) {
                $beforeAttrs = trim($m[1] ?? '');
                $quote       = $m[2] ?? '"';
                $src         = $m[3] ?? '';
                $afterAttrs  = trim($m[4] ?? '');

                // 生成 WebP 路径，仅替换扩展名部分
                $webp = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $src);

                // 保留原有的其他属性，避免丢失样式 / class
                $attrs = trim($beforeAttrs . ' ' . $afterAttrs);
                if ($attrs !== '') {
                    $attrs = ' ' . $attrs;
                }

                $placeholder = '/assets/images/image-placeholder.svg';
                $srcAttr     = 'src=' . $quote . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . $quote;
                $dataSrcAttr = 'data-src=' . $quote . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . $quote;
                $webpAttr    = 'data-src-webp=' . $quote . htmlspecialchars($webp, ENT_QUOTES, 'UTF-8') . $quote;

                return '<img ' . $srcAttr . ' ' . $dataSrcAttr . ' ' . $webpAttr . $attrs . '>';
            },
            $html
        );
    }
}

// 工具函数：将正文中的 <video> 标签改为懒加载并为 Plyr 提供统一类名
if (!function_exists('article_transform_videos_lazy')) {
    function article_transform_videos_lazy(string $html): string {
        return preg_replace_callback(
            '#<video\s+([^>]*?)src=("|\')([^"\']+)\2([^>]*)>(.*?)</video>#is',
            function ($m) {
                $beforeAttrs = trim($m[1] ?? '');
                $quote       = $m[2] ?? '"';
                $src         = $m[3] ?? '';
                $afterAttrs  = trim($m[4] ?? '');
                $inner       = $m[5] ?? '';

                // 合并属性（不含 src），用于后续统一生成 <video>
                $rawAttrs = trim($beforeAttrs . ' ' . $afterAttrs);
                $attrs    = $rawAttrs !== '' ? ' ' . $rawAttrs : '';
                $attrs    = preg_replace('/\s*src=("|\')[^"\']*\1/i', '', $attrs);

                // 解析出可能存在的自定义宽度：
                // - 编辑器自动插入的 100% 视为“默认”，不当作自定义宽度
                // - 其它值（30%、400px 等）视为自定义宽度，后续交给 Plyr 容器处理
                $customWidth    = null;
                $customMaxWidth = null;

                // 从 style 中提取 max-width / width
                if (preg_match('/style=("|\')(.*?)\1/i', $rawAttrs, $styleMatch)) {
                    $styleBody = $styleMatch[2];
                    if (preg_match('/max-width\s*:\s*([^;]+)/i', $styleBody, $mw)) {
                        $val = trim($mw[1]);
                        if ($val !== '' && !preg_match('/^100%\s*$/', $val)) {
                            $customMaxWidth = $val;
                        }
                    }
                    if (preg_match('/\bwidth\s*:\s*([^;]+)/i', $styleBody, $sw)) {
                        $val = trim($sw[1]);
                        if ($val !== '' && !preg_match('/^100%\s*$/', $val)) {
                            $customWidth = $val;
                        }
                    }
                }

                // 从 width 属性中提取百分比或像素宽度（仅当不是 100% 时）
                if (preg_match('/\bwidth\s*=\s*"([^"]+)"/i', $rawAttrs, $widthMatch)) {
                    $val = trim($widthMatch[1]);
                    if ($val !== '' && !preg_match('/^100%\s*$/', $val)) {
                        if ($customWidth === null) {
                            $customWidth = $val;
                        }
                    }
                }

                // 确保包含 plyr-video 类
                if (preg_match('/\sclass=("|\')([^"\']*)\1/i', $attrs, $mm)) {
                    $classQuote = $mm[1];
                    $classVal   = trim($mm[2] . ' plyr-video');
                    $newClass   = ' class=' . $classQuote . $classVal . $classQuote;
                    $attrs = preg_replace('/\sclass=("|\')[^"\']*\1/i', $newClass, $attrs);
                } else {
                    $attrs .= ' class="plyr-video"';
                }

                // 若未显式设置 preload，则默认使用 metadata，兼顾懒加载与预览体验
                if (stripos($attrs, 'preload=') === false) {
                    $attrs .= ' preload="metadata"';
                }

                // 构造 data- 属性：data-src + 可选的 data-plyr-width / data-plyr-max-width
                $dataAttrs = [];
                $dataAttrs[] = 'data-src=' . $quote . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . $quote;
                if ($customWidth !== null) {
                    $dataAttrs[] = 'data-plyr-width=' . $quote . htmlspecialchars($customWidth, ENT_QUOTES, 'UTF-8') . $quote;
                }
                if ($customMaxWidth !== null) {
                    $dataAttrs[] = 'data-plyr-max-width=' . $quote . htmlspecialchars($customMaxWidth, ENT_QUOTES, 'UTF-8') . $quote;
                }

                $dataAttrStr = $dataAttrs ? ' ' . implode(' ', $dataAttrs) : '';

                return '<video' . $attrs . $dataAttrStr . '>' . $inner . '</video>';
            },
            $html
        );
    }
}

// 先对整篇正文应用图片懒加载 + WebP 转换
$articleHtml = article_transform_images_webp_lazy($articleHtml);
// 再对正文中的视频应用懒加载与 Plyr 兼容处理
$articleHtml = article_transform_videos_lazy($articleHtml);

// 读取块级内容（用于后续按块展示“谁写的”）
$articleBlocks = [];
try {
    $articleBlocks = $db->fetchAll(
        "SELECT b.block_index, b.user_id, b.speaker, b.html, u.role, u.nickname, u.avatar
         FROM article_blocks b
         LEFT JOIN users u ON b.user_id = u.id
         WHERE b.article_id = :article_id
         ORDER BY b.block_index ASC",
        ['article_id' => $article['id']]
    );
} catch (Exception $e) {
    $articleBlocks = [];
}

// 支持 ==高亮== ：在渲染后的 HTML 中，将 ==...== 转为 <mark>...</mark>
$articleHtml = preg_replace('/==(.+?)==/u', '<mark>$1</mark>', $articleHtml);

// 支持任务列表语法：
// - [ ] 待办
// - [x] 已完成
$articleHtml = preg_replace(
    '/<li>\s*\[\s\]\s*(.*?)<\/li>/iu',
    '<li class="task-item"><input type="checkbox" disabled> $1</li>',
    $articleHtml
);

$articleHtml = preg_replace(
    '/<li>\s*\[(x|X)\]\s*(.*?)<\/li>/iu',
    '<li class="task-item completed"><input type="checkbox" checked disabled> $2</li>',
    $articleHtml
);

// 对块级 HTML 也应用相同的标记转换，保持显示一致
if (!empty($articleBlocks)) {
    foreach ($articleBlocks as &$blk) {
        $html = (string)($blk['html'] ?? '');
        if ($html === '') {
            continue;
        }
        $html = preg_replace('/==(.+?)==/u', '<mark>$1</mark>', $html);
        $html = preg_replace(
            '/<li>\s*\[\s\]\s*(.*?)<\/li>/iu',
            '<li class="task-item"><input type="checkbox" disabled> $1</li>',
            $html
        );
        $html = preg_replace(
            '/<li>\s*\[(x|X)\]\s*(.*?)<\/li>/iu',
            '<li class="task-item completed"><input type="checkbox" checked disabled> $2</li>',
            $html
        );

        // 对块级内容中的图片也应用同样的懒加载 + WebP 处理
        $html = article_transform_images_webp_lazy($html);
        // 对块级内容中的视频也应用相同的懒加载 + Plyr 处理
        $html = article_transform_videos_lazy($html);

        $blk['html'] = $html;
    }
    unset($blk);
}

// 仅在开启评论时才去取评论列表（包含登录用户与游客回复）
$comments = [];
if ($commentsEnabled) {
    $comments = $db->fetchAll(
        "SELECT c.*, u.nickname AS user_nickname, u.avatar AS user_avatar, u.role 
         FROM comments c 
         LEFT JOIN users u ON c.user_id = u.id 
         WHERE c.article_id = :id 
         ORDER BY c.created_at ASC",
        ['id' => $id]
    );
}

$partner = $auth->getPartner();

// 计算男主 / 女主头像（用于行内小头像展示）：
// 若有登录情侣，则使用当前登录情侣；若为访客，则直接按 role 从用户表中取出 user1 / user2 的头像
$maleAvatarInline   = '';
$femaleAvatarInline = '';
try {
    if (!empty($currentUser) && !empty($partner) && !empty($currentUser['role']) && !empty($partner['role'])) {
        if ($currentUser['role'] === 'user1') {
            $maleAvatarInline   = !empty($currentUser['avatar']) ? $currentUser['avatar'] : '/assets/images/default-avatar.svg';
            $femaleAvatarInline = !empty($partner['avatar']) ? $partner['avatar'] : '/assets/images/default-avatar.svg';
        } else {
            $maleAvatarInline   = !empty($partner['avatar']) ? $partner['avatar'] : '/assets/images/default-avatar.svg';
            $femaleAvatarInline = !empty($currentUser['avatar']) ? $currentUser['avatar'] : '/assets/images/default-avatar.svg';
        }
    } else {
        // 访客模式：直接按 role 取当前站点的 user1 / user2 头像
        $maleRow = $db->fetch("SELECT avatar FROM users WHERE role = 'user1' AND status = 'active' LIMIT 1");
        $femaleRow = $db->fetch("SELECT avatar FROM users WHERE role = 'user2' AND status = 'active' LIMIT 1");
        if ($maleRow && !empty($maleRow['avatar'])) {
            $maleAvatarInline = $maleRow['avatar'];
        }
        if ($femaleRow && !empty($femaleRow['avatar'])) {
            $femaleAvatarInline = $femaleRow['avatar'];
        }
        if ($maleAvatarInline === '') {
            $maleAvatarInline = '/assets/images/default-avatar.svg';
        }
        if ($femaleAvatarInline === '') {
            $femaleAvatarInline = '/assets/images/default-avatar.svg';
        }
    }
} catch (Exception $e) {
    $maleAvatarInline   = '/assets/images/default-avatar.svg';
    $femaleAvatarInline = '/assets/images/default-avatar.svg';
}

// 在内联片段前展示头像：通过 CSS 变量 + ::before，而不是改写正文 HTML，避免文本被打乱

// 计算文章是否为"共创"：情侣双方各自贡献字数都达到阈值（例如 10 字）
$creatorAvatar    = !empty($article['avatar']) ? $article['avatar'] : '/assets/images/default-avatar.svg';
$creatorNickname  = !empty($article['nickname']) ? $article['nickname'] : '匿名用户';
$secondAvatar     = '';
$secondNickname   = '';
$isCoCreated      = false;
$displayAuthorRaw = $creatorNickname;
$creatorChars     = 0;
$otherChars       = 0;
$otherNickname    = '';
$maleChars        = 0;
$femaleChars      = 0;

if (!empty($article['user_id'])) {
    // 获取情侣双方信息（不依赖登录状态）
    $couple = get_couple_users();
    $user1 = $couple['user1'];
    $user2 = $couple['user2'];
    
    if ($user1 && $user2) {
        $creatorId  = (int) $article['user_id'];
        $user1Id    = (int) $user1['id'];
        $user2Id    = (int) $user2['id'];

        try {
            // 确保贡献统计表存在（若不存在则不会影响正常浏览，仅无法判定共创）
            $db->query("
                CREATE TABLE IF NOT EXISTS `article_contributions` (
                    `article_id` int(11) NOT NULL COMMENT '文章ID',
                    `user_id` int(11) NOT NULL COMMENT '用户ID',
                    `contributed_chars` int(11) NOT NULL DEFAULT 0 COMMENT '累计贡献字数',
                    `last_updated_at` datetime NOT NULL COMMENT '最后更新时间',
                    PRIMARY KEY (`article_id`, `user_id`),
                    KEY `user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章贡献统计';
            ");

            $rows = $db->fetchAll(
                "SELECT user_id, contributed_chars 
                 FROM article_contributions 
                 WHERE article_id = :article_id",
                ['article_id' => $article['id']]
            );

            $user1Chars = 0;
            $user2Chars = 0;
            foreach ($rows as $row) {
                $uid   = (int) $row['user_id'];
                $chars = (int) $row['contributed_chars'];
                if ($uid === $user1Id) {
                    $user1Chars = $chars;
                } elseif ($uid === $user2Id) {
                    $user2Chars = $chars;
                }
            }

            $threshold = 10;
            if ($user1Chars >= $threshold && $user2Chars >= $threshold) {
                $isCoCreated = true;
            }

            // 计算头像与显示昵称，以及贡献字数展示
            if ($creatorId === $user1Id) {
                $creatorChars  = $user1Chars;
                $otherChars    = $user2Chars;
                $secondAvatar  = !empty($user2['avatar']) ? $user2['avatar'] : '/assets/images/default-avatar.svg';
                $secondNickname = !empty($user2['nickname']) ? $user2['nickname'] : '';
                $otherNickname  = $secondNickname;
            } elseif ($creatorId === $user2Id) {
                $creatorChars  = $user2Chars;
                $otherChars    = $user1Chars;
                $secondAvatar  = !empty($user1['avatar']) ? $user1['avatar'] : '/assets/images/default-avatar.svg';
                $secondNickname = !empty($user1['nickname']) ? $user1['nickname'] : '';
                $otherNickname  = $secondNickname;
            } else {
                // 创建者并不在情侣两人之一中，仅用于显示当前情侣的贡献
                $creatorChars = $user1Chars;
                $otherChars   = $user2Chars;
                $otherNickname = '';
            }

            // 按角色映射为男主 / 女主的字数（user1=男主，user2=女主）
            $maleChars   = $user1Chars;
            $femaleChars = $user2Chars;

            if ($isCoCreated) {
                if ($secondNickname === '') {
                    $secondNickname = '另一半';
                }
                $displayAuthorRaw = $creatorNickname . ' & ' . $secondNickname;
            }
        } catch (Exception $e) {
            $isCoCreated    = false;
            $secondAvatar   = '';
            $secondNickname = '';
        }
    }
}
// 若不是共创或未命中第二个昵称，保持只显示创建者名称
if (!$isCoCreated) {
    $displayAuthorRaw = $creatorNickname;
}

// 构建一级评论和回复的映射（只做一层回复）
$rootComments    = [];
$repliesByParent = [];

foreach ($comments as $comment) {
    if (!empty($comment['parent_id'])) {
        $repliesByParent[$comment['parent_id']][] = $comment;
    } else {
        $rootComments[] = $comment;
    }
}

// 标记当前为文章详情页，方便在全局样式中做定向移动端优化
$isArticleDetail = true;

include __DIR__ . '/views/header.php';
?>

    <!-- 仅文章详情页加载本地 Plyr 播放器与初始化脚本 -->
    <link rel="stylesheet" href="/assets/vendor/plyr/plyr.css">
    <style>
        /* 统一视频播放器圆角，避免四角露出黑色底 */
        .plyr--video,
        .plyr--video .plyr__video-wrapper {
            border-radius: 16px;
            overflow: hidden;
        }
        .plyr--video video {
            border-radius: 0;
        }
    </style>
    <script src="/assets/vendor/plyr/plyr.polyfilled.min.js"></script>
    <script src="/assets/js/article-player.js"></script>

<section class="content-section">
    <div class="section-header">
        <h2><i class="fas fa-book-open"></i> 文章详情</h2>
    </div>

    <article class="article-detail article-card">
        <div class="author-meta">
            <div class="<?php echo $isCoCreated && $secondAvatar ? 'album-avatar-stack album-avatar-stack-co' : 'album-avatar-stack'; ?>" style="margin-right:0.9375rem;">
                <img src="<?php echo e($creatorAvatar); ?>" alt="<?php echo e($creatorNickname); ?>" class="album-avatar album-avatar-main">
                <?php if ($isCoCreated && $secondAvatar): ?>
                    <img src="<?php echo e($secondAvatar); ?>" alt="<?php echo e($secondNickname ?: $creatorNickname); ?>" class="album-avatar-secondary">
                <?php endif; ?>
            </div>
            <div class="meta-info">
                <div class="meta-main">
                    <div class="author-line">
                        <span class="author-name"><?php echo e($displayAuthorRaw); ?></span>
                        <?php if ($isCoCreated): ?>
                            <span class="album-meta-co">
                                <i class="fas fa-heart"></i>
                                共创
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="meta-tags">
                        <span class="meta-item">
                            <i class="far fa-clock"></i> <?php echo formatDate($article['created_at']); ?>
                        </span>
                        <span class="meta-item">
                            <i class="far fa-eye"></i> <?php echo $article['views']; ?> 浏览
                        </span>
                    </div>
                </div>
                <?php if ($maleChars > 0 || $femaleChars > 0): ?>
                    <div class="author-contrib">
                        <?php if ($maleChars > 0): ?>
                            <span class="badge-role badge-male author-contrib-pill">
                                男主 <?php echo $maleChars; ?> 字
                            </span>
                        <?php endif; ?>
                        <?php if ($femaleChars > 0): ?>
                            <span class="badge-role badge-female author-contrib-pill">
                                女主 <?php echo $femaleChars; ?> 字
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($article['is_encrypted']) && empty($currentUser)): ?>
            <div class="article-content article-body">
                <div class="encrypted-content">
                    <h1 class="article-title">加密文章</h1>
                    <i class="fas fa-lock"></i>
                    <p>当前内容已加密，请登录后双方可见</p>
                </div>
            </div>
        <?php else: ?>
            <h1 class="article-title"><?php echo e($article['title']); ?></h1>
            <?php
            // 为正文容器构造内联 CSS 变量，供 ::before 作为头像背景使用
            $articleBodyStyle = '';
            if ($maleAvatarInline) {
                $articleBodyStyle .= '--male-avatar-url: url(' . "'" . e($maleAvatarInline) . "'" . ');';
            }
            if ($femaleAvatarInline) {
                $articleBodyStyle .= '--female-avatar-url: url(' . "'" . e($femaleAvatarInline) . "'" . ');';
            }
            ?>
            <?php if (!empty($articleBlocks) && isset($article['edit_mode']) && $article['edit_mode'] !== 'full'): ?>
                <div class="article-content article-body article-dialog"<?php echo $articleBodyStyle ? ' style="' . $articleBodyStyle . '"' : ''; ?>>
                    <?php
                    $lastRole = null;
                    foreach ($articleBlocks as $blk):
                        $blockHtml = (string)($blk['html'] ?? '');
                        if ($blockHtml === '') {
                            continue;
                        }

                        $blockRole    = $blk['role'] ?? '';
                        $blockSpeaker = $blk['speaker'] ?? null;
                        $itemClass    = 'dialog-item-neutral';
                        $label        = '';

                        // 优先使用 speaker 字段（male / female / system）
                        if ($blockSpeaker === 'male') {
                            $itemClass = 'dialog-item-male';
                            $label     = '男主';
                        } elseif ($blockSpeaker === 'female') {
                            $itemClass = 'dialog-item-female';
                            $label     = '女主';
                        } elseif ($blockSpeaker === 'system') {
                            $itemClass = 'dialog-item-neutral';
                            $label     = '系统';
                        } else {
                            // 兼容老数据：根据用户 role 推断男主 / 女主
                            if ($blockRole === 'user1') {
                                $itemClass = 'dialog-item-male';
                                $label     = '男主';
                            } elseif ($blockRole === 'user2') {
                                $itemClass = 'dialog-item-female';
                                $label     = '女主';
                            }
                        }

                        // 系统身份单独渲染样式：不参与连续头像逻辑
                        if ($blockSpeaker === 'system') {
                            // 系统消息：去掉头像与气泡，使用两侧横线 + 中间提示文字的形式
                            $systemText = trim(strip_tags($blockHtml));
                            if ($systemText === '') {
                                $systemText = '系统消息';
                            }
                            ?>
                            <div class="dialog-item-system">
                                <div class="system-separator">
                                    <span class="system-separator-line"></span>
                                    <span class="system-separator-text"><?php echo e($systemText); ?></span>
                                    <span class="system-separator-line"></span>
                                </div>
                            </div>
                            <?php
                            continue;
                        }

                        // 判断是否为同一说话人的连续块，用于控制头像显示与间距
                        $isSameSpeaker      = $blockRole && ($blockRole === $lastRole);
                        $isFirstOfSequence  = !$isSameSpeaker;
                        if ($blockRole) {
                            $lastRole = $blockRole;
                        }

                        $itemClassFull = $itemClass;
                        if (!$isFirstOfSequence) {
                            $itemClassFull .= ' dialog-item-continuous';
                        }

                        // 判断是否为“仅单张图片”的块：单个 <img> 且无其他有效文本
                        $isImageOnly = false;
                        $imgCount    = 0;
                        if (stripos($blockHtml, '<img') !== false) {
                            if (preg_match_all('/<img\b[^>]*>/i', $blockHtml, $m)) {
                                $imgCount = count($m[0]);
                            }
                            if ($imgCount === 1) {
                                $tmp = preg_replace('/<img\b[^>]*>/i', '', $blockHtml);
                                $tmp = preg_replace('/<br\s*\/?>/i', '', $tmp);
                                $tmp = preg_replace('/&nbsp;|\xC2\xA0/iu', '', $tmp);
                                $tmp = preg_replace('/<p>\s*<\/p>/i', '', $tmp);
                                $tmp = trim(strip_tags($tmp));
                                if ($tmp === '') {
                                    $isImageOnly = true;
                                }
                            }
                        }

                        $bubbleClass = 'dialog-bubble';
                        if ($isImageOnly) {
                            $bubbleClass .= ' dialog-bubble-image-only';
                        }
                        ?>
                        <div class="dialog-item <?php echo $itemClassFull; ?>">
                            <div class="dialog-avatar-col">
                                <div class="dialog-avatar"></div>
                                <?php if ($label && $isFirstOfSequence): ?>
                                    <div class="dialog-label dialog-label-under-avatar">
                                        <span class="badge-role <?php echo $blockRole === 'user1' ? 'badge-male' : 'badge-female'; ?>">
                                            <?php echo $label; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="<?php echo $bubbleClass; ?>">
                                <div class="dialog-content">
                                    <?php echo $blockHtml; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="article-content article-body"<?php echo $articleBodyStyle ? ' style="' . $articleBodyStyle . '"' : ''; ?>>
                    <?php echo $articleHtml; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($article['tags'])): ?>
            <div class="article-footer">
                <?php foreach (explode(',', $article['tags']) as $tag): ?>
                    <span class="tag-pill"># <?php echo e(trim($tag)); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
</article>

    <div class="comment-section">
        <div class="section-title">
            <i class="fas fa-comments"></i>
            <span>评论 (<?php echo count($comments); ?>)</span>
            <?php if (!$commentsEnabled): ?>
                <span style="font-size: 0.9rem; margin-left: .5rem;">- 评论已关闭</span>
            <?php endif; ?>
        </div>
        
        <?php if ($commentsEnabled): ?>
            <form method="POST" action="/api/comment.php" class="comment-form" id="commentForm" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="article_id" value="<?php echo $id; ?>">
                <?php if (!$currentUser): ?>
                <div class="qq-row">
                    <div class="qq-avatar-wrap">
                        <img
                            id="comment-qq-avatar"
                            src="/assets/images/default-avatar.svg"
                            alt="QQ 头像">
                    </div>
                    <div class="qq-input-wrap">
                        <input
                            type="text"
                            name="qq"
                            id="comment-qq-input"
                            class="qq-input"
                            placeholder="填写 QQ 号（用于获取头像和昵称）">
                        <input
                            type="text"
                            name="guest_nickname"
                            id="comment-qq-nickname"
                            class="qq-input"
                            placeholder="昵称（可通过 QQ 自动获取，也可手动填写）">
                    </div>
                    <input
                        type="hidden"
                        name="guest_avatar"
                        id="comment-qq-avatar-input"
                        value="">
                </div>
                <?php endif; ?>
                <textarea name="content" class="comment-textarea" placeholder="写下你的评论..."></textarea>
                <div class="form-actions">
                    <button type="submit" class="submit-btn">
                        <i class="fas fa-paper-plane"></i> 发表评论
                    </button>
                </div>
            </form>
            <div id="commentFeedback" class="comment-feedback" aria-live="polite"></div>

            <div class="comment-list" id="commentsList">
                <?php foreach ($rootComments as $comment): ?>
                <?php
                    $displayNickname = $comment['user_nickname'] ?: ($comment['guest_nickname'] ?: '匿名用户');
                    $displayAvatar   = $comment['user_avatar'] ?: ($comment['guest_avatar'] ?: '/assets/images/default-avatar.svg');
                    $commentId       = (int) $comment['id'];
                    $locationText    = isset($comment['location']) && $comment['location'] !== '' ? $comment['location'] : '';
                ?>
                <div class="comment-item">
                    <img src="<?php echo e($displayAvatar); ?>" alt="<?php echo e($displayNickname); ?>" class="comment-avatar-img">
                    <div class="comment-body">
                        <div class="comment-header">
                            <span class="comment-user"><?php echo e($displayNickname); ?></span>
                            <div class="comment-header-right">
                                <span class="comment-time"><?php echo timeAgo($comment['created_at']); ?></span>
                                <?php if (!empty($comment['role']) && $comment['role'] !== 'admin'): ?>
                                    <span class="badge-role <?php echo $comment['role'] === 'user1' ? 'badge-male' : 'badge-female'; ?>">
                                        <?php echo $comment['role'] === 'user1' ? '男主' : '女主'; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($locationText): ?>
                                    <span class="badge-location">
                                        <?php echo e($locationText); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="comment-text">
                            <?php echo nl2br(e($comment['content'])); ?>
                        </div>

                        <div class="comment-actions">
                            <button
                                type="button"
                                class="action-link comment-reply-btn"
                                data-comment-id="<?php echo $commentId; ?>">
                                <i class="fas fa-reply"></i> 回复
                            </button>
                            <?php if (!empty($repliesByParent[$commentId])): ?>
                            <button
                                type="button"
                                class="action-link comment-toggle-replies-btn"
                                data-comment-id="<?php echo $commentId; ?>">
                                <i class="fas fa-comment-dots"></i> 展开 <?php echo count($repliesByParent[$commentId]); ?> 条回复
                            </button>
                            <?php endif; ?>
                        </div>

                        <div class="comment-reply-form is-collapsed" id="reply-form-<?php echo $commentId; ?>">
                            <?php if ($currentUser): ?>
                            <form method="POST" action="/api/comment.php" class="comment-reply-form-inner" novalidate>
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="article_id" value="<?php echo $id; ?>">
                                <input type="hidden" name="parent_id" value="<?php echo $commentId; ?>">
                                <textarea name="content" placeholder="写下你的回复..."></textarea>
                                <button type="submit" class="btn btn-primary btn-reply-submit">
                                    <i class="fas fa-reply"></i> 发送回复
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="POST" action="/api/comment.php" class="comment-reply-form-inner" novalidate>
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="article_id" value="<?php echo $id; ?>">
                                <input type="hidden" name="parent_id" value="<?php echo $commentId; ?>">
                                <div class="qq-row">
                                    <div class="qq-avatar-wrap">
                                        <img
                                            src="/assets/images/default-avatar.svg"
                                            alt="QQ 头像">
                                    </div>
                                    <div class="qq-input-wrap">
                                        <input
                                            type="text"
                                            name="qq"
                                            class="qq-input"
                                            placeholder="填写 QQ 号（用于获取头像和昵称）">
                                        <input
                                            type="text"
                                            name="guest_nickname"
                                            class="qq-input"
                                            placeholder="昵称（可通过 QQ 自动获取，也可手动填写）">
                                    </div>
                                    <input
                                        type="hidden"
                                        name="guest_avatar"
                                        value="">
                                </div>
                                <textarea name="content" placeholder="写下你的回复..."></textarea>
                                <button type="submit" class="btn btn-primary btn-reply-submit">
                                    <i class="fas fa-reply"></i> 发送回复
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($repliesByParent[$commentId])): ?>
                        <div class="comment-replies is-collapsed" id="comment-replies-<?php echo $commentId; ?>">
                            <?php foreach ($repliesByParent[$commentId] as $reply): ?>
                            <?php
                                $replyNickname = $reply['user_nickname'] ?: ($reply['guest_nickname'] ?: '匿名用户');
                                $replyAvatar   = $reply['user_avatar'] ?: ($reply['guest_avatar'] ?: '/assets/images/default-avatar.svg');
                                $replyLocation = isset($reply['location']) && $reply['location'] !== '' ? $reply['location'] : '';
                            ?>
                            <div class="comment-item reply-item">
                                <img src="<?php echo e($replyAvatar); ?>" alt="<?php echo e($replyNickname); ?>" class="comment-avatar-img">
                                <div class="comment-body">
                                    <div class="comment-header">
                                        <span class="comment-user"><?php echo e($replyNickname); ?></span>
                                        <div class="comment-header-right">
                                            <span class="comment-time"><?php echo timeAgo($reply['created_at']); ?></span>
                                            <?php if (!empty($reply['role']) && $reply['role'] !== 'admin'): ?>
                                                <span class="badge-role <?php echo $reply['role'] === 'user1' ? 'badge-male' : 'badge-female'; ?>">
                                                    <?php echo $reply['role'] === 'user1' ? '男主' : '女主'; ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($replyLocation): ?>
                                                <span class="badge-location">
                                                    <?php echo e($replyLocation); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="comment-text">
                                        <?php echo nl2br(e($reply['content'])); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="comments-disabled-text">该文章的评论功能已关闭。</p>
        <?php endif; ?>
    </div>
</section>

<style>
.article-detail {
    margin-bottom: 1rem;
    padding: 1.25rem 3.75rem !important;
}


@media (max-width: 1400px) {
    .article-detail {
        padding: 1.25rem 3.25rem !important;
    }
}

@media (max-width: 1200px) {
    .article-detail {
        padding: 1.25rem 2.75rem !important;
    }
}

@media (max-width: 768px) {
    .article-detail {
        padding: 1.25rem 1.75rem !important;
    }
}

@media (max-width: 480px) {
    .article-detail {
        padding: 1.25rem 1rem !important;
    }
}

.article-detail-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding-bottom: 1.5rem;
    border-bottom: 2px solid rgba(255, 255, 255, 0.2);
    margin-bottom: 2rem;
}

.article-info {
    margin-top: 0.5rem;
    font-size: 0.9rem;
}

.article-detail-content h1 {
    font-size: 2rem;
    margin-bottom: 1.5rem;
    padding-bottom: 1.25rem;
    border-bottom: 2px solid rgba(15, 23, 42, 0.18);
    color: var(--text-dark);
}

.article-body {
    font-size: 1rem;
    line-height: 1.55;
    color: var(--text-dark) !important;
    margin-bottom: 1rem;
}

.article-body p,
.article-body li,
.article-body blockquote {
    color: var(--text-dark) !important;
}

/* Markdown 内容排版增强 */
.article-body h2 {
    font-size: 1.6rem;
    margin-top: 1rem;
    margin-bottom: 0.35rem;
    padding-bottom: 0.45rem;
    border-bottom: 1.5px solid rgba(15, 23, 42, 0.16);
    color: var(--text-dark);
}

.article-body h3 {
    font-size: 1.35rem;
    margin-top: 0.9rem;
    margin-bottom: 0.3rem;
    color: var(--text-dark);
}

.article-body h4 {
    font-size: 1.15rem;
    margin-top: 0.8rem;
    margin-bottom: 0.25rem;
    color: var(--text-dark);
}

.article-body p {
    margin: 0.25rem 0;
}

.article-body a {
    color: var(--primary-color);
    text-decoration: underline;
    text-decoration-thickness: 1px;
    text-underline-offset: 0.18em;
}

.article-body a:hover {
    color: #ec4899;
}

.article-body ul,
.article-body ol {
    margin: 0.35rem 0;
    padding-left: 1.5rem;
}

.article-body li {
    margin: 0.08rem 0;
}

.article-body blockquote {
    margin: 1.1rem 0;
    padding: 1rem 1.25rem 1rem 1.4rem;
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(248, 250, 252, 0.98), rgba(239, 246, 255, 0.98));
    border-left: 4px solid rgba(129, 140, 248, 0.7);
    position: relative;
    color: var(--text-dark);
}

.article-body blockquote p {
    margin: 0.3rem 0;
}

.article-body blockquote::before {
    content: "“";
    position: absolute;
    left: 0.95rem;
    top: 0.5rem;
    font-size: 2.1rem;
    line-height: 1;
    color: rgba(148, 163, 184, 0.65);
    pointer-events: none;
}

/* 引用块变体：提示 / 警告 / 成功 */
.article-body blockquote.bq-note {
    background: linear-gradient(135deg, rgba(239, 246, 255, 0.98), rgba(219, 234, 254, 0.98));
    border-left-color: rgba(59, 130, 246, 0.85);
}

.article-body blockquote.bq-note::before {
    content: "i";
    font-size: 1.4rem;
    top: 0.6rem;
    color: rgba(59, 130, 246, 0.85);
}

.article-body blockquote.bq-warning {
    background: linear-gradient(135deg, rgba(255, 251, 235, 0.98), rgba(254, 243, 199, 0.98));
    border-left-color: rgba(245, 158, 11, 0.9);
}

.article-body blockquote.bq-warning::before {
    content: "!";
    font-size: 1.6rem;
    top: 0.55rem;
    color: rgba(245, 158, 11, 0.9);
}

.article-body blockquote.bq-success {
    background: linear-gradient(135deg, rgba(240, 253, 250, 0.98), rgba(220, 252, 231, 0.98));
    border-left-color: rgba(22, 163, 74, 0.9);
}

.article-body blockquote.bq-success::before {
    content: "✓";
    font-size: 1.5rem;
    top: 0.55rem;
    color: rgba(22, 163, 74, 0.9);
}

.article-body span[data-author="male"] {
    background: rgba(129, 140, 248, 0.18);
    border-radius: 0.25rem;
    padding: 0 0.08em;
}

.article-body span[data-author="female"] {
    background: rgba(244, 114, 182, 0.18);
    border-radius: 0.25rem;
    padding: 0 0.08em;
}

.article-body code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 0.9em;
    padding: 0.15em 0.4em;
    border-radius: 4px;
    background: #f3f4f6;
    color: #374151;
}

.article-body pre {
    margin: 1.1rem 0;
    padding: 1rem 1.25rem;
    border-radius: 14px;
    background: #0f172a;
    color: #e5e7eb;
    overflow-x: auto;
    font-size: 0.95rem;
}

.article-body pre code {
    background: transparent;
    padding: 0;
    color: inherit;
}

.article-body hr {
    margin: 1.1rem 0;
    border: 0;
    border-top: 1.5px solid #d1d5db;
}

.article-body table {
    width: 100%;
    border-collapse: collapse;
    margin: 1.2rem 0;
    font-size: 0.95rem;
}

/* Markdown 生成的表格：整体卡片化样式 */
.article-body table.md-table {
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
}

.article-body th,
.article-body td {
    padding: 0.6rem 0.75rem;
    border-bottom: 1px solid #e5e7eb;
}

.article-body thead th {
    background: #f9fafb;
    font-weight: 600;
}

.article-body tbody tr:nth-child(2n) {
    background: #f9fafb;
}

.article-body img {
    max-width: 100%;
    border-radius: 16px;
    display: block;
    margin: 1.5rem auto;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
}

.article-body span[data-author="male"] {
    background: rgba(129, 140, 248, 0.18);
    border-radius: 0.25rem;
    padding: 0 0.16em 0 0.16em;
    position: relative;
    display: inline;
    padding-left: 24px; /* 为头像留出空间 */
}

.article-body span[data-author="female"] {
    background: rgba(244, 114, 182, 0.18);
    border-radius: 0.25rem;
    padding: 0 0.16em 0 0.16em;
    position: relative;
    display: inline;
    padding-left: 24px; /* 为头像留出空间 */
}

.article-body span[data-author="male"]::before,
.article-body span[data-author="female"]::before {
    content: "";
    position: absolute;
    left: 2px;
    top: 0.5em; /* 基于字体大小，约为第一行中心 */
    transform: translateY(-50%); /* 头像自身垂直居中 */
    width: 18px;
    height: 18px;
    border-radius: 999px;
    background-size: cover;
    background-position: center;
}

.article-body span[data-author="male"]::before {
    background-image: var(--male-avatar-url);
}

.article-body span[data-author="female"]::before {
    background-image: var(--female-avatar-url);
}

.article-body span[data-author="male"]::after,
.article-body span[data-author="female"]::after {
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translate(-50%, -0.1rem) scale(0.92);
    font-size: 0.75rem;
    line-height: 1.2;
    padding: 0.12rem 0.4rem;
    border-radius: 999px;
    color: #f9fafb;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
}

.article-body span[data-author="male"]:hover::after {
    content: "男主写的";
    background: rgba(59, 130, 246, 0.96);
    animation: co-bubble-in 0.26s cubic-bezier(0.16, 0.84, 0.44, 1) forwards;
}

.article-body span[data-author="female"]:hover::after {
    content: "女主写的";
    background: rgba(236, 72, 153, 0.96);
    animation: co-bubble-in 0.26s cubic-bezier(0.16, 0.84, 0.44, 1) forwards;
}

@keyframes co-bubble-in {
    0% {
        opacity: 0;
        transform: translate(-50%, -0.1rem) scale(0.9);
    }
    100% {
        opacity: 1;
        transform: translate(-50%, -0.22rem) scale(1);
    }
}

/* 块级作者对话气泡布局 */
.article-dialog {
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
}

.dialog-item {
    display: flex;
    align-items: flex-start; /* 头像与气泡顶部对齐，营造对话感 */
}

.dialog-item-male {
    flex-direction: row;
    justify-content: flex-start;
}

.dialog-item-female {
    flex-direction: row-reverse;
    justify-content: flex-start;
}

.dialog-item-neutral {
    justify-content: center;
}

.dialog-item-system {
    margin: 1rem 0;
    display: flex;
    justify-content: center;
}

.dialog-avatar-col {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.dialog-item-continuous {
    margin-top: -0.3rem; /* 同一说话人连续气泡更靠近上一条，但不会完全贴在一起 */
}

.dialog-avatar {
    width: 36px;
    height: 36px;
    border-radius: 999px;
    overflow: hidden;
    flex-shrink: 0;
    box-shadow: 0 4px 10px rgba(15, 23, 42, 0.15);
    background: #e5e7eb;
}

.dialog-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.dialog-item-male .dialog-avatar-col {
    margin-right: 0.5rem;
}

.dialog-item-female .dialog-avatar-col {
    margin-left: 0.5rem;
}

.dialog-item-male .dialog-avatar {
    background-image: var(--male-avatar-url);
    background-size: cover;
    background-position: center;
}

.dialog-item-female .dialog-avatar {
    background-image: var(--female-avatar-url);
    background-size: cover;
    background-position: center;
}

.dialog-bubble {
    max-width: 80%;
    padding: 0.6rem 0.9rem;
    border-radius: 1rem;
    background: #f3f4f6;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
    position: relative;
}

.dialog-item-male .dialog-bubble {
    background: rgba(239, 246, 255, 0.98);
    border: 1px solid rgba(129, 140, 248, 0.4);
}

.dialog-item-female .dialog-bubble {
    background: rgba(253, 242, 248, 0.98);
    border: 1px solid rgba(244, 114, 182, 0.4);
}

.dialog-item-neutral .dialog-bubble {
    background: #f9fafb;
    border: 1px solid rgba(148, 163, 184, 0.35);
}

.dialog-bubble.dialog-bubble-image-only {
    max-width: 80%;
    padding: 0;
    background: transparent;
    border: none;
    box-shadow: none;
}

.dialog-bubble-image-only .dialog-content img {
    display: block;
    max-width: 100%;
    height: auto;
    border-radius: 1.1rem;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.18);
}

.dialog-label {
    margin-bottom: 0.25rem;
    font-size: 0.78rem;
}

.dialog-label-under-avatar {
    margin-top: 0.25rem;
}

.dialog-item-continuous .dialog-avatar,
.dialog-item-continuous .dialog-label-under-avatar {
    visibility: hidden; /* 保留占位，让连续气泡与上一条对齐，但不显示头像和标签 */
}

.system-separator {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    max-width: 100%;
    color: #6b7280;
    font-size: 0.78rem;
}

.system-separator-line {
    flex: 1;
    height: 1px;
    background: rgba(148, 163, 184, 0.6);
}

.system-separator-text {
    flex-shrink: 0;
    max-width: 60%;
    padding: 0.15rem 0.6rem;
    border-radius: 999px;
    background: #f9fafb;
    border: 1px solid rgba(148, 163, 184, 0.5);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* 正文内图片 / 视频最大宽度约束，防止撑破布局 */
.article-body img,
.article-dialog img {
    max-width: 100%;
    height: auto;
}

.article-body video,
.article-dialog video {
    max-width: 100%;
    height: auto;
    display: block;
    border-radius: 0.9rem;
}

.dialog-content p:last-child {
    margin-bottom: 0;
}

/* 代码块外层容器与复制按钮 */
.article-body .code-block-wrap {
    position: relative;
    margin: 1.5rem 0;
}

.article-body .code-block-wrap pre {
    margin: 0;
}

.code-copy-btn {
    position: absolute;
    top: 0.6rem;
    right: 0.9rem;
    border: none;
    border-radius: 999px;
    padding: 0.25rem 0.7rem;
    font-size: 0.78rem;
    color: #e5e7eb;
    background: rgba(15, 23, 42, 0.7);
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    cursor: pointer;
    opacity: 0.85;
    transition: opacity 0.2s ease, transform 0.15s ease, background 0.2s ease;
}

.code-copy-btn i {
    font-size: 0.8rem;
}

.code-copy-btn:hover {
    opacity: 1;
    transform: translateY(-1px);
    background: rgba(15, 23, 42, 0.9);
}

.code-copy-btn.copied {
    background: rgba(34, 197, 94, 0.9);
}

.article-tags {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.article-tags .tag {
    padding: 0.25rem 0.75rem;
    border-radius: 999px;
    background: rgba(102, 126, 234, 0.15);
    color: #667eea;
    font-size: 0.85rem;
}

/* 文章详情页评论区（新版样式） */
.comment-section {
    margin-top: 2.5rem;
    background: #ffffff;
    border-radius: 24px;
    padding: 2.5rem;
    box-shadow: 0 28px 80px rgba(15, 23, 42, 0.10);
}

.comment-section .section-title {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #333333;
}

.comment-section .section-title i {
    font-size: 1.1rem;
    color: var(--accent-purple);
}

.comment-form {
    margin-bottom: 2.5rem;
    background: #f9f9f9;
    padding: 1.5rem;
    border-radius: 20px;
    border: 1px solid rgba(0, 0, 0, 0.02);
}

.comment-section .qq-row {
    display: flex;
    gap: 0.9rem;
    margin-bottom: 1rem;
    align-items: center;
}

.comment-section .qq-avatar-wrap {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    overflow: hidden;
    border: 2px solid #ffffff;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    flex-shrink: 0;
    background: #eee;
    display: flex;
    align-items: center;
    justify-content: center;
}

.comment-section .qq-avatar-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.comment-section .qq-input-wrap {
    flex: 1;
    display: flex;
    gap: 0.9rem;
}

.comment-section .qq-input {
    flex: 1;
    padding: 0.75rem 0.9rem;
    border-radius: 12px;
    border: 1px solid #e0e0e0;
    background: #ffffff;
    font-size: 0.9rem;
}

.comment-textarea {
    width: 100%;
    min-height: 120px;
    padding: 0.95rem 1.05rem;
    border-radius: 15px;
    border: 1px solid #e0e0e0;
    background: #ffffff;
    font-size: 0.95rem;
    font-family: inherit;
    resize: vertical;
    outline: none;
    transition: all 0.3s;
}

.comment-textarea:focus,
.comment-section .qq-input:focus {
    border-color: var(--accent-purple);
    box-shadow: 0 0 0 3px rgba(161, 140, 209, 0.1);
}

.comment-section .qq-input::placeholder,
.comment-textarea::placeholder {
    color: #b0b0b0;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 1rem;
}

.submit-btn {
    background: linear-gradient(135deg, #ff9a9e 0%, #fad0c4 100%);
    border: none;
    padding: 0.6rem 1.4rem;
    border-radius: 20px;
    color: #ffffff;
    font-weight: 600;
    cursor: pointer;
    font-size: 0.9rem;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    box-shadow: 0 4px 10px rgba(255, 154, 158, 0.3);
    transition: transform 0.2s, box-shadow 0.2s;
}

.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(255, 154, 158, 0.4);
}

.comment-list {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    margin-top: 1rem;
}

.comment-item {
    display: flex;
    gap: 0.9rem;
}

.comment-item.reply-item {
    margin-top: 0.4rem;
}

.comment-avatar-img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}

.comment-body {
    flex: 1;
}

.comment-header {
    display: flex;
    align-items: center;
    margin-bottom: 0.35rem;
    font-size: 0.9rem;
}

.comment-user {
    font-weight: 700;
    font-size: 0.9rem;
    margin-right: 0.5rem;
    color: #333333;
}

.comment-header-right {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.8rem;
}

.comment-time {
    font-size: 0.78rem;
    color: #aaaaaa;
}

.badge-role {
    font-size: 0.65rem;
    padding: 0.1rem 0.5rem;
    border-radius: 999px;
    font-weight: normal;
}

.badge-male {
    background: #e3f2fd;
    color: #2196f3;
}

.badge-female {
    background: #fce4ec;
    color: #e91e63;
}

.badge-location {
    font-size: 0.65rem;
    padding: 0.1rem 0.5rem;
    border-radius: 999px;
    font-weight: normal;
    background: #e5e7eb;
    color: #374151;
}

.comment-text {
    font-size: 0.9rem;
    color: #555555;
    line-height: 1.6;
    margin-bottom: 0.5rem;
    word-break: break-all;
}

.comment-actions {
    display: flex;
    gap: 1rem;
}

.action-link {
    font-size: 0.8rem;
    color: #999999;
    cursor: pointer;
    transition: color 0.2s;
    user-select: none;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0;
    border: none;
    background: transparent;
}

.action-link:hover {
    color: var(--accent-purple);
}

/* 回复输入框样式与主评论输入保持一致风格 */
.comment-reply-form-inner textarea {
    width: 100%;
    min-height: 90px;
    padding: 0.75rem 0.9rem;
    border-radius: 10px;
    border: 1px solid #eeeeee;
    background: #f9f9f9;
    font-size: 0.85rem;
    font-family: inherit;
    resize: vertical;
    outline: none;
    transition: all 0.3s;
}

.comment-reply-form-inner textarea::placeholder {
    color: #b0b0b0;
}

.comment-reply-form-inner textarea:focus {
    border-color: var(--accent-purple);
    box-shadow: 0 0 0 3px rgba(161, 140, 209, 0.08);
}

.comment-replies {
    margin-top: 0.75rem;
    padding-left: 3rem;
    border-left: 1px dashed #e5e7eb;
    max-height: 0;
    opacity: 0;
    overflow: hidden;
    transform: translateY(-4px);
    transition:
        max-height 0.28s ease,
        opacity 0.25s ease,
        transform 0.25s ease;
}

.comment-replies.is-open {
    max-height: 1000px;
    opacity: 1;
    transform: translateY(0);
}

.comment-replies.is-collapsed {
    max-height: 0;
    opacity: 0;
}

.comment-actions-row {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.comment-reply-btn {
    padding: 0.25rem 0.75rem;
    border-radius: 999px;
    border: 1px solid #e5e7eb;
    background: #f9fafb;
    font-size: 0.8rem;
    color: #6b7280;
    cursor: pointer;
}

.comment-reply-btn:hover {
    background: #f3f4f6;
}

.comment-toggle-replies-btn {
    padding: 0.25rem 0.75rem;
    border-radius: 999px;
    border: 1px solid #e5e7eb;
    background: #ffffff;
    font-size: 0.8rem;
    color: #6b7280;
    cursor: pointer;
}

.comment-toggle-replies-btn:hover {
    background: #f9fafb;
}

.btn-reply-submit {
    margin-top: 0.5rem;
}

/* 回复表单展开/收起动画（与回复列表风格统一） */
.comment-reply-form {
    max-height: 0;
    opacity: 0;
    overflow: hidden;
    transform: translateY(-4px);
    margin-top: 0.75rem;
    transition:
        max-height 0.24s ease,
        opacity 0.22s ease,
        transform 0.22s ease;
}

.comment-reply-form.is-open {
    max-height: 600px;
    opacity: 1;
    transform: translateY(0);
}

.comment-reply-form.is-collapsed {
    max-height: 0;
    opacity: 0;
}

.comments-disabled-text {
    color: #999;
    font-size: 0.9rem;
}

.comment-feedback {
    margin-top: 0.5rem;
    font-size: 0.9rem;
}

.comment-feedback-success {
    color: #16a34a;
}

.comment-feedback-error {
    color: #dc2626;
}
</style>

<script>
// 文章详情页代码块复制按钮
(function () {
    const container = document.querySelector('.article-body');
    if (!container) return;

    const pres = container.querySelectorAll('pre');
    if (!pres.length) return;

    pres.forEach(function (pre) {
        const wrapper = document.createElement('div');
        wrapper.className = 'code-block-wrap';

        const parent = pre.parentNode;
        parent.insertBefore(wrapper, pre);
        wrapper.appendChild(pre);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'code-copy-btn';
        btn.innerHTML = '<i class="fas fa-copy"></i><span>复制</span>';
        wrapper.appendChild(btn);

        btn.addEventListener('click', function () {
            const code = pre.innerText || pre.textContent || '';
            if (!code) return;

            const trySetCopied = () => {
                btn.classList.add('copied');
                const span = btn.querySelector('span');
                if (span) span.textContent = '已复制';
                setTimeout(() => {
                    btn.classList.remove('copied');
                    if (span) span.textContent = '复制';
                }, 1500);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code).then(trySetCopied).catch(() => {
                    fallbackCopy(code, trySetCopied);
                });
            } else {
                fallbackCopy(code, trySetCopied);
            }
        });
    });

    function fallbackCopy(text, onSuccess) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.top = '-9999px';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            const ok = document.execCommand('copy');
            if (ok && typeof onSuccess === 'function') {
                onSuccess();
            }
        } catch (e) {
            console.error('复制失败', e);
        }
        document.body.removeChild(textarea);
    }
})();

// 文章评论 Ajax 提交，避免刷新导致重复提交
(function () {
    var form = document.getElementById('commentForm');
    if (!form) return;

    var feedback = document.getElementById('commentFeedback');
    var submitting = false;

    function setFeedback(message, type) {
        if (typeof window.showToast === 'function') {
            window.showToast(message || '', type === 'error' ? 'error' : 'success');
        } else if (feedback) {
            feedback.textContent = message || '';
            feedback.className = 'comment-feedback ' + (type === 'error' ? 'comment-feedback-error' : 'comment-feedback-success');
        }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (submitting) return;

        var textarea = form.querySelector('textarea[name="content"]');
        if (!textarea || !textarea.value.trim()) {
            setFeedback('评论内容不能为空', 'error');
            return;
        }

        var text = textarea.value.trim();
        if (text.length > 100) {
            setFeedback('评论内容不能超过 100 个字符', 'error');
            return;
        }

        // 游客评论前端校验：如果存在 QQ 输入，则必须同时填写 QQ 和昵称
        var qqInput = document.getElementById('comment-qq-input');
        var nickInput = document.getElementById('comment-qq-nickname');
        if (qqInput && nickInput) {
            var qq = qqInput.value.trim();
            var nick = nickInput.value.trim();
            if (!qq || !nick) {
                setFeedback('请填写 QQ 号和昵称后再发表评论', 'error');
                return;
            }
        }

        submitting = true;
        setFeedback('正在提交评论...', 'success');

        var formData = new FormData(form);

        fetch(form.action, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (res) {
            return res.json().catch(function () {
                return { success: false, message: '评论提交成功，正在刷新页面检查结果…' };
            });
        }).then(function (data) {
            if (data && data.success) {
                setFeedback(data.message || '评论发表成功，正在刷新…', 'success');
                textarea.value = '';
                setTimeout(function () {
                    // 使用 GET 刷新当前文章页，避免重复 POST
                    var url = window.location.href.split('#')[0];
                    var baseUrl = url.split('?')[0];
                    var query = url.indexOf('?') !== -1 ? url.split('?')[1] : '';
                    var newUrl = baseUrl;
                    if (query) {
                        newUrl += '?' + query;
                    }
                    if (newUrl.indexOf('?') === -1) {
                        newUrl += '?comment_success=1';
                    } else {
                        newUrl += '&comment_success=1';
                    }
                    window.location.href = newUrl + '#comments';
                }, 600);
            } else {
                setFeedback((data && data.message) || '评论提交失败，请稍后重试', 'error');
            }
        }).catch(function () {
            setFeedback('网络异常，评论提交失败，请稍后重试', 'error');
        }).finally(function () {
            submitting = false;
        });
    });
})();

// 评论回复表单：点击“回复”展开，提交后 Ajax 刷新
(function () {
    var replyButtons = document.querySelectorAll('.comment-reply-btn');
    if (replyButtons.length) {
        replyButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = this.getAttribute('data-comment-id');
                var formWrap = document.getElementById('reply-form-' + id);
                if (!formWrap) return;
                var isOpen = formWrap.classList.contains('is-open');

                if (isOpen) {
                    formWrap.classList.remove('is-open');
                    formWrap.classList.add('is-collapsed');
                } else {
                    formWrap.classList.remove('is-collapsed');
                    formWrap.classList.add('is-open');
                    // 展开时轻微滚动到表单位置
                    setTimeout(function () {
                        formWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 80);
                }
            });
        });
    }

    // 默认折叠回复列表，通过按钮展开/收起
    var toggleReplyButtons = document.querySelectorAll('.comment-toggle-replies-btn');
    if (toggleReplyButtons.length) {
        toggleReplyButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = this.getAttribute('data-comment-id');
                var repliesWrap = document.getElementById('comment-replies-' + id);
                if (!repliesWrap) return;

                var isOpen = repliesWrap.classList.contains('is-open');

                if (isOpen) {
                    // 收起
                    repliesWrap.classList.remove('is-open');
                    repliesWrap.classList.add('is-collapsed');
                    this.textContent = this.textContent.replace('收起', '展开');
                } else {
                    // 展开
                    repliesWrap.classList.remove('is-collapsed');
                    repliesWrap.classList.add('is-open');
                    this.textContent = this.textContent.replace('展开', '收起');
                    // 稍微延迟再滚动，保证动画开始后再定位
                    setTimeout(function () {
                        repliesWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 80);
                }
            });
        });
    }

    var replyForms = document.querySelectorAll('.comment-reply-form-inner');
    replyForms.forEach(function (rf) {
        rf.addEventListener('submit', function (e) {
            e.preventDefault();
            var form = this;

            var textarea = form.querySelector('textarea[name="content"]');
            if (!textarea || !textarea.value.trim()) {
                if (typeof window.showToast === 'function') {
                    window.showToast('回复内容不能为空', 'error');
                }
                return;
            }

            var text = textarea.value.trim();
            if (text.length > 100) {
                if (typeof window.showToast === 'function') {
                    window.showToast('回复内容不能超过 100 个字符', 'error');
                }
                return;
            }

            // 游客回复前端校验：如果存在 QQ 输入，则必须同时填写 QQ 和昵称
            var qqInput = form.querySelector('input[name="qq"]');
            var nickInput = form.querySelector('input[name="guest_nickname"]');
            if (qqInput && nickInput) {
                var qq = qqInput.value.trim();
                var nick = nickInput.value.trim();
                if (!qq || !nick) {
                    if (typeof window.showToast === 'function') {
                        window.showToast('请填写 QQ 号和昵称后再发送回复', 'error');
                    }
                    return;
                }
            }

            var formData = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (res) {
                return res.json().catch(function () {
                    return { success: false, message: '回复提交成功，正在刷新页面检查结果…' };
                });
                }).then(function (data) {
            if (data && data.success) {
                    var url = window.location.href.split('#')[0];
                    var baseUrl = url.split('?')[0];
                    var query = url.indexOf('?') !== -1 ? url.split('?')[1] : '';
                    var newUrl = baseUrl;
                    if (query) {
                        newUrl += '?' + query;
                    }
                    if (newUrl.indexOf('?') === -1) {
                        newUrl += '?comment_success=1';
                    } else {
                        newUrl += '&comment_success=1';
                    }
                    window.location.href = newUrl + '#comments';
                } else {
                    var msg = (data && data.message) || '回复提交失败，请稍后重试';
                    if (typeof window.showToast === 'function') {
                        window.showToast(msg, 'error');
                    }
                }
            }).catch(function () {
                var msg = '网络异常，回复提交失败，请稍后重试';
                if (typeof window.showToast === 'function') {
                    window.showToast(msg, 'error');
                }
            });
        });
    });
})();

// 文章评论 & 回复：根据 QQ 获取头像和昵称 + 加载弹窗
(function () {
    var isLoading = false;

    // 显示/隐藏获取昵称头像的加载弹窗（与留言页保持一致）
    function showQQLoading(show) {
        var overlay = document.getElementById('qq-loading-overlay');
        // 如果不存在弹窗节点，动态创建一个最简版，避免因为HTML缺失导致无提示
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'qq-loading-overlay';
            overlay.style.position = 'fixed';
            overlay.style.inset = '0';
            overlay.style.background = 'rgba(15,23,42,0.35)';
            overlay.style.display = 'flex';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            overlay.style.zIndex = '9999';
            overlay.innerHTML = '<div style="min-width:240px;padding:1.2rem 1.5rem;border-radius:14px;background:rgba(31,41,55,0.96);color:#e5e7eb;text-align:center;font-size:0.9rem;">获取昵称头像中…</div>';
            document.body.appendChild(overlay);
        }
        overlay.style.display = show ? 'flex' : 'none';
    }

    // 通用：根据 QQ 获取头像和昵称，更新传入的元素
    function fetchQQProfileForFields(qq, avatarImgEl, nicknameInputEl, avatarHiddenInputEl) {
        if (!qq || isLoading) return;
        if (!avatarImgEl || !nicknameInputEl) return;

        isLoading = true;
        showQQLoading(true);

        var originalSrc = avatarImgEl.getAttribute('src');
        if (originalSrc) {
            avatarImgEl.setAttribute('data-original-src', originalSrc);
        }
        avatarImgEl.src = '/assets/images/default-avatar.svg';

        // 1) 头像：直接使用官方 qlogo 地址
        var avatar = 'https://q1.qlogo.cn/g?b=qq&nk=' + encodeURIComponent(qq) + '&s=100';
        avatarImgEl.src = avatar;
        if (avatarHiddenInputEl) {
            avatarHiddenInputEl.value = avatar;
        }

        // 2) 昵称：通过站内适配接口获取（使用多接口轮循），每次以接口返回为准覆盖当前昵称
        fetch('/api/qq_profile.php?qq=' + encodeURIComponent(qq))
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (json) {
                if (!json || !json.success) return;
                var nick = json.nickname || '';
                if (nick) {
                    nicknameInputEl.value = nick;
                } else {
                    nicknameInputEl.value = '获取昵称失败，请手动填写';
                }
            })
            .catch(function () {
                // 忽略错误，仅影响昵称自动填充
            })
            .finally(function () {
                showQQLoading(false);
                isLoading = false;
            });
    }

    // 顶部主评论表单（游客）
    var mainQQInput = document.getElementById('comment-qq-input');
    var mainNicknameInput = document.getElementById('comment-qq-nickname');
    var mainAvatarInput = document.getElementById('comment-qq-avatar-input');
    var mainAvatarImg = document.getElementById('comment-qq-avatar');

    if (mainQQInput && mainNicknameInput && mainAvatarInput && mainAvatarImg) {
        mainQQInput.addEventListener('blur', function () {
            var qq = mainQQInput.value.trim();
            if (!qq) return;
            fetchQQProfileForFields(qq, mainAvatarImg, mainNicknameInput, mainAvatarInput);
        });
    }

    // 每条评论下的游客回复表单
    var replyForms = document.querySelectorAll('.comment-reply-form-inner');
    replyForms.forEach(function (form) {
        var qqField = form.querySelector('input[name="qq"]');
        var nickField = form.querySelector('input[name="guest_nickname"]');
        var avatarField = form.querySelector('input[name="guest_avatar"]');
        var avatarImgEl = form.querySelector('.qq-avatar-wrap img');

        // 只为包含 QQ 输入的一组（即游客回复）绑定事件
        if (!qqField || !nickField || !avatarImgEl || !avatarField) {
            return;
        }

        qqField.addEventListener('blur', function () {
            var qq = qqField.value.trim();
            if (!qq) return;
            fetchQQProfileForFields(qq, avatarImgEl, nickField, avatarField);
        });
    });
})();
</script>

<!-- QQ 昵称头像获取中的加载弹窗（与留言页共享样式） -->
<div class="qq-loading-overlay" id="qq-loading-overlay" style="display:none;">
    <div class="qq-loading-dialog">
        <div class="qq-loading-title">获取昵称头像中</div>
        <div class="qq-loading-dots">
            <span></span><span></span><span></span>
        </div>
        <div class="qq-loading-text">请稍等片刻</div>
    </div>
</div>

<?php include __DIR__ . '/views/footer.php'; ?>
