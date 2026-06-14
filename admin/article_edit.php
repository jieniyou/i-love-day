<?php
// 新版后台 - 编辑文章（移动端优先）
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/helpers.php';

// 尝试为老版本补充新字段（edit_mode / speaker）
migrate_schema_if_needed();

/**
 * 清理 wangEditor 生成的外层容器，只保留正文 HTML
 */
function clean_wangeditor_html(string $html): string
{
    if ($html === '' || (strpos($html, 'w-e-text') === false && strpos($html, 'w-e-text-container') === false)) {
        return $html;
    }

    if (class_exists('DOMDocument')) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<div>' . $html . '</div>';
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        if ($loaded) {
            $xpath = new DOMXPath($dom);
            $nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " w-e-text ")]');
            if ($nodes->length > 0) {
                $node = $nodes->item(0);
                $innerHtml = '';
                foreach ($node->childNodes as $child) {
                    $innerHtml .= $dom->saveHTML($child);
                }
                libxml_clear_errors();
                return trim($innerHtml);
            }
        }
        libxml_clear_errors();
    }

    $html = preg_replace('/\scontenteditable="true"/i', '', $html);
    $html = preg_replace('/\scontenteditable="false"/i', '', $html);
    $html = preg_replace('/\sid="text-elem[0-9]+"/i', '', $html);
    $html = preg_replace('/\sclass="([^"]*?)w-e-text[^"]*"/i', '', $html);
    $html = preg_replace('/\sclass="([^"]*?)w-e-[^"]*"/i', ' class="$1"', $html);

    return trim($html);
}

/**
 * 规范块级 HTML，去掉多余的空段落（如大量 <p><br></p>）
 */
function normalize_block_html_for_save(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return $html;
    }

    // 直接移除所有“完全空”的段落：
    // <p>、<p><br></p>、只有空格/&nbsp; 的 <p> 都会被干掉
    $html = preg_replace(
        '/\s*<p>\s*(?:&nbsp;|\xC2\xA0|\s|<br\s*\/?>)*<\/p>\s*/iu',
        '',
        $html
    );

    return trim($html);
}

$auth = new Auth();
$auth->requireLogin();
$db          = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$partner     = $auth->getPartner();

$error   = '';
$success = '';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: articles.php');
    exit;
}

// 获取当前文章（情侣双方共享，按 id 即可）
$article = $db->fetch(
    "SELECT * FROM articles WHERE id = :id LIMIT 1",
    ['id' => $id]
);

if (!$article) {
    header('Location: articles.php?success=' . urlencode('未找到该文章'));
    exit;
}

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
    // 表创建失败不影响其它逻辑，后续仅在表存在时写入权限
}

