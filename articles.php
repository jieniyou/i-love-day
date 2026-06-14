<?php
// 设置 UTF-8 编码
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/helpers.php';

// 设置页面标题
$pageTitle = '点点滴滴';

$auth        = new Auth();
$db          = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$partner     = $currentUser ? $auth->getPartner() : null;

$page    = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 12;
$offset  = ($page - 1) * $perPage;

// 获取文章总数
$total      = $db->fetch("SELECT COUNT(*) AS count FROM articles WHERE status = 'published'")['count'] ?? 0;
$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

// 获取文章列表
$articles = $db->fetchAll(
    "SELECT a.*, u.nickname, u.avatar 
     FROM articles a 
     LEFT JOIN users u ON a.user_id = u.id 
     WHERE a.status = 'published' 
     ORDER BY a.created_at DESC 
     LIMIT :limit OFFSET :offset",
    ['limit' => $perPage, 'offset' => $offset]
);

// 文章共创标记：创建者始终显示，另一半需要在后台有实际编辑记录才算共创
$articleCoCreated        = [];
$articleDisplayAuthors   = [];
$articleSecondAvatars    = [];
$articleCreatorCharsMap  = [];
$articleOtherCharsMap    = [];
$articleOtherNamesMap    = [];

if (!empty($articles)) {
    // 获取情侣双方信息（不依赖登录状态）
    $couple = get_couple_users();
    $user1 = $couple['user1'];
    $user2 = $couple['user2'];
    
    if ($user1 && $user2) {
        try {
            // 确保文章贡献统计表存在
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

            $articleIds = array_column($articles, 'id');
            $articleIds = array_map('intval', $articleIds);

            if ($articleIds) {
                $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
                $rows = $db->fetchAll(
                    "SELECT article_id, user_id, contributed_chars
                     FROM article_contributions
                     WHERE article_id IN ($placeholders)",
                    $articleIds
                );

                $contributors = [];
                foreach ($rows as $row) {
                    $aid   = (int) $row['article_id'];
                    $uid   = (int) $row['user_id'];
                    $chars = (int) $row['contributed_chars'];
                    if (!isset($contributors[$aid])) {
                        $contributors[$aid] = [];
                    }
                    $contributors[$aid][$uid] = $chars;
                }

                $user1Id   = (int) $user1['id'];
                $user2Id   = (int) $user2['id'];
                $threshold = 10;

                foreach ($articles as $article) {
                    $aid          = (int) $article['id'];
                    $creatorId    = isset($article['user_id']) ? (int) $article['user_id'] : 0;
                    $creatorName  = !empty($article['nickname']) ? $article['nickname'] : '匿名用户';
                    $displayName  = $creatorName;
                    $secondAvatar = '';
                    $stats        = $contributors[$aid] ?? [];

                    $user1Chars = $stats[$user1Id] ?? 0;
                    $user2Chars = $stats[$user2Id] ?? 0;
                    $isCo       = ($user1Chars >= $threshold && $user2Chars >= $threshold);

                    $creatorChars = 0;
                    $otherChars   = 0;
                    $otherName    = '';

                    if ($creatorId === $user1Id) {
                        $creatorChars = $user1Chars;
                        $otherChars   = $user2Chars;
                        $otherName    = !empty($user2['nickname']) ? $user2['nickname'] : '';
                        if ($isCo) {
                            $secondAvatar = !empty($user2['avatar']) ? $user2['avatar'] : '/assets/images/default-avatar.svg';
                        }
                    } elseif ($creatorId === $user2Id) {
                        $creatorChars = $user2Chars;
                        $otherChars   = $user1Chars;
                        $otherName    = !empty($user1['nickname']) ? $user1['nickname'] : '';
                        if ($isCo) {
                            $secondAvatar = !empty($user1['avatar']) ? $user1['avatar'] : '/assets/images/default-avatar.svg';
                        }
                    } else {
                        // 创建者并不在情侣两人之一中，仅用于展示当前情侣双方的贡献
                        $creatorChars = $user1Chars;
                        $otherChars   = $user2Chars;
                    }

                    if ($isCo) {
                        $secondName = $otherName;
                        if ($secondName === '') {
                            $secondName = '另一半';
                        }
                        $displayName = $creatorName . ' & ' . $secondName;
                    }

                    $articleCoCreated[$aid]         = $isCo;
                    $articleDisplayAuthors[$aid]    = $displayName;
                    $articleSecondAvatars[$aid]     = $secondAvatar;
                    $articleCreatorCharsMap[$aid]   = $creatorChars;
                    $articleOtherCharsMap[$aid]     = $otherChars;
                    $articleOtherNamesMap[$aid]     = $otherName;
                }
            }
        } catch (Exception $e) {
            $articleCoCreated        = [];
            $articleDisplayAuthors   = [];
            $articleSecondAvatars    = [];
            $articleCreatorCharsMap  = [];
            $articleOtherCharsMap    = [];
            $articleOtherNamesMap    = [];
        }
    }
}

