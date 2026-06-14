<?php
// 新版后台 - 单篇文章评论管理（仅文章评论）
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

$articleId = isset($_GET['article_id']) ? intval($_GET['article_id']) : 0;

if ($articleId <= 0) {
    die('缺少有效的文章 ID');
}

// 获取文章信息
$article = $db->fetch(
    "SELECT id, title, created_at 
     FROM articles 
     WHERE id = :id AND status != 'deleted'
     LIMIT 1",
    ['id' => $articleId]
);

if (!$article) {
    die('文章不存在或已被删除');
}

// 处理 IP 拉黑（优先于其他操作）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['block_ip'])) {
    require_csrf();
    $blockIp = trim($_POST['block_ip'] ?? '');

    if ($blockIp === '' || !filter_var($blockIp, FILTER_VALIDATE_IP)) {
        header('Location: article_comments.php?article_id=' . $articleId . '&error=' . urlencode('无效的 IP 地址，无法拉黑'));
        exit;
    }

    try {
        $db->query("
            CREATE TABLE IF NOT EXISTS `comment_ip_blacklist` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `ip` varchar(45) NOT NULL COMMENT 'IP地址',
                `reason` varchar(255) DEFAULT NULL COMMENT '拉黑原因',
                `expires_at` datetime DEFAULT NULL COMMENT '过期时间，NULL 表示永久',
                `created_at` datetime NOT NULL COMMENT '创建时间',
                PRIMARY KEY (`id`),
                UNIQUE KEY `ip` (`ip`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论IP黑名单';
        ");

        $now      = date('Y-m-d H:i:s');
        $reason   = '后台手动拉黑（文章评论管理）';
        $existing = $db->fetch(
            "SELECT id FROM comment_ip_blacklist WHERE ip = :ip LIMIT 1",
            ['ip' => $blockIp]
        );

        if ($existing) {
            $db->update(
                'comment_ip_blacklist',
                [
                    'reason'     => $reason,
                    'expires_at' => null,
                ],
                'id = :id',
                ['id' => $existing['id']]
            );
        } else {
            $db->insert('comment_ip_blacklist', [
                'ip'         => $blockIp,
                'reason'     => $reason,
                'expires_at' => null,
                'created_at' => $now,
            ]);
        }

        header('Location: article_comments.php?article_id=' . $articleId . '&success=' . urlencode('IP 已加入评论黑名单'));
        exit;
    } catch (Throwable $e) {
        header('Location: article_comments.php?article_id=' . $articleId . '&error=' . urlencode('IP 拉黑失败：' . $e->getMessage()));
        exit;
    }
}

// 处理删除评论
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    require_csrf();
    $deleteId = intval($_POST['delete_id']);
    if ($deleteId > 0) {
        $commentRow = $db->fetch(
            "SELECT id, article_id FROM comments WHERE id = :id LIMIT 1",
            ['id' => $deleteId]
        );
        if (!$commentRow || (int)$commentRow['article_id'] !== (int)$articleId) {
            header('Location: article_comments.php?article_id=' . $articleId . '&error=' . urlencode('评论不存在或不属于当前文章'));
            exit;
        }

        // 简单实现：物理删除评论记录
        $db->delete('comments', 'id = :id', ['id' => $deleteId]);
        header('Location: article_comments.php?article_id=' . $articleId . '&success=' . urlencode('评论已删除'));
        exit;
    }
}