// 确保文章编辑记录表存在（用于前台判断是否为共创，保留）
try {
    $db->query("
        CREATE TABLE IF NOT EXISTS `article_edit_logs` (
            `article_id` int(11) NOT NULL COMMENT '文章ID',
            `user_id` int(11) NOT NULL COMMENT '编辑用户ID',
            `last_edited_at` datetime NOT NULL COMMENT '最后编辑时间',
            PRIMARY KEY (`article_id`, `user_id`),
            KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章编辑记录';
    ");
} catch (Exception $e) {
    // 表创建失败不影响其它逻辑，仅影响前台共创判断
}

// 确保文章贡献统计表存在（用于记录双方各自贡献了多少字）
try {
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
} catch (Exception $e) {
    // 表创建失败不影响其它逻辑，仅影响共创统计
}

// 确保文章段落归属表存在（用于精确记录每段文字归属谁）
try {
    $db->query("
        CREATE TABLE IF NOT EXISTS `article_segments` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `article_id` int(11) NOT NULL COMMENT '文章ID',
            `user_id` int(11) NOT NULL COMMENT '用户ID',
            `start_offset` int(11) NOT NULL COMMENT '起始字符位置（从0开始）',
            `length` int(11) NOT NULL COMMENT '该段字符长度',
            `created_at` datetime NOT NULL COMMENT '创建时间',
            `updated_at` datetime NOT NULL COMMENT '最后更新时间',
            PRIMARY KEY (`id`),
            KEY `article_id` (`article_id`),
            KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章文字归属段落';
    ");
} catch (Exception $e) {
    // 表创建失败不影响其它逻辑，仅影响精确统计
}

// 确保文章块表存在（用于按块记录每段 HTML 归属谁，便于后续共创展示）
try {
    $db->query("
        CREATE TABLE IF NOT EXISTS `article_blocks` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `article_id` int(11) NOT NULL COMMENT '文章ID',
            `block_index` int(11) NOT NULL COMMENT '块索引（从0开始）',
            `user_id` int(11) NOT NULL COMMENT '作者用户ID',
            `html` mediumtext NOT NULL COMMENT '该块的 HTML 内容',
            `created_at` datetime NOT NULL COMMENT '创建时间',
            `updated_at` datetime NOT NULL COMMENT '最后更新时间',
            PRIMARY KEY (`id`),
            KEY `article_id` (`article_id`),
            KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章块级内容归属';
    ");
} catch (Exception $e) {
    // 表创建失败不影响其它逻辑，仅影响块级展示
}

// 读取文章权限（若表不存在或查询失败，则默认允许另一半编辑）
$allowPartnerEdit = 1;
try {
    $permRow = $db->fetch(
        "SELECT allow_partner_edit FROM article_permissions WHERE article_id = :article_id",
        ['article_id' => $id]
    );
    if ($permRow) {
        $allowPartnerEdit = (int) $permRow['allow_partner_edit'];
    }
} catch (Exception $e) {
    $allowPartnerEdit = 1;
}

// 计算是否有权限编辑：创建者永远可编辑；另一半仅在允许编辑时可编辑
$isOwner       = isset($article['user_id']) && (int) $article['user_id'] === (int) $currentUser['id'];
$partnerId     = $partner['id'] ?? null;
$isPartnerUser = $partnerId && isset($article['user_id']) && (int) $article['user_id'] === (int) $partnerId;
$canEdit       = $isOwner || ($isPartnerUser && $allowPartnerEdit);

if (!$canEdit) {
    header('Location: articles.php?error=' . urlencode('你没有权限编辑这篇文章'));
    exit;
}

// 读取当前文章的男主 / 女主贡献字数，用于在后台给参与共创的人一个提示
$maleChars   = 0;
$femaleChars = 0;
$articleCoCreated = false;
try {
    // 仅在情侣双方信息齐全时统计
    if (!empty($currentUser) && !empty($partner)) {
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

        $rows = $db->fetchAll(
            "SELECT user_id, contributed_chars 
             FROM article_contributions 
             WHERE article_id = :article_id",
            ['article_id' => $id]
        );

        $stats = [];
        foreach ($rows as $row) {
            $stats[(int) $row['user_id']] = (int) $row['contributed_chars'];
        }

        // 通过 role 映射男主 / 女主
        $maleId   = null;
        $femaleId = null;
        if (!empty($currentUser['role']) && !empty($partner['role'])) {
            if ($currentUser['role'] === 'user1') {
                $maleId   = (int) $currentUser['id'];
                $femaleId = (int) $partner['id'];
            } else {
                $maleId   = (int) $partner['id'];
                $femaleId = (int) $currentUser['id'];
            }
        }

        if ($maleId) {
            $maleChars = $stats[$maleId] ?? 0;
        }
        if ($femaleId) {
            $femaleChars = $stats[$femaleId] ?? 0;
        }

        // 共创判定：双方各自贡献至少 10 字
        $threshold = 10;
        if ($maleChars >= $threshold && $femaleChars >= $threshold) {
            $articleCoCreated = true;
        }
    }
} catch (Exception $e) {
    $maleChars   = 0;
    $femaleChars = 0;
    $articleCoCreated = false;
}

// PRG 成功提示
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $success = '文章更新成功';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $title           = trim($_POST['title'] ?? '');
    $content         = trim($_POST['content'] ?? '');
    $type            = $_POST['type'] ?? 'article';
    $isEncrypted     = isset($_POST['is_encrypted']) ? 1 : 0;
    $tags            = trim($_POST['tags'] ?? '');
    $disableComments = isset($_POST['disable_comments']) ? 1 : 0;
    $postedEditMode  = $_POST['edit_mode'] ?? '';
    $postedEditMode  = in_array($postedEditMode, ['full','blocks','chat'], true) ? $postedEditMode : 'full';
    // 对话框列表模式下，使用块级内容重建全文；整篇/聊天模式则仅以上方已有内容或自动同步结果为准
    $useBlockEditor  = ($postedEditMode === 'blocks');
    $allowPartnerEditNew = $allowPartnerEdit;
    if ($isOwner) {
        $allowPartnerEditNew = isset($_POST['allow_partner_edit']) ? 1 : 0;
    }

    // 对话框模式下，使用块内容重建全文 HTML
    $blocksInput    = $useBlockEditor ? ($_POST['blocks'] ?? null) : null;
    $blocksForSave  = [];
    if (is_array($blocksInput)) {
        $coupleUsers   = get_couple_users();
        $maleUserRow   = $coupleUsers['user1'] ?? null;
        $femaleUserRow = $coupleUsers['user2'] ?? null;
        $maleId        = $maleUserRow ? (int) $maleUserRow['id'] : null;
        $femaleId      = $femaleUserRow ? (int) $femaleUserRow['id'] : null;

        foreach ($blocksInput as $idx => $blockRow) {
            $blockHtml = (string)($blockRow['html'] ?? '');
            // 先清理 wangEditor 外层容器（w-e-text-container 等），只保留正文 HTML
            $blockHtml = clean_wangeditor_html($blockHtml);
            // 再去掉多余的空段落
            $blockHtml = normalize_block_html_for_save($blockHtml);
            $blockHtml = trim($blockHtml);
            if ($blockHtml === '') {
                continue;
            }

            // 根据选择的身份（男主 / 女主 / 系统）推断用户与 speaker
            $identity     = isset($blockRow['user_id']) ? (string) $blockRow['user_id'] : '';
            $blockUserId  = 0;
            $blockSpeaker = null;

            if ($identity === 'male') {
                $blockSpeaker = 'male';
                $blockUserId  = $maleId ?? (int) ($article['user_id'] ?? $currentUser['id']);
            } elseif ($identity === 'female') {
                $blockSpeaker = 'female';
                $blockUserId  = $femaleId ?? (int) ($article['user_id'] ?? $currentUser['id']);
            } elseif ($identity === 'system') {
                $blockSpeaker = 'system';
                $blockUserId  = (int) ($article['user_id'] ?? $currentUser['id']);
            } else {
                // 兼容老表单：直接提交具体 user_id
                $blockUserId = isset($blockRow['user_id']) ? (int) $blockRow['user_id'] : 0;
            }

            $blocksForSave[] = [
                'user_id' => $blockUserId,
                'speaker' => $blockSpeaker,
                'html'    => $blockHtml,
            ];
        }

        if (!empty($blocksForSave)) {
            // 按块顺序拼接全文 HTML
            $rebuilt = '';
            foreach ($blocksForSave as $b) {
                $rebuilt .= $b['html'];
            }
            $content = trim($rebuilt);
        }
    }

    // 兜底：清理 wangEditor 可能带上的内部容器（w-e-text-container 等），只保留正文 HTML
    if ($content !== '') {
        $content = clean_wangeditor_html($content);
    }

    $requireContent = ($postedEditMode !== 'chat');
    if ($title === '' || ($requireContent && $content === '')) {
        $error = '请填写标题和内容';
    } else {
        $oldContent = (string) ($article['content'] ?? '');
        $data = [
            'title'            => $title,
            'type'             => $type,
            'is_encrypted'     => $isEncrypted,
            'comments_enabled' => $disableComments ? 0 : 1,
            'tags'             => $tags,
            'edit_mode'        => $postedEditMode,
            'updated_at'       => date('Y-m-d H:i:s'),
        ];
        // 非聊天模式下才直接更新 content，聊天模式使用自动同步接口维护全文内容
        if ($postedEditMode !== 'chat') {
            $data['content'] = $content;
        }

        // 非聊天模式下：在更新文章前，清理本次编辑中被移除且不再被引用的上传文件（图片 / 视频）
        if ($postedEditMode !== 'chat' && function_exists('extract_upload_paths_from_html')) {
            try {
                $oldPaths = extract_upload_paths_from_html($oldContent);
                $newPaths = extract_upload_paths_from_html($content);
                if (!empty($oldPaths)) {
                    $oldPaths = array_unique($oldPaths);
                }
                if (!empty($newPaths)) {
                    $newPaths = array_unique($newPaths);
                }
                $removedPaths = array_diff($oldPaths, $newPaths);
                if (!empty($removedPaths)) {
                    foreach ($removedPaths as $relPath) {
                        // 先按全局逻辑尝试删除不再被任何文章引用的上传文件
                        delete_upload_file_if_unused($relPath, $id);

                        // 若是当前文章下的视频文件，则额外清理 article_videos 记录及其封面图
                        try {
                            if (strpos($relPath, 'articles/') === 0 && strpos($relPath, '/videos/') !== false) {
                                $videoRows = $db->fetchAll(
                                    "SELECT id, poster_path, original_video_path 
                                     FROM article_videos 
                                     WHERE article_id = :article_id
                                       AND (video_path = :path OR original_video_path = :path)",
                                    [
                                        'article_id' => $id,
                                        'path'       => $relPath,
                                    ]
                                );

                                if ($videoRows) {
                                    foreach ($videoRows as $vRow) {
                                        $poster = trim((string) ($vRow['poster_path'] ?? ''));
                                        $orig   = trim((string) ($vRow['original_video_path'] ?? ''));

                                        if ($poster !== '') {
                                            deleteFile($poster);
                                        }
                                        // 原始文件通常在转码成功时已删除，这里再次尝试删除仅作兜底
                                        if ($orig !== '' && $orig !== $relPath) {
                                            deleteFile($orig);
                                        }

                                        $db->delete('article_videos', 'id = :id', ['id' => (int) $vRow['id']]);
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            // 忽略文章视频封面清理失败，不影响主流程
                        }
                    }
                }
            } catch (Exception $e) {
                // 忽略清理失败，不影响文章保存
            }
        }

        $db->update('articles', $data, 'id = :id', ['id' => $id]);

        // 同步更新文章权限（仅创建者可修改，若权限表不存在则静默忽略）
        if ($isOwner) {
            try {
                $db->query("
                    INSERT INTO article_permissions (article_id, allow_partner_edit, updated_at)
                    VALUES (:article_id, :allow_partner_edit, :updated_at)
                    ON DUPLICATE KEY UPDATE
                        allow_partner_edit = VALUES(allow_partner_edit),
                        updated_at = VALUES(updated_at)
                ", [
                    'article_id'        => $id,
                    'allow_partner_edit'=> $allowPartnerEditNew,
                    'updated_at'        => date('Y-m-d H:i:s'),
                ]);
            } catch (Exception $e) {
                // 忽略权限表写入失败
            }
        }

        // 非聊天模式才重建统计和块表；聊天模式下由聊天接口维护 article_blocks 和全文
        if ($postedEditMode !== 'chat') {
            // 基于 HTML 中的 data-author 标记，重建逐字级的段落归属与贡献统计
            try {
                $db->delete('article_segments', 'article_id = :article_id', ['article_id' => $id]);
                $db->delete('article_contributions', 'article_id = :article_id', ['article_id' => $id]);

                $contentHtml = (string) $content;
                if ($contentHtml !== '') {
                    // 计算当前情侣中的男主 / 女主用户 ID
                    $maleId   = null;
                    $femaleId = null;
                    if (!empty($currentUser) && !empty($partner) && !empty($currentUser['role']) && !empty($partner['role'])) {
                        if ($currentUser['role'] === 'user1') {
                            $maleId   = (int) $currentUser['id'];
                            $femaleId = (int) $partner['id'];
                        } elseif ($currentUser['role'] === 'user2') {
                            $maleId   = (int) $partner['id'];
                            $femaleId = (int) $currentUser['id'];
                        }
                    }

                    $segments = [];
                    $stats    = [];
                    $nowSeg   = date('Y-m-d H:i:s');
                    $offset   = 0;

                    if (class_exists('DOMDocument')) {
                        libxml_use_internal_errors(true);
                        $dom = new DOMDocument('1.0', 'UTF-8');
                        $htmlWrapped = '<div>' . $contentHtml . '</div>';
                        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $htmlWrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                        if ($loaded) {
                            $root = $dom->getElementsByTagName('div')->item(0);
                            if ($root) {
                                $walker = function ($node, $currentAuthor) use (&$walker, &$offset, &$segments, &$stats, $maleId, $femaleId) {
                                    if ($node->nodeType === XML_TEXT_NODE) {
                                        $text = $node->nodeValue;
                                        if ($text === '') {
                                            return;
                                        }
                                        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
                                        if ($length <= 0) {
                                            return;
                                        }
                                        $uid = null;
                                        if ($currentAuthor === 'male' && $maleId) {
                                            $uid = $maleId;
                                        } elseif ($currentAuthor === 'female' && $femaleId) {
                                            $uid = $femaleId;
                                        }
                                        if ($uid) {
                                            $segments[] = [
                                                'user_id'      => $uid,
                                                'start_offset' => $offset,
                                                'length'       => $length,
                                            ];
                                            if (!isset($stats[$uid])) {
                                                $stats[$uid] = 0;
                                            }
                                            $stats[$uid] += $length;
                                        }
                                        $offset += $length;
                                        return;
                                    }

                                    if ($node->nodeType === XML_ELEMENT_NODE) {
                                        $author = $currentAuthor;
                                        if ($node->hasAttribute('data-author')) {
                                            $val = $node->getAttribute('data-author');
                                            if ($val === 'male' || $val === 'female') {
                                                $author = $val;
                                            }
                                        }
                                        foreach ($node->childNodes as $child) {
                                            $walker($child, $author);
                                        }
                                    }
                                };

                                $walker($root, '');
                            }
                        }
                        libxml_clear_errors();
                    }

                    if (!empty($segments)) {
                        // 基于 data-author 的逐字级统计（原有逻辑）
                        usort($segments, function ($a, $b) {
                            if ($a['start_offset'] === $b['start_offset']) {
                                return 0;
                            }
                            return ($a['start_offset'] < $b['start_offset']) ? -1 : 1;
                        });

                        $merged = [];
                        foreach ($segments as $seg) {
                            if ($seg['length'] <= 0) {
                                continue;
                            }
                            if (empty($merged)) {
                                $merged[] = $seg;
                                continue;
                            }
                            $lastIndex = count($merged) - 1;
                            $last      = $merged[$lastIndex];
                            if ($last['user_id'] === $seg['user_id']
                                && ($last['start_offset'] + $last['length']) === $seg['start_offset']) {
                                $merged[$lastIndex]['length'] += $seg['length'];
                            } else {
                                $merged[] = $seg;
                            }
                        }

                        foreach ($merged as $seg) {
                            $db->query("
                                INSERT INTO article_segments (article_id, user_id, start_offset, length, created_at, updated_at)
                                VALUES (:article_id, :user_id, :start_offset, :length, :created_at, :updated_at)
                            ", [
                                'article_id'   => $id,
                                'user_id'      => $seg['user_id'],
                                'start_offset' => $seg['start_offset'],
                                'length'       => $seg['length'],
                                'created_at'   => $nowSeg,
                                'updated_at'   => $nowSeg,
                            ]);
                        }

                        foreach ($stats as $uid => $chars) {
                            $db->query("
                                INSERT INTO article_contributions (article_id, user_id, contributed_chars, last_updated_at)
                                VALUES (:article_id, :user_id, :contributed_chars, :last_updated_at)
                                ON DUPLICATE KEY UPDATE
                                    contributed_chars = VALUES(contributed_chars),
                                    last_updated_at = VALUES(last_updated_at)
                            ", [
                                'article_id'        => $id,
                                'user_id'           => $uid,
                                'contributed_chars' => $chars,
                                'last_updated_at'   => $nowSeg,
                            ]);
                        }
                    } elseif (!empty($blocksForSave)) {
                        // 若正文中没有任何 data-author 片段，则回退为基于块级作者的粗略统计，
                        // 用于启用块级编辑时的“共创”判断
                        $blockStats = [];
                        foreach ($blocksForSave as $b) {
                            $uid      = (int) ($b['user_id'] ?? 0);
                            $speakerB = $b['speaker'] ?? null;
                            // 系统身份或无有效用户的块不计入双方贡献
                            if ($speakerB === 'system' || $uid <= 0) {
                                continue;
                            }
                            $html  = (string) ($b['html'] ?? '');
                            if ($html === '') {
                                continue;
                            }
                            // 粗略计算该块的字符数：去掉 HTML 标签和实体
                            $plain = strip_tags($html);
                            $plain = html_entity_decode($plain, ENT_QUOTES, 'UTF-8');
                            $plain = trim($plain);
                            if ($plain === '') {
                                continue;
                            }
                            $len = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);
                            if ($len <= 0) {
                                continue;
                            }
                            if (!isset($blockStats[$uid])) {
                                $blockStats[$uid] = 0;
                            }
                            $blockStats[$uid] += $len;
                        }

                        foreach ($blockStats as $uid => $chars) {
                            $db->query("
                                INSERT INTO article_contributions (article_id, user_id, contributed_chars, last_updated_at)
                                VALUES (:article_id, :user_id, :contributed_chars, :last_updated_at)
                                ON DUPLICATE KEY UPDATE
                                    contributed_chars = VALUES(contributed_chars),
                                    last_updated_at = VALUES(last_updated_at)
                            ", [
                                'article_id'        => $id,
                                'user_id'           => $uid,
                                'contributed_chars' => $chars,
                                'last_updated_at'   => $nowSeg,
                            ]);
                        }
                    }
                }

                // 记录编辑日志（保留）
                $db->query("
                    INSERT INTO article_edit_logs (article_id, user_id, last_edited_at)
                    VALUES (:article_id, :user_id, :last_edited_at)
                    ON DUPLICATE KEY UPDATE
                        last_edited_at = VALUES(last_edited_at)
                ", [
                    'article_id'     => $id,
                    'user_id'        => $currentUser['id'],
                    'last_edited_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (Exception $e) {
                // 忽略统计失败，不影响文章保存
            }

            // 更新文章块表：当前阶段以块编辑器提交的块为准；若未使用块编辑器，则整篇作为单块归属文章创建者
            try {
                $nowBlock    = date('Y-m-d H:i:s');
                $coupleUsers = get_couple_users();
                $db->delete('article_blocks', 'article_id = :article_id', ['article_id' => $id]);
                if (!empty($blocksForSave)) {
                    $blockIndex = 0;
                    foreach ($blocksForSave as $b) {
                        $htmlBlock = (string) ($b['html'] ?? '');
                        if ($htmlBlock === '') {
                            continue;
                        }
                        $blockUserId = (int) ($b['user_id'] ?? 0);
                        $speaker     = $b['speaker'] ?? null;
                        if ($speaker === 'system') {
                            if ($blockUserId <= 0) {
                                $blockUserId = (int) ($article['user_id'] ?? $currentUser['id']);
                            }
                        } else {
                            if ($blockUserId <= 0) {
                                $blockUserId = (int) ($article['user_id'] ?? $currentUser['id']);
                            }
                            // 若未显式指定 speaker，则根据 user_id 推断男主 / 女主
                            if ($speaker === null) {
                                if (!empty($coupleUsers['user1']) && (int)$blockUserId === (int)$coupleUsers['user1']['id']) {
                                    $speaker = 'male';
                                } elseif (!empty($coupleUsers['user2']) && (int)$blockUserId === (int)$coupleUsers['user2']['id']) {
                                    $speaker = 'female';
                                }
                            }
                        }

                        $db->query("
                            INSERT INTO article_blocks (article_id, block_index, user_id, speaker, html, created_at, updated_at)
                            VALUES (:article_id, :block_index, :user_id, :speaker, :html, :created_at, :updated_at)
                        ", [
                            'article_id'  => $id,
                            'block_index' => $blockIndex,
                            'user_id'     => $blockUserId,
                            'speaker'     => $speaker,
                            'html'        => $htmlBlock,
                            'created_at'  => $nowBlock,
                            'updated_at'  => $nowBlock,
                        ]);
                        $blockIndex++;
                    }
                    if ($blockIndex === 0 && $content !== '') {
                        $db->query("
                            INSERT INTO article_blocks (article_id, block_index, user_id, speaker, html, created_at, updated_at)
                            VALUES (:article_id, :block_index, :user_id, :speaker, :html, :created_at, :updated_at)
                        ", [
                            'article_id'  => $id,
                            'block_index' => 0,
                            'user_id'     => (int) ($article['user_id'] ?? $currentUser['id']),
                            'speaker'     => null,
                            'html'        => (string) $content,
                            'created_at'  => $nowBlock,
                            'updated_at'  => $nowBlock,
                        ]);
                    }
                } else {
                    $db->query("
                        INSERT INTO article_blocks (article_id, block_index, user_id, speaker, html, created_at, updated_at)
                        VALUES (:article_id, :block_index, :user_id, :speaker, :html, :created_at, :updated_at)
                    ", [
                        'article_id'  => $id,
                        'block_index' => 0,
                        'user_id'     => (int) ($article['user_id'] ?? $currentUser['id']),
                        'speaker'     => null,
                        'html'        => (string) $content,
                        'created_at'  => $nowBlock,
                        'updated_at'  => $nowBlock,
                    ]);
                }
            } catch (Exception $e) {
                // 忽略块级记录失败，不影响文章保存
            }
        }

        header('Location: article_edit.php?id=' . $id . '&success=1');
        exit;
    }

    // 有错误时更新 $article 用于回显
    $article['title']            = $title;
    $article['content']          = $content;
    $article['type']             = $type;
    $article['is_encrypted']     = $isEncrypted;
    $article['comments_enabled'] = $disableComments ? 0 : 1;
    $article['tags']             = $tags;
    $article['edit_mode']        = $postedEditMode;
}

