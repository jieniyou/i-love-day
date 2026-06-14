<?php
// 新版后台 - 个人资料（移动端优先）
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

$sessionUser = $auth->getCurrentUser();
if (!$sessionUser) {
    $auth->logout();
    header('Location: /login.php');
    exit;
}

$currentUser = $db->fetch("SELECT * FROM users WHERE id = :id LIMIT 1", ['id' => $sessionUser['id']]);
if (!$currentUser) {
    $auth->logout();
    header('Location: /login.php');
    exit;
}

$error   = '';
$success = '';

if (isset($_GET['success']) && $_GET['success'] === '1') {
    $success = '资料更新成功';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $nickname        = trim($_POST['nickname'] ?? '');
    $qq              = trim($_POST['qq'] ?? '');
    $avatarSource    = $_POST['avatar_source'] ?? 'upload';
    $newPassword     = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($nickname === '') {
        $error = '昵称不能为空';
    } elseif ($newPassword !== '' && $newPassword !== $confirmPassword) {
        $error = '两次输入的新密码不一致';
    } else {
        $data = [
            'nickname'      => $nickname,
            'qq'            => $qq,
            'avatar_source' => $avatarSource,
        ];

        $newAvatarUrl = null;

        if ($avatarSource === 'qq') {
            if ($qq === '') {
                $error = '请选择 QQ 头像时，请先填写 QQ 号';
            } else {
                // 官方 QQ 头像接口：直接按 QQ 号生成头像 URL
                // https://q1.qlogo.cn/g?b=qq&nk=QQ号码&s=640
                $newAvatarUrl   = 'https://q1.qlogo.cn/g?b=qq&nk=' . urlencode($qq) . '&s=640';
                $data['avatar'] = $newAvatarUrl;
            }
        } elseif ($avatarSource === 'upload') {
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $upload = uploadFile($_FILES['avatar'], 'avatars');
                if (!empty($upload['success'])) {
                    $newAvatarUrl   = $upload['url'];
                    $data['avatar'] = $newAvatarUrl;
                } else {
                    $error = $upload['message'] ?? '头像上传失败';
                }
            }
        }

        if ($error === '') {
            if ($newAvatarUrl && !empty($currentUser['avatar']) && strpos($currentUser['avatar'], '/uploads/') === 0) {
                deleteFile(str_replace(UPLOAD_URL, '', $currentUser['avatar']));
            }

            if ($newPassword !== '') {
                $data['new_password'] = $newPassword;
            }

            $auth->updateProfile($data);

            header('Location: profile.php?success=1');
            exit;
        } else {
            $currentUser['nickname']      = $nickname;
            $currentUser['qq']            = $qq;
            $currentUser['avatar_source'] = $avatarSource;
        }
    }
}

$adminPage = 'profile';

