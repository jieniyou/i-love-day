<?php
// 新版后台 - 仪表盘（移动端优先）
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

// 统计数据
// 基础统计
$articleCountRow = $db->fetch("SELECT COUNT(*) AS c FROM articles WHERE status != 'deleted'");
$albumCountRow   = $db->fetch("SELECT COUNT(*) AS c FROM albums");
$eventCountRow   = $db->fetch("SELECT COUNT(*) AS c FROM events");
$messageCountRow = $db->fetch("SELECT COUNT(*) AS c FROM messages WHERE status != 'deleted'");

$articleCount = (int) ($articleCountRow['c'] ?? 0);
$albumCount   = (int) ($albumCountRow['c'] ?? 0);
$eventCount   = (int) ($eventCountRow['c'] ?? 0);
$messageCount = (int) ($messageCountRow['c'] ?? 0);

// ffmpeg 状态检测（统一放在仪表盘），用于提示视频相关能力
// 状态：
// - ok: 已检测到可调用的 ffmpeg 命令，且 shell_exec 未被禁用
// - no_shell: 检测到 ffmpeg 可执行文件，但 shell_exec 被禁用，无法自动调用
// - missing: 未检测到可用 ffmpeg，或无法确认
$ffmpegStatus    = 'missing';
$ffmpegPath      = '';
$ffmpegHintPath  = '/usr/bin/ffmpeg';

$disableFunctions = ini_get('disable_functions');
$disableFunctions = is_string($disableFunctions) ? $disableFunctions : '';
$shellDisabled    = stripos($disableFunctions, 'shell_exec') !== false;
$canUseShell      = function_exists('shell_exec') && !$shellDisabled;

// 更详细的 ffmpeg 能力检测信息，用于「查看详情」弹窗
$ffmpegDiagnostics = [
    'can_use_shell' => $canUseShell,
    'binary_found'  => false,
    'binary_path'   => '',
    'has_libx264'   => null, // true=有，false=无，null=未知
    'has_aac'       => null,
    'version_line'  => '',
];

if ($canUseShell) {
    $whichOutput = @shell_exec('command -v ffmpeg 2>/dev/null');
    if (is_string($whichOutput)) {
        $whichOutput = trim($whichOutput);
    }
    if (!empty($whichOutput)) {
        $ffmpegStatus = 'ok';
        $ffmpegPath   = $whichOutput;
    } else {
        $ffmpegStatus = 'missing';
    }
} else {
    if (is_executable('/usr/bin/ffmpeg')) {
        $ffmpegStatus = 'no_shell';
        $ffmpegPath   = '/usr/bin/ffmpeg';
    } elseif (is_executable('/usr/local/bin/ffmpeg')) {
        $ffmpegStatus = 'no_shell';
        $ffmpegPath   = '/usr/local/bin/ffmpeg';
    } else {
        $ffmpegStatus = 'missing';
    }
}

// 在已知 ffmpeg 可执行文件路径且 shell_exec 可用时，进一步检测版本与编码器支持情况
$ffmpegDiagnostics['binary_path']  = $ffmpegPath;
$ffmpegDiagnostics['binary_found'] = !empty($ffmpegPath);

if ($canUseShell && $ffmpegDiagnostics['binary_found']) {
    $versionOutput = @shell_exec(escapeshellarg($ffmpegPath) . ' -version 2>&1');
    if (is_string($versionOutput)) {
        $versionOutput = trim($versionOutput);
        if ($versionOutput !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $versionOutput);
            if (!empty($lines[0])) {
                $ffmpegDiagnostics['version_line'] = trim($lines[0]);
            }
        }
    }

    $codecsOutput = @shell_exec(escapeshellarg($ffmpegPath) . ' -codecs 2>&1');
    if (is_string($codecsOutput) && $codecsOutput !== '') {
        $ffmpegDiagnostics['has_libx264'] = stripos($codecsOutput, 'libx264') !== false;
        $ffmpegDiagnostics['has_aac']     = preg_match('/\baac\b/i', $codecsOutput) === 1;
    }
}

// 图片概览：采样最近部分相册图片估算平均体积
$albumImageStats = [
    'count'       => 0,
    'total_bytes' => 0,
    'avg_bytes'   => 0,
];

