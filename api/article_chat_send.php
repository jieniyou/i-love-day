<?php
// 聊天创作模式：发送一条对话块
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/helpers.php';

$auth = new Auth();

// 确保新字段（edit_mode / speaker）在老数据库中也存在
if (function_exists('migrate_schema_if_needed')) {
    migrate_schema_if_needed();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!csrf_verify($_POST['_token'] ?? null)) {
    echo json_encode(['success' => false, 'message' => '表单已过期，请刷新页面后重试'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$articleId = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;
$speaker   = $_POST['speaker'] ?? '';
$type      = $_POST['type'] ?? 'text';
$content   = trim((string)($_POST['content'] ?? ''));

if ($articleId <= 0 || $content === '') {
    echo json_encode(['success' => false, 'message' => '参数错误或内容为空'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db          = Database::getInstance();
    $currentUser = $auth->getCurrentUser();
    $partner     = $auth->getPartner();

    // 简单节流：防止聊天创作接口被恶意高频调用
    // 规则：
    // - 同一登录用户：2 秒内仅允许一次发送
    // - 附加 IP 维度限制：同一 IP 最近 1 分钟内最多 60 次
    $ip  = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $now = time();

    try {
        // 幂等创建节流记录表
        $db->query("
            CREATE TABLE IF NOT EXISTS `article_chat_attempts` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) DEFAULT NULL,
                `ip` varchar(45) DEFAULT NULL,
                `created_at` datetime NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_user_time` (`user_id`,`created_at`),
                KEY `idx_ip_time` (`ip`,`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='聊天创作发送尝试记录';
        ");

        // 用户级：2 秒内仅允许一次
        if (!empty($currentUser['id'])) {
            $userId      = (int) $currentUser['id'];
            $recentStart = date('Y-m-d H:i:s', $now - 2);
            $rowUser = $db->fetch(
                "SELECT id FROM article_chat_attempts WHERE user_id = :uid AND created_at >= :start LIMIT 1",
                [
                    'uid'   => $userId,
                    'start' => $recentStart,
                ]
            );
            if ($rowUser) {
                echo json_encode(['success' => false, 'message' => '发送太频繁，请稍后再试'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // IP 级：1 分钟内最多 60 次
        if (!empty($ip)) {
            $ipWindowStart = date('Y-m-d H:i:s', $now - 60);
            $rowIp = $db->fetch(
                "SELECT COUNT(*) AS c FROM article_chat_attempts WHERE ip = :ip AND created_at >= :start",
                [
                    'ip'    => $ip,
                    'start' => $ipWindowStart,
                ]
            );
            $ipCount = $rowIp ? (int) ($rowIp['c'] ?? 0) : 0;
            if ($ipCount >= 60) {
                echo json_encode(['success' => false, 'message' => '当前 IP 操作太频繁，请稍后再试'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    } catch (Throwable $e) {
        // 节流逻辑异常不影响主流程，仅不做限制
    }

    $article = $db->fetch("SELECT * FROM articles WHERE id = :id LIMIT 1", ['id' => $articleId]);
    if (!$article) {
        echo json_encode(['success' => false, 'message' => '文章不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $isOwner   = isset($article['user_id']) && (int)$article['user_id'] === (int)$currentUser['id'];
    $partnerId = $partner['id'] ?? null;
    $isPartner = $partnerId && isset($article['user_id']) && (int)$article['user_id'] === (int)$partnerId;

    // 读取是否允许另一半编辑
    $allowPartnerEdit = 1;
    try {
        $permRow = $db->fetch(
            "SELECT allow_partner_edit FROM article_permissions WHERE article_id = :article_id",
            ['article_id' => $articleId]
        );
        if ($permRow) {
            $allowPartnerEdit = (int)$permRow['allow_partner_edit'];
        }
    } catch (Exception $e) {
        $allowPartnerEdit = 1;
    }

    if (!$isOwner && !($isPartner && $allowPartnerEdit)) {
        echo json_encode(['success' => false, 'message' => '你没有权限编辑这篇文章'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 构造 HTML
    $html = '';
    if ($type === 'image') {
        $url = $content;
        $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $html = '<p><img src="' . $url . '" alt="" /></p>';
    } elseif ($type === 'video') {
        $url = $content;
        $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $html = '<p><video src="' . $url . '" controls preload="metadata" style="max-width:100%;height:auto;border-radius:0.9rem;"></video></p>';
    } else {
        // 文本：换行转 <br>
        $text = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
        $text = nl2br($text);
        $html = '<p>' . $text . '</p>';
    }

    if (function_exists('clean_wangeditor_html')) {
        $html = clean_wangeditor_html($html);
    }
    if (function_exists('normalize_block_html_for_save')) {
        $html = normalize_block_html_for_save($html);
    }
    if ($html === '') {
        echo json_encode(['success' => false, 'message' => '内容为空'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 映射 speaker -> user_id
    $coupleUsers   = get_couple_users();
    $maleUserRow   = $coupleUsers['user1'] ?? null;
    $femaleUserRow = $coupleUsers['user2'] ?? null;
    $blockUserId   = 0;
    $speakerVal    = null;

    if ($speaker === 'male') {
        $speakerVal  = 'male';
        $blockUserId = $maleUserRow ? (int)$maleUserRow['id'] : (int)$article['user_id'];
    } elseif ($speaker === 'female') {
        $speakerVal  = 'female';
        $blockUserId = $femaleUserRow ? (int)$femaleUserRow['id'] : (int)$article['user_id'];
    } elseif ($speaker === 'system') {
        $speakerVal  = 'system';
        $blockUserId = (int)$article['user_id'];
    } else {
        // 未知身份时默认归属文章作者
        $blockUserId = (int)$article['user_id'];
    }

    // 计算下一个 block_index
    $row = $db->fetch(
        "SELECT MAX(block_index) AS max_idx FROM article_blocks WHERE article_id = :article_id",
        ['article_id' => $articleId]
    );
    $nextIndex = isset($row['max_idx']) && $row['max_idx'] !== null ? ((int)$row['max_idx'] + 1) : 0;
    $now = date('Y-m-d H:i:s');

    // 插入块（使用 Database::insert 以获取自增ID）
    $blockId = (int)$db->insert('article_blocks', [
        'article_id'  => $articleId,
        'block_index' => $nextIndex,
        'user_id'     => $blockUserId,
        'speaker'     => $speakerVal,
        'html'        => $html,
        'created_at'  => $now,
        'updated_at'  => $now,
    ]);

    // 记录一次聊天发送尝试（最佳努力，不影响主流程）
    try {
        if (!empty($ip)) {
            $db->insert('article_chat_attempts', [
                'user_id'    => !empty($currentUser['id']) ? (int) $currentUser['id'] : null,
                'ip'         => $ip,
                'created_at' => $now,
            ]);
        }
    } catch (Throwable $e) {
        // 记录失败忽略
    }

    // 聊天创作：增量更新共创贡献统计 + 编辑日志（最佳努力，失败不影响主流程）
    try {
        // 仅对有效用户、且非系统身份的块记录贡献
        if ($blockUserId > 0 && $speakerVal !== 'system') {
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

            // 基于本次块的 HTML 粗略计算新增字数：去掉 HTML 标签与实体
            $plain = strip_tags((string)$html);
            $plain = html_entity_decode($plain, ENT_QUOTES, 'UTF-8');
            $plain = trim($plain);

            if ($plain !== '') {
                $len = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);
                if ($len > 0) {
                    $db->query("
                        INSERT INTO article_contributions (article_id, user_id, contributed_chars, last_updated_at)
                        VALUES (:article_id, :user_id, :contributed_chars, :last_updated_at)
                        ON DUPLICATE KEY UPDATE
                            contributed_chars = contributed_chars + VALUES(contributed_chars),
                            last_updated_at = VALUES(last_updated_at)
                    ", [
                        'article_id'        => $articleId,
                        'user_id'           => $blockUserId,
                        'contributed_chars' => $len,
                        'last_updated_at'   => $now,
                    ]);
                }
            }
        }

        // 记录编辑日志：用于前台共创判断等（若表不存在则尝试创建）
        if (!empty($currentUser['id'])) {
            $db->query("
                CREATE TABLE IF NOT EXISTS `article_edit_logs` (
                    `article_id` int(11) NOT NULL COMMENT '文章ID',
                    `user_id` int(11) NOT NULL COMMENT '编辑用户ID',
                    `last_edited_at` datetime NOT NULL COMMENT '最后编辑时间',
                    PRIMARY KEY (`article_id`, `user_id`),
                    KEY `user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章编辑记录';
            ");

            $db->query("
                INSERT INTO article_edit_logs (article_id, user_id, last_edited_at)
                VALUES (:article_id, :user_id, :last_edited_at)
                ON DUPLICATE KEY UPDATE
                    last_edited_at = VALUES(last_edited_at)
            ", [
                'article_id'     => $articleId,
                'user_id'        => (int)$currentUser['id'],
                'last_edited_at' => $now,
            ]);
        }
    } catch (Throwable $e) {
        // 共创统计或编辑日志失败时忽略，不影响发送成功
    }

    // 重建全文 HTML
    $blocks = $db->fetchAll(
        "SELECT html FROM article_blocks WHERE article_id = :article_id ORDER BY block_index ASC",
        ['article_id' => $articleId]
    );
    $contentHtml = '';
    foreach ($blocks as $bRow) {
        $part = (string)($bRow['html'] ?? '');
        if ($part === '') continue;
        $contentHtml .= $part;
    }
    $contentHtml = trim($contentHtml);

    // 这里只更新内容与更新时间，不强制修改 edit_mode，以兼容老数据库中仍为 full/blocks 的情况
    $db->update('articles', [
        'content'    => $contentHtml,
        'updated_at' => $now,
    ], 'id = :id', ['id' => $articleId]);

    echo json_encode([
        'success' => true,
        'block'   => [
            'id'       => $blockId,
            'index'    => $nextIndex,
            'speaker'  => $speakerVal,
            'html'     => $html,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // 线上环境：不暴露具体错误细节，仅提示发送失败
    echo json_encode([
        'success' => false,
        'message' => '发送失败，请稍后重试',
    ], JSON_UNESCAPED_UNICODE);
}