// 处理编辑评论
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'])) {
    require_csrf();
    $editId  = intval($_POST['edit_id']);
    $content = trim($_POST['content'] ?? '');

    if ($editId <= 0) {
        header('Location: article_comments.php?article_id=' . $articleId . '&error=' . urlencode('无效的评论 ID'));
        exit;
    }

    if ($content === '') {
        header('Location: article_comments.php?article_id=' . $articleId . '&error=' . urlencode('评论内容不能为空'));
        exit;
    }

    if (mb_strlen($content, 'UTF-8') > 100) {
        header('Location: article_comments.php?article_id=' . $articleId . '&error=' . urlencode('评论内容不能超过 100 个字符'));
        exit;
    }

    $commentRow = $db->fetch(
        "SELECT id, article_id FROM comments WHERE id = :id LIMIT 1",
        ['id' => $editId]
    );
    if (!$commentRow || (int)$commentRow['article_id'] !== (int)$articleId) {
        header('Location: article_comments.php?article_id=' . $articleId . '&error=' . urlencode('评论不存在或不属于当前文章'));
        exit;
    }

    $db->update(
        'comments',
        ['content' => $content],
        'id = :id',
        ['id' => $editId]
    );

    header('Location: article_comments.php?article_id=' . $articleId . '&success=' . urlencode('评论已更新'));
    exit;
}

// 获取当前文章的所有评论（仅文章评论）
$comments = $db->fetchAll(
    "SELECT c.*, u.nickname AS user_nickname, u.role 
     FROM comments c 
     LEFT JOIN users u ON c.user_id = u.id 
     WHERE c.article_id = :article_id 
     ORDER BY c.created_at DESC",
    ['article_id' => $articleId]
);

// 构建父子关系映射（只做一层回复）
$rootComments    = [];
$repliesByParent = [];

foreach ($comments as $comment) {
    if (!empty($comment['parent_id'])) {
        $repliesByParent[$comment['parent_id']][] = $comment;
    } else {
        $rootComments[] = $comment;
    }
}

$adminPage = 'articles';

