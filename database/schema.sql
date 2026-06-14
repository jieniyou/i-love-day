-- 站点数据库结构（安装脚本使用）

-- 用户表
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL COMMENT '用户名',
  `password` varchar(255) NOT NULL COMMENT '密码（加密后）',
  `nickname` varchar(50) NOT NULL COMMENT '昵称',
  `role` enum('user1','user2','admin') NOT NULL DEFAULT 'user1' COMMENT '角色',
  `qq` varchar(32) DEFAULT NULL COMMENT 'QQ 号',
  `avatar` varchar(255) DEFAULT NULL COMMENT '头像地址',
  `avatar_source` varchar(20) DEFAULT 'upload' COMMENT '头像来源',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active' COMMENT '状态',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';

-- 文章表
CREATE TABLE IF NOT EXISTS `articles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '作者用户ID',
  `title` varchar(200) NOT NULL COMMENT '标题',
  `content` text NOT NULL COMMENT '内容',
  `type` enum('article','diary') NOT NULL DEFAULT 'article' COMMENT '类型',
  `is_encrypted` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否加密',
  `tags` varchar(255) DEFAULT NULL COMMENT '标签，逗号分隔',
  `comments_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否允许评论（1=允许，0=关闭）',
  `views` int(11) NOT NULL DEFAULT 0 COMMENT '浏览量',
  `status` enum('published','draft','deleted') NOT NULL DEFAULT 'published' COMMENT '状态',
  `edit_mode` enum('full','blocks','chat') NOT NULL DEFAULT 'full' COMMENT '编辑模式：full=整篇富文本，blocks=块级对话，chat=聊天创作',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章表';

-- 相册表
CREATE TABLE IF NOT EXISTS `albums` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '创建用户ID',
  `name` varchar(100) NOT NULL COMMENT '相册名称',
  `description` text DEFAULT NULL COMMENT '描述',
  `cover_image` varchar(255) DEFAULT NULL COMMENT '封面图片',
  `is_encrypted` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否加密',
  `keep_original_quality` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否保留原始画质（0=默认压缩，1=尽量不压缩主图）',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='相册表';

-- 相册图片表
CREATE TABLE IF NOT EXISTS `album_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `album_id` int(11) NOT NULL COMMENT '相册ID',
  `image_path` varchar(255) NOT NULL COMMENT '图片路径',
  `thumbnail_path` varchar(255) DEFAULT NULL COMMENT '缩略图路径',
  `is_optimized` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已按当前规则压缩',
  `skip_optimize` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否永久跳过主图压缩',
  `description` varchar(255) DEFAULT NULL COMMENT '描述',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序值',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `album_id` (`album_id`),
  KEY `sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='相册图片表';

-- 相册视频表
CREATE TABLE IF NOT EXISTS `album_videos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `album_id` int(11) NOT NULL COMMENT '相册ID',
  `video_path` varchar(255) NOT NULL COMMENT '视频路径',
  `poster_path` varchar(255) DEFAULT NULL COMMENT '封面图片路径',
  `description` varchar(255) DEFAULT NULL COMMENT '视频描述',
  `uploader_id` int(11) DEFAULT NULL COMMENT '上传用户ID',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序值',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `album_id` (`album_id`),
  KEY `sort_order` (`sort_order`),
  KEY `uploader_id` (`uploader_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='相册视频表';

-- 事件表（精简版本，无颜色字段）
CREATE TABLE IF NOT EXISTS `events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '创建用户ID',
  `title` varchar(200) NOT NULL COMMENT '事件标题',
  `description` text DEFAULT NULL COMMENT '事件描述',
  `event_date` date NOT NULL COMMENT '事件日期',
  `icon` varchar(50) DEFAULT 'heart' COMMENT '图标',
  `is_important` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否重要',
  `is_recurring` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否每年重复',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序值，越小越靠前',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `event_date` (`event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='事件表';

-- 留言表
CREATE TABLE IF NOT EXISTS `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '主人用户ID',
  `guest_nickname` varchar(100) DEFAULT NULL COMMENT '访客昵称',
  `guest_avatar` varchar(255) DEFAULT NULL COMMENT '访客头像',
  `guest_qq` varchar(20) DEFAULT NULL COMMENT '访客QQ',
  `ip` varchar(45) DEFAULT NULL COMMENT '留言IP',
  `location` varchar(255) DEFAULT NULL COMMENT 'IP归属地',
  `content` text NOT NULL COMMENT '留言内容',
  `is_public` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否公开',
  `status` enum('published','deleted') NOT NULL DEFAULT 'published' COMMENT '状态',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='留言表';

-- 评论表
CREATE TABLE IF NOT EXISTS `comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `article_id` int(11) DEFAULT NULL COMMENT '文章ID',
  `album_id` int(11) DEFAULT NULL COMMENT '相册ID',
  `event_id` int(11) DEFAULT NULL COMMENT '事件ID',
  `user_id` int(11) NOT NULL COMMENT '评论用户ID',
  `guest_nickname` varchar(100) DEFAULT NULL COMMENT '访客昵称',
  `guest_avatar` varchar(255) DEFAULT NULL COMMENT '访客头像',
  `guest_qq` varchar(20) DEFAULT NULL COMMENT '访客QQ',
  `ip` varchar(45) DEFAULT NULL COMMENT '评论IP',
  `location` varchar(255) DEFAULT NULL COMMENT 'IP归属地',
  `content` text NOT NULL COMMENT '评论内容',
  `parent_id` int(11) DEFAULT NULL COMMENT '父评论ID',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `article_id` (`article_id`),
  KEY `album_id` (`album_id`),
  KEY `event_id` (`event_id`),
  KEY `user_id` (`user_id`),
  KEY `parent_id` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论表';

