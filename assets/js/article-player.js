document.addEventListener('DOMContentLoaded', function () {
    if (typeof Plyr === 'undefined') return;

    function activateVideo(video) {
        if (!video || video._plyrInited) return;

        // 恢复 data-src 到 src，实现懒加载
        var dataSrc = video.getAttribute('data-src');
        if (dataSrc && !video.getAttribute('src')) {
            video.setAttribute('src', dataSrc);
        }

        var sources = video.querySelectorAll('source[data-src]');
        if (sources && sources.length) {
            sources.forEach(function (s) {
                if (!s.getAttribute('src')) {
                    s.setAttribute('src', s.getAttribute('data-src'));
                }
            });
        }

        video._plyrInited = true;

        try {
            // 初始化 Plyr 播放器
            var player = new Plyr(video, {
                controls: [
                    'play-large',
                    'play',
                    'progress',
                    'current-time',
                    'duration',
                    'mute',
                    'volume',
                    'fullscreen'
                ]
            });

            // 若原始 <video> 上设置了宽度 / 最大宽度（例如 style="max-width:30%" 或 data-plyr-width="30%"），
            // 则将其同步到 Plyr 容器，避免播放器始终占满整行宽度。
            try {
                var container = player && player.elements && player.elements.container ? player.elements.container : null;
                if (container) {
                    // 优先使用后端标记的 data-plyr-* 宽度
                    var dataMaxWidth = video.getAttribute('data-plyr-max-width') || '';
                    var dataWidth    = video.getAttribute('data-plyr-width') || '';

                    if (dataMaxWidth) {
                        container.style.maxWidth = dataMaxWidth;
                    }
                    if (dataWidth) {
                        container.style.width = dataWidth;
                    } else {
                        // 回退到内联样式或 width 属性
                        var styleWidth    = video.style.width || '';
                        var styleMaxWidth = video.style.maxWidth || '';
                        var attrWidth     = video.getAttribute('width') || '';

                        if (styleMaxWidth) {
                            container.style.maxWidth = styleMaxWidth;
                        }
                        if (styleWidth) {
                            container.style.width = styleWidth;
                        } else if (attrWidth) {
                            container.style.width = attrWidth;
                        }
                    }
                }
            } catch (e) {
                // 同步宽度失败不影响主流程
            }

            // 对于竖屏视频（高 > 宽），允许容器使用“自然比例”而不是固定 16:9，
            // 避免在文章中出现高度过高或过矮的黑色区域。
            var applyNaturalRatio = function () {
                try {
                    var w = video.videoWidth;
                    var h = video.videoHeight;
                    if (!w || !h) return;
                    // 仅在明显竖屏时启用自然比例（避免影响普通横屏视频）
                    if (h <= w) return;

                    var containerEl = container || video.closest('.plyr');
                    var wrapper = video.closest('.plyr__video-wrapper');
                    if (!containerEl || !wrapper) return;

                    containerEl.classList.add('plyr--natural-ratio');
                    wrapper.style.paddingBottom = '0';
                    wrapper.style.height = 'auto';
                } catch (e) {
                    // 忽略比例调整失败，保持默认行为
                }
            };

            if (video.readyState >= 1) {
                applyNaturalRatio();
            } else {
                video.addEventListener('loadedmetadata', function onNaturalMeta() {
                    video.removeEventListener('loadedmetadata', onNaturalMeta);
                    applyNaturalRatio();
                });
            }
        } catch (e) {
            video._plyrInited = false;
        }
    }

    var videos = document.querySelectorAll('video.plyr-video');
    if (!videos.length) return;

    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var v = entry.target;
                io.unobserve(v);
                activateVideo(v);
            });
        }, {
            root: null,
            rootMargin: '0px 0px 200px 0px',
            threshold: 0.01
        });

        videos.forEach(function (v) {
            io.observe(v);
        });
    } else {
        videos.forEach(function (v) {
            activateVideo(v);
        });
    }
});