$adminPage = 'articles';

include __DIR__ . '/header.php';
?>

    <script>
        // 块编辑器：增加 / 删除块 + 初始化 wangEditor（仅在编辑页面中使用）
    window.setupCoBlockEditors = function () {
        var form   = document.querySelector('form.admin-card');
        var addBtn = document.getElementById('co-block-add');
        if (!form) return;

        function initBlockEditor(item) {
            if (!item || item._weEditor) return;

            var textarea = item.querySelector('textarea[name^="blocks["]');
            var toolbar  = item.querySelector('.co-block-toolbar');
            var editorEl = item.querySelector('.co-block-editor');
            if (!textarea || !toolbar || !editorEl) return;

            // 与正文保持一致：使用 toolbar + editor 容器模式
            var E = window.wangEditor;
            if (!E) return;
            var editor = new E(toolbar, editorEl);
            editor.config.zIndex = 5;
            // 对话框编辑器：仅保留插入图片 / 视频的工具按钮
            editor.config.menus = ['image', 'video'];
            // 禁用「网络图片」入口，避免因外链受限导致失败
            editor.config.showLinkImg = false;
            // 块级编辑器上传配置（与主编辑器保持一致）
            if (typeof WANG_CSRF_TOKEN !== 'undefined') {
                editor.config.uploadImgServer = '/api/upload_image.php';
                editor.config.uploadImgParams = {
                    _token: WANG_CSRF_TOKEN,
                    article_id: '<?php echo (int)$id; ?>'
                };
                editor.config.uploadVideoServer = '/api/upload_video.php';
                editor.config.uploadVideoParams = {
                    _token: WANG_CSRF_TOKEN,
                    article_id: '<?php echo (int)$id; ?>'
                };
                editor.config.uploadVideoHooks = {
                    customInsert: function (insertVideoFn, result) {
                        try {
                            if (!result) return;
                            if (typeof result.errno !== 'undefined' && result.errno !== 0) {
                                var errMsg = result.message || '视频上传失败，请稍后重试';
                                window.showToast(errMsg, 'error');
                                return;
                            }
                            var url = '';
                            if (result.data) {
                                if (Array.isArray(result.data) && result.data.length > 0) {
                                    url = result.data[0];
                                } else if (typeof result.data === 'object' && result.data.url) {
                                    url = result.data.url;
                                }
                            }
                            if (url) {
                                insertVideoFn(url);
                            }
                        } catch (e) {}
                    },
                    fail: function (xhr, editor, res) {
                        try {
                            var msg = (res && res.message) ? res.message : '';
                            if (!msg && xhr && xhr.responseText) {
                                try {
                                    var parsed = JSON.parse(xhr.responseText);
                                    if (parsed && parsed.message) {
                                        msg = parsed.message;
                                    }
                                } catch (e) {}
                            }
                            if (!msg) {
                                msg = '视频上传失败。可能是文件过大：可以在“系统设置 → 上传与其他 → 单文件最大上传大小（MB）”中调整，或开启“视频上传仅受服务器限制”开关；也可能是服务器上传大小限制导致，请压缩后重试或联系管理员。';
                            }
                            window.showToast(msg, 'error');
                        } catch (e) {}
                    },
                    error: function (xhr, editor, res) {
                        try {
                            var msg = (res && res.message) ? res.message : '';
                            if (!msg && xhr && xhr.responseText) {
                                try {
                                    var parsed = JSON.parse(xhr.responseText);
                                    if (parsed && parsed.message) {
                                        msg = parsed.message;
                                    }
                                } catch (e) {}
                            }
                            if (!msg) {
                                msg = '视频上传失败。可能是文件过大：可以在“系统设置 → 上传与其他 → 单文件最大上传大小（MB）”中调整，或开启“视频上传仅受服务器限制”开关；也可能是服务器上传大小限制导致，请压缩后重试或联系管理员。';
                            }
                            window.showToast(msg, 'error');
                        } catch (e) {}
                    }
                };
            }
            editor.config.onchange = function (html) {
                textarea.value = html;
            };
            editor.create();
            editor.txt.html(textarea.value || editorEl.innerHTML || '');

            // 兼容性处理：仅在点击空白区域时，将光标移动到内容末尾；
            // 如果点击的是已有文字或元素，则交给浏览器默认行为
            editorEl.addEventListener('click', function (e) {
                try {
                    if (e.target && e.target.closest('.w-e-text')) {
                        return;
                    }
                    var textElem = editorEl.querySelector('.w-e-text');
                    if (!textElem) return;
                    var range = document.createRange();
                    range.selectNodeContents(textElem);
                    range.collapse(false);
                    var sel = window.getSelection();
                    sel.removeAllRanges();
                    sel.addRange(range);
                } catch (e) {}
            });

            item._weEditor = editor;
        }

        // 初始化已有块的编辑器
        Array.prototype.forEach.call(
            form.querySelectorAll('.co-block-editor-item'),
            function (item) { initBlockEditor(item); }
        );

        // 工具：获取当前所有对话框项
        function getBlockItems() {
            return Array.prototype.slice.call(form.querySelectorAll('.co-block-editor-item'));
        }

        // 根据当前顺序重排索引与字段 name
        function renumberBlocks() {
            var items = getBlockItems();
            items.forEach(function (item, index) {
                item.setAttribute('data-block-index', String(index));
                var labelSpan = item.querySelector('.co-block-drag-handle + span');
                if (labelSpan) {
                    labelSpan.textContent = '对话框 #' + index;
                }
                var select = item.querySelector('select[name^="blocks["]');
                var textarea = item.querySelector('textarea[name^="blocks["]');
                if (select) {
                    select.name = 'blocks[' + index + '][user_id]';
                }
                if (textarea) {
                    textarea.name = 'blocks[' + index + '][html]';
                }
            });
        }

        // 自定义拖拽：让对话框跟随鼠标移动
        var draggingItem = null;
        var placeholder = null;
        var dragStartY = 0;
        var dragOffsetY = 0;
        var listRect = null;

        function onMouseMove(e) {
            if (!draggingItem) return;
            e.preventDefault();
            dragOffsetY = e.clientY - dragStartY;
            draggingItem.style.transform = 'translateY(' + dragOffsetY + 'px)';

            var items = getBlockItems().filter(function (el) { return el !== draggingItem; });
            var currentTop = draggingItem.getBoundingClientRect().top + draggingItem.offsetHeight / 2;
            var target = null;
            for (var i = 0; i < items.length; i++) {
                var rect = items[i].getBoundingClientRect();
                var mid = rect.top + rect.height / 2;
                if (currentTop < mid) {
                    target = items[i];
                    break;
                }
            }
            var parent = draggingItem.parentNode;
            if (!parent || !placeholder) return;
            var helpNode = parent.querySelector('.co-block-help');
            if (target && placeholder.parentNode === parent) {
                parent.insertBefore(placeholder, target);
            } else if (!target && placeholder.parentNode === parent) {
                // 当拖到列表底部时，将占位块插入到说明文字之前，避免跑到说明和按钮下面
                if (helpNode && helpNode.parentNode === parent) {
                    parent.insertBefore(placeholder, helpNode);
                } else if (addBtn && addBtn.parentNode === parent) {
                    parent.insertBefore(placeholder, addBtn);
                } else {
                    parent.appendChild(placeholder);
                }
            }
        }

        function onMouseUp() {
            if (!draggingItem) return;
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);

            draggingItem.style.position = '';
            draggingItem.style.left = '';
            draggingItem.style.top = '';
            draggingItem.style.width = '';
            draggingItem.style.zIndex = '';
            draggingItem.style.transform = '';
            draggingItem.classList.remove('co-block-dragging');

            if (placeholder && placeholder.parentNode) {
                placeholder.parentNode.insertBefore(draggingItem, placeholder);
                placeholder.parentNode.removeChild(placeholder);
            }
            placeholder = null;
            draggingItem = null;

            renumberBlocks();
        }

        form.addEventListener('mousedown', function (e) {
            var handle = e.target.closest('.co-block-drag-handle');
            if (!handle) return;
            var item = handle.closest('.co-block-editor-item');
            if (!item) return;
            e.preventDefault();

            draggingItem = item;
            dragStartY = e.clientY;
            dragOffsetY = 0;

            var rect = item.getBoundingClientRect();
            // 确保父容器作为定位参考系
            var parent = item.parentNode;
            var parentStyle = window.getComputedStyle(parent);
            if (parentStyle.position === 'static') {
                parent.style.position = 'relative';
            }
            listRect = parent.getBoundingClientRect();

            placeholder = document.createElement('div');
            placeholder.className = 'co-block-placeholder';
            placeholder.style.height = rect.height + 'px';
            placeholder.style.marginBottom = getComputedStyle(item).marginBottom;
            item.parentNode.insertBefore(placeholder, item.nextSibling);

            item.classList.add('co-block-dragging');
            item.style.position = 'absolute';
            item.style.left = (rect.left - listRect.left) + 'px';
            item.style.top = (rect.top - listRect.top) + 'px';
            item.style.width = rect.width + 'px';
            item.style.zIndex = '20';

            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        });

        function getCurrentMaxIndex() {
            var items = form.querySelectorAll('.co-block-editor-item');
            var max = -1;
            items.forEach(function (el) {
                var idx = parseInt(el.getAttribute('data-block-index') || '-1', 10);
                if (!isNaN(idx) && idx > max) max = idx;
            });
            return max;
        }

        if (addBtn) {
            addBtn.addEventListener('click', function () {
                var items = form.querySelectorAll('.co-block-editor-item');
                if (!items.length) return;
                var last = items[items.length - 1];

                var newIndex = getCurrentMaxIndex() + 1;

                // 新建块容器，而不是克隆已有编辑器内部结构，避免 wangEditor 事件和 DOM 冲突
                var item = document.createElement('div');
                item.className = 'co-block-editor-item';
                item.setAttribute('data-block-index', String(newIndex));
                item.style.border = '1px solid rgba(148,163,184,0.45)';
                item.style.borderRadius = '0.75rem';
                item.style.padding = '0.45rem 0.6rem';
                item.style.marginBottom = '0.5rem';

                // 头部（拖动手柄 + 标题 + 身份选择 + 删除按钮）克隆自最后一个块的头部
                var lastHeader = last.querySelector('div');
                var header = lastHeader ? lastHeader.cloneNode(true) : document.createElement('div');
                header.style.marginBottom = '0.35rem';

                // 更新标题
                var titleSpan = header.querySelector('.co-block-drag-handle + span');
                if (titleSpan) {
                    titleSpan.textContent = '对话框 #' + newIndex;
                }

                // 更新作者选择 name
                var select = header.querySelector('select[name^="blocks["]');
                if (select) {
                    select.name = 'blocks[' + newIndex + '][user_id]';
                }

                item.appendChild(header);

                // 隐藏 textarea（由块级 wangEditor 同步 HTML）
                var lastTextarea = last.querySelector('textarea[name^="blocks["]');
                var textarea = lastTextarea ? lastTextarea.cloneNode(false) : document.createElement('textarea');
                textarea.name = 'blocks[' + newIndex + '][html]';
                textarea.className = 'co-block-textarea';
                textarea.style.display = 'none';
                textarea.value = '';
                item.appendChild(textarea);

                // 编辑器容器（由 wangEditor 接管）
                var wrapper = document.createElement('div');
                wrapper.className = 'co-block-editor-wrapper';
                wrapper.style.borderRadius = '0.65rem';
                wrapper.style.border = '1px solid rgba(148,163,184,0.7)';
                wrapper.style.background = '#ffffff';
                wrapper.style.overflow = 'visible';
                wrapper.style.marginTop = '0.35rem';

                var toolbar = document.createElement('div');
                toolbar.className = 'co-block-toolbar';
                var editorEl = document.createElement('div');
                editorEl.className = 'co-block-editor';
                editorEl.style.minHeight = '120px';

                wrapper.appendChild(toolbar);
                wrapper.appendChild(editorEl);
                item.appendChild(wrapper);

                last.parentNode.insertBefore(item, addBtn);

                // 身份选择：默认根据当前登录者角色自动选择男主 / 女主
                var select = item.querySelector('select[name^="blocks["]');
                if (select && window.CO_CURRENT_AUTHOR_ROLE) {
                    if (window.CO_CURRENT_AUTHOR_ROLE === 'male' || window.CO_CURRENT_AUTHOR_ROLE === 'female') {
                        select.value = window.CO_CURRENT_AUTHOR_ROLE;
                    }
                }

                // 初始化新块的 wangEditor
                initBlockEditor(item);
            });
        }

        // 删除块（事件委托）
        form.addEventListener('click', function (e) {
            var target = e.target;
            if (!(target instanceof HTMLElement)) return;
            if (!target.classList.contains('co-block-remove')) return;

            var item = target.closest('.co-block-editor-item');
            if (!item) return;

            var items = form.querySelectorAll('.co-block-editor-item');
            if (items.length <= 1) {
                // 保留至少一个块，避免完全为空
                var textarea = item.querySelector('textarea[name^="blocks["]');
                if (textarea) {
                    textarea.value = '';
                }
                return;
            }

            // 销毁 wangEditor 实例
            if (item._weEditor && typeof item._weEditor.destroy === 'function') {
                item._weEditor.destroy();
            }

            item.remove();
        });
    };
    </script>

    <section class="admin-page-title">
        <h1>编辑文章</h1>
        <p>修改已经发布的内容</p>
    </section>

    <?php
    $showContribCard = false;
    if (!empty($allowPartnerEdit) && ($maleChars > 0 || $femaleChars > 0)) {
        // 开启共创编辑：只要有人有贡献，就显示提示
        $showContribCard = true;
    } elseif (empty($allowPartnerEdit) && $articleCoCreated) {
        // 关闭共创编辑但已经形成共创内容：给一个特别提醒
        $showContribCard = true;
    }
    ?>

    <?php if ($showContribCard): ?>
        <div class="admin-card" style="margin-bottom:0.75rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;font-size:0.85rem;color:var(--text-light);">
                <div>
                    <div style="margin-bottom:0.25rem;">当前这篇文章的共创贡献统计：</div>
                    <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                        <?php if ($maleChars > 0): ?>
                            <span class="badge-role badge-male" style="font-size:0.7rem;padding:0.12rem 0.6rem;">
                                男主 <?php echo $maleChars; ?> 字
                            </span>
                        <?php endif; ?>
                        <?php if ($femaleChars > 0): ?>
                            <span class="badge-role badge-female" style="font-size:0.7rem;padding:0.12rem 0.6rem;">
                                女主 <?php echo $femaleChars; ?> 字
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="font-size:0.78rem;color:#9ca3af;text-align:right;">
                    <?php if (!empty($allowPartnerEdit)): ?>
                        双方各自达到 <strong>10 字</strong> 后，<br>前台会显示共创标签、双头像和彩蛋背景。
                    <?php elseif ($articleCoCreated): ?>
                        当前文章已关闭共创编辑，<br>但男主和女主都曾为这篇文章写下内容，前台仍会展示为共创作品。
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

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
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">标题 *</label>
            <input
                type="text"
                name="title"
                value="<?php echo e($article['title']); ?>"
                style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;">
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">类型</label>
            <select
                name="type"
                style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;">
                <option value="article" <?php echo $article['type'] === 'article' ? 'selected' : ''; ?>>文章</option>
                <option value="diary" <?php echo $article['type'] === 'diary' ? 'selected' : ''; ?>>日记</option>
            </select>
        </div>

        <?php
        $currentEditMode = isset($article['edit_mode']) ? $article['edit_mode'] : 'full';
        ?>
        <div class="form-group" id="editModeToggleRow" style="margin-bottom:0.9rem;padding:0.6rem 0.8rem;border-radius:0.9rem;border:1px solid rgba(148,163,184,0.55);background:linear-gradient(135deg, rgba(248,250,252,0.96), rgba(239,246,255,0.9));">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                <div>
                    <div style="font-size:0.85rem;font-weight:600;color:#0f172a;margin-bottom:0.15rem;">
                        编辑模式
                    </div>
                    <div style="font-size:0.78rem;color:var(--text-light);max-width:19rem;">
                        在这里选择是使用「整篇编辑」还是「对话框模式」。仅当选择对话框模式时，下方的对话框内容才会参与保存与前台展示。
                    </div>
                </div>
                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                    <label style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.25rem 0.7rem;border-radius:999px;border:1px solid rgba(148,163,184,0.8);background:#ffffff;font-size:0.8rem;cursor:pointer;">
                        <input
                            type="radio"
                            name="edit_mode"
                            value="full"
                            style="margin:0;"
                            <?php echo $currentEditMode === 'full' ? 'checked' : ''; ?>>
                        <span>整篇编辑（主编辑器）</span>
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.25rem 0.7rem;border-radius:999px;border:1px solid rgba(129,140,248,0.9);background:rgba(239,246,255,0.95);font-size:0.8rem;cursor:pointer;">
                        <input
                            type="radio"
                            name="edit_mode"
                            value="blocks"
                            style="margin:0;"
                            <?php echo $currentEditMode === 'blocks' ? 'checked' : ''; ?>>
                        <span>对话框模式（气泡对话）</span>
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.25rem 0.7rem;border-radius:999px;border:1px solid rgba(16,185,129,0.9);background:rgba(209,250,229,0.95);font-size:0.8rem;cursor:pointer;">
                        <input
                            type="radio"
                            name="edit_mode"
                            value="chat"
                            style="margin:0;"
                            <?php echo $currentEditMode === 'chat' ? 'checked' : ''; ?>>
                        <span>对话创作模式（聊天输入）</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="form-group" id="fullEditorSection" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">内容 *（富文本编辑，自动插入 HTML）</label>

            <?php
            // 计算当前情侣中的男主 / 女主，用于标记按钮和统计
            $maleUser   = null;
            $femaleUser = null;
            $currentAuthorRoleKey = '';
            if (!empty($currentUser) && !empty($partner) && !empty($currentUser['role']) && !empty($partner['role'])) {
                if ($currentUser['role'] === 'user1') {
                    $maleUser   = $currentUser;
                    $femaleUser = $partner;
                } elseif ($currentUser['role'] === 'user2') {
                    $maleUser   = $partner;
                    $femaleUser = $currentUser;
                }
                if ($maleUser && (int)$maleUser['id'] === (int)$currentUser['id']) {
                    $currentAuthorRoleKey = 'male';
                } elseif ($femaleUser && (int)$femaleUser['id'] === (int)$currentUser['id']) {
                    $currentAuthorRoleKey = 'female';
                }
            }
            $initialContent = $article['content'];
            ?>

            <div class="md-toolbar" id="mdToolbar">
                <div class="md-toolbar-right">
                    <button type="button" id="editorModeVisual" class="editor-mode-btn active">
                        <i class="fas fa-eye"></i> 可视化
                    </button>
                    <button type="button" id="editorModeCode" class="editor-mode-btn">
                        <i class="fas fa-code"></i> 代码
                    </button>
                </div>
            </div>

            <!-- 代码模式下的快捷插入按钮（如 H2/H3/H4 标题模板、引用块变体、代码块、下载按钮） -->
            <div class="code-snippet-toolbar" id="codeSnippetToolbar" style="display:none;">
                <button type="button" data-snippet="h2">H2 标题</button>
                <button type="button" data-snippet="h3">H3 标题</button>
                <button type="button" data-snippet="h4">H4 标题</button>
                <button type="button" data-snippet="blockquote">普通引用</button>
                <button type="button" data-snippet="blockquote-note">提示块</button>
                <button type="button" data-snippet="blockquote-warning">警告块</button>
                <button type="button" data-snippet="blockquote-success">成功块</button>
                <button type="button" data-snippet="code">代码块</button>
                <button type="button" data-snippet="download">下载按钮</button>
            </div>

            <!-- 男女主标记快捷按钮（独立于 wangEditor 菜单） -->
            <div class="author-mark-toolbar">
                <button type="button" id="btnMarkMale">标记为男主</button>
                <button type="button" id="btnMarkFemale">标记为女主</button>
                <button type="button" id="btnUnmarkAuthor">取消标记</button>
            </div>
            <!-- 隐藏 textarea，提交前由 JS 从富文本编辑器同步 HTML -->
            <textarea
                name="content"
                id="articleContent"
                style="display:none;"><?php echo e($initialContent); ?></textarea>

            <!-- 可视化编辑器（wangEditor：上方为菜单，下方为正文） -->
            <div id="articleEditorWrapper" style="width:100%;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);overflow:visible;background:#ffffff;">
                <div id="editorToolbar"></div>
                <div id="articleEditor" style="min-height:260px;"><?php echo $initialContent; ?></div>
            </div>
            
            <!-- 代码编辑器 -->
            <textarea
                id="articleCodeEditor"
                style="display:none;width:100%;min-height:260px;padding:0.65rem 0.8rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.85rem;line-height:1.5;background:#1e293b;color:#e2e8f0;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;resize:vertical;overflow:auto;"><?php echo e($initialContent); ?></textarea>
        </div>

        <?php
        // 块编辑器：当前简单实现为若干 HTML 块 + 身份选择
        $blocksForEdit = [];
        try {
            $blocksForEdit = $db->fetchAll(
                "SELECT b.id, b.block_index, b.user_id, b.html, b.speaker, u.role, u.nickname 
                 FROM article_blocks b
                 LEFT JOIN users u ON b.user_id = u.id
                 WHERE b.article_id = :article_id
                 ORDER BY b.block_index ASC",
                ['article_id' => $id]
            );
        } catch (Exception $e) {
            $blocksForEdit = [];
        }
        if (empty($blocksForEdit)) {
            // 初始对话框：优先使用当前登录者作为作者身份
            $defaultSpeaker = '';
            if (!empty($currentUser['role'])) {
                if ($currentUser['role'] === 'user1') {
                    $defaultSpeaker = 'male';
                } elseif ($currentUser['role'] === 'user2') {
                    $defaultSpeaker = 'female';
                }
            }
            $blocksForEdit[] = [
                'block_index' => 0,
                'user_id'     => $currentUser['id'],
                'html'        => $article['content'],
                'role'        => $currentUser['role'] ?? '',
                'nickname'    => $currentUser['nickname'] ?? '',
                'speaker'     => $defaultSpeaker,
            ];
        }

        // 允许的身份选项：男主 / 女主 / 系统
        $authorOptions = [];
        $coupleUsers   = get_couple_users();
        $maleUserRow   = $coupleUsers['user1'] ?? null;
        $femaleUserRow = $coupleUsers['user2'] ?? null;
        if ($maleUserRow) {
            $authorOptions[] = [
                'value'   => 'male',
                'text'    => '男主（' . ($maleUserRow['nickname'] ?? '无名氏') . '）',
            ];
        }
        if ($femaleUserRow) {
            $authorOptions[] = [
                'value'   => 'female',
                'text'    => '女主（' . ($femaleUserRow['nickname'] ?? '无名氏') . '）',
            ];
        }
        // 系统身份
        $authorOptions[] = [
            'value' => 'system',
            'text'  => '系统',
        ];
        // 聊天模式下的身份按钮标签：根据当前登录身份自动标注“我”
        $chatMaleLabel   = '男主';
        $chatFemaleLabel = '女主';
        if ($currentAuthorRoleKey === 'male') {
            $chatMaleLabel = '男主（我）';
            if ($femaleUserRow) {
                $chatFemaleLabel = '女主（' . ($femaleUserRow['nickname'] ?? 'Ta') . '）';
            }
        } elseif ($currentAuthorRoleKey === 'female') {
            $chatFemaleLabel = '女主（我）';
            if ($maleUserRow) {
                $chatMaleLabel = '男主（' . ($maleUserRow['nickname'] ?? 'Ta') . '）';
            }
        } else {
            if ($maleUserRow) {
                $chatMaleLabel = '男主（' . ($maleUserRow['nickname'] ?? '用户1') . '）';
            }
            if ($femaleUserRow) {
                $chatFemaleLabel = '女主（' . ($femaleUserRow['nickname'] ?? '用户2') . '）';
            }
        }
        ?>

        <div class="form-group" id="dialogEditorSection" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">对话框内容（块级编辑）</label>
            <p style="margin:0 0 0.4rem;font-size:0.78rem;color:var(--text-light);">
                将文章拆分成多个「对话框」，并为每个对话框指定主要作者。选择对话框模式时，保存会以这些对话框内容重建全文。
            </p>
            <?php foreach ($blocksForEdit as $idx => $blk): ?>
                <div
                    class="co-block-editor-item"
                    data-block-index="<?php echo (int) $idx; ?>"
                    data-block-id="<?php echo isset($blk['id']) ? (int)$blk['id'] : 0; ?>"
                    style="border:1px solid rgba(148,163,184,0.45);border-radius:0.75rem;padding:0.45rem 0.6rem;margin-bottom:0.5rem;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.35rem;gap:0.5rem;">
                        <div style="display:flex;align-items:center;gap:0.35rem;">
                            <span class="co-block-drag-handle" draggable="true" title="拖动以调整对话顺序" style="cursor:move;font-size:0.9rem;color:#94a3b8;">☰</span>
                            <span style="font-size:0.8rem;color:var(--text-light);">
                                对话框 #<?php echo (int) $idx; ?>
                            </span>
                        </div>
                        <select
                            name="blocks[<?php echo (int) $idx; ?>][user_id]"
                            style="font-size:0.8rem;padding:0.12rem 0.5rem;border-radius:0.5rem;border:1px solid rgba(148,163,184,0.7);">
                            <?php
                            $currentSpeaker = $blk['speaker'] ?? '';
                            // 兼容老数据：若未显式记录 speaker，则通过 role 推断
                            if ($currentSpeaker === '' && !empty($blk['role'])) {
                                if ($blk['role'] === 'user1') {
                                    $currentSpeaker = 'male';
                                } elseif ($blk['role'] === 'user2') {
                                    $currentSpeaker = 'female';
                                }
                            }
                            foreach ($authorOptions as $opt):
                                $selected = ($currentSpeaker === $opt['value']) ? 'selected' : '';
                            ?>
                                <option value="<?php echo e($opt['value']); ?>" <?php echo $selected; ?>>
                                    <?php echo e($opt['text']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button
                            type="button"
                            class="co-block-remove"
                            style="margin-left:0.5rem;font-size:0.75rem;padding:0.1rem 0.4rem;border-radius:999px;border:1px solid rgba(248,113,113,0.6);background:rgba(248,113,113,0.05);color:#b91c1c;">
                            删除块
                        </button>
                    </div>
                    <!-- 隐藏 textarea，由块级 wangEditor 在变更时同步 HTML -->
                    <textarea
                        name="blocks[<?php echo (int) $idx; ?>][html]"
                        class="co-block-textarea"
                        style="display:none;"><?php echo $blk['html']; ?></textarea>

                    <div class="co-block-editor-wrapper" style="border-radius:0.65rem;border:1px solid rgba(148,163,184,0.7);background:#ffffff;overflow:visible;margin-top:0.35rem;">
                        <div class="co-block-toolbar"></div>
                        <div class="co-block-editor" style="min-height:120px;"><?php echo $blk['html']; ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
            <p class="co-block-help" style="margin:0;font-size:0.78rem;color:var(--text-light);">
                可以在上方拆分为多个块并指定作者。删除所有块时，系统会退回整篇内容视为一个块。
            </p>
            <button
                type="button"
                id="co-block-add"
                style="margin-top:0.35rem;font-size:0.8rem;padding:0.18rem 0.7rem;border-radius:999px;border:1px solid rgba(59,130,246,0.7);background:rgba(59,130,246,0.06);color:#1d4ed8;">
                新增一个对话框
            </button>
        </div>

        <div class="form-group" id="chatEditorSection" style="margin-bottom:0.75rem;display:none;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">对话创作模式（聊天输入）</label>
            <p style="margin:0 0 0.4rem;font-size:0.78rem;color:var(--text-light);">
                在这里像聊天一样发送每一句话，系统会自动拼成对话块并保存，无需手动点击保存按钮。
            </p>
            <div id="chatMessages" style="border-radius:0.9rem;border:1px solid rgba(148,163,184,0.7);padding:0.65rem 0.75rem;max-height:420px;overflow-y:auto;background:#f9fafb;">
                <?php foreach ($blocksForEdit as $blk): ?>
                    <?php
                    $html    = (string)($blk['html'] ?? '');
                    $speaker = $blk['speaker'] ?? '';
                    $role    = $blk['role'] ?? '';
                    $msgId   = isset($blk['id']) ? (int)$blk['id'] : 0;
                    $msgClass = 'chat-msg-neutral';
                    if ($speaker === 'male' || ($speaker === '' && $role === 'user1')) {
                        $msgClass = 'chat-msg-male';
                    } elseif ($speaker === 'female' || ($speaker === '' && $role === 'user2')) {
                        $msgClass = 'chat-msg-female';
                    } elseif ($speaker === 'system') {
                        $msgClass = 'chat-msg-system';
                    }
                    ?>
                    <div class="chat-msg <?php echo $msgClass; ?>" data-block-id="<?php echo $msgId; ?>">
                        <div class="chat-bubble">
                            <?php echo $html; ?>
                        </div>
                        <?php if ($msgId > 0): ?>
                            <button type="button" class="chat-revoke-btn" data-block-id="<?php echo $msgId; ?>">撤回</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div id="chatComposer" style="margin-top:0.6rem;padding:0.55rem 0.65rem;border-radius:0.9rem;border:1px solid rgba(148,163,184,0.7);background:#ffffff;">
                <p style="margin:0 0 0.35rem;font-size:0.78rem;color:var(--text-light);">
                    当前登录身份：<?php echo $currentAuthorRoleKey === 'male' ? '男主' : ($currentAuthorRoleKey === 'female' ? '女主' : '未识别'); ?>，默认使用该身份发送，你也可以在下方切换为另一方或系统。
                </p>
                <div style="display:flex;gap:0.4rem;margin-bottom:0.4rem;flex-wrap:wrap;">
                    <button type="button" class="chat-role-btn" data-role="male"><?php echo e($chatMaleLabel); ?></button>
                    <button type="button" class="chat-role-btn" data-role="female"><?php echo e($chatFemaleLabel); ?></button>
                    <button type="button" class="chat-role-btn" data-role="system">系统</button>
                </div>
                <textarea id="chatInput" placeholder="输入要发送的内容（仅文本）" style="width:100%;min-height:72px;padding:0.45rem 0.55rem;border-radius:0.65rem;border:1px solid rgba(148,163,184,0.7);font-size:0.85rem;"></textarea>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:0.4rem;gap:0.5rem;flex-wrap:wrap;">
                    <div style="display:flex;gap:0.35rem;align-items:center;">
                        <button type="button" id="chatUploadImageBtn" class="btn btn-secondary" style="padding:0.18rem 0.6rem;font-size:0.78rem;">
                            <i class="far fa-image"></i>
                            <span>图片</span>
                        </button>
                        <button type="button" id="chatUploadVideoBtn" class="btn btn-secondary" style="padding:0.18rem 0.6rem;font-size:0.78rem;">
                            <i class="fas fa-video"></i>
                            <span>视频</span>
                        </button>
                        <input type="file" id="chatImageFile" accept="image/*" style="display:none;">
                        <input type="file" id="chatVideoFile" accept="video/*" style="display:none;">
                    </div>
                    <button type="button" id="chatSendBtn" class="btn btn-primary" style="padding:0.25rem 0.9rem;font-size:0.85rem;">
                        <i class="fas fa-paper-plane"></i>
                        <span>发送</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">标签（逗号分隔）</label>
            <input
                type="text"
                name="tags"
                value="<?php echo e($article['tags']); ?>"
                placeholder="例如：恋爱、旅行、日常"
                style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;">
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <?php if ($isOwner): ?>
                <label class="switch">
                    <input
                        type="checkbox"
                        name="allow_partner_edit"
                        value="1"
                        <?php echo $allowPartnerEdit ? 'checked' : ''; ?>>
                    <span class="switch-track">
                        <span class="switch-thumb"></span>
                    </span>
                    <span class="switch-label">允许另一半在后台编辑这篇文章</span>
                </label>
                <p style="margin:0.25rem 0 0;font-size:0.78rem;color:var(--text-light);">
                    关闭后，另一半将无法在后台编辑或删除这篇文章，但前台阅读不受影响。
                </p>
            <?php else: ?>
                <label class="switch switch-disabled">
                    <input type="checkbox" disabled <?php echo $allowPartnerEdit ? 'checked' : ''; ?>>
                    <span class="switch-track">
                        <span class="switch-thumb"></span>
                    </span>
                    <span class="switch-label">
                        由创建者控制是否允许另一半编辑
                        <?php if (!$allowPartnerEdit): ?>
                            （当前已关闭）
                        <?php endif; ?>
                    </span>
                </label>
            <?php endif; ?>
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label class="switch">
                <input type="checkbox" name="is_encrypted" value="1" <?php echo !empty($article['is_encrypted']) ? 'checked' : ''; ?>>
                <span class="switch-track">
                    <span class="switch-thumb"></span>
                </span>
                <span class="switch-label">加密内容（仅双方可见）</span>
            </label>
        </div>

        <?php
        $commentsEnabled         = isset($article['comments_enabled']) ? (int) $article['comments_enabled'] : 1;
        $commentsDisabledChecked = $commentsEnabled ? '' : 'checked';
        ?>
        <div class="form-group" style="margin-bottom:1rem;">
            <label class="switch">
                <input type="checkbox" name="disable_comments" value="1" <?php echo $commentsDisabledChecked; ?>>
                <span class="switch-track">
                    <span class="switch-thumb"></span>
                </span>
                <span class="switch-label">关闭评论区</span>
            </label>
        </div>

        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i>
                <span>保存修改</span>
            </button>
            <a href="/admin/articles.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i>
                <span>返回列表</span>
            </a>
        </div>
    </form>

    <!-- wangEditor 脚本（本地） -->
    <script src="/assets/js/wangeditor.min.js"></script>

    <script>
    // 若前台通用的 showToast 尚未定义，则在后台提供一个兼容版本，使用与前台一致的样式
    if (typeof window.showToast !== 'function') {
        (function () {
            function getToastContainer() {
                var container = document.getElementById('toast-container');
                if (!container) {
                    container = document.createElement('div');
                    container.id = 'toast-container';
                    container.className = 'toast-container';
                    document.body.appendChild(container);
                }
                return container;
            }
            window.showToast = function (message, type) {
                if (!message) return;
                type = type || 'info';
                var container = getToastContainer();
                var toast = document.createElement('div');
                var msg = document.createElement('div');
                toast.className = 'toast' + (type ? ' toast-' + type : '');
                msg.className = 'toast-message';
                msg.textContent = message;
                toast.appendChild(msg);
                container.appendChild(toast);
                toast.addEventListener('click', function () {
                    toast.classList.add('toast-hide');
                    setTimeout(function () {
                        if (toast.parentNode) toast.parentNode.removeChild(toast);
                    }, 220);
                });
                var duration = type === 'error' ? 5200 : 3600;
                setTimeout(function () {
                    toast.classList.add('toast-hide');
                }, duration);
                setTimeout(function () {
                    if (toast.parentNode) toast.parentNode.removeChild(toast);
                }, duration + 260);
            };
        })();
    }

    window.CO_CURRENT_AUTHOR_ROLE = <?php echo json_encode($currentAuthorRoleKey, JSON_UNESCAPED_UNICODE); ?>;
    const WANG_CSRF_TOKEN = <?php echo json_encode(csrf_token(), JSON_UNESCAPED_UNICODE); ?>;
    (function () {
        const editorWrapper = document.getElementById('articleEditorWrapper');
        const toolbarContainer = document.getElementById('editorToolbar');
        const editorContainer = document.getElementById('articleEditor');
        const codeEditor = document.getElementById('articleCodeEditor');
        const textarea = document.getElementById('articleContent');
        const visualBtn = document.getElementById('editorModeVisual');
        const codeBtn = document.getElementById('editorModeCode');
        const codeSnippetToolbar = document.getElementById('codeSnippetToolbar');
        const btnMarkMale = document.getElementById('btnMarkMale');
        const btnMarkFemale = document.getElementById('btnMarkFemale');
        const btnUnmarkAuthor = document.getElementById('btnUnmarkAuthor');
        
        if (!editorWrapper || !toolbarContainer || !editorContainer || !codeEditor || !textarea) return;

        // 防止重复初始化
        if (editorContainer.getAttribute('data-we-inited') === '1') {
            return;
        }
        editorContainer.setAttribute('data-we-inited', '1');

        // ------------------------
        // 初始化 wangEditor
        // ------------------------
        const E = window.wangEditor;
        const weEditor = new E(toolbarContainer, editorContainer);

        // 同步内容到隐藏 textarea
        weEditor.config.zIndex = 10;
        // 主编辑器上传配置（图片 / 视频）
        weEditor.config.uploadImgServer = '/api/upload_image.php';
        weEditor.config.uploadImgParams = {
            _token: WANG_CSRF_TOKEN,
            article_id: '<?php echo (int)$id; ?>'
        };
        weEditor.config.uploadVideoServer = '/api/upload_video.php';
        weEditor.config.uploadVideoParams = {
            _token: WANG_CSRF_TOKEN,
            article_id: '<?php echo (int)$id; ?>'
        };
        // 适配当前后端返回结构：{ errno:0, data:[url] }
        weEditor.config.uploadVideoHooks = {
            customInsert: function (insertVideoFn, result) {
                try {
                    if (!result) return;
                    // errno != 0 视为失败，直接提示 message
                    if (typeof result.errno !== 'undefined' && result.errno !== 0) {
                        var errMsg = result.message || '视频上传失败，请稍后重试';
                        window.showToast(errMsg, 'error');
                        return;
                    }
                    var url = '';
                    if (result.data) {
                        if (Array.isArray(result.data) && result.data.length > 0) {
                            url = result.data[0];
                        } else if (typeof result.data === 'object' && result.data.url) {
                            url = result.data.url;
                        }
                    }
                    if (url) {
                        insertVideoFn(url);
                    }
                } catch (e) {}
            },
            // 上传失败/出错时使用站内通知样式提示
            fail: function (xhr, editor, res) {
                try {
                    var msg = (res && res.message) ? res.message : '';
                    if (!msg && xhr && xhr.responseText) {
                        try {
                            var parsed = JSON.parse(xhr.responseText);
                            if (parsed && parsed.message) {
                                msg = parsed.message;
                            }
                        } catch (e) {}
                    }
                    if (!msg) {
                        msg = '视频上传失败。可能是文件过大：可以在“系统设置 → 上传与其他 → 单文件最大上传大小（MB）”中调整，或开启“视频上传仅受服务器限制”开关；也可能是服务器上传大小限制导致，请压缩后重试或联系管理员。';
                    }
                    window.showToast(msg, 'error');
                } catch (e) {}
            },
            error: function (xhr, editor, res) {
                try {
                    var msg = (res && res.message) ? res.message : '';
                    if (!msg && xhr && xhr.responseText) {
                        try {
                            var parsed = JSON.parse(xhr.responseText);
                            if (parsed && parsed.message) {
                                msg = parsed.message;
                            }
                        } catch (e) {}
                    }
                    if (!msg) {
                        msg = '视频上传失败。可能是文件过大：可以在“系统设置 → 上传与其他 → 单文件最大上传大小（MB）”中调整，或开启“视频上传仅受服务器限制”开关；也可能是服务器上传大小限制导致，请压缩后重试或联系管理员。';
                    }
                    window.showToast(msg, 'error');
                } catch (e) {}
            }
        };
        // 禁用「网络图片」入口，避免外链失败带来的困惑
        weEditor.config.showLinkImg = false;
        weEditor.config.onchange = function (html) {
            textarea.value = html;
        };
        weEditor.create();
        weEditor.txt.html(textarea.value || editorContainer.innerHTML || '');

        // 初始化块级编辑器（正文编辑器就绪后）
        if (typeof window.setupCoBlockEditors === 'function') {
            window.setupCoBlockEditors();
        }

        // 根据编辑模式显示 / 隐藏对应区域
        const fullSection     = document.getElementById('fullEditorSection');
        const dialogSection   = document.getElementById('dialogEditorSection');
        const chatSection     = document.getElementById('chatEditorSection');
        const editModeRadios  = document.querySelectorAll('input[name="edit_mode"]');
        const chatMessagesEl  = document.getElementById('chatMessages');
        const chatInputEl     = document.getElementById('chatInput');
        const chatSendBtn     = document.getElementById('chatSendBtn');
        const chatRoleBtns    = document.querySelectorAll('.chat-role-btn');
        const chatUploadImageBtn = document.getElementById('chatUploadImageBtn');
        const chatUploadVideoBtn = document.getElementById('chatUploadVideoBtn');
        const chatImageFile   = document.getElementById('chatImageFile');
        const chatVideoFile   = document.getElementById('chatVideoFile');

        function applyEditModeUI(mode) {
            if (!fullSection || !dialogSection || !chatSection) return;
            if (mode === 'blocks') {
                fullSection.style.display = 'none';
                dialogSection.style.display = 'block';
                chatSection.style.display   = 'none';
            } else if (mode === 'chat') {
                fullSection.style.display = 'none';
                dialogSection.style.display = 'none';
                chatSection.style.display   = 'block';
            } else {
                fullSection.style.display   = 'block';
                dialogSection.style.display = 'none';
                chatSection.style.display   = 'none';
            }
        }

        let initialMode = 'full';
        editModeRadios.forEach(function (radio) {
            if (radio.checked) {
                if (radio.value === 'blocks' || radio.value === 'chat') {
                    initialMode = radio.value;
                } else {
                    initialMode = 'full';
                }
            }
            radio.addEventListener('change', function () {
                let mode = 'full';
                if (this.value === 'blocks' || this.value === 'chat') {
                    mode = this.value;
                }
                applyEditModeUI(mode);
            });
        });
        applyEditModeUI(initialMode);

        // 聊天创作模式：身份选择状态
        let currentChatRole = window.CO_CURRENT_AUTHOR_ROLE || 'male';
        function updateChatRoleUI() {
            if (!chatRoleBtns) return;
            chatRoleBtns.forEach(function (btn) {
                const role = btn.getAttribute('data-role');
                if (role === currentChatRole) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        }
        if (chatRoleBtns && chatRoleBtns.length) {
            chatRoleBtns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const role = this.getAttribute('data-role');
                    if (role === 'male' || role === 'female' || role === 'system') {
                        currentChatRole = role;
                        updateChatRoleUI();
                    }
                });
            });
            updateChatRoleUI();
        }

        // 聊天模式：渲染新消息
        function appendChatMessage(block, isPrepend) {
            if (!chatMessagesEl || !block) return;
            const msgId    = block.id || 0;
            const speaker  = block.speaker || '';
            const html     = block.html || '';

            let cls = 'chat-msg-neutral';
            if (speaker === 'male') cls = 'chat-msg-male';
            else if (speaker === 'female') cls = 'chat-msg-female';
            else if (speaker === 'system') cls = 'chat-msg-system';

            const wrap = document.createElement('div');
            wrap.className = 'chat-msg ' + cls;
            wrap.setAttribute('data-block-id', String(msgId));

            const bubble = document.createElement('div');
            bubble.className = 'chat-bubble';
            bubble.innerHTML = html;
            wrap.appendChild(bubble);

            if (msgId > 0 && speaker !== 'system') {
                const revokeBtn = document.createElement('button');
                revokeBtn.type = 'button';
                revokeBtn.className = 'chat-revoke-btn';
                revokeBtn.setAttribute('data-block-id', String(msgId));
                revokeBtn.textContent = '撤回';
                wrap.appendChild(revokeBtn);
            }

            if (isPrepend) {
                chatMessagesEl.insertBefore(wrap, chatMessagesEl.firstChild);
            } else {
                chatMessagesEl.appendChild(wrap);
            }
            chatMessagesEl.scrollTop = chatMessagesEl.scrollHeight;
        }

        // 聊天模式：发送文本消息
        function sendChatMessage(type, payload) {
            if (!payload) return;
            const text = typeof payload === 'string' ? payload : '';
            if (type === 'text' && (!text || !text.trim())) return;

            const fd = new FormData();
            fd.append('_token', WANG_CSRF_TOKEN || '');
            fd.append('article_id', '<?php echo (int)$id; ?>');
            fd.append('speaker', currentChatRole);
            fd.append('type', type);
            fd.append('content', payload);

            fetch('/api/article_chat_send.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(function (res) {
                return res.text().then(function (text) {
                    return { ok: res.ok, text: text };
                });
            }).then(function (result) {
                var ok = result.ok;
                var text = result.text || '';
                var data = null;
                if (text) {
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        data = null;
                    }
                }

                if (data && data.success && data.block) {
                    if (window.showToast) {
                        window.showToast('已自动保存', 'success');
                    }
                    appendChatMessage(data.block, false);
                    return;
                }

                // 如果 HTTP 正常但解析失败，说明后端很可能已成功写入，这里做乐观处理：
                if (ok && !data) {
                    if (window.showToast) {
                        window.showToast('已自动保存', 'success');
                    }
                    // 前端构造一个临时气泡（不带撤回按钮，刷新后会变成真实块）
                    var html = '';
                    if (type === 'image') {
                        var url = String(payload || '');
                        html = '<p><img src="' + url.replace(/"/g, '&quot;') + '" alt="" /></p>';
                    } else if (type === 'video') {
                        var vurl = String(payload || '');
                        html = '<p><video src="' + vurl.replace(/"/g, '&quot;') + '" controls preload="metadata" style="max-width:100%;height:auto;border-radius:0.9rem;"></video></p>';
                    } else {
                        var safe = String(payload || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        safe = safe.replace(/\r?\n/g, '<br>');
                        html = '<p>' + safe + '</p>';
                    }
                    appendChatMessage({ id: 0, speaker: currentChatRole, html: html }, false);
                    return;
                }

                // 确认失败分支
                var msg = (data && data.message) ? data.message : '发送失败';
                window.showToast(msg, 'error');
            }).catch(function () {
                window.showToast('发送失败，请稍后重试', 'error');
            });
        }

        if (chatSendBtn && chatInputEl) {
            chatSendBtn.addEventListener('click', function () {
                const text = chatInputEl.value || '';
                sendChatMessage('text', text);
                chatInputEl.value = '';
            });
            chatInputEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    const text = chatInputEl.value || '';
                    sendChatMessage('text', text);
                    chatInputEl.value = '';
                }
            });
        }

        // 聊天模式：撤回
        if (chatMessagesEl) {
            chatMessagesEl.addEventListener('click', function (e) {
                const btn = e.target.closest('.chat-revoke-btn');
                if (!btn) return;
                const blockId = parseInt(btn.getAttribute('data-block-id') || '0', 10);
                if (!blockId) return;

                const fd = new FormData();
                fd.append('_token', WANG_CSRF_TOKEN || '');
                fd.append('article_id', '<?php echo (int)$id; ?>');
                fd.append('block_id', String(blockId));

                fetch('/api/article_chat_revoke.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                }).then(function (res) {
                    return res.json();
                }).then(function (data) {
                    if (!data || !data.success) {
                        var msg = (data && data.message) ? data.message : '撤回失败';
                        window.showToast(msg, 'error');
                        return;
                    }
                    const msgEl = chatMessagesEl.querySelector('.chat-msg[data-block-id="' + blockId + '"]');
                    if (msgEl && msgEl.parentNode) {
                        msgEl.parentNode.removeChild(msgEl);
                    }
                }).catch(function () {
                    window.showToast('撤回失败，请稍后重试', 'error');
                });
            });
        }

        // 聊天模式：上传图片 / 视频，先调用上传接口获取 URL，再当作消息发送
        function uploadMedia(fileInput, type) {
            if (!fileInput || !fileInput.files || !fileInput.files[0]) return;
            const file = fileInput.files[0];
            const fd = new FormData();
            fd.append('_token', WANG_CSRF_TOKEN || '');
            fd.append('article_id', '<?php echo (int)$id; ?>');
            fd.append('file', file);

            const uploadUrl = type === 'image' ? '/api/upload_image.php' : '/api/upload_video.php';

            fetch(uploadUrl, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(function (res) {
                return res.json();
            }).then(function (data) {
                if (!data || data.errno !== 0 || !data.data || !data.data[0]) {
                    var msg = (data && data.message) ? data.message : '上传失败';
                    window.showToast(msg, 'error');
                    return;
                }
                const url = data.data[0];
                sendChatMessage(type, url);
            }).catch(function () {
                window.showToast('上传失败，请稍后重试', 'error');
            }).finally(function () {
                fileInput.value = '';
            });
        }

        if (chatUploadImageBtn && chatImageFile) {
            chatUploadImageBtn.addEventListener('click', function () {
                chatImageFile.click();
            });
            chatImageFile.addEventListener('change', function () {
                uploadMedia(chatImageFile, 'image');
            });
        }

        if (chatUploadVideoBtn && chatVideoFile) {
            chatUploadVideoBtn.addEventListener('click', function () {
                chatVideoFile.click();
            });
            chatVideoFile.addEventListener('change', function () {
                uploadMedia(chatVideoFile, 'video');
            });
        }

        // 编辑器模式管理（visual: wangEditor；code: 代码 textarea）
        let currentMode = 'visual'; // 'visual' 或 'code'

        function switchToVisual() {
            if (currentMode === 'visual') return;
            weEditor.txt.html(codeEditor.value || '');
            editorWrapper.style.display = 'block';
            codeEditor.style.display = 'none';
            visualBtn.classList.add('active');
            codeBtn.classList.remove('active');
            if (codeSnippetToolbar) {
                codeSnippetToolbar.style.display = 'none';
            }
            currentMode = 'visual';
        }

        // 兼容性处理：仅在点击正文空白区域时，将光标移动到内容末尾；
        // 若点击到了已有文字/元素，则保持浏览器默认的精确定位行为
        editorContainer.addEventListener('click', function (e) {
            try {
                if (e.target && e.target.closest('.w-e-text')) {
                    return;
                }
                var textElem = editorContainer.querySelector('.w-e-text');
                if (!textElem) return;
                var range = document.createRange();
                range.selectNodeContents(textElem);
                range.collapse(false); // 光标到末尾
                var sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
            } catch (e) {}
        });

        function switchToCode() {
            if (currentMode === 'code') return;
            codeEditor.value = weEditor.txt.html();
            editorWrapper.style.display = 'none';
            codeEditor.style.display = 'block';
            visualBtn.classList.remove('active');
            codeBtn.classList.add('active');
            if (codeSnippetToolbar) {
                codeSnippetToolbar.style.display = 'flex';
            }
            currentMode = 'code';
        }

        // 绑定切换按钮事件
        if (visualBtn) {
            visualBtn.addEventListener('click', switchToVisual);
        }
        if (codeBtn) {
            codeBtn.addEventListener('click', switchToCode);
        }

        // 代码编辑器插入函数（仅代码模式）
        function insertCodeSnippet(snippet) {
            const start = codeEditor.selectionStart;
            const end = codeEditor.selectionEnd;
            const text = codeEditor.value;
            codeEditor.value = text.substring(0, start) + snippet + text.substring(end);
            const newPos = start + snippet.length;
            codeEditor.selectionStart = newPos;
            codeEditor.selectionEnd = newPos;
            codeEditor.focus();
        }

        // 代码模式下：绑定插入 H2/H3/H4 标题模板、各种引用块、代码块和下载按钮
        if (codeSnippetToolbar) {
            codeSnippetToolbar.addEventListener('click', function (e) {
                const btn = e.target.closest('button[data-snippet]');
                if (!btn) return;
                const type = btn.getAttribute('data-snippet');
                let snippet = '';
                if (type === 'h2') {
                    snippet = '<h2 class="h-title">在这里输入标题</h2>\n';
                } else if (type === 'h3') {
                    snippet = '<h3 class="h-title">在这里输入小标题</h3>\n';
                } else if (type === 'h4') {
                    snippet = '<h4 class="h-title">在这里输入小节标题</h4>\n';
                } else if (type === 'blockquote') {
                    snippet = '<blockquote>在这里输入引用内容</blockquote>\n';
                } else if (type === 'blockquote-note') {
                    snippet = '<blockquote class="bq-note">在这里输入提示内容</blockquote>\n';
                } else if (type === 'blockquote-warning') {
                    snippet = '<blockquote class="bq-warning">在这里输入警告内容</blockquote>\n';
                } else if (type === 'blockquote-success') {
                    snippet = '<blockquote class="bq-success">在这里输入成功提示</blockquote>\n';
                } else if (type === 'code') {
                    snippet = '<pre><code>// 在这里粘贴代码\n</code></pre>\n';
                } else if (type === 'download') {
                    snippet = '<p><a class="btn-download" href="#" target="_blank" rel="noopener"><i class="fas fa-download"></i> 下载附件</a></p>\n';
                }
                if (!snippet) return;
                if (currentMode !== 'code') {
                    switchToCode();
                }
                insertCodeSnippet(snippet);
            });
        }

        // 原有的编辑器功能
        const editor = editorContainer;
        let lastRange = null;

        function saveSelection() {
            const sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) return;
            const range = sel.getRangeAt(0);
            if (!editor.contains(range.commonAncestorContainer)) return;
            if (range.collapsed) return;
            lastRange = range.cloneRange();
        }

        editor.addEventListener('mouseup', saveSelection);
        editor.addEventListener('keyup', saveSelection);

        function getCurrentAuthor() {
            const autoBox = document.querySelector('input[name="enable_auto_author"]');
            const autoOn = autoBox && autoBox.checked;
            if (!autoOn) return '';
            const roleKey = window.CO_CURRENT_AUTHOR_ROLE || '';
            if (roleKey === 'male' || roleKey === 'female') {
                return roleKey;
            }
            return '';
        }

        function withAuthor(snippet) {
            // 极简、稳定：若设置了当前作者，则直接用 span[data-author] 包裹整个片段
            const author = getCurrentAuthor();
            if (!author) return snippet;
            return '<span data-author="' + author + '">' + snippet + '</span>';
        }

        function insertSnippet(snippet) {
            editor.focus();
            const finalSnippet = withAuthor(snippet);
            const sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) {
                editor.insertAdjacentHTML('beforeend', finalSnippet);
                return;
            }
            const range = sel.getRangeAt(0);
            if (!editor.contains(range.commonAncestorContainer)) {
                const newRange = document.createRange();
                newRange.selectNodeContents(editor);
                newRange.collapse(false);
                sel.removeAllRanges();
                sel.addRange(newRange);
            }
            const useRange = sel.getRangeAt(0);
            useRange.deleteContents();
            const fragment = useRange.createContextualFragment(finalSnippet);
            const lastNode = fragment.lastChild;
            useRange.insertNode(fragment);
            if (lastNode) {
                const newRange2 = document.createRange();
                newRange2.setStartAfter(lastNode);
                newRange2.collapse(true);
                sel.removeAllRanges();
                sel.addRange(newRange2);
            }
        }

        function markSelectionAuthor(author) {
            if (!author) return;
            const sel = window.getSelection();
            let range = null;

            if (sel && sel.rangeCount > 0) {
                const current = sel.getRangeAt(0);
                if (editor.contains(current.commonAncestorContainer) && !current.collapsed) {
                    range = current;
                }
            }

            if (!range && lastRange) {
                range = lastRange.cloneRange();
                if (sel) {
                    sel.removeAllRanges();
                    sel.addRange(range);
                }
            }

            // 情况一：有有效选区，按原逻辑包裹选中文本
            if (range && !range.collapsed && editor.contains(range.commonAncestorContainer)) {
                // 提前清理选区内部已有的 data-author span，避免重叠 / 套娃
                const contents = range.extractContents();
                const walker = document.createTreeWalker(contents, NodeFilter.SHOW_ELEMENT, null);
                const toUnwrap = [];
                while (true) {
                    const node = walker.nextNode();
                    if (!node) break;
                    if (node.nodeType === 1 && node.matches('span[data-author]')) {
                        toUnwrap.push(node);
                    }
                }
                toUnwrap.forEach(function (node) {
                    const parent = node.parentNode;
                    if (!parent) return;
                    while (node.firstChild) {
                        parent.insertBefore(node.firstChild, node);
                    }
                    parent.removeChild(node);
                });

                const wrapper = document.createElement('span');
                wrapper.setAttribute('data-author', author);
                wrapper.appendChild(contents);
                range.insertNode(wrapper);

                // 在标记块后面插入一个零宽字符，让光标落在一个“干净”的文本节点里
                const placeholder = document.createTextNode('\u200b');
                if (wrapper.parentNode) {
                    wrapper.parentNode.insertBefore(placeholder, wrapper.nextSibling);
                }

                // 将光标移动到占位文本之后，避免后续输入继续落在 span 内
                const newRange = document.createRange();
                newRange.setStart(placeholder, 1);
                newRange.collapse(true);
                sel.removeAllRanges();
                sel.addRange(newRange);

                // 本次标记完成后清空 lastRange，避免错误复用旧选区
                lastRange = null;
                return;
            }

            // 情况二：没有有效选区（光标在空白处）——插入一个带作者标记的空段落
            editor.focus();
            const insertSel = window.getSelection();
            let insertRange = null;

            if (insertSel && insertSel.rangeCount > 0) {
                const current = insertSel.getRangeAt(0);
                if (editor.contains(current.commonAncestorContainer)) {
                    insertRange = current;
                }
            }

            if (!insertRange) {
                insertRange = document.createRange();
                insertRange.selectNodeContents(editor);
                insertRange.collapse(false);
            }

            const span = document.createElement('span');
            span.setAttribute('data-author', author);
            const zw = document.createTextNode('\u200b');
            span.appendChild(zw);

            const p = document.createElement('p');
            p.appendChild(span);

            insertRange.insertNode(p);

            const caretRange = document.createRange();
            caretRange.setStart(zw, 1);
            caretRange.collapse(true);
            insertSel.removeAllRanges();
            insertSel.addRange(caretRange);

            lastRange = null;
        }

        function clearSelectionAuthor() {
            const sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) {
                if (lastRange) {
                    sel.addRange(lastRange.cloneRange());
                } else {
                    return;
                }
            }
            const range = sel.getRangeAt(0);
            if (!editor.contains(range.commonAncestorContainer)) return;

            const isCollapsed = range.collapsed;
            let targetNodes = [];

            if (isCollapsed) {
                let node = range.startContainer;
                if (node.nodeType === 3) {
                    node = node.parentNode;
                }
                if (node && node.nodeType === 1 && node.matches('span[data-author]')) {
                    targetNodes.push(node);
                }
            } else {
                const contents = range.extractContents();
                const walker = document.createTreeWalker(contents, NodeFilter.SHOW_ELEMENT, null);
                while (true) {
                    const node = walker.nextNode();
                    if (!node) break;
                    if (node.nodeType === 1 && node.matches('span[data-author]')) {
                        targetNodes.push(node);
                    }
                }
                range.insertNode(contents);
            }

            targetNodes.forEach(function (node) {
                const parent = node.parentNode;
                if (!parent) return;
                while (node.firstChild) {
                    parent.insertBefore(node.firstChild, node);
                }
                parent.removeChild(node);
            });
        }

        // 男女主标记按钮绑定（作用于当前可视化内容）
        if (btnMarkMale) {
            btnMarkMale.addEventListener('click', function () {
                markSelectionAuthor('male');
            });
        }
        if (btnMarkFemale) {
            btnMarkFemale.addEventListener('click', function () {
                markSelectionAuthor('female');
            });
        }
        if (btnUnmarkAuthor) {
            btnUnmarkAuthor.addEventListener('click', function () {
                clearSelectionAuthor();
            });
        }


        const form = document.querySelector('form.admin-card');
        if (form) {
            form.addEventListener('submit', function () {
                if (currentMode === 'visual') {
                    // 可视化模式下，内容已在 onchange 中同步到 textarea，这里保持一致性
                    textarea.value = weEditor.txt.html();
                } else {
                    textarea.value = codeEditor.value;
                }

                // 提交前强制同步所有块级编辑器的内容到各自隐藏 textarea，
                // 避免用户在块中输入后未触发 onchange 导致 html 为空
                const blockItems = form.querySelectorAll('.co-block-editor-item');
                blockItems.forEach(function (item) {
                    const blockTextarea = item.querySelector('textarea[name^="blocks["]');
                    if (!blockTextarea) return;
                    if (item._weEditor && typeof item._weEditor.txt === 'object' && typeof item._weEditor.txt.html === 'function') {
                        blockTextarea.value = item._weEditor.txt.html();
                    }
                });
            });
        }
    })();
    </script>