include __DIR__ . '/header.php';
?>

    <section class="admin-page-title">
        <h1>评论管理：<?php echo e($article['title']); ?></h1>
        <p>查看、编辑和删除这篇文章下的评论</p>
    </section>

    <div class="admin-card" style="margin-bottom:0.75rem;">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">文章信息</div>
                <div class="admin-card-subtitle">
                    ID：<?php echo $article['id']; ?> · 创建于 <?php echo formatDate($article['created_at']); ?>
                </div>
            </div>
            <div style="display:flex;gap:0.5rem;align-items:center;">
                <a href="/admin/comment_ip_blacklist.php" class="btn btn-outline">
                    <i class="fas fa-user-slash"></i>
                    <span>评论黑名单</span>
                </a>
                <a href="/admin/articles.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    <span>返回文章列表</span>
                </a>
            </div>
        </div>
    </div>

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

    <div class="admin-card">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">评论列表</div>
                <div class="admin-card-subtitle">共 <?php echo count($comments); ?> 条评论</div>
            </div>
        </div>

        <?php if (empty($comments)): ?>
            <p style="font-size:0.9rem;color:var(--text-light);margin-top:0.25rem;">
                当前文章还没有任何评论。
            </p>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:1rem;margin-top:0.5rem;">
                <?php foreach ($rootComments as $comment): ?>
                    <?php
                    $author = $comment['guest_nickname'] ?: ($comment['user_nickname'] ?? '');
                    if ($author === '') {
                        $author = '匿名用户';
                    }
                    $roleLabel = '';
                        if (!empty($comment['user_id'])) {
                            if (!empty($comment['role']) && $comment['role'] === 'user1') {
                                $roleLabel = '男主';
                            } elseif (!empty($comment['role']) && $comment['role'] === 'user2') {
                                $roleLabel = '女主';
                        } else {
                            $roleLabel = '登录用户';
                        }
                    } else {
                        $roleLabel = '访客';
                    }
                    $ipText       = !empty($comment['ip']) ? $comment['ip'] : '';
                    $locationText = !empty($comment['location']) ? $comment['location'] : '';
                    ?>
                    <article class="admin-comment-card" style="border-bottom:1px solid rgba(226,232,240,0.8);padding-bottom:0.75rem;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;">
                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;align-items:center;gap:0.4rem;">
                                    <span style="font-weight:600;font-size:0.9rem;"><?php echo e($author); ?></span>
                                    <span class="badge badge-secondary" style="font-size:0.7rem;">
                                        <?php echo e($roleLabel); ?>
                                    </span>
                                    <?php if (!empty($comment['guest_qq'])): ?>
                                        <span style="font-size:0.75rem;color:var(--text-light);">
                                            QQ：<?php echo e($comment['guest_qq']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:0.8rem;color:var(--text-light);margin-top:0.15rem;">
                                    评论 ID：<?php echo $comment['id']; ?> ·
                                    <?php echo formatDate($comment['created_at']); ?>
                                    <?php if ($ipText): ?>
                                        · IP：<?php echo e($ipText); ?>
                                    <?php endif; ?>
                                    <?php if ($locationText): ?>
                                        · 归属地：<?php echo e($locationText); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="display:flex;flex-direction:column;gap:0.25rem;align-items:flex-end;flex-shrink:0;">
                                <form method="POST" data-confirm="确定要删除这条评论吗？">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="delete_id" value="<?php echo $comment['id']; ?>">
                                    <button type="submit" class="btn btn-secondary"
                                            style="background:#fee2e2;color:#b91c1c;border:1px solid rgba(248,113,113,0.6);padding:0.25rem 0.7rem;font-size:0.78rem;">
                                        <i class="fas fa-trash"></i>
                                        <span>删除</span>
                                    </button>
                                </form>
                                <?php if ($ipText): ?>
                                    <form method="POST" data-confirm="确定要拉黑该 IP 吗？此 IP 将无法再发表评论。">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="block_ip" value="<?php echo e($ipText); ?>">
                                        <button type="submit" class="btn btn-outline"
                                                style="border-color:rgba(96,165,250,0.8);color:#2563eb;padding:0.15rem 0.7rem;font-size:0.78rem;">
                                            <i class="fas fa-user-slash"></i>
                                            <span>拉黑 IP</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="margin-top:0.4rem;font-size:0.9rem;color:var(--text-dark);">
                            <?php echo nl2br(e($comment['content'])); ?>
                        </div>

                        <details style="margin-top:0.5rem;">
                            <summary style="font-size:0.8rem;color:var(--text-light);cursor:pointer;">
                                编辑这条评论
                            </summary>
                            <form method="POST" style="margin-top:0.35rem;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="edit_id" value="<?php echo $comment['id']; ?>">
                                <textarea name="content" rows="3" style="width:100%;border-radius:10px;border:1px solid #e5e7eb;padding:0.6rem;font-size:0.85rem;"><?php echo e($comment['content']); ?></textarea>
                                <div style="margin-top:0.4rem;display:flex;justify-content:flex-end;">
                                    <button type="submit" class="btn btn-primary" style="font-size:0.85rem;padding:0.35rem 0.9rem;">
                                        <i class="fas fa-save"></i>
                                        <span>保存修改</span>
                                    </button>
                                </div>
                            </form>
                        </details>

                        <?php if (!empty($repliesByParent[$comment['id']])): ?>
                            <div style="margin-top:0.5rem;padding-left:0.75rem;border-left:2px dashed rgba(226,232,240,0.8);display:flex;flex-direction:column;gap:0.5rem;">
                                <?php foreach ($repliesByParent[$comment['id']] as $reply): ?>
                                    <?php
                                    $replyAuthor = $reply['guest_nickname'] ?: ($reply['user_nickname'] ?? '');
                                    if ($replyAuthor === '') {
                                        $replyAuthor = '匿名用户';
                                    }
                                    $replyRoleLabel = '';
                                    if (!empty($reply['user_id'])) {
                                        if (!empty($reply['role']) && $reply['role'] === 'user1') {
                                            $replyRoleLabel = '男主';
                                        } elseif (!empty($reply['role']) && $reply['role'] === 'user2') {
                                            $replyRoleLabel = '女主';
                                        } else {
                                            $replyRoleLabel = '登录用户';
                                        }
                                    } else {
                                        $replyRoleLabel = '访客';
                                    }
                                    $replyIpText       = !empty($reply['ip']) ? $reply['ip'] : '';
                                    $replyLocationText = !empty($reply['location']) ? $reply['location'] : '';
                                    ?>
                                    <div style="border-radius:10px;background:#f9fafb;padding:0.5rem 0.6rem;">
                                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;">
                                            <div style="flex:1;min-width:0;">
                                                <div style="display:flex;align-items:center;gap:0.4rem;">
                                                    <span style="font-weight:600;font-size:0.85rem;"><?php echo e($replyAuthor); ?></span>
                                                    <span class="badge badge-secondary" style="font-size:0.7rem;">
                                                        <?php echo e($replyRoleLabel); ?>
                                                    </span>
                                                    <?php if (!empty($reply['guest_qq'])): ?>
                                                        <span style="font-size:0.75rem;color:var(--text-light);">
                                                            QQ：<?php echo e($reply['guest_qq']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div style="font-size:0.78rem;color:var(--text-light);margin-top:0.1rem;">
                                                    回复 ID：<?php echo $reply['id']; ?> ·
                                                    <?php echo formatDate($reply['created_at']); ?>
                                                    <?php if ($replyIpText): ?>
                                                        · IP：<?php echo e($replyIpText); ?>
                                                    <?php endif; ?>
                                                    <?php if ($replyLocationText): ?>
                                                        · 归属地：<?php echo e($replyLocationText); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div style="display:flex;flex-direction:column;gap:0.25rem;align-items:flex-end;flex-shrink:0;">
                                                <form method="POST" data-confirm="确定要删除这条回复吗？">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="delete_id" value="<?php echo $reply['id']; ?>">
                                                    <button type="submit" class="btn btn-secondary"
                                                            style="background:#fee2e2;color:#b91c1c;border:1px solid rgba(248,113,113,0.6);padding:0.2rem 0.6rem;font-size:0.75rem;">
                                                        <i class="fas fa-trash"></i>
                                                        <span>删除</span>
                                                    </button>
                                                </form>
                                                <?php if ($replyIpText): ?>
                                                    <form method="POST" data-confirm="确定要拉黑该 IP 吗？此 IP 将无法再发表评论。">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="block_ip" value="<?php echo e($replyIpText); ?>">
                                                        <button type="submit" class="btn btn-outline"
                                                                style="border-color:rgba(96,165,250,0.8);color:#2563eb;padding:0.15rem 0.6rem;font-size:0.75rem;">
                                                            <i class="fas fa-user-slash"></i>
                                                            <span>拉黑 IP</span>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div style="margin-top:0.35rem;font-size:0.85rem;color:var(--text-dark);">
                                            <?php echo nl2br(e($reply['content'])); ?>
                                        </div>
                                        <details style="margin-top:0.35rem;">
                                            <summary style="font-size:0.8rem;color:var(--text-light);cursor:pointer;">
                                                编辑这条回复
                                            </summary>
                                            <form method="POST" style="margin-top:0.3rem;">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="edit_id" value="<?php echo $reply['id']; ?>">
                                                <textarea name="content" rows="3" style="width:100%;border-radius:10px;border:1px solid #e5e7eb;padding:0.55rem;font-size:0.82rem;"><?php echo e($reply['content']); ?></textarea>
                                                <div style="margin-top:0.35rem;display:flex;justify-content:flex-end;">
                                                    <button type="submit" class="btn btn-primary" style="font-size:0.8rem;padding:0.3rem 0.8rem;">
                                                        <i class="fas fa-save"></i>
                                                        <span>保存修改</span>
                                                    </button>
                                                </div>
                                            </form>
                                        </details>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<?php include __DIR__ . '/footer.php'; ?>
