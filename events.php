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
$pageTitle = '纪念事件';

$auth        = new Auth();
$db          = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$partner     = $currentUser ? $auth->getPartner() : null;

// 获取恋爱开始日期
$loveDateRow   = $db->fetch("SELECT value FROM settings WHERE `key` = 'love_date'");
$loveStartDate = ($loveDateRow && !empty($loveDateRow['value']))
    ? $loveDateRow['value']
    : date('Y-m-d');

$events = $db->fetchAll(
    "SELECT e.*, u.nickname, u.avatar 
     FROM events e 
     LEFT JOIN users u ON e.user_id = u.id 
     ORDER BY e.sort_order ASC, e.event_date DESC, e.created_at DESC"
);

// 纪念事件彩色卡片配色（随机从中挑选一组）
$eventCardColors = [
    ['#ff6b81', '#ff9aa2'],
    ['#4ecdc4', '#7ee8df'],
    ['#3b82f6', '#60a5fa'],
    ['#fb923c', '#fed7aa'],
    ['#a855f7', '#c4b5fd'],
    ['#14b8a6', '#5eead4'],
    ['#f97373', '#fecaca'],
    ['#0ea5e9', '#38bdf8'],
];

// 创建颜色索引数组并打乱，用于按顺序分配颜色
$colorIndices = range(0, count($eventCardColors) - 1);
shuffle($colorIndices);
$colorIndex = 0; // 当前使用的颜色索引

include __DIR__ . '/views/header.php';
?>

<section class="content-section">
    <div class="section-header card-header-row">
        <h2><i class="fas fa-calendar-days"></i> 纪念事件</h2>
    </div>

    <div class="events-list">
        <?php foreach ($events as $event): ?>
        <?php
        $daysFromStart = daysBetween($loveStartDate, $event['event_date']);
        $todayStr      = date('Y-m-d');

        if (!empty($event['is_recurring'])) {
            // 每年今日的事件：计算距离下一次纪念日还有多少天，并使用今年/下一次年度来展示日期
            $eventDate = new DateTime($event['event_date']);
            $today     = new DateTime($todayStr);

            $currentYearDate = DateTime::createFromFormat(
                'Y-m-d',
                $today->format('Y') . '-' . $eventDate->format('m-d')
            );
            if ($currentYearDate < $today) {
                $currentYearDate->modify('+1 year');
            }
            $displayDate = $currentYearDate->format('Y-m-d');
            $daysUntil   = daysBetween($today->format('Y-m-d'), $displayDate);
        } else {
            // 普通事件：计算距离事件发生已有/还有多少天
            $daysUntil = null;
            $daysAgo   = daysBetween($event['event_date'], $todayStr);
            $displayDate = $event['event_date'];
        }

        // 按顺序从打乱的颜色索引中选择颜色，确保不重复
        $palette = $eventCardColors[$colorIndices[$colorIndex]];
        $colorIndex++;

        // 当所有颜色都用完后，重新打乱并从头开始
        if ($colorIndex >= count($colorIndices)) {
            shuffle($colorIndices);
            $colorIndex = 0;
        }

        $bgStart       = $palette[0];
        $bgEnd         = $palette[1];
        ?>
        <div class="event-pill" style="background: linear-gradient(135deg, <?php echo $bgStart; ?> 0%, <?php echo $bgEnd; ?> 100%);">
            <div class="event-pill-main">
                <div class="event-pill-title">
                    <i class="fas fa-<?php echo e(event_icon_class($event['icon'])); ?>"></i>
                    <span><?php echo e($event['title']); ?></span>
                    <?php if (!empty($event['is_important'])): ?>
                    <span class="badge badge-important"><i class="fas fa-star"></i> 重要</span>
                    <?php endif; ?>
                    <?php if (!empty($event['is_recurring'])): ?>
                    <span class="badge badge-recurring"><i class="fas fa-infinity"></i> 每年今日</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($event['description'])): ?>
                <p class="event-pill-desc"><?php echo e($event['description']); ?></p>
                <?php endif; ?>
            </div>
            <div class="event-pill-meta">
                <div class="event-pill-date"><?php echo formatDate($displayDate, 'Y-m-d'); ?></div>
                <div class="event-pill-days">
                    <?php if (!empty($event['is_recurring'])): ?>
                        <?php if ($daysUntil === 0): ?>
                            就是今天
                        <?php else: ?>
                            距离下一次还有 <strong><?php echo $daysUntil; ?></strong> 天
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($daysAgo === 0): ?>
                            就是今天
                        <?php elseif (strtotime($event['event_date']) > time()): ?>
                            还有 <strong><?php echo $daysAgo; ?></strong> 天
                        <?php else: ?>
                            已经 <strong><?php echo $daysAgo; ?></strong> 天
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($events)): ?>
        <div class="empty-state glass-card">
            <i class="fas fa-calendar-heart"></i>
            <p>还没有任何纪念事件，去添加第一条专属于你们的记忆吧。</p>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/views/footer.php'; ?>