try {
    $rows = $db->fetchAll("
        SELECT image_path
        FROM album_images
        ORDER BY id DESC
        LIMIT 200
    ");
    $totalBytes = 0;
    $count      = 0;
    foreach ($rows as $row) {
        $path = $row['image_path'] ?? '';
        if (!$path) continue;
        $abs = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($path, '/');
        if (!is_file($abs)) continue;
        $sz = filesize($abs);
        if ($sz === false) continue;
        $totalBytes += $sz;
        $count++;
    }
    if ($count > 0) {
        $albumImageStats['count']       = $count;
        $albumImageStats['total_bytes'] = $totalBytes;
        $albumImageStats['avg_bytes']   = (int) floor($totalBytes / $count);
    }
} catch (Throwable $e) {
    // 忽略统计失败，仪表盘保持可用
}

$adminPage = 'dashboard';

include __DIR__ . '/header.php';
?>

    <section class="admin-page-title">
        <h1>欢迎回来，<?php echo e($currentUser['nickname'] ?? $currentUser['username']); ?></h1>
        <p>快速了解你们的小站运行情况</p>
    </section>

    <?php
    // 根据 ffmpeg 状态选择不同的提示样式与摘要文案（统一在仪表盘常驻显示）
    $ffmpegCardBg     = 'rgba(59,130,246,0.04)';
    $ffmpegCardBorder = 'rgba(59,130,246,0.45)';
    $ffmpegCardColor  = '#1d4ed8';
    $ffmpegIcon       = 'fas fa-circle-info';
    $ffmpegSummaryText = '';

    if ($ffmpegStatus === 'ok') {
        $ffmpegCardBg     = 'rgba(34,197,94,0.05)';
        $ffmpegCardBorder = 'rgba(34,197,94,0.45)';
        $ffmpegCardColor  = '#15803d';
        $ffmpegIcon       = 'fas fa-check-circle';

        if ($ffmpegDiagnostics['has_libx264'] === true && $ffmpegDiagnostics['has_aac'] === true) {
            $ffmpegSummaryText = '已检测到可用的 ffmpeg，支持 H.264 + AAC 视频转码。';
        } elseif ($ffmpegDiagnostics['has_libx264'] === false) {
            $ffmpegSummaryText = '已检测到 ffmpeg，但当前环境不支持 H.264（libx264） 视频转码，仅封面截取等基础能力可用。';
        } else {
            $ffmpegSummaryText = '已检测到可用的 ffmpeg，可用于视频相关能力，编码器支持情况建议查看详情。';
        }
    } elseif ($ffmpegStatus === 'no_shell') {
        $ffmpegCardBg     = 'rgba(245,158,11,0.05)';
        $ffmpegCardBorder = 'rgba(245,158,11,0.55)';
        $ffmpegCardColor  = '#b45309';
        $ffmpegIcon       = 'fas fa-exclamation-triangle';
        $ffmpegSummaryText = '检测到 ffmpeg，但 PHP 当前无法调用 shell_exec，自动转码与封面生成能力受限。';
    } else {
        $ffmpegSummaryText = '当前未检测到可用的 ffmpeg 命令，视频转码与自动封面生成能力将被关闭。';
    }
    ?>
    <div class="admin-card" style="margin-bottom:0.75rem;background:<?php echo $ffmpegCardBg; ?>;border:1px solid <?php echo $ffmpegCardBorder; ?>;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.75rem;color:<?php echo $ffmpegCardColor; ?>;font-size:0.86rem;line-height:1.5;">
            <div style="display:flex;align-items:flex-start;gap:0.55rem;flex:1 1 auto;">
                <i class="<?php echo $ffmpegIcon; ?>" style="margin-top:2px;"></i>
                <div>
                    <div style="font-weight:600;margin-bottom:2px;">视频能力与 ffmpeg 状态</div>
                    <div style="font-size:0.8rem;"><?php echo e($ffmpegSummaryText); ?></div>
                </div>
            </div>
            <div style="flex:0 0 auto;display:flex;align-items:center;">
                <button type="button"
                        class="btn btn-secondary"
                        style="padding:0.25rem 0.7rem;font-size:0.78rem;white-space:nowrap;"
                        data-ffmpeg-details="open">
                    查看详情
                </button>
            </div>
        </div>
    </div>

    <div class="admin-modal-backdrop" id="ffmpegDetailBackdrop">
        <div class="admin-modal">
            <div class="admin-modal-header">ffmpeg 详细信息</div>
            <div class="admin-modal-body">
                <div style="font-size:0.85rem;line-height:1.5;">
                    <div>
                        <strong>检测状态：</strong>
                        <?php
                        if ($ffmpegStatus === 'ok') {
                            echo '已检测到可用的 ffmpeg 命令';
                        } elseif ($ffmpegStatus === 'no_shell') {
                            echo '检测到 ffmpeg，但 PHP 禁用了 shell_exec';
                        } else {
                            echo '未检测到可用的 ffmpeg 命令';
                        }
                        ?>
                    </div>
                    <div style="margin-top:0.25rem;">
                        <strong>shell_exec：</strong><?php echo $ffmpegDiagnostics['can_use_shell'] ? '可用' : '不可用（被禁用或不存在）'; ?>
                    </div>
                    <div style="margin-top:0.25rem;">
                        <strong>可执行路径：</strong>
                        <?php echo $ffmpegDiagnostics['binary_found'] ? '<code>' . e($ffmpegDiagnostics['binary_path']) . '</code>' : '未找到'; ?>
                    </div>
                    <?php if (!empty($ffmpegDiagnostics['version_line'])): ?>
                        <div style="margin-top:0.25rem;">
                            <strong>版本信息：</strong>
                            <span style="font-size:0.8rem;"><code><?php echo e($ffmpegDiagnostics['version_line']); ?></code></span>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top:0.4rem;">
                        <strong>编码器支持：</strong>
                        <ul style="margin:0.25rem 0 0 1rem;padding:0;font-size:0.8rem;list-style:disc;">
                            <li>H.264（libx264）：
                                <?php
                                if ($ffmpegDiagnostics['has_libx264'] === true) {
                                    echo '<span style="color:#15803d;">已检测到</span>';
                                } elseif ($ffmpegDiagnostics['has_libx264'] === false) {
                                    echo '<span style="color:#b91c1c;">未检测到，视频转码功能将无法正常工作</span>';
                                } else {
                                    echo '无法确认（可能 shell_exec 被禁用或检测失败）';
                                }
                                ?>
                            </li>
                            <li>AAC 音频：
                                <?php
                                if ($ffmpegDiagnostics['has_aac'] === true) {
                                    echo '<span style="color:#15803d;">已检测到</span>';
                                } elseif ($ffmpegDiagnostics['has_aac'] === false) {
                                    echo '<span style="color:#b91c1c;">未检测到，可能导致部分音频无法转码</span>';
                                } else {
                                    echo '无法确认';
                                }
                                ?>
                            </li>
                        </ul>
                    </div>

                    <div style="margin-top:0.5rem;font-size:0.78rem;color:var(--text-light);">
                        提示：视频上传时的转码命令使用 <code>ffmpeg -c:v libx264 -c:a aac</code>，若缺少 libx264 或 AAC 编码器，将导致转码失败但封面截取仍然可用。
                    </div>
                </div>
            </div>
            <div class="admin-modal-actions">
                <button type="button" class="btn btn-secondary" data-ffmpeg-details="close">关闭</button>
            </div>
        </div>
    </div>

    <section class="admin-grid" style="margin-bottom: 0.75rem;">
        <div class="admin-card">
            <div class="admin-card-header">
                <div>
                    <div class="admin-card-title">文章 · 日记</div>
                    <div class="admin-card-subtitle">记录的点点滴滴</div>
                </div>
                <a href="/admin/articles.php" class="btn btn-outline">
                    <i class="fas fa-pen"></i><span>去管理</span>
                </a>
            </div>
            <div>
                <div class="admin-stat-value"><?php echo $articleCount; ?></div>
                <div class="admin-stat-label">篇内容</div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <div>
                    <div class="admin-card-title">相册</div>
                    <div class="admin-card-subtitle">保存的照片与回忆</div>
                </div>
                <a href="/admin/albums.php" class="btn btn-outline">
                    <i class="fas fa-images"></i><span>去查看</span>
                </a>
            </div>
            <div>
                <div class="admin-stat-value"><?php echo $albumCount; ?></div>
                <div class="admin-stat-label">个相册</div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <div>
                    <div class="admin-card-title">纪念事件</div>
                    <div class="admin-card-subtitle">重要的时刻</div>
                </div>
                <a href="/admin/events.php" class="btn btn-outline">
                    <i class="fas fa-calendar-plus"></i><span>去添加</span>
                </a>
            </div>
            <div>
                <div class="admin-stat-value"><?php echo $eventCount; ?></div>
                <div class="admin-stat-label">个事件</div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <div>
                    <div class="admin-card-title">留言</div>
                    <div class="admin-card-subtitle">来自你们或朋友的话</div>
                </div>
                <a href="/admin/messages.php" class="btn btn-outline">
                    <i class="fas fa-comments"></i><span>去查看</span>
                </a>
            </div>
            <div>
                <div class="admin-stat-value"><?php echo $messageCount; ?></div>
                <div class="admin-stat-label">条留言</div>
            </div>
        </div>
    </section>

    <section class="admin-grid">
        <div class="admin-card">
            <div class="admin-card-header">
                <div>
                    <div class="admin-card-title">快捷设置</div>
                    <div class="admin-card-subtitle">常用设置与个人信息入口</div>
                </div>
            </div>
            <ul style="list-style:none;margin:0;padding:0;">
                <li style="padding:0.4rem 0;border-bottom:1px solid rgba(226,232,240,0.7);">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;">
                        <div>
                            <div style="font-size:0.9rem;">网站设置</div>
                            <div style="font-size:0.78rem;color:var(--text-light);">修改站点标题、描述、首页大图、备案信息</div>
                        </div>
                        <a href="/admin/settings.php" class="btn btn-secondary" style="white-space:nowrap;">
                            <span>进入</span><i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </li>
                <li style="padding:0.4rem 0;border-bottom:1px solid rgba(226,232,240,0.7);">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;">
                        <div>
                            <div style="font-size:0.9rem;">个人资料</div>
                            <div style="font-size:0.78rem;color:var(--text-light);">修改昵称、头像、QQ 头像来源与登录密码</div>
                        </div>
                        <a href="/admin/profile.php" class="btn btn-secondary" style="white-space:nowrap;">
                            <span>进入</span><i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </li>
                <li style="padding:0.4rem 0;border-bottom:1px solid rgba(226,232,240,0.7);">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;">
                        <div>
                            <div style="font-size:0.9rem;">留言管理</div>
                            <div style="font-size:0.78rem;color:var(--text-light);">查看和删除前台的留言内容</div>
                        </div>
                        <a href="/admin/messages.php" class="btn btn-secondary" style="white-space:nowrap;">
                            <span>进入</span><i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </li>
                <li style="padding:0.4rem 0;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;">
                        <div>
                            <div style="font-size:0.9rem;">IP 黑名单</div>
                            <div style="font-size:0.78rem;color:var(--text-light);">统一管理被禁止评论与留言的 IP</div>
                        </div>
                        <a href="/admin/comment_ip_blacklist.php" class="btn btn-secondary" style="white-space:nowrap;">
                            <span>进入</span><i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </li>
            </ul>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <div>
                    <div class="admin-card-title">图片概览</div>
                    <div class="admin-card-subtitle">最近相册图片的体积与加载估算</div>
                </div>
                <a href="/admin/tools_image_stats.php" class="btn btn-secondary">
                    <span>详情</span><i class="fas fa-chevron-right"></i>
                </a>
            </div>
            <?php if ($albumImageStats['count'] > 0): ?>
                <?php
                $count      = $albumImageStats['count'];
                $totalBytes = $albumImageStats['total_bytes'];
                $avgBytes   = $albumImageStats['avg_bytes'];
                $avgKb      = round($avgBytes / 1024, 1);
                $totalMb    = round($totalBytes / 1024 / 1024, 1);
                $sceneCount = 30;
                $sceneMb    = round($avgBytes * $sceneCount / 1024 / 1024, 2);
                ?>
                <ul style="list-style:none;margin:0;padding:0;font-size:0.88rem;color:var(--text-normal);">
                    <li style="padding:0.3rem 0;border-bottom:1px solid rgba(226,232,240,0.7);">
                        最近采样图片：<strong><?php echo $count; ?></strong> 张，总体积约 <strong><?php echo $totalMb; ?> MB</strong>
                    </li>
                    <li style="padding:0.3rem 0;border-bottom:1px solid rgba(226,232,240,0.7);">
                        平均单张大小：约 <strong><?php echo $avgKb; ?> KB</strong>
                    </li>
                    <li style="padding:0.3rem 0;">
                        场景估算：一次加载 <strong><?php echo $sceneCount; ?></strong> 张图 ≈ <strong><?php echo $sceneMb; ?> MB</strong>
                        <div style="font-size:0.78rem;color:var(--text-light);margin-top:0.2rem;">
                            实际流量会因 WebP 与缩略图启用而更低，仅供参考。
                        </div>
                    </li>
                </ul>
            <?php else: ?>
                <p style="font-size:0.85rem;color:var(--text-light);">暂时还没有足够的相册图片用于统计。</p>
            <?php endif; ?>
        </div>
    </section>

<?php include __DIR__ . '/footer.php'; ?>
