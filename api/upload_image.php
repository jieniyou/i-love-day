<?php
// wangEditor 图片上传接口（后台使用）
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/helpers.php';

$auth = new Auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['errno' => 1, 'message' => 'Invalid request'], JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF 校验：后台编辑器也必须携带 token
if (!csrf_verify($_POST['_token'] ?? null)) {
    echo json_encode(['errno' => 1, 'message' => '表单已过期，请刷新页面后重试'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 仅登录用户可上传
if (!$auth->isLoggedIn()) {
    echo json_encode(['errno' => 1, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 兼容不同字段名：取第一个文件
if (empty($_FILES)) {
    echo json_encode(['errno' => 1, 'message' => '没有选择文件'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = reset($_FILES);

// 尝试按文章 ID 进行分目录存储：uploads/articles/{article_id}/...
// - 新建文章阶段（无 article_id）仍回退到 uploads/articles 根目录，兼容旧数据
$articleId = isset($_POST['article_id']) ? (int) $_POST['article_id'] : 0;
if ($articleId > 0) {
    $subDir = 'articles/' . $articleId;
} else {
    $subDir = 'articles';
}

$result = uploadFile($file, $subDir);
if (!$result['success']) {
    echo json_encode(['errno' => 1, 'message' => $result['message']], JSON_UNESCAPED_UNICODE);
    exit;
}

$url = upload_url($result['path'] ?? '');
if ($url === '') {
    echo json_encode(['errno' => 1, 'message' => '生成图片地址失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 返回 wangEditor 期望的结构
echo json_encode([
    'errno' => 0,
    'data'  => [$url],
], JSON_UNESCAPED_UNICODE);
exit;