include __DIR__ . '/header.php';
?>

    <section class="admin-page-title">
        <h1>个人资料</h1>
        <p>修改你的昵称、头像和登录密码</p>
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

    <form method="POST" enctype="multipart/form-data" class="admin-card" novalidate>
        <?php echo csrf_field(); ?>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">用户名</label>
            <input
                type="text"
                value="<?php echo e($currentUser['username']); ?>"
                disabled
                style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px dashed rgba(148,163,184,0.7);background:#f9fafb;font-size:0.9rem;">
            <div style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                用户名暂不支持修改。
            </div>
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">昵称 *</label>
            <input
                type="text"
                name="nickname"
                value="<?php echo e($currentUser['nickname'] ?? ''); ?>"
                style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;">
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">QQ 号</label>
            <input
                type="text"
                name="qq"
                value="<?php echo e($currentUser['qq'] ?? ''); ?>"
                placeholder="填写 QQ 号可通过 QQ 接口获取头像"
                style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;">
            <input type="hidden" name="qq_avatar_url" id="qqAvatarUrlField" value="">
            <button
                type="button"
                id="fetchQQAvatarBtn"
                class="btn btn-secondary"
                style="margin-top:0.4rem;padding:0.25rem 0.65rem;font-size:0.8rem;border-radius:999px;display:inline-flex;align-items:center;gap:0.35rem;">
                <i class="fas fa-sync-alt" id="fetchQQAvatarIcon"></i>
                <span>从 QQ 获取头像并预览</span>
            </button>
            <div id="fetchQQAvatarHint" style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                仅用于预览，保存时会再次通过 QQ 接口获取头像。
            </div>
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">头像来源</label>
            <?php $avatarSource = $currentUser['avatar_source'] ?? 'upload'; ?>
            <div style="display:flex;gap:1rem;flex-wrap:wrap;font-size:0.85rem;">
                <label style="display:flex;align-items:center;gap:0.35rem;">
                    <input type="radio" name="avatar_source" value="upload" <?php echo $avatarSource === 'upload' ? 'checked' : ''; ?>>
                    <span>上传头像</span>
                </label>
                <label style="display:flex;align-items:center;gap:0.35rem;">
                    <input type="radio" name="avatar_source" value="qq" <?php echo $avatarSource === 'qq' ? 'checked' : ''; ?>>
                    <span>使用 QQ 头像</span>
                </label>
            </div>
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">当前头像</label>
            <div style="margin-bottom:0.75rem;">
                <img src="<?php echo e($currentUser['avatar']); ?>" alt="头像"
                     style="width:80px;height:80px;border-radius:50%;object-fit:cover;box-shadow:var(--shadow-md);">
            </div>
            <input type="file" name="avatar" accept="image/*" style="font-size:0.85rem;">
            <?php
            $maxUploadBytes = get_max_upload_size_bytes();
            $maxUploadMb    = round($maxUploadBytes / 1024 / 1024, 1);
            ?>
            <div style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                支持 JPG / PNG / GIF / WebP，建议尺寸不小于 200×200，单文件最大约 <?php echo $maxUploadMb; ?>MB。
            </div>
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">新密码</label>
            <input
                type="password"
                name="new_password"
                placeholder="留空则不修改密码"
                style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;">
        </div>

        <div class="form-group" style="margin-bottom:1rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">确认新密码</label>
            <input
                type="password"
                name="confirm_password"
                placeholder="再次输入新密码"
                style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;">
        </div>

        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i>
                <span>保存修改</span>
            </button>
        </div>
    </form>

<?php include __DIR__ . '/footer.php'; ?>

<style>
@keyframes qqAvatarSpin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
.qq-avatar-spin {
    animation: qqAvatarSpin 0.8s linear infinite;
}
</style>

<script>
(function () {
    var btn = document.getElementById('fetchQQAvatarBtn');
    if (!btn) return;

    var qqInput = document.querySelector('input[name="qq"]');
    var avatarImg = document.querySelector('img[alt="头像"]');
    var avatarSourceRadios = document.querySelectorAll('input[name="avatar_source"]');
    var hint = document.getElementById('fetchQQAvatarHint');
    var icon = document.getElementById('fetchQQAvatarIcon');
    var qqAvatarField = document.getElementById('qqAvatarUrlField');

    function setHint(text, isError) {
        if (!hint) return;
        hint.textContent = text;
        hint.style.color = isError ? '#b91c1c' : 'var(--text-light)';
    }

    btn.addEventListener('click', function () {
        if (!qqInput || !avatarImg) return;
        var qq = qqInput.value.trim();
        if (!qq) {
            setHint('请先填写 QQ 号，再尝试获取头像。', true);
            qqInput.focus();
            return;
        }

        setHint('正在生成 QQ 头像预览…', false);
        btn.disabled = true;
        if (icon) {
            icon.classList.add('qq-avatar-spin');
        }

        // 使用官方 QQ 头像地址，无需调用第三方接口
        var avatarUrl = 'https://q1.qlogo.cn/g?b=qq&nk=' + encodeURIComponent(qq) + '&s=640';
        avatarImg.src = avatarUrl;
        if (qqAvatarField) {
            qqAvatarField.value = avatarUrl;
        }
        // 自动切换头像来源为 QQ
        avatarSourceRadios.forEach(function (radio) {
            if (radio.value === 'qq') radio.checked = true;
        });
        setTimeout(function () {
            btn.disabled = false;
            if (icon) {
                icon.classList.remove('qq-avatar-spin');
            }
            setHint('预览成功，保存资料时会同步使用该 QQ 头像。', false);
        }, 400);
    });
})();
</script>
