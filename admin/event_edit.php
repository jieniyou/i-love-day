<?php
// 新版后台 - 编辑纪念事件（移动端优先）
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

$error   = '';
$success = '';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: events.php');
    exit;
}

$event = $db->fetch(
    "SELECT * FROM events WHERE id = :id LIMIT 1",
    ['id' => $id]
);

if (!$event) {
    header('Location: events.php?success=' . urlencode('未找到该事件'));
    exit;
}

if (isset($_GET['success']) && $_GET['success'] === '1') {
    $success = '事件更新成功';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $eventDate   = $_POST['event_date'] ?? '';
    $icon        = $_POST['icon'] ?? 'heart';
    $isImportant = isset($_POST['is_important']) ? 1 : 0;
    $isRecurring = isset($_POST['is_recurring']) ? 1 : 0;
    $sortOrder   = isset($_POST['sort_order']) ? intval($_POST['sort_order']) : 0;

    if ($title === '' || $eventDate === '') {
        $error = '请填写事件标题和日期';
    } else {
        $data = [
            'title'        => $title,
            'description'  => $description,
            'event_date'   => $eventDate,
            'icon'         => $icon,
            'is_important' => $isImportant,
            'is_recurring' => $isRecurring,
            'sort_order'   => $sortOrder,
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        $db->update(
            'events',
            $data,
            'id = :id',
            ['id' => $id]
        );

        header('Location: event_edit.php?id=' . $id . '&success=1');
        exit;
    }

    $event['title']        = $title;
    $event['description']  = $description;
    $event['event_date']   = $eventDate;
    $event['icon']         = $icon;
    $event['is_important'] = $isImportant;
    $event['is_recurring'] = $isRecurring;
    $event['sort_order']   = $sortOrder;
}

$adminPage = 'events';

include __DIR__ . '/header.php';
?>

    <section class="admin-page-title">
        <h1>编辑纪念事件</h1>
        <p>修改已经记录的特别日子</p>
    </section>

    <?php if ($error): ?>
        <div class="admin-card" style="margin-bottom:0.75rem;background:rgba(248,113,113,0.05);border:1px solid rgba(248,113,113,0.35);">
            <div style="display:flex;align-items:center;gap:0.5rem;color:#b91c1c;font-size:0.9rem;">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo e($error); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="admin-card" style="margin-bottom:0.75rem;background:rgba(34,197,94,0.05);border:1px solid rgba(34,197,94,0.35);">
            <div style="display:flex;align-items:center;gap:0.5rem;color:#15803d;font-size:0.9rem;">
                <i class="fas fa-check-circle"></i>
                <span><?php echo e($success); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" class="admin-card" novalidate>
        <?php echo csrf_field(); ?>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">事件标题 *</label>
            <input
                type="text"
                name="title"
                value="<?php echo e($event['title']); ?>"
                placeholder="例如：第一次见面"
                style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;">
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">事件日期 *</label>
            <input
                type="date"
                name="event_date"
                value="<?php echo e($event['event_date']); ?>"
                style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;">
            <div style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                建议选择真实发生的那天；如果是“每年今天”，系统按月日计算，年份仅作记录。
            </div>
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">描述</label>
            <textarea
                name="description"
                style="width:100%;min-height:80px;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;resize:vertical;"><?php echo e($event['description']); ?></textarea>
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label class="switch">
                <input type="checkbox" name="is_recurring" value="1" <?php echo !empty($event['is_recurring']) ? 'checked' : ''; ?>>
                <span class="switch-track">
                    <span class="switch-thumb"></span>
                </span>
                <span class="switch-label">每年这一天都是纪念日</span>
            </label>
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">图标</label>
            <select
                name="icon"
                style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;">
                <option value="heart" <?php echo $event['icon'] === 'heart' ? 'selected' : ''; ?>>爱心</option>
                <option value="star" <?php echo $event['icon'] === 'star' ? 'selected' : ''; ?>>星星</option>
                <option value="gift" <?php echo $event['icon'] === 'gift' ? 'selected' : ''; ?>>礼物</option>
                <option value="cake" <?php echo $event['icon'] === 'cake' ? 'selected' : ''; ?>>蛋糕</option>
                <option value="ring" <?php echo $event['icon'] === 'ring' ? 'selected' : ''; ?>>戒指</option>
                <option value="heartbeat" <?php echo $event['icon'] === 'heartbeat' ? 'selected' : ''; ?>>心跳</option>
            </select>
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">排序序号</label>
            <input
                type="number"
                name="sort_order"
                value="<?php echo e($event['sort_order'] ?? 0); ?>"
                placeholder="数字越小越靠前，默认 0"
                style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;">
        </div>

        <div class="form-group" style="margin-bottom:1rem;">
            <label class="switch">
                <input type="checkbox" name="is_important" value="1" <?php echo !empty($event['is_important']) ? 'checked' : ''; ?>>
                <span class="switch-track">
                    <span class="switch-thumb"></span>
                </span>
                <span class="switch-label">标记为重要事件</span>
            </label>
        </div>

        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i>
                <span>保存修改</span>
            </button>
            <a href="/admin/events.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i>
                <span>返回列表</span>
            </a>
        </div>
    </form>

<?php include __DIR__ . '/footer.php'; ?>