-- 登录尝试记录表（防爆破）
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_username_ip_time` (`username`,`ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录尝试记录';

-- 评论尝试记录表（评论节流）
CREATE TABLE IF NOT EXISTS `comment_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论尝试记录';

-- 评论IP黑名单表
CREATE TABLE IF NOT EXISTS `comment_ip_blacklist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL COMMENT 'IP地址',
  `reason` varchar(255) DEFAULT NULL COMMENT '拉黑原因',
  `expires_at` datetime DEFAULT NULL COMMENT '过期时间，NULL 表示永久',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论IP黑名单';

-- 留言尝试记录表（留言节流）
CREATE TABLE IF NOT EXISTS `message_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='留言尝试记录';

-- 点赞表
CREATE TABLE IF NOT EXISTS `likes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `article_id` int(11) DEFAULT NULL COMMENT '文章ID',
  `album_id` int(11) DEFAULT NULL COMMENT '相册ID',
  `event_id` int(11) DEFAULT NULL COMMENT '事件ID',
  `user_id` int(11) NOT NULL COMMENT '点赞用户ID',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_like` (`user_id`,`article_id`,`album_id`,`event_id`),
  KEY `article_id` (`article_id`),
  KEY `album_id` (`album_id`),
  KEY `event_id` (`event_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='点赞表';

-- 文章权限表（是否允许另一半编辑）
CREATE TABLE IF NOT EXISTS `article_permissions` (
  `article_id` int(11) NOT NULL COMMENT '文章ID',
  `allow_partner_edit` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否允许另一半编辑',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章权限表';

-- 文章编辑记录表（用于共创判断）
CREATE TABLE IF NOT EXISTS `article_edit_logs` (
  `article_id` int(11) NOT NULL COMMENT '文章ID',
  `user_id` int(11) NOT NULL COMMENT '编辑用户ID',
  `last_edited_at` datetime NOT NULL COMMENT '最后编辑时间',
  PRIMARY KEY (`article_id`, `user_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章编辑记录';

-- 文章贡献统计（记录双方各自贡献字数）
CREATE TABLE IF NOT EXISTS `article_contributions` (
  `article_id` int(11) NOT NULL COMMENT '文章ID',
  `user_id` int(11) NOT NULL COMMENT '用户ID',
  `contributed_chars` int(11) NOT NULL DEFAULT 0 COMMENT '累计贡献字数',
  `last_updated_at` datetime NOT NULL COMMENT '最后更新时间',
  PRIMARY KEY (`article_id`, `user_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章贡献统计';

-- 文章文字归属段落表（逐字级统计）
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

-- 文章块级内容归属表（按块记录 HTML 归属）
CREATE TABLE IF NOT EXISTS `article_blocks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `article_id` int(11) NOT NULL COMMENT '文章ID',
  `block_index` int(11) NOT NULL COMMENT '块索引（从0开始）',
  `user_id` int(11) NOT NULL COMMENT '作者用户ID',
  `speaker` enum('male','female','system') DEFAULT NULL COMMENT '说话人：男主/女主/系统',
  `html` mediumtext NOT NULL COMMENT '该块的 HTML 内容',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '最后更新时间',
  PRIMARY KEY (`id`),
  KEY `article_id` (`article_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章块级内容归属';

-- 相册权限表（是否允许另一半编辑/上传）
CREATE TABLE IF NOT EXISTS `album_permissions` (
  `album_id` int(11) NOT NULL COMMENT '相册ID',
  `allow_partner_edit` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否允许另一半编辑与上传',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`album_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='相册权限表';

-- 相册图片上传者映射表
CREATE TABLE IF NOT EXISTS `album_image_uploads` (
  `image_id` int(11) NOT NULL COMMENT '图片ID',
  `user_id` int(11) NOT NULL COMMENT '上传用户ID',
  `created_at` datetime NOT NULL COMMENT '记录创建时间',
  PRIMARY KEY (`image_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='相册图片上传者映射';

-- 系统设置表
CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL COMMENT '键名',
  `value` text DEFAULT NULL COMMENT '键值',
  `description` varchar(255) DEFAULT NULL COMMENT '描述',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置表';

-- 插入默认设置
INSERT IGNORE INTO `settings` (`key`, `value`, `description`) VALUES
('love_date', '', '恋爱开始日期'),
('site_title', 'I Love Day', '网站标题'),
('site_description', '记录我们的小小点滴', '网站描述'),
('video_upload_ignore_site_limit', '0', '视频上传是否忽略站点单文件大小限制（仅受服务器限制）'),
('turnstile_enabled', '0', '是否启用 Cloudflare Turnstile 登录验证'),
('turnstile_site_key', '', 'Cloudflare Turnstile Site Key'),
('turnstile_secret_key', '', 'Cloudflare Turnstile Secret Key');
