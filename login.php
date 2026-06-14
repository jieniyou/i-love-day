<?php
// 登录 / 注册页面（UTF-8）
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/helpers.php';

$auth = new Auth();
$db   = Database::getInstance();

// Turnstile 当前配置
$turnstileEnabled = (string) get_setting('turnstile_enabled', '0') === '1';
$turnstileSiteKey = '';
if ($turnstileEnabled) {
    $turnstileSiteKey = (string) get_setting('turnstile_site_key', '');
    if ($turnstileSiteKey === '') {
        $turnstileEnabled = false;
    }
}

// 已登录则直接回到首页
if ($auth->isLoggedIn()) {
    redirect('/');
}

$error   = '';
$success = '';

// 统计当前活跃用户数量，用于控制是否开放注册入口
$userCountRow   = $db->fetch("SELECT COUNT(*) AS c FROM users WHERE status = 'active'");
$activeUserCount = $userCountRow ? (int) $userCountRow['c'] : 0;
$registerEnabled = $activeUserCount < 2;

// 重定向后的成功提示
if (isset($_GET['success']) && $_GET['success'] === 'register') {
    $success = '注册成功，请使用新账号登录';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 统一使用 CSRF 校验
    $token = $_POST['_token'] ?? '';
    if (!csrf_verify($token)) {
        $error = '表单已过期，请刷新页面后重试';
    } elseif ($turnstileEnabled) {
        $tsToken = $_POST['cf-turnstile-response'] ?? '';
        if (!verify_turnstile((string)$tsToken)) {
            $error = '验证未通过，请完成安全验证后再试';
        }
    }

    if (!$error) {
        $action = $_POST['action'] ?? 'login';

        if ($action === 'login') {
            $username = trim($_POST['username'] ?? '');
            $password = (string) ($_POST['password'] ?? '');

            if ($username === '' || $password === '') {
                $error = '请输入用户名和密码';
            } else {
                if ($auth->login($username, $password)) {
                    // 登录成功后直接重定向到首页，防止刷新重复提交
                    redirect('/');
                } else {
                    // 统一错误提示，避免暴露具体原因
                    $error = '用户名或密码错误，或尝试次数过多，请稍后再试';
                }
            }
        } elseif ($action === 'register') {
            if (!$registerEnabled) {
                $error = '当前已注册满两位用户，已关闭注册';
            } else {
                $username = trim($_POST['username'] ?? '');
                $password = (string) ($_POST['password'] ?? '');
                $nickname = trim($_POST['nickname'] ?? '');
                $role     = $_POST['role'] ?? 'user1';

                $result = $auth->register($username, $password, $nickname, $role);
                if (!empty($result['success'])) {
                    // 注册成功使用 PRG 模式，避免刷新重复提交注册
                    header('Location: /login.php?success=register');
                    exit;
                } else {
                    $error = $result['message'] ?? '注册失败，请稍后重试';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - <?php echo e(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
    <link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css"
          onerror="this.onerror=null;this.href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';">
    <?php if ($turnstileEnabled && $turnstileSiteKey): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-box glass-card">
            <div class="auth-header">
                <h1><i class="fas fa-heart"></i> <?php echo e(SITE_NAME); ?></h1>
                <p>登录你的账号，记录你们的点点滴滴</p>
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

            <!-- 登录表单 -->
            <form method="POST" action="/login.php" class="auth-form" id="loginForm" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="login">

                <div class="form-group">
                    <label><i class="fas fa-user"></i> 用户名</label>
                    <input type="text" name="username" autofocus>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-lock"></i> 密码</label>
                    <input type="password" name="password">
                </div>

                <?php if ($turnstileEnabled && $turnstileSiteKey): ?>
                <div class="form-group turnstile-group">
                    <div class="cf-turnstile"
                         data-sitekey="<?php echo e($turnstileSiteKey); ?>">
                    </div>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-sign-in-alt"></i> 登录
                </button>
            </form>

            <?php if ($registerEnabled): ?>
            <div class="auth-divider">
                <span>或</span>
            </div>

            <!-- 注册切换 Tab -->
            <div class="auth-tabs">
                <button class="tab-btn active" data-tab="register">注册新账号</button>
            </div>

            <!-- 注册表单 -->
            <form method="POST" action="/login.php" class="auth-form" id="registerForm" style="display: none;" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="register">

                <div class="form-group">
                    <label><i class="fas fa-user"></i> 用户名</label>
                    <input type="text" name="username">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-user-tag"></i> 昵称</label>
                    <input type="text" name="nickname">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-lock"></i> 密码</label>
                    <input type="password" name="password">
                </div>

                <?php if ($turnstileEnabled && $turnstileSiteKey): ?>
                <div class="form-group turnstile-group">
                    <div class="cf-turnstile"
                         data-sitekey="<?php echo e($turnstileSiteKey); ?>">
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label><i class="fas fa-user-friends"></i> 角色（选择当前用户在网站中的身份）</label>
                    <div class="role-toggle">
                        <label class="role-option">
                            <input type="radio" name="role" value="user1" checked>
                            <span class="role-option-inner">
                                <i class="fas fa-mars role-male-icon"></i>
                                <span class="role-text-main">男生</span>
                                <span class="role-text-sub">（角色 1）</span>
                            </span>
                        </label>
                        <label class="role-option">
                            <input type="radio" name="role" value="user2">
                            <span class="role-option-inner">
                                <i class="fas fa-venus role-female-icon"></i>
                                <span class="role-text-main">女生</span>
                                <span class="role-text-sub">（角色 2）</span>
                            </span>
                        </label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-user-plus"></i> 注册
                </button>
            </form>
            <?php else: ?>
            <p style="margin-top: 1.5rem; text-align: center; color: var(--text-light);">
                当前已注册满两位用户，注册入口已关闭。
            </p>
            <?php endif; ?>

            <div class="auth-footer">
                <a href="/" class="back-link">
                    <i class="fas fa-arrow-left"></i> 返回首页
                </a>
            </div>
        </div>
    </div>

    <script src="/assets/js/main.js"></script>
    <script src="/assets/js/auth.js"></script>
</body>
</html>
