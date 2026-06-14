<?php
// 新版后台 - 文章列表（移动端优先）
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db          = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$partner     = $auth->getPartner();

// 确保文章权限表存在（用于控制另一半是否可编辑）
try {
    $db->query("
        CREATE TABLE IF NOT EXISTS `article_permissions` (
            `article_id` int(11) NOT NULL COMMENT '文章ID',
            `allow_partner_edit` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否允许另一半编辑',
            `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
            PRIMARY KEY (`article_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章权限表';
    ");
} catch (Exception $e) {
    // 表创建失败时，后续保持默认“允许另一半编辑”行为
}

// 删除文章：通过 POST + CSRF 处理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    require_csrf();
    $id = intval($_POST['delete_id']);
    if ($id > 0) {
        // 权限校验：创建者永远可删除；另一半仅在允许编辑时可删除
        $articleRow = null;
        try {
            $articleRow = $db->fetch(
                "SELECT a.*, COALESCE(ap.allow_partner_edit, 1) AS allow_partner_edit
                 FROM articles a
                 LEFT JOIN article_permissions ap ON ap.article_id = a.id
                 WHERE a.id = :id",
                ['id' => $id]
            );
        } catch (Exception $e) {
            // 如果权限表不存在或查询失败，则退回只查 articles 表
            $articleRow = $db->fetch(
                "SELECT a.* FROM articles a WHERE a.id = :id",
                ['id' => $id]
            );
        }

        if (!$articleRow) {
            header('Location: articles.php?error=' . urlencode('文章不存在或已被删除'));
            exit;
        }

        $isOwner       = isset($articleRow['user_id']) && (int) $articleRow['user_id'] === (int) $currentUser['id'];
        $partnerId     = $partner['id'] ?? null;
        $isPartnerUser = $partnerId && isset($articleRow['user_id']) && (int) $articleRow['user_id'] === (int) $partnerId;
        // 若数据库中尚未添加 allow_partner_edit 字段，则默认为允许另一半编辑
        $allowPartnerEdit = isset($articleRow['allow_partner_edit']) ? (int) $articleRow['allow_partner_edit'] : 1;
        $canDeleteArticle = $isOwner || ($isPartnerUser && $allowPartnerEdit);

        if (!$canDeleteArticle) {
            header('Location: articles.php?error=' . urlencode('你没有权限删除这篇文章'));
            exit;
        }

        // 在标记文章为删除前，尝试清理未再被引用的上传文件（图片 / 视频）
        try {
            $htmlPieces = [];

            // 当前文章正文
            $articleContent = (string) ($articleRow['content'] ?? '');
            if ($articleContent !== '') {
                $htmlPieces[] = $articleContent;
            }

            // 兼容块 / 聊天模式：附加块级 HTML
            try {
                $blocks = $db->fetchAll(
                    "SELECT html FROM article_blocks WHERE article_id = :article_id",
                    ['article_id' => $id]
                );
                foreach ($blocks as $bRow) {
                    $blockHtml = (string) ($bRow['html'] ?? '');
                    if ($blockHtml !== '') {
                        $htmlPieces[] = $blockHtml;
                    }
                }
            } catch (Exception $e) {
                // 旧库没有 article_blocks 表时忽略
            }

            if (!empty($htmlPieces) && function_exists('extract_upload_paths_from_html')) {
                $allPaths = [];
                foreach ($htmlPieces as $html) {
                    $paths = extract_upload_paths_from_html($html);
                    if (!empty($paths)) {
                        $allPaths = array_merge($allPaths, $paths);
                    }
                }
                if (!empty($allPaths)) {
                    $allPaths = array_unique($allPaths);
                    foreach ($allPaths as $relPath) {
                        delete_upload_file_if_unused($relPath, $id);
                    }
                }
            }
        } catch (Exception $e) {
            // 忽略清理失败，不影响删除主流程
        }

        // 尝试删除与该文章关联的文章视频记录（仅删除数据库记录，不影响其它文章）
        try {
            $db->delete('article_videos', 'article_id = :article_id', ['article_id' => $id]);
        } catch (Exception $e) {
            // 旧库没有 article_videos 表时忽略
        }

        // 兜底措施：删除 uploads/articles/{id} 目录下的所有文件与子目录，避免遗留无用文件与空目录
        if (defined('UPLOAD_DIR')) {
            $articleUploadDir = rtrim(UPLOAD_DIR, '/\\') . '/articles/' . (int)$id;
            if (is_dir($articleUploadDir)) {
                try {
                    $it = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($articleUploadDir, \FilesystemIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($it as $fsItem) {
                        /** @var \SplFileInfo $fsItem */
                        if ($fsItem->isDir()) {
                            @rmdir($fsItem->getPathname());
                        } else {
                            @unlink($fsItem->getPathname());
                        }
                    }
                    @rmdir($articleUploadDir);
                } catch (Throwable $e) {
                    // 兜底清理失败不影响删除主流程
                }
            }
        }

        $db->update('articles', ['status' => 'deleted'], 'id = :id', [
            'id' => $id,
        ]);
        header('Location: articles.php?success=删除成功');
        exit;
    }
}

// 展示所有未删除文章（附带权限信息，用于控制编辑/删除按钮）
try {
    $articles = $db->fetchAll(
        "SELECT a.*,
                COALESCE(ap.allow_partner_edit, 1) AS allow_partner_edit
         FROM articles a
         LEFT JOIN article_permissions ap ON ap.article_id = a.id
         WHERE a.status != 'deleted'
         ORDER BY a.created_at DESC"
    );
} catch (Exception $e) {
    // 若权限表不存在或查询失败，退回不带权限信息的文章列表
    $articles = $db->fetchAll(
        "SELECT * FROM articles WHERE status != 'deleted' ORDER BY created_at DESC"
    );
}

$adminPage = 'articles';

include __DIR__ . '/header.php';
?>

    <section class="admin-page-title">
        <h1>文章 · 日记</h1>
        <p>管理你们记录下来的每一段回忆</p>
    </section>

    <?php if (isset($_GET['success'])): ?>
        <div class="admin-card" style="margin-bottom:0.75rem;background:rgba(34,197,94,0.05);border:1px solid rgba(34,197,94,0.35);">
            <div style="display:flex;align-items:center;gap:0.5rem;color:#15803d;font-size:0.9rem;">
                <i class="fas fa-check-circle"></i>
                <span><?php echo e($_GET['success']); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="admin-card" style="margin-bottom:0.75rem;background:rgba(248,113,113,0.05);border:1px solid rgba(248,113,113,0.35);">
            <div style="display:flex;align-items:center;gap:0.5rem;color:#b91c1c;font-size:0.9rem;">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo e($_GET['error']); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <div class="admin-card" style="margin-bottom:0.75rem;">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">内容概览</div>
                <div class="admin-card-subtitle">共 <?php echo count($articles); ?> 篇内容</div>
            </div>
            <a href="/admin/article_add.php" class="btn btn-primary">
                <i class="fas fa-plus"></i>
                <span>撰写新文章</span>
            </a>
        </div>
    </div>

    <?php if (empty($articles)): ?>
        <div class="admin-card">
            <p style="font-size:0.9rem;color:var(--text-light);">
                还没有任何文章，点击右上角“撰写新文章”开始记录第一篇吧～
            </p>
        </div>
    <?php else: ?>
        <section class="admin-grid">
            <?php foreach ($articles as $article): ?>
                <?php
                $isOwner          = isset($article['user_id']) && (int) $article['user_id'] === (int) $currentUser['id'];
                $partnerId        = $partner['id'] ?? null;
                $isPartnerUser    = $partnerId && isset($article['user_id']) && (int) $article['user_id'] === (int) $partnerId;
                $allowPartnerEdit = isset($article['allow_partner_edit']) ? (int) $article['allow_partner_edit'] : 1;
                $canEditArticle   = $isOwner || ($isPartnerUser && $allowPartnerEdit);
                $editMode         = isset($article['edit_mode']) ? $article['edit_mode'] : 'full';
                $editModeLabel    = $editMode === 'blocks' ? '对话框模式' : '整篇模式';

                // 统计该文章的评论数量（仅文章评论）
                $commentCount = 0;
                try {
                    $row = $db->fetch(
                        "SELECT COUNT(*) AS c FROM comments WHERE article_id = :article_id",
                        ['article_id' => $article['id']]
                    );
                    $commentCount = (int)($row['c'] ?? 0);
                } catch (Exception $e) {
                    $commentCount = 0;
                }
                ?>
                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <div class="admin-card-title" style="max-width:15rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                <?php echo e($article['title']); ?>
                                <?php if (!empty($article['is_encrypted'])): ?>
                                    <i class="fas fa-lock" style="color:#f97373;margin-left:0.25rem;"></i>
                                <?php endif; ?>
                            </div>
                            <div class="admin-card-subtitle">
                                <?php echo $article['type'] === 'article' ? '文章' : '日记'; ?>
                                · <?php echo formatDate($article['created_at']); ?>
                                · 编辑：<?php echo $editModeLabel; ?>
                                · 评论：<?php echo $commentCount; ?> 条
                            </div>
                        </div>
                        <span class="badge <?php echo $article['status'] === 'published' ? 'badge-success' : 'badge-warning'; ?>">
                            <?php echo $article['status'] === 'published' ? '已发布' : '草稿'; ?>
                        </span>
                    </header>

                    <div style="font-size:0.8rem;color:var(--text-light);margin-bottom:0.5rem;">
                        浏览量：<?php echo (int) $article['views']; ?>
                    </div>

                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                        <a href="/article.php?id=<?php echo $article['id']; ?>" target="_blank" class="btn btn-secondary">
                            <i class="fas fa-eye"></i>
                            <span>前台查看</span>
                        </a>
                        <a href="/admin/article_comments.php?article_id=<?php echo $article['id']; ?>" class="btn btn-outline">
                            <i class="fas fa-comments"></i>
                            <span>管理评论</span>
                        </a>
                        <?php if ($canEditArticle): ?>
                            <a href="/admin/article_edit.php?id=<?php echo $article['id']; ?>" class="btn btn-outline">
                                <i class="fas fa-edit"></i>
                                <span>编辑</span>
                            </a>
                            <form method="POST" data-confirm="确定要删除这篇文章吗？">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="delete_id" value="<?php echo $article['id']; ?>">
                                <button type="submit" class="btn btn-secondary" style="background:#fee2e2;color:#b91c1c;border:1px solid rgba(248,113,113,0.6);">
                                    <i class="fas fa-trash"></i>
                                    <span>删除</span>
                                </button>
                            </form>
                        <?php else: ?>
                            <button type="button" class="btn btn-outline" disabled style="opacity:0.6;cursor:not-allowed;">
                                <i class="fas fa-lock"></i>
                                <span>对方已关闭共创</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
