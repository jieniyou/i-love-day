<?php
// 新版后台 - 留言列表（移动端优先）
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

// 处理 IP 拉黑（复用评论 IP 黑名单）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['block_ip'])) {
    require_csrf();
    $blockIp = trim($_POST['block_ip'] ?? '');

    if ($blockIp !== '' && filter_var($blockIp, FILTER_VALIDATE_IP)) {
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
            $reason   = '后台手动拉黑（留言管理）';
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

            header('Location: messages.php?success=' . urlencode('IP 已加入评论与留言黑名单'));
            exit;
        } catch (Throwable $e) {
            header('Location: messages.php?success=' . urlencode('IP 拉黑失败：' . $e->getMessage()));
            exit;
        }
    } else {
        header('Location: messages.php?success=' . urlencode('无效的 IP 地址，无法拉黑'));
        exit;
    }
}

// 删除留言
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    require_csrf();
    $id = intval($_POST['delete_id']);
    if ($id > 0) {
        $db->update(
            'messages',
            ['status' => 'deleted'],
            'id = :id',
            ['id' => $id]
        );
        header('Location: messages.php?success=删除成功');
        exit;
    }
}

// 确保 messages 表存在 IP 与归属地字段（幂等迁移）
try {
    $db->query("
        ALTER TABLE `messages`
        ADD COLUMN `ip` varchar(45) DEFAULT NULL COMMENT '留言IP' AFTER `guest_qq`
    ");
} catch (Throwable $e) {
    // 字段已存在时忽略
}

try {
    $db->query("
        ALTER TABLE `messages`
        ADD COLUMN `location` varchar(255) DEFAULT NULL COMMENT 'IP归属地' AFTER `ip`
    ");
} catch (Throwable $e) {
    // 字段已存在时忽略
}

// 获取所有未删除的留言
$messages = $db->fetchAll(
    "SELECT m.*, u.nickname AS user_nickname 
     FROM messages m 
     LEFT JOIN users u ON m.user_id = u.id 
     WHERE m.status != 'deleted'
     ORDER BY m.created_at DESC"
);

$adminPage = 'messages';

include __DIR__ . '/header.php';
?>

    <section class="admin-page-title">
        <h1>留言管理</h1>
        <p>查看与管理你们的小小心事</p>
    </section>

    <?php if (isset($_GET['success'])): ?>
        <div class="admin-card" style="margin-bottom:0.75rem;background:rgba(34,197,94,0.05);border:1px solid rgba(34,197,94,0.35);">
            <div style="display:flex;align-items:center;gap:0.5rem;color:#15803d;font-size:0.9rem;">
                <i class="fas fa-check-circle"></i>
                <span><?php echo e($_GET['success']); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <div class="admin-card" style="margin-bottom:0.75rem;">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">留言概览</div>
                <div class="admin-card-subtitle">共 <?php echo count($messages); ?> 条留言</div>
            </div>
            <div style="display:flex;gap:0.5rem;align-items:center;">
                <a href="/admin/comment_ip_blacklist.php" class="btn btn-outline">
                    <i class="fas fa-user-slash"></i>
                    <span>IP 黑名单</span>
                </a>
                <a href="/messages.php" target="_blank" class="btn btn-secondary">
                    <i class="fas fa-comment"></i>
                    <span>去前台写留言</span>
                </a>
            </div>
        </div>
    </div>

    <?php if (empty($messages)): ?>
        <div class="admin-card">
            <p style="font-size:0.9rem;color:var(--text-light);">
                还没有留言记录，点击上方“去前台写留言”试一试吧～
            </p>
        </div>
    <?php else: ?>
        <section class="admin-grid">
            <?php foreach ($messages as $message): ?>
                <?php
                $author       = $message['guest_nickname'] ?: ($message['user_nickname'] ?: '匿名用户');
                $ipText       = !empty($message['ip']) ? $message['ip'] : '';
                $locationText = !empty($message['location']) ? $message['location'] : '';
                ?>
                <article class="admin-card">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;">
                        <div style="flex:1;min-width:0;">
                            <div class="admin-card-title" style="font-size:0.9rem;">
                                <?php echo e($author); ?>
                            </div>
                            <div class="admin-card-subtitle">
                                <?php echo formatDate($message['created_at']); ?>
                                <?php if ($ipText): ?>
                                    · IP：<?php echo e($ipText); ?>
                                <?php endif; ?>
                                <?php if ($locationText): ?>
                                    · 归属地：<?php echo e($locationText); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:0.25rem;align-items:flex-end;">
                            <span class="badge <?php echo !empty($message['is_public']) ? 'badge-success' : 'badge-warning'; ?>">
                                <?php echo !empty($message['is_public']) ? '公开' : '仅自己可见'; ?>
                            </span>
                            <span class="badge badge-success">已发布</span>
                        </div>
                    </div>

                    <div style="margin-top:0.5rem;font-size:0.85rem;color:var(--text-dark);max-height:4.2em;overflow:hidden;">
                        <?php echo nl2br(e($message['content'])); ?>
                    </div>

                    <div style="margin-top:0.75rem;display:flex;gap:0.5rem;flex-wrap:wrap;justify-content:flex-end;">
                        <?php if ($ipText): ?>
                            <form method="POST" data-confirm="确定要拉黑该 IP 吗？此 IP 将无法再留言与评论。">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="block_ip" value="<?php echo e($ipText); ?>">
                                <button type="submit" class="btn btn-outline" style="border-color:rgba(96,165,250,0.8);color:#2563eb;">
                                    <i class="fas fa-user-slash"></i>
                                    <span>拉黑 IP</span>
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" data-confirm="确定要删除这条留言吗？">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="delete_id" value="<?php echo $message['id']; ?>">
                            <button type="submit" class="btn btn-secondary" style="background:#fee2e2;color:#b91c1c;border:1px solid rgba(248,113,113,0.6);">
                                <i class="fas fa-trash"></i>
                                <span>删除</span>
                            </button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
