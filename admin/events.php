<?php
// 新版后台 - 纪念事件列表（移动端优先）
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

// 删除事件：通过 POST + CSRF 处理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    require_csrf();
    $id = intval($_POST['delete_id']);
    if ($id > 0) {
        $db->delete('events', 'id = :id', ['id' => $id]);
        header('Location: events.php?success=删除成功');
        exit;
    }
}

// 两个用户都可以管理所有事件
$events = $db->fetchAll(
    "SELECT * FROM events ORDER BY sort_order ASC, event_date DESC, created_at DESC"
);

$adminPage = 'events';

include __DIR__ . '/header.php';
?>

    <section class="admin-page-title">
        <h1>纪念事件</h1>
        <p>记录在一起的重要时刻</p>
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
                <div class="admin-card-title">纪念事件概览</div>
                <div class="admin-card-subtitle">共 <?php echo count($events); ?> 个事件</div>
            </div>
            <a href="/admin/event_add.php" class="btn btn-primary">
                <i class="fas fa-plus"></i>
                <span>新增事件</span>
            </a>
        </div>
    </div>

    <?php if (empty($events)): ?>
        <div class="admin-card">
            <p style="font-size:0.9rem;color:var(--text-light);">
                还没有纪念事件，点击右上角“新增事件”记录你们的第一个重要时刻。
            </p>
        </div>
    <?php else: ?>
        <section class="admin-grid">
            <?php foreach ($events as $event): ?>
                <article class="admin-card">
                    <div style="display:flex;align-items:flex-start;gap:0.75rem;">
                        <div style="width:40px;height:40px;border-radius:999px;background:rgba(248,250,252,0.9);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-<?php echo e(event_icon_class($event['icon'])); ?>" style="color:#fb7185;"></i>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;">
                                <div>
                                    <div class="admin-card-title" style="max-width:12rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?php echo e($event['title']); ?>
                                    </div>
                                    <div class="admin-card-subtitle">
                                        <?php echo formatDate($event['event_date'], 'Y-m-d'); ?>
                                        <?php if (!empty($event['is_recurring'])): ?>
                                            · 每年这一天
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="badge <?php echo !empty($event['is_important']) ? 'badge-warning' : 'badge-success'; ?>">
                                    <?php echo !empty($event['is_important']) ? '重要' : '普通'; ?>
                                </span>
                            </div>
                            <?php if (!empty($event['description'])): ?>
                                <div style="margin-top:0.4rem;font-size:0.8rem;color:var(--text-light);max-height:3.2em;overflow:hidden;">
                                    <?php echo nl2br(e($event['description'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="margin-top:0.75rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
                        <a href="/admin/event_edit.php?id=<?php echo $event['id']; ?>" class="btn btn-outline">
                            <i class="fas fa-edit"></i>
                            <span>编辑</span>
                        </a>
                        <form method="POST" data-confirm="确定要删除这个事件吗？">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="delete_id" value="<?php echo $event['id']; ?>">
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
