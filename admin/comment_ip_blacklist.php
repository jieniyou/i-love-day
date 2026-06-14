<?php
// 新版后台 - 评论 / 留言 IP 黑名单管理
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

// 确保黑名单表存在（幂等）
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
} catch (Throwable $e) {
    // 表创建失败时，页面会在下方展示错误信息
    $tableError = $e->getMessage();
}

// 解除拉黑
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    require_csrf();
    $id = intval($_POST['delete_id']);
    if ($id > 0) {
        $db->delete('comment_ip_blacklist', 'id = :id', ['id' => $id]);
        header('Location: comment_ip_blacklist.php?success=' . urlencode('已解除该 IP 的评论限制'));
        exit;
    }
}

// 手动新增拉黑（可选）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_ip'])) {
    require_csrf();
    $ip     = trim($_POST['ip'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        header('Location: comment_ip_blacklist.php?error=' . urlencode('请输入有效的 IP 地址'));
        exit;
    }

    $now    = date('Y-m-d H:i:s');
    $reason = $reason !== '' ? $reason : '后台手动添加';

    try {
        $existing = $db->fetch(
            "SELECT id FROM comment_ip_blacklist WHERE ip = :ip LIMIT 1",
            ['ip' => $ip]
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
                'ip'         => $ip,
                'reason'     => $reason,
                'expires_at' => null,
                'created_at' => $now,
            ]);
        }

        header('Location: comment_ip_blacklist.php?success=' . urlencode('IP 已加入评论黑名单'));
        exit;
    } catch (Throwable $e) {
        header('Location: comment_ip_blacklist.php?error=' . urlencode('添加失败：' . $e->getMessage()));
        exit;
    }
}

// 拉黑列表
$blacklist = [];
if (empty($tableError)) {
    $blacklist = $db->fetchAll(
        "SELECT * FROM comment_ip_blacklist ORDER BY created_at DESC"
    );
}

$adminPage = 'comment_ip_blacklist';

include __DIR__ . '/header.php';
?>

    <section class="admin-page-title">
        <h1>评论 / 留言 IP 黑名单</h1>
        <p>查看、添加或解除被禁止发表评论或留言的 IP</p>
    </section>

    <?php if (!empty($tableError)): ?>
        <div class="admin-card" style="margin-bottom:0.75rem;background:rgba(248,113,113,0.05);border:1px solid rgba(248,113,113,0.35);">
            <div style="display:flex;align-items:center;gap:0.5rem;color:#b91c1c;font-size:0.9rem;">
                <i class="fas fa-exclamation-circle"></i>
                <span>黑名单表创建失败：<?php echo e($tableError); ?></span>
            </div>
        </div>
    <?php endif; ?>

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
                <div class="admin-card-title">快速添加拉黑 IP</div>
                <div class="admin-card-subtitle">支持手动录入 IP 并立即禁止其发表评论</div>
            </div>
        </div>
        <form method="POST" style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.5rem;align-items:flex-end;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="add_ip" value="1">
            <div style="flex:1;min-width:200px;">
                <label style="display:block;font-size:0.8rem;color:var(--text-light);margin-bottom:0.25rem;">IP 地址</label>
                <input type="text" name="ip" class="form-control" placeholder="例如：127.0.0.1" style="width:100%;padding:0.5rem 0.75rem;border-radius:8px;border:1px solid #e5e7eb;">
            </div>
            <div style="flex:2;min-width:220px;">
                <label style="display:block;font-size:0.8rem;color:var(--text-light);margin-bottom:0.25rem;">备注原因（可选）</label>
                <input type="text" name="reason" class="form-control" placeholder="例如：恶意刷屏、垃圾广告等" style="width:100%;padding:0.5rem 0.75rem;border-radius:8px;border:1px solid #e5e7eb;">
            </div>
            <div>
                <button type="submit" class="btn btn-primary" style="white-space:nowrap;">
                    <i class="fas fa-user-slash"></i>
                    <span>添加到黑名单</span>
                </button>
            </div>
        </form>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">当前黑名单列表</div>
                <div class="admin-card-subtitle">
                    共 <?php echo count($blacklist); ?> 条记录
                </div>
            </div>
        </div>

        <?php if (empty($blacklist)): ?>
            <p style="font-size:0.9rem;color:var(--text-light);margin-top:0.25rem;">
                暂无被拉黑的 IP。
            </p>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:0.75rem;margin-top:0.5rem;">
                <?php foreach ($blacklist as $item): ?>
                    <?php
                    $ip        = $item['ip'];
                    $reason    = $item['reason'] ?: '未填写';
                    $created   = $item['created_at'];
                    $expiresAt = $item['expires_at'];
                    $isExpired = false;
                    if (!empty($expiresAt) && $expiresAt !== '0000-00-00 00:00:00') {
                        $isExpired = strtotime($expiresAt) < time();
                    }
                    ?>
                    <article class="admin-card" style="margin:0;border:1px solid rgba(226,232,240,0.9);">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;">
                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;align-items:center;gap:0.5rem;">
                                    <span style="font-weight:600;font-size:0.95rem;"><?php echo e($ip); ?></span>
                                    <span class="badge <?php echo $isExpired ? 'badge-secondary' : 'badge-warning'; ?>">
                                        <?php echo $isExpired ? '已过期' : '生效中'; ?>
                                    </span>
                                </div>
                                <div style="font-size:0.8rem;color:var(--text-light);margin-top:0.15rem;">
                                    加入时间：<?php echo formatDate($created); ?>
                                    <?php if (!empty($expiresAt) && $expiresAt !== '0000-00-00 00:00:00'): ?>
                                        · 过期时间：<?php echo formatDate($expiresAt); ?>
                                    <?php else: ?>
                                        · 过期时间：永久
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top:0.35rem;font-size:0.85rem;color:var(--text-dark);">
                                    备注：<?php echo e($reason); ?>
                                </div>
                            </div>
                            <form method="POST" data-confirm="确定要解除该 IP 的评论限制吗？" style="flex-shrink:0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="delete_id" value="<?php echo $item['id']; ?>">
                                <button type="submit" class="btn btn-secondary"
                                        style="background:#dcfce7;color:#16a34a;border:1px solid rgba(74,222,128,0.7);padding:0.25rem 0.8rem;font-size:0.8rem;">
                                    <i class="fas fa-unlock"></i>
                                    <span>解除拉黑</span>
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<?php include __DIR__ . '/footer.php'; ?>