<?php include __DIR__ . '/footer.php'; ?>

<style>
/* 模式切换工具栏布局（仅保留可视化/代码按钮） */
.md-toolbar {
    display: flex;
    justify-content: flex-end;
    gap: 0.25rem;
    margin-bottom: 0.5rem;
}

/* 编辑器模式切换按钮 */
.editor-mode-btn {
    padding: 0.4rem 0.75rem;
    border: 1px solid rgba(148, 163, 184, 0.4);
    background: #ffffff;
    color: #64748b;
    border-radius: 0.5rem;
    font-size: 0.8rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

.editor-mode-btn:hover {
    background: #f8fafc;
    border-color: rgba(148, 163, 184, 0.6);
    color: #475569;
}

.editor-mode-btn.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-color: #667eea;
    color: #ffffff;
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
}

.editor-mode-btn i {
    font-size: 0.75rem;
}

/* 男女主标记按钮区域 */
.author-mark-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    margin-bottom: 0.5rem;
}

.author-mark-toolbar button {
    padding: 0.3rem 0.7rem;
    border-radius: 999px;
    border: 1px solid rgba(148,163,184,0.7);
    background: #ffffff;
    font-size: 0.78rem;
    cursor: pointer;
}

.author-mark-toolbar button:hover {
    background: #f8fafc;
}

