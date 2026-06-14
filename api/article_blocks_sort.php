<?php
// 对话框块排序接口：仅更新块顺序（block_index）
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/helpers.php';

$auth = new Auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request'], JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF 校验
if (!csrf_verify($_POST['_token'] ?? null)) {
    echo json_encode(['success' => false, 'message' => '表单已过期，请刷新页面后重试'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$articleId = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;
// blocks 通过 JSON 传递，更易于前端构造
$blocksJson = $_POST['blocks_json'] ?? '';
$blocks     = $blocksJson ? json_decode($blocksJson, true) : null;

if ($articleId <= 0 || !is_array($blocks)) {
    echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db          = Database::getInstance();
    $currentUser = $auth->getCurrentUser();
    $partner     = $auth->getPartner();

    // 权限校验：与编辑页保持一致
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
        echo json_encode(['success' => false, 'message' => '你没有权限调整这篇文章的对话顺序'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // blocks 预期格式：[{id: blockId, index: newIndex}, ...]
    $db->beginTransaction();
    foreach ($blocks as $row) {
        $blockId = isset($row['id']) ? intval($row['id']) : 0;
        $index   = isset($row['index']) ? intval($row['index']) : 0;
        if ($blockId <= 0) {
            continue;
        }
        $db->query(
            "UPDATE article_blocks SET block_index = :block_index WHERE id = :id AND article_id = :article_id",
            [
                'block_index' => $index,
                'id'          => $blockId,
                'article_id'  => $articleId,
            ]
        );
    }
    $db->commit();

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($db)) {
        try { $db->rollBack(); } catch (Throwable $e2) {}
    }
    echo json_encode(['success' => false, 'message' => '排序保存失败，请稍后重试'], JSON_UNESCAPED_UNICODE);
}
