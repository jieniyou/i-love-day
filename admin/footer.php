    </main>
</div>

<nav class="admin-tabbar">
    <div class="admin-tabbar-inner">
        <a href="/admin/index.php"
           class="admin-tab-item <?php echo $adminPage === 'dashboard' ? 'admin-tab-item-active' : ''; ?>">
            <i class="fas fa-home"></i>
            <span>概览</span>
        </a>
        <a href="/admin/articles.php"
           class="admin-tab-item <?php echo $adminPage === 'articles' ? 'admin-tab-item-active' : ''; ?>">
            <i class="fas fa-book-open"></i>
            <span>内容</span>
        </a>
        <a href="/admin/albums.php"
           class="admin-tab-item <?php echo $adminPage === 'albums' ? 'admin-tab-item-active' : ''; ?>">
            <i class="fas fa-images"></i>
            <span>相册</span>
        </a>
        <a href="/admin/messages.php"
           class="admin-tab-item <?php echo $adminPage === 'messages' ? 'admin-tab-item-active' : ''; ?>">
            <i class="fas fa-comment-dots"></i>
            <span>留言</span>
        </a>
    </div>
</nav>

<div class="admin-modal-backdrop" id="adminConfirmBackdrop">
    <div class="admin-modal">
        <div class="admin-modal-header">操作确认</div>
        <div class="admin-modal-body" id="adminConfirmMessage">
            确认执行该操作？
        </div>
        <div class="admin-modal-actions">
            <button type="button" class="btn btn-secondary" data-admin-confirm="cancel">取消</button>
            <button type="button" class="btn btn-primary" data-admin-confirm="ok">确定</button>
        </div>
    </div>
</div>

<script src="/assets/js/admin_v2.js"></script>
</body>
</html>