/* 代码模式下的快捷插入按钮工具栏 */
.code-snippet-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    margin: 0.4rem 0 0.5rem;
}

.code-snippet-toolbar button {
    padding: 0.3rem 0.7rem;
    border-radius: 999px;
    border: 1px solid rgba(148,163,184,0.7);
    background: #ffffff;
    font-size: 0.78rem;
    cursor: pointer;
}

.code-snippet-toolbar button:hover {
    background: #f8fafc;
}

/* 块级编辑器样式 */
.co-block-editor-wrapper .w-e-text-container {
    min-height: 120px;
}

/* 代码编辑器滚动条美化 */
#articleCodeEditor::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

#articleCodeEditor::-webkit-scrollbar-track {
    background: #0f172a;
    border-radius: 4px;
}

#articleCodeEditor::-webkit-scrollbar-thumb {
    background: #475569;
    border-radius: 4px;
}

#articleCodeEditor::-webkit-scrollbar-thumb:hover {
    background: #64748b;
}

/* 原有样式 */
#articleEditor span[data-author="male"] {
    background: rgba(129, 140, 248, 0.18);
    border-radius: 0.25rem;
    padding: 0 0.12em;
    position: relative;
    display: inline;
}

#articleEditor span[data-author="female"] {
    background: rgba(244, 114, 182, 0.18);
    border-radius: 0.25rem;
    padding: 0 0.12em;
    position: relative;
    display: inline;
}

