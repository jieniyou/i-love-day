<?php
// 聊天创作模式：撤回（删除）一条对话块
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
$blockId   = isset($_POST['block_id']) ? intval($_POST['block_id']) : 0;

if ($articleId <= 0 || $blockId <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db          = Database::getInstance();
    $currentUser = $auth->getCurrentUser();
    $partner     = $auth->getPartner();

    $article = $db->fetch("SELECT * FROM articles WHERE id = :id LIMIT 1", ['id' => $articleId]);
    $oldContent = (string) ($article['content'] ?? '');
    if (!$article) {
        echo json_encode(['success' => false, 'message' => '文章不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $isOwner   = isset($article['user_id']) && (int)$article['user_id'] === (int)$currentUser['id'];
    $partnerId = $partner['id'] ?? null;
    $isPartner = $partnerId && isset($article['user_id']) && (int)$article['user_id'] === (int)$partnerId;

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

    // 确保块属于该文章
    $block = $db->fetch(
        "SELECT * FROM article_blocks WHERE id = :id AND article_id = :article_id LIMIT 1",
        ['id' => $blockId, 'article_id' => $articleId]
    );
    if (!$block) {
        echo json_encode(['success' => false, 'message' => '该对话已不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db->query(
        "DELETE FROM article_blocks WHERE id = :id AND article_id = :article_id",
        ['id' => $blockId, 'article_id' => $articleId]
    );

    // 删除后重建全文 HTML
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

    $db->update('articles', [
        'content'   => $contentHtml,
        'updated_at'=> date('Y-m-d H:i:s'),
    ], 'id = :id', ['id' => $articleId]);

    // 撤回对话块后：清理本次撤回导致不再被引用的上传文件（图片 / 视频）
    if (function_exists('extract_upload_paths_from_html')) {
        try {
            $oldPaths = extract_upload_paths_from_html($oldContent);
            $newPaths = extract_upload_paths_from_html($contentHtml);
            if (!empty($oldPaths)) {
                $oldPaths = array_unique($oldPaths);
            }
            if (!empty($newPaths)) {
                $newPaths = array_unique($newPaths);
            }
            $removedPaths = array_diff($oldPaths, $newPaths);
            if (!empty($removedPaths)) {
                foreach ($removedPaths as $relPath) {
                    delete_upload_file_if_unused($relPath, (int) $articleId);
                }
            }
        } catch (Throwable $e) {
            // 忽略清理失败，不影响撤回结果
        }
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => '撤回失败，请稍后重试'], JSON_UNESCAPED_UNICODE);
}