include __DIR__ . '/views/header.php';
?>

<section class="content-section">
    <div class="section-header card-header-row">
        <h2><i class="fas fa-book"></i> 点点滴滴</h2>
    </div>
    
    <div class="article-list-large">
        <?php foreach ($articles as $article): ?>
        <?php
            $aid = (int) $article['id'];
            $isCoCreated = !empty($articleCoCreated[$aid]);
            $creatorAvatar = !empty($article['avatar']) ? $article['avatar'] : '/assets/images/default-avatar.svg';
            $displayAuthor = $articleDisplayAuthors[$aid] ?? (!empty($article['nickname']) ? $article['nickname'] : '匿名用户');
            $secondAvatar = $articleSecondAvatars[$aid] ?? '';
            $stackClass = 'album-avatar-stack' . ($isCoCreated && $secondAvatar ? ' album-avatar-stack-co' : '');
            $cardClass = 'article-card-large' . ($isCoCreated ? ' article-card-large-gradient' : '');
        ?>
        <div class="<?php echo $cardClass; ?>">
            <div class="article-card-window">
                <span class="article-card-window-dot red"></span>
                <span class="article-card-window-dot yellow"></span>
                <span class="article-card-window-dot green"></span>
            </div>
            <div class="article-card-header">
                <div class="<?php echo $stackClass; ?>">
                    <img src="<?php echo e($creatorAvatar); ?>" alt="<?php echo e($displayAuthor); ?>" class="album-avatar album-avatar-main">
                    <?php if ($isCoCreated && $secondAvatar): ?>
                        <img src="<?php echo e($secondAvatar); ?>" alt="<?php echo e($displayAuthor); ?>" class="album-avatar-secondary">
                    <?php endif; ?>
                </div>
                <div class="article-card-header-meta article-card-header-meta-with-co">
                    <div>
                        <h3><?php echo e($displayAuthor); ?></h3>
                        <span class="article-card-time"><?php echo formatDate($article['created_at'], 'Y-m-d H:i'); ?></span>
                    </div>
                    <div class="article-card-right-meta">
                        <?php if ($isCoCreated): ?>
                            <span class="album-meta-co">
                                <i class="fas fa-heart"></i>
                                共创
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="article-content">
                <?php if (!empty($article['is_encrypted']) && empty($currentUser)): ?>
                <div class="encrypted-content">
                    <i class="fas fa-lock"></i>
                    <p>当前内容已加密，请登录后查看</p>
                </div>
                <?php else: ?>
                <h4 class="article-card-title"><?php echo e($article['title']); ?></h4>
                <p class="article-card-excerpt"><?php echo mb_substr(strip_tags($article['content']), 0, 140); ?>...</p>
                <?php if (!empty($article['tags'])): ?>
                <div class="article-tags">
                    <?php foreach (explode(',', $article['tags']) as $tag): ?>
                    <span class="tag"><?php echo e(trim($tag)); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="article-footer">
                <a href="/article.php?id=<?php echo $article['id']; ?>" class="btn-view">
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($articles)): ?>
        <div class="glass-card">
            <p>还没有任何动态，去后台发布第一篇文章吧～</p>
        </div>
        <?php endif; ?>
    </div>
    
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page - 1; ?>" class="page-link">
            <i class="fas fa-chevron-left"></i> 上一页
        </a>
        <?php endif; ?>
        
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="?page=<?php echo $i; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
            <?php echo $i; ?>
        </a>
        <?php endfor; ?>
        
        <?php if ($page < $totalPages): ?>
        <a href="?page=<?php echo $page + 1; ?>" class="page-link">
            下一页 <i class="fas fa-chevron-right"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<style>
.pagination {
    display: flex;
    justify-content: center;
    gap: 0.5rem;
    margin-top: 2rem;
    flex-wrap: wrap;
}

.page-link {
    padding: 0.75rem 1rem;
    background: rgba(255, 255, 255, 0.25);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 10px;
    color: white;
    text-decoration: none;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.page-link:hover,
.page-link.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    transform: translateY(-2px);
}

.article-tags {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-top: 0.5rem;
}

.article-tags .tag {
    padding: 0.25rem 0.75rem;
    background: rgba(102, 126, 234, 0.2);
    border-radius: 20px;
    font-size: 0.85rem;
    color: #667eea;
}
</style>

<?php include __DIR__ . '/views/footer.php'; ?>
