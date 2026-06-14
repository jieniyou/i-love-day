<?php
// 强制使用 UTF-8 输出
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/helpers.php';

// 页面标题
$pageTitle = '留言墙';

$auth        = new Auth();
$db          = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$partner     = $currentUser ? $auth->getPartner() : null;

$error   = '';
$success = '';

// 每页加载的留言数量（首屏 + 后续分页保持一致）
$messagesPerPage = 6;

// 留言表单的一次性 token key
$messageFormKey = 'message_form';

// 重定向后显示的成功提示
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $success = '留言发布成功';
}

// 处理留言提交（已登录用户，或未登录 QQ/昵称留言）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 校验
    $token = $_POST['_token'] ?? '';
    if (!csrf_verify($token)) {
        $error = '表单已过期，请刷新页面后重试';
    } elseif (!verify_and_consume_form_once_token($messageFormKey, $_POST['message_once_token'] ?? null)) {
        // 同一个一次性 token 已经使用过，防止刷新或重复提交
        $error = '本条留言已经提交，请不要重复提交。如需再留言，请刷新页面后重新填写。';
    } else {
        $content       = trim($_POST['content'] ?? '');
        $isPublic      = isset($_POST['is_public']) ? 1 : 0;
        $qq            = trim($_POST['qq'] ?? '');
        $guestNickname = trim($_POST['guest_nickname'] ?? '');
        $guestAvatar   = trim($_POST['guest_avatar'] ?? '');

        if ($content === '') {
            $error = '留言内容不能为空';
        } elseif (mb_strlen($content, 'UTF-8') > 100) {
            $error = '留言内容不能超过 100 个字符';
        } else {
            // 简单节流：限制同一用户/会话短时间内频繁提交留言
            $now = time();
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            // IP 级节流：限制同一 IP 在一段时间内的留言次数（防止恶意刷留言）
            $ip = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

            // 留言 IP 黑名单：复用评论 IP 黑名单表，若当前 IP 在黑名单中且未过期，则禁止留言
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

                if (!empty($ip) && $ip !== '0.0.0.0') {
                    $row = $db->fetch(
                        "SELECT id, expires_at FROM comment_ip_blacklist WHERE ip = :ip LIMIT 1",
                        ['ip' => $ip]
                    );
                    if ($row) {
                        $expiresAt = $row['expires_at'] ?? null;
                        if ($expiresAt === null || $expiresAt === '0000-00-00 00:00:00' || strtotime($expiresAt) >= $now) {
                            $error = '当前 IP 已被限制留言与评论';
                        }
                    }
                }
            } catch (Throwable $e) {
                // 黑名单表创建失败或查询失败时，不影响正常留言逻辑
            }

            try {
                // 幂等创建 message_attempts 表，用于记录 IP 级留言尝试
                $db->query("
                    CREATE TABLE IF NOT EXISTS `message_attempts` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `ip` varchar(45) DEFAULT NULL,
                        `created_at` datetime NOT NULL,
                        PRIMARY KEY (`id`),
                        KEY `idx_ip_time` (`ip`,`created_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='留言尝试记录';
                ");

                // 统计最近一段时间内的留言次数（例如 1 小时最多 20 条）
                $windowStart = date('Y-m-d H:i:s', $now - 3600);
                $row = $db->fetch(
                    "SELECT COUNT(*) AS c FROM message_attempts WHERE ip = :ip AND created_at >= :start",
                    [
                        'ip'    => $ip,
                        'start' => $windowStart,
                    ]
                );
                $ipCount = $row ? (int) ($row['c'] ?? 0) : 0;
                if ($ipCount >= 20) {
                    $error = '当前 IP 留言太频繁，请稍后再试';
                }
            } catch (Throwable $e) {
                // 表不存在或查询失败时不影响正常留言逻辑
            }

            if ($error === '') {
                if ($currentUser) {
                    // 已登录用户按 user_id 节流：60 秒内只允许一条
                    $recentTime = date('Y-m-d H:i:s', $now - 60);
                    $recentMsg = $db->fetch(
                        "SELECT id FROM messages WHERE user_id = :uid AND created_at >= :recent LIMIT 1",
                        [
                            'uid'    => $currentUser['id'],
                            'recent' => $recentTime,
                        ]
                    );
                    if ($recentMsg) {
                        $error = '留言太频繁，请稍后再试';
                    }
                } else {
                    // 未登录访客按 session 节流：60 秒内只允许一条
                    $lastGuestTime = $_SESSION['last_guest_message_time'] ?? 0;
                    if ($lastGuestTime && ($now - (int)$lastGuestTime) < 60) {
                        $error = '留言太频繁，请稍后再试';
                    }
                }
            }

            if ($error === '') {
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

                // 根据 IP 获取归属地（若可用）
                $location = '';
                if (!empty($ip) && function_exists('get_ip_location_local')) {
                    $location = trim(get_ip_location_local($ip));
                }

                if ($currentUser) {
                    // 已登录用户
                    $data = [
                        'user_id'        => $currentUser['id'],
                        'guest_nickname' => null,
                        'guest_avatar'   => null,
                        'guest_qq'       => null,
                        'ip'             => $ip ?: null,
                        'location'       => $location ?: null,
                        'content'        => $content,
                        'is_public'      => $isPublic,
                        'status'         => 'published',
                        'created_at'     => date('Y-m-d H:i:s'),
                    ];
                } else {
                    // 游客留言，必须同时填写 QQ 和昵称，头像可根据 QQ 自动生成
                    if ($qq === '' || $guestNickname === '') {
                        $error = '请填写 QQ 号和昵称';
                    } else {
                        if ($qq !== '' && ($guestNickname === '' || $guestAvatar === '')) {
                            // 头像统一使用官方 qlogo 地址
                            if ($guestAvatar === '') {
                                $guestAvatar = 'https://q1.qlogo.cn/g?b=qq&nk=' . urlencode($qq) . '&s=100';
                            }
                        }

                        if ($guestNickname === '') {
                            $guestNickname = 'QQ 用户';
                        }
                        if ($guestAvatar === '') {
                            $guestAvatar = '/assets/images/default-avatar.svg';
                        }

                        $data = [
                            'user_id'        => 0,
                            'guest_nickname' => $guestNickname,
                            'guest_avatar'   => $guestAvatar,
                            'guest_qq'       => $qq,
                            'ip'             => $ip ?: null,
                            'location'       => $location ?: null,
                            'content'        => $content,
                            'is_public'      => $isPublic,
                            'status'         => 'published',
                            'created_at'     => date('Y-m-d H:i:s'),
                        ];
                    }
                }
            }

            if ($error === '') {
                $messageId = $db->insert('messages', $data);
                if ($messageId) {
                    // 记录 IP 级留言尝试，用于后续节流（最佳努力，不影响主流程）
                    try {
                        if (!empty($ip)) {
                            $db->insert('message_attempts', [
                                'ip'         => $ip,
                                'created_at' => date('Y-m-d H:i:s', $now),
                            ]);
                        }
                    } catch (Throwable $e) {
                        // 记录失败忽略
                    }

                    if (!$currentUser) {
                        // 记录游客节流时间
                        $_SESSION['last_guest_message_time'] = $now;
                    }
                    // PRG 模式：提交成功后重定向，避免刷新重复提交
                    header('Location: ' . url('messages.php?success=1'));
                    exit;
                }

                // 插入失败
                $error = '保存留言失败，请稍后再试';
            }
        }
    }
}

// 加载留言列表（首屏只加载第一页，其余通过前端按需分页加载）：
// - 已登录用户：可以看到自己的私密留言 + 所有公开留言
// - 未登录访客：只能看到公开留言
if ($currentUser) {
    $messages = $db->fetchAll(
        "SELECT m.*, 
         COALESCE(u.nickname, m.guest_nickname, '匿名用户') AS nickname,
         COALESCE(u.avatar, m.guest_avatar, '/assets/images/default-avatar.svg') AS avatar
         FROM messages m 
         LEFT JOIN users u ON m.user_id = u.id 
         WHERE m.status = 'published' AND (m.is_public = 1 OR m.user_id = :user_id)
         ORDER BY m.created_at DESC
         LIMIT " . (int) $messagesPerPage,
        ['user_id' => $currentUser['id']]
    );
} else {
    $messages = $db->fetchAll(
        "SELECT m.*, 
         COALESCE(u.nickname, m.guest_nickname, '匿名用户') AS nickname,
         COALESCE(u.avatar, m.guest_avatar, '/assets/images/default-avatar.svg') AS avatar
         FROM messages m 
         LEFT JOIN users u ON m.user_id = u.id 
         WHERE m.status = 'published' AND m.is_public = 1
         ORDER BY m.created_at DESC
         LIMIT " . (int) $messagesPerPage
    );
}

// 为当前页面生成留言表单的一次性 token
$messageOnceToken = form_once_token($messageFormKey);

include __DIR__ . '/views/header.php';
?>

<section class="content-section">
    <div class="section-header">
        <h2><i class="fas fa-comment"></i> 留言墙</h2>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-error glass-card">
        <i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?>
    </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div class="alert alert-success glass-card">
        <i class="fas fa-check-circle"></i> <?php echo e($success); ?>
    </div>
    <?php endif; ?>
    
    <div class="message-form glass-card">
        <h3><i class="fas fa-edit"></i> 写下你想说的话</h3>
        <form method="POST" novalidate>
            <?php echo csrf_field(); ?>
            <input type="hidden" name="message_once_token" value="<?php echo e($messageOnceToken); ?>">

            <?php if (!$currentUser): ?>
            <div class="qq-row">
                <div class="qq-avatar-wrap">
                    <img id="qq-avatar" src="<?php echo e($_POST['guest_avatar'] ?? '/assets/images/default-avatar.svg'); ?>" alt="QQ 头像">
                </div>
                <div class="qq-input-wrap">
                    <input
                        type="text"
                        name="qq"
                        id="qq-input"
                        class="qq-input"
                        placeholder="填写 QQ 号（用于获取头像和昵称）"
                        value="<?php echo e($_POST['qq'] ?? ''); ?>">
                    <input
                        type="text"
                        name="guest_nickname"
                        id="qq-nickname"
                        class="qq-input"
                        placeholder="昵称（必填）"
                        value="<?php echo e($_POST['guest_nickname'] ?? ''); ?>">
                </div>
                <input
                    type="hidden"
                    name="guest_avatar"
                    id="qq-avatar-input"
                    value="<?php echo e($_POST['guest_avatar'] ?? ''); ?>">
            </div>
            <?php endif; ?>

            <div class="message-textarea-wrap">
                <textarea
                    name="content"
                    placeholder="写点什么吧..."><?php echo e($_POST['content'] ?? ''); ?></textarea>
            </div>
            <input type="hidden" name="is_public" value="1">
            <div class="message-actions">
                <button type="submit" class="btn btn-primary">
                    <span>提交留言</span>
                    <i class="fas fa-mouse-pointer"></i>
                </button>
            </div>
        </form>
    </div>
    
    <div class="messages-list messages-masonry">
        <?php
        $messageCardVariants = ['card-pink', 'card-green', 'card-blue', 'card-purple'];
        $variantIndex = 0;
        ?>
        <?php foreach ($messages as $message): ?>
        <?php
        $variantClass  = $messageCardVariants[$variantIndex];
        $variantIndex  = ($variantIndex + 1) % count($messageCardVariants);
        $locationText  = isset($message['location']) && $message['location'] !== '' ? $message['location'] : '';
        ?>
        <div class="message-item message-card <?php echo $variantClass; ?>">
            <div class="msg-top-deco"></div>
            <div class="msg-avatar">
                <img
                    src="<?php echo e($message['avatar'] ?: '/assets/images/default-avatar.svg'); ?>"
                    alt="<?php echo e($message['nickname']); ?>">
            </div>
            <div class="msg-user"><?php echo e($message['nickname']); ?></div>
            <div class="msg-content">
                <i class="fas fa-quote-left quote-icon"></i>
                <p><?php echo nl2br(e($message['content'])); ?></p>
            </div>
            <div class="msg-footer">
                <span class="msg-time"><?php echo timeAgo($message['created_at']); ?></span>
                <?php if ($locationText): ?>
                    <span class="msg-location"><?php echo e($locationText); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($messages)): ?>
        <div class="empty-state glass-card">
            <i class="fas fa-comment"></i>
            <p>还没有任何留言，快来写下第一条吧。</p>
        </div>
        <?php endif; ?>
    </div>
    <div id="messages-load-more-sentinel" class="messages-load-more-sentinel"></div>
</section>

<?php include __DIR__ . '/views/footer.php'; ?>

<script>
(function () {
    var qqInput = document.getElementById('qq-input');
    var nicknameInput = document.getElementById('qq-nickname');
    var avatarInput = document.getElementById('qq-avatar-input');
    var avatarImg = document.getElementById('qq-avatar');
    var form = qqInput ? qqInput.closest('form') : null;

    if (!qqInput || !nicknameInput || !avatarInput || !avatarImg || !form) {
        return;
    }

    var isLoading = false;

    // 显示/隐藏获取昵称头像的加载弹窗
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

    function fetchQQProfile(qq) {
        if (!qq || isLoading) return;
        isLoading = true;

        showQQLoading(true);

        var originalSrc = avatarImg.getAttribute('src');
        avatarImg.setAttribute('data-original-src', originalSrc);
        avatarImg.src = '/assets/images/default-avatar.svg';

        // 直接使用官方 qlogo 头像地址进行预览
        var avatar = 'https://q1.qlogo.cn/g?b=qq&nk=' + encodeURIComponent(qq) + '&s=100';
        avatarImg.src = avatar;
        avatarInput.value = avatar;

        // 尝试通过站内接口获取昵称（使用多接口轮循），每次以接口返回为准覆盖当前昵称
        fetch('/api/qq_profile.php?qq=' + encodeURIComponent(qq))
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (json) {
                if (!json || !json.success) return;
                var nick = json.nickname || '';
                if (nick) {
                    nicknameInput.value = nick;
                } else {
                    nicknameInput.value = '获取昵称失败，请手动填写';
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

    qqInput.addEventListener('blur', function () {
        var qq = qqInput.value.trim();
        if (!qq) return;
        fetchQQProfile(qq);
    });

    // 表单提交前前端校验：游客必须同时填写 QQ 和昵称
    form.addEventListener('submit', function (e) {
        var qq = qqInput.value.trim();
        var nick = nicknameInput.value.trim();
        if (!qq || !nick) {
            e.preventDefault();
            if (typeof window.showToast === 'function') {
                window.showToast('请填写 QQ 号和昵称后再提交留言', 'error');
            }
        }
    });
})();

// 留言内容长度前端校验：最多 100 个字符
(function () {
    var form = document.querySelector('.message-form form');
    if (!form) return;

    var textarea = form.querySelector('textarea[name="content"]');
    if (!textarea) return;

    form.addEventListener('submit', function (e) {
        var text = textarea.value.trim();

        if (!text) {
            e.preventDefault();
            if (typeof window.showToast === 'function') {
                window.showToast('留言内容不能为空', 'error');
            }
            return;
        }

        if (text.length > 100) {
            e.preventDefault();
            if (typeof window.showToast === 'function') {
                window.showToast('留言内容不能超过 100 个字符', 'error');
            }
        }
    });
})();

// 留言墙：滚动到下方时按需加载更多留言卡片
(function () {
    var masonryContainer = document.querySelector('.messages-masonry');
    var sentinel = document.getElementById('messages-load-more-sentinel');
    if (!masonryContainer || !sentinel) return;

    var currentPage = 1;
    var perPage = <?php echo (int) $messagesPerPage; ?>;
    var isLoading = false;
    var hasMore = true;

    var variantClasses = ['card-pink', 'card-green', 'card-blue', 'card-purple'];
    var existingCount = masonryContainer.querySelectorAll('.message-item').length;
    var variantIndex = existingCount % variantClasses.length;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function buildMessageCard(item) {
        var card = document.createElement('div');
        card.className = 'message-item message-card';

        // 追加配色类（与首屏保持循环规律）
        var variantClass = variantClasses[variantIndex];
        variantIndex = (variantIndex + 1) % variantClasses.length;
        card.classList.add(variantClass);

        card.innerHTML =
            '<div class="msg-top-deco"></div>' +
            '<div class="msg-avatar">' +
                '<img src="' + escapeHtml(item.avatar || '/assets/images/default-avatar.svg') + '" alt="' + escapeHtml(item.nickname || '') + '">' +
            '</div>' +
            '<div class="msg-user">' + escapeHtml(item.nickname || '') + '</div>' +
            '<div class="msg-content">' +
                '<i class="fas fa-quote-left quote-icon"></i>' +
                '<p>' + (item.content_html || '') + '</p>' +
            '</div>' +
            '<div class="msg-footer">' +
                '<span class="msg-time">' + escapeHtml(item.time_ago || '') + '</span>' +
                (item.location ? '<span class="msg-location">' + escapeHtml(item.location || '') + '</span>' : '') +
            '</div>';

        return card;
    }

    function appendMessages(items) {
        if (!items || !items.length) return;
        var frag = document.createDocumentFragment();
        items.forEach(function (item) {
            frag.appendChild(buildMessageCard(item));
        });
        masonryContainer.appendChild(frag);

        // 为新增卡片重新初始化瀑布流布局和动画
        if (typeof initMasonryForContainer === 'function') {
            initMasonryForContainer('.messages-masonry', '.message-item');
        }
    }

    function loadMore() {
        if (isLoading || !hasMore) return;
        isLoading = true;
        var nextPage = currentPage + 1;

        fetch('/api/messages.php?page=' + nextPage + '&per_page=' + perPage, {
            credentials: 'same-origin'
        })
            .then(function (res) {
                if (!res.ok) return null;
                return res.json();
            })
            .then(function (json) {
                if (!json || !json.success) return;
                appendMessages(json.items || []);
                currentPage = json.page || nextPage;
                hasMore = !!json.has_more;
                if (!hasMore && observer) {
                    observer.unobserve(sentinel);
                }
            })
            .catch(function () {
                // 加载失败时保持现状，下次滚动可再次尝试
            })
            .finally(function () {
                isLoading = false;
            });
    }

    var observer = null;
    if ('IntersectionObserver' in window) {
        observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    loadMore();
                }
            });
        }, {
            root: null,
            rootMargin: '0px 0px 200px 0px',
            threshold: 0.01
        });
        observer.observe(sentinel);
    }
})();
</script>

<!-- QQ 昵称头像获取中的加载弹窗 -->
<div class="qq-loading-overlay" id="qq-loading-overlay" style="display:none;">
    <div class="qq-loading-dialog">
        <div class="qq-loading-title">获取昵称头像中</div>
        <div class="qq-loading-dots">
            <span></span><span></span><span></span>
        </div>
        <div class="qq-loading-text">请稍等片刻</div>
    </div>
</div>