#articleEditor span[data-author="male"]::after,
#articleEditor span[data-author="female"]::after {
    position: absolute;
    bottom: 100%;
    left: 0; /* 改为左对齐，而不是居中 */
    transform: translateY(-0.1rem) scale(0.92);
    font-size: 0.75rem;
    line-height: 1.2;
    padding: 0.12rem 0.4rem;
    border-radius: 999px;
    color: #f9fafb;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
}

#articleEditor span[data-author="male"]:hover::after {
    content: "男主写的";
    background: rgba(59, 130, 246, 0.96);
    animation: co-bubble-in 0.26s cubic-bezier(0.16, 0.84, 0.44, 1) forwards;
}

#articleEditor span[data-author="female"]:hover::after {
    content: "女主写的";
    background: rgba(236, 72, 153, 0.96);
    animation: co-bubble-in 0.26s cubic-bezier(0.16, 0.84, 0.44, 1) forwards;
}

@keyframes co-bubble-in {
    0% {
        opacity: 0;
        transform: translateY(-0.1rem) scale(0.9);
    }
    100% {
        opacity: 1;
        transform: translateY(-0.22rem) scale(1);
    }
}

/* 提示编辑行为的鼠标样式 */
#articleEditor,
.co-block-editor {
    cursor: text;
}

