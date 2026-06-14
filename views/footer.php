    </main>

    <footer class="main-footer">
        <div class="footer-content">
            <?php
            // 获取备案号和底部版权信息
            $icpBeian = '';
            $footerCopyright = '';
            if (isset($db) && is_object($db)) {
                try {
                    $row = $db->fetch("SELECT value FROM settings WHERE `key` = 'icp_beian'");
                    if ($row && !empty($row['value'])) {
                        $icpBeian = $row['value'];
                    }
                    $row = $db->fetch("SELECT value FROM settings WHERE `key` = 'site_footer_copyright'");
                    if ($row && !empty($row['value'])) {
                        $footerCopyright = $row['value'];
                    }
                } catch (Exception $e) {
                    // 忽略读取失败的异常
                }
            } elseif (class_exists('Database')) {
                try {
                    $db = Database::getInstance();
                    $row = $db->fetch("SELECT value FROM settings WHERE `key` = 'icp_beian'");
                    if ($row && !empty($row['value'])) {
                        $icpBeian = $row['value'];
                    }
                    $row = $db->fetch("SELECT value FROM settings WHERE `key` = 'site_footer_copyright'");
                    if ($row && !empty($row['value'])) {
                        $footerCopyright = $row['value'];
                    }
                } catch (Exception $e) {
                    // 忽略读取失败的异常
                }
            }
            
            // 如果设置了备案号，显示备案号链接
            if (!empty($icpBeian)):
            ?>
            <p class="footer-beian">
                <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer">
                    <?php echo e($icpBeian); ?>
                </a>
            </p>
            <?php endif; ?>
            <?php if (!empty($footerCopyright)): ?>
            <p><?php echo e($footerCopyright); ?></p>
            <?php else: ?>
            <p>Coypright © <?php echo date('Y'); ?> <?php echo e(isset($siteTitle) ? $siteTitle : SITE_NAME); ?> All Rights Reserved.</p>
            <?php endif; ?>
        </div>
    </footer>

    <?php
    // 站点统计代码：从 settings.site_analytics_code 读取并原样输出
    $analyticsCode = '';
    try {
        if (isset($db) && is_object($db)) {
            $row = $db->fetch("SELECT value FROM settings WHERE `key` = 'site_analytics_code'");
        } elseif (class_exists('Database')) {
            $db  = Database::getInstance();
            $row = $db->fetch("SELECT value FROM settings WHERE `key` = 'site_analytics_code'");
        } else {
            $row = null;
        }
        if ($row && !empty($row['value'])) {
            $analyticsCode = $row['value'];
        }
    } catch (Exception $e) {
        // 忽略读取失败的异常
    }

    if (!empty($analyticsCode)) {
        // 统计代码允许包含 HTML/JS，因此不进行转义，直接输出
        echo $analyticsCode;
    }
    ?>

    <script src="/assets/js/main.js"></script>
</body>
</html>