/* 对话框编辑器拖拽体验优化 */
.co-block-editor-item {
    transition: box-shadow 0.12s ease, transform 0.12s ease;
}

.co-block-drag-handle {
    cursor: move;
}

.co-block-placeholder {
    border-radius: 0.75rem;
    border: 1px dashed rgba(148,163,184,0.7);
    background: rgba(241,245,249,0.7);
}

.co-block-dragging {
    box-shadow: 0 12px 30px rgba(15,23,42,0.18);
}

/* 对话创作模式样式 */
.chat-msg {
    display: flex;
    align-items: flex-end;
    margin-bottom: 0.45rem;
    gap: 0.4rem;
}

.chat-msg-male {
    justify-content: flex-start;
}

.chat-msg-female {
    justify-content: flex-end;
}

.chat-msg-system {
    justify-content: center;
}

.chat-bubble {
    max-width: 80%;
    padding: 0.45rem 0.7rem;
    border-radius: 0.9rem;
    font-size: 0.85rem;
    line-height: 1.5;
    background: #f3f4f6;
    border: 1px solid rgba(148,163,184,0.5);
}

.chat-msg-male .chat-bubble {
    background: rgba(239,246,255,0.98);
    border-color: rgba(129,140,248,0.5);
}

.chat-msg-female .chat-bubble {
    background: rgba(253,242,248,0.98);
    border-color: rgba(244,114,182,0.5);
}

.chat-msg-system .chat-bubble {
    background: #f9fafb;
    border-style: dashed;
}

.chat-bubble img,
.chat-bubble video {
    max-width: 100%;
    height: auto;
    display: block;
    border-radius: 0.9rem;
}

.chat-revoke-btn {
    border: none;
    background: transparent;
    color: #9ca3af;
    font-size: 0.72rem;
    cursor: pointer;
}

.chat-revoke-btn:hover {
    color: #ef4444;
}

.chat-role-btn {
    border-radius: 999px;
    border: 1px solid rgba(148,163,184,0.7);
    background: #ffffff;
    font-size: 0.78rem;
    padding: 0.18rem 0.6rem;
    cursor: pointer;
}

.chat-role-btn.active {
    background: rgba(59,130,246,0.08);
    border-color: rgba(59,130,246,0.8);
    color: #1d4ed8;
}
</style>
