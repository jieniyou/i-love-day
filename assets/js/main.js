/**
 * 主JavaScript文件
 */

// WebP 支持探测（用于懒加载时优先加载 WebP）
window.__supportsWebP = false;
(function() {
    try {
        var img = new Image();
        img.onload = function () {
            if (img.width > 0 && img.height > 0) {
                window.__supportsWebP = true;
            }
        };
        img.onerror = function () {
            window.__supportsWebP = false;
        };
        // 一个极小的 WebP 测试图片（1x1）
        img.src = 'data:image/webp;base64,UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA';
    } catch (e) {
        window.__supportsWebP = false;
    }
})();

// 页面加载完成后执行
document.addEventListener('DOMContentLoaded', function() {
    // 初始化所有功能
    initPageLoader();
    initToastSystem();
    initAnimations();
    initLazyLoading();
    initAlbumImagePrefetch();
    initHeaderBannerLazyLoad();
    initLoveCounter();
    initStatCounters();
    // initFloatingHearts(); // 可选：漂浮爱心动画
    initMasonryLayout();
    initInteractiveAnimations();
    initHomeSectionAnimations();
    initPageAnimations(); // 通用页面动画
});

/**
 * 顶部气泡通知系统
 */
function initToastSystem() {
    // 创建/获取容器
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

    // 暴露全局调用方法
    window.showToast = function(message, type) {
        if (!message) return;
        var container = getToastContainer();
        var toast = document.createElement('div');
        var msg = document.createElement('div');

        type = type || 'info';

        toast.className = 'toast' + (type ? ' toast-' + type : '');
        msg.className = 'toast-message';
        msg.textContent = message;

        toast.appendChild(msg);
        container.appendChild(toast);

        // 点击立即关闭
        toast.addEventListener('click', function () {
            toast.classList.add('toast-hide');
            setTimeout(function () {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 220);
        });

        // 自动关闭
        var duration = type === 'error' ? 5200 : 3600;
        setTimeout(function () {
            toast.classList.add('toast-hide');
        }, duration);
        setTimeout(function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, duration + 260);
    };

    // 将现有的 .alert 提示转换为气泡通知
    var alerts = document.querySelectorAll('.alert.alert-error, .alert.alert-success');
    alerts.forEach(function (el) {
        var text = (el.textContent || '').replace(/\s+/g, ' ').trim();
        if (!text) {
            el.style.display = 'none';
            return;
        }
        var type = el.classList.contains('alert-error') ? 'error' : 'success';
        window.showToast(text, type);
        el.style.display = 'none';
    });
}

/**
 * 页面加载动画 - 优化版
 */
function initPageLoader() {
    const loader = document.createElement('div');
    loader.className = 'page-loader';
    loader.innerHTML = `
        <div class="loader-content">
            <div class="triple-spinner"></div>
            <div class="loader-text">页面加载中...</div>
        </div>
    `;
    document.body.appendChild(loader);

    // 默认在页面完全就绪前，不触发真实图片加载
    if (typeof window.__pageReadyForImages === 'undefined') {
        window.__pageReadyForImages = false;
    }
    // 相册详情页图片提前加载标记：在 window.load 触发后置为 true，
    // 这样相册图片可以在加载动画淡出之前就开始请求。
    if (typeof window.__pageReadyForAlbumImages === 'undefined') {
        window.__pageReadyForAlbumImages = false;
    }

    let loaderClosed = false;

    function closeLoader() {
        if (loaderClosed) return;
        loaderClosed = true;

        loader.classList.add('fade-out');
        setTimeout(() => {
            if (loader.parentNode) {
                loader.remove();
            }

            // 标记页面已准备好，可以开始加载懒加载图片
            window.__pageReadyForImages = true;

            // 通知所有依赖“页面图片就绪”的组件（例如首页大图背景）可以开始加载
            try {
                if (typeof document !== 'undefined' && typeof document.dispatchEvent === 'function') {
                    let evt;
                    if (typeof Event === 'function') {
                        evt = new Event('pageReadyForImages');
                    } else if (document.createEvent) {
                        evt = document.createEvent('Event');
                        evt.initEvent('pageReadyForImages', true, true);
                    }
                    if (evt) {
                        document.dispatchEvent(evt);
                    }
                }
            } catch (e) {
                // 忽略事件派发错误，避免影响主流程
            }

            // 如果有在此之前已经进入视口但被“挂起”的懒加载图片，这里一次性触发
            if (typeof window.__flushPendingLazyImages === 'function') {
                window.__flushPendingLazyImages();
            }

            // 文章正文内图片：点击放大预览（复用相册灯箱样式）
            try {
                var articleImages = document.querySelectorAll('.article-detail .article-content img');
                if (articleImages.length) {
                    var imageList = Array.prototype.slice.call(articleImages);
                    var lightbox = document.getElementById('lightbox');

                    // 若当前页面没有相册灯箱结构，则为文章动态创建一个仅支持单张预览的简化版
                    if (!lightbox) {
                        lightbox = document.createElement('div');
                        lightbox.id = 'lightbox';
                        lightbox.className = 'lightbox';
                        lightbox.innerHTML = '' +
                            '<span class=\"lightbox-close\">&times;</span>' +
                            '<div class=\"lightbox-inner\">' +
                                '<div class=\"lightbox-card\"><img id=\"lightbox-img\" src=\"\" alt=\"\"></div>' +
                            '</div>';
                        document.body.appendChild(lightbox);
                    }

                    var lightboxImg   = document.getElementById('lightbox-img');
                    var closeIcon     = lightbox.querySelector('.lightbox-close');
                    function openArticleLightbox(imgEl) {
                        if (!imgEl || !lightboxImg) return;
                        var src = imgEl.currentSrc || imgEl.dataset.src || imgEl.src;
                        if (!src) return;
                        lightboxImg.src = src;
                        lightbox.classList.add('active');
                    }

                    function closeArticleLightbox() {
                        lightbox.classList.remove('active');
                    }

                    imageList.forEach(function (img) {
                        img.addEventListener('click', function (e) {
                            e.stopPropagation();
                            openArticleLightbox(img);
                        });
                    });

                    lightbox.addEventListener('click', function (e) {
                        if (e.target === lightbox) {
                            closeArticleLightbox();
                        }
                    });

                    if (closeIcon) {
                        closeIcon.addEventListener('click', function (e) {
                            e.stopPropagation();
                            closeArticleLightbox();
                        });
                    }

                    // ESC 关闭文章灯箱
                    document.addEventListener('keydown', function (e) {
                        if (e.key === 'Escape' && lightbox.classList.contains('active')) {
                            closeArticleLightbox();
                        }
                    });
                }
            } catch (e) {
                // 忽略文章图片灯箱绑定失败
            }
        }, 600);
    }

    // 正常情况：等 window.load 后略微延迟再关闭
    window.addEventListener('load', function() {
        // 一旦 window.load 触发，就允许相册详情页图片开始加载，
        // 利用加载动画展示期间这段时间尽早准备好图片。
        window.__pageReadyForAlbumImages = true;
        if (typeof window.__flushPendingAlbumImages === 'function') {
            window.__flushPendingAlbumImages();
        }
        setTimeout(closeLoader, 200);
    });

    // 兜底方案：即使 window.load 长时间不触发，也强制在最大时长后关闭加载动画
    // 避免因为外部资源（如第三方字体/CDN 图片）挂起导致页面一直停留在“页面加载中…”
    const MAX_LOADER_DURATION = 8000; // ms
    setTimeout(closeLoader, MAX_LOADER_DURATION);
}

/**
 * 初始化卡片进入动画
 */
function initAnimations() {
    const cards = document.querySelectorAll(
        '.glass-card, .event-card, .article-card, .album-card, .article-card-large, ' +
        '.article-card-large, .event-pill, .stat-card, .home-message-card'
    );

    // 定义动画类型数组（更自然的动画）
    const animationTypes = [
        'fadeInUp',
        'fadeInDown',
        'fadeInLeft',
        'fadeInRight',
        'slideInUp',
        'slideInDown',
        'slideInLeft',
        'slideInRight',
        'zoomIn',
        'scaleIn'
    ];

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry, index) => {
            if (entry.isIntersecting) {
                const card = entry.target;

                // 相册卡片和首页留言卡片由瀑布流布局函数单独处理，这里直接跳过
                if ((card.classList.contains('album-card') &&
                     card.closest('.albums-masonry')) ||
                    // 留言卡片（首页预览和留言墙页）统一交给瀑布流布局处理淡入动画
                    (card.classList.contains('home-message-card') &&
                     (card.closest('.home-messages-masonry') || card.closest('.messages-masonry'))) ||
                    (card.classList.contains('message-item') &&
                     card.closest('.messages-masonry'))) {
                    return;
                }

                // 首页动态卡片：使用不规则滑入动画，单独处理，不与其他动画冲突
                if (card.classList.contains('article-card-large')) {
                    // 不规则滑入：和相册卡片瀑布流一样的效果 - 轻微下移+透明，然后滑入
                    // 每个卡片有随机的延迟和轻微的左右偏移，创造不规则感
                    const baseOffsetY = 18;
                    const randomExtraY = Math.random() * 12; // 额外 Y 位移（0-12px）
                    const offsetY = baseOffsetY + randomExtraY;
                    const direction = Math.random() > 0.5 ? 1 : -1;
                    const offsetX = direction * (Math.random() * 8); // 左右轻微偏移（0-8px）
                    const delay = index * 80 + Math.random() * 40; // 基础延迟 + 随机延迟（0-40ms）
                    // 立即清除所有可能存在的动画类和样式，确保只执行一次动画
                    card.classList.remove('animate-ready', 'animate-in', 'animate-fadeInUp', 'animate-fadeInDown', 'animate-dynamic-card');
                    card.removeAttribute('style');
                    card.setAttribute('data-animation-handled', 'true');

                    // 设置初始状态
                    card.style.opacity = '0';
                    card.style.transform = `translate3d(${offsetX}px, ${offsetY}px, 0)`;
                    card.style.transition = `opacity 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94) ${delay}ms, transform 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94) ${delay}ms`;

                    requestAnimationFrame(() => {
                        setTimeout(() => {
                            card.style.opacity = '1';
                            card.style.transform = 'translate3d(0, 0, 0)';
                            card.classList.add('animate-in', 'animate-dynamic-card');
                            setTimeout(() => {
                                card.classList.add('animation-complete');
                                card.style.transition = '';
                            }, 600 + delay);
                        }, 50);
                    });

                    observer.unobserve(card);
                    return; // 提前返回，不执行后续的通用动画
                }

                // 根据卡片类型选择不同的动画（更自然的动画）
                let animationType;
                if (card.classList.contains('event-pill')) {
                    // 事件卡片：原地上下翻转
                    animationType = 'flipInY';
                } else if (card.classList.contains('stat-card')) {
                    // 统计卡片：缩放进入
                    animationType = 'zoomIn';
                } else {
                    // 其他卡片：从动画数组中循环选择
                    animationType = animationTypes[index % animationTypes.length];
                }

                // 通用类动画
                requestAnimationFrame(() => {
                    setTimeout(() => {
                        // 添加动画类
                        card.classList.add('animate-in', `animate-${animationType}`);
                        // 动画完成后移除will-change以释放资源
                        setTimeout(() => {
                            card.classList.add('animation-complete');
                        }, card.classList.contains('event-pill') ? 800 : 600); // flipInY动画是0.8s
                    }, index * 80);
                });

                observer.unobserve(card);
            }
        });
    }, {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    });

    cards.forEach((card) => {
        // 动态卡片不添加 animate-ready 类，避免CSS动画类冲突
        if (!card.classList.contains('article-card-large')) {
            // 事件卡片：只添加类，不设置内联样式，让CSS完全控制
            if (card.classList.contains('event-pill')) {
                // 确保初始状态是隐藏的（由CSS控制）
                card.style.visibility = 'visible'; // 保持可见性，但opacity由CSS控制
            }
            card.classList.add('animate-ready');
        }
        observer.observe(card);
    });
}

/**
 * 初始化懒加载
 */
function initLazyLoading() {
    const images = document.querySelectorAll('img[data-src]');

    // 用于记录在页面未完全就绪时，已经进入视口但尚未真正加载的图片
    const pendingImages = new Set();

    if (typeof window.__pageReadyForImages === 'undefined') {
        window.__pageReadyForImages = false;
    }
    // 相册详情页图片允许在加载动画仍显示时提前加载，
    // 通过单独的标记与普通图片区分开。
    if (typeof window.__pageReadyForAlbumImages === 'undefined') {
        window.__pageReadyForAlbumImages = false;
    }

    // 为懒加载图片设置一个最小可感知的过渡时间，避免在极快网络下“瞬移”
    const MIN_FADE_DURATION = 180; // ms

    function loadLazyImage(img, observer) {
        if (!img || !img.dataset) return;

        // 记录开始进入懒加载阶段的时间
        if (!img.dataset.lazyStart) {
            img.dataset.lazyStart = String(Date.now());
        }

        // 懒加载时再标记为 lazy-image，保留占位图可见
        img.classList.add('lazy-image');

        const handleLoad = () => {
            const start = parseInt(img.dataset.lazyStart || Date.now(), 10);
            const elapsed = Date.now() - start;
            const remaining = Math.max(0, MIN_FADE_DURATION - elapsed);

            setTimeout(() => {
                img.classList.add('image-loaded');
                delete img.dataset.lazyStart;

                // 如果该图片是通过预取方式加载的，加载完成后可安全释放 objectURL
                if (img.dataset.prefetchedUrl) {
                    try {
                        URL.revokeObjectURL(img.dataset.prefetchedUrl);
                    } catch (e) {
                        // 忽略释放错误，避免影响主流程
                    }
                    delete img.dataset.prefetchedUrl;
                }

                // 相册详情页瀑布流：在相册图片真正加载完成并标记为 image-loaded 后，
                // 主动通知瀑布流布局进行一次重排，避免懒加载后高度变化导致的卡片重叠。
                const wall = img.closest('.album-photo-wall');
                if (wall) {
                    try {
                        let evt;
                        if (typeof Event === 'function') {
                            evt = new Event('albumPhotoLoaded');
                        } else if (document.createEvent) {
                            evt = document.createEvent('Event');
                            evt.initEvent('albumPhotoLoaded', true, true);
                        }
                        if (evt) {
                            document.dispatchEvent(evt);
                        }
                    } catch (e) {
                        // 忽略事件派发错误，避免影响主流程
                    }
                }
            }, remaining);
        };

        const isAlbumImage = img.closest('.album-photo-media') || img.closest('.album-grid-item');

        if (isAlbumImage) {
            // 相册相关图片：只在真正的 data-src 加载完成后再标记 image-loaded
            img.addEventListener('load', handleLoad, { once: true });
        } else {
            // 其他图片：如果已经缓存完成，直接触发淡入；否则等待 load 事件
            if (img.complete && img.naturalWidth !== 0) {
                handleLoad();
            } else {
                img.addEventListener('load', handleLoad, { once: true });
            }
        }

        // 目标 URL：优先选择 WebP，其次回退到原始 URL。
        let targetUrl = img.dataset.src || '';
        const webpUrl = img.dataset.srcWebp || '';

        // 如果已有通过 fetch 预取的结果，优先使用预取 URL（通常为最终实际请求的 URL）
        const prefetchedUrl = img.dataset.prefetchedUrl;
        if (prefetchedUrl) {
            targetUrl = prefetchedUrl;
        } else if (webpUrl && window.__supportsWebP) {
            targetUrl = webpUrl;

            // 如果 WebP 加载失败，优先回退到原始 data-src
            const fallback = img.dataset.src || '';
            if (fallback) {
                img.addEventListener('error', function handleError() {
                    img.removeEventListener('error', handleError);
                    img.src = fallback;
                });
            }
        }

        if (targetUrl) {
            img.src = targetUrl;
        }

        img.removeAttribute('data-src');

        if (observer) {
            observer.unobserve(img);
        }
    }

    const imageObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                const isAlbumImage = img.closest('.album-photo-media') || img.closest('.album-grid-item');

                // 如果页面还没准备好加载图片，则将其加入待加载队列，等加载动画结束后再统一触发
                if (!window.__pageReadyForImages) {
                    // 相册详情页图片：在 window.load 之后即可开始加载，
                    // 不必等加载动画完全消失，从而在网络良好时尽可能提前准备好图片。
                    if (isAlbumImage && window.__pageReadyForAlbumImages) {
                        loadLazyImage(img, imageObserver);
                        return;
                    } else {
                        pendingImages.add(img);
                        return;
                    }
                }

                loadLazyImage(img, imageObserver);
            }
        });
    }, {
        // 更严格的懒加载：仅在接近视口时才触发加载
        root: null,
        rootMargin: '0px 0px 150px 0px',
        threshold: 0.01
    });

    images.forEach(img => imageObserver.observe(img));

    // 提供一个全局方法，供页面加载动画结束后调用：
    // 将之前进入视口但被挂起的图片一次性触发真正加载
    window.__flushPendingLazyImages = function() {
        if (!window.__pageReadyForImages) return;
        pendingImages.forEach((img) => {
            loadLazyImage(img, imageObserver);
            pendingImages.delete(img);
        });
    };

    // 提供一个仅针对相册详情页图片的刷新方法，在 window.load 之后即可调用，
    // 让已经进入视口的相册图片在加载动画仍显示时就开始加载。
    window.__flushPendingAlbumImages = function() {
        pendingImages.forEach((img) => {
            const isAlbumImage = img.closest('.album-photo-media') || img.closest('.album-grid-item');
            if (isAlbumImage) {
                loadLazyImage(img, imageObserver);
                pendingImages.delete(img);
            }
        });
    };
}

/**
 * 相册详情页图片预取：在不阻塞 window.load 的前提下，
 * 提前通过 fetch 预取首屏附近的相册图片，提升好网场景下的首屏体验。
 */
function initAlbumImagePrefetch() {
    if (typeof window.fetch !== 'function' || typeof window.Blob === 'undefined') {
        return;
    }

    const wall = document.querySelector('.album-photo-wall');
    if (!wall) return;

    const candidates = wall.querySelectorAll('.album-photo-media img[data-src]');
    if (!candidates.length) return;

    const PREFETCH_LIMIT = 8; // 预取前 8 张相册图片
    let count = 0;

    candidates.forEach((img) => {
        if (count >= PREFETCH_LIMIT) return;
        let url = img.dataset.src || '';
        const webpUrl = img.dataset.srcWebp || '';
        if (window.__supportsWebP && webpUrl) {
            url = webpUrl;
        }
        if (!url) return;

        count++;

        // 避免重复预取
        if (img.dataset.prefetching === '1' || img.dataset.prefetchedUrl) {
            return;
        }
        img.dataset.prefetching = '1';

        fetch(url, { cache: 'force-cache' })
            .then(function (resp) {
                if (!resp.ok) {
                    throw new Error('album image prefetch failed');
                }
                return resp.blob();
            })
            .then(function (blob) {
                const objectUrl = URL.createObjectURL(blob);
                img.dataset.prefetchedUrl = objectUrl;
            })
            .catch(function () {
                // 预取失败时不影响正常懒加载，忽略错误
            })
            .finally(function () {
                delete img.dataset.prefetching;
            });
    });
}

/**
 * 全站图片加载失败时的统一兜底：使用本地 Coverloaderror 图
 */
function initImageErrorFallback() {
    const FALLBACK_SRC = '/assets/images/Coverloaderror.jpg';

    function onError(e) {
        const img = e.target;
        if (!img || img.tagName !== 'IMG') return;

        // 已经是兜底图，或没有 src 时不处理
        if (!img.src || img.src.indexOf('Coverloaderror.jpg') !== -1) return;

        // 如果当前 src 是 webp，而 data-src 还有原始格式，先回退到原始 data-src
        if (img.dataset && img.dataset.src && img.src !== img.dataset.src) {
            img.src = img.dataset.src;
            return;
        }

        // 最终兜底：使用本地 Coverloaderror 图
        img.src = FALLBACK_SRC;
    }

    // 捕获阶段监听所有 IMG 的 error 事件，避免逐个绑
    document.addEventListener('error', onError, true);
}

/**
 * 首页大图懒加载（canvas 版）：
 * 使用隐藏的 Image 预加载 + canvas 一次性绘制，完全避免“分块/分段”显示，
 * 然后在 canvas 上做从模糊到清晰的线性过渡。
 */
function initHeaderBannerLazyLoad() {
    const banner = document.querySelector('.header-background[data-bg]');
    if (!banner) return;

    const url = banner.getAttribute('data-bg');
    if (!url) return;

    // 创建 canvas，用来承载最终画面
    let canvas = banner.querySelector('.header-image-canvas');
    if (!canvas) {
        canvas = document.createElement('canvas');
        canvas.className = 'header-image-canvas';
        const overlay = banner.querySelector('.header-overlay');
        if (overlay && overlay.parentNode === banner) {
            banner.insertBefore(canvas, overlay);
        } else {
            banner.insertBefore(canvas, banner.firstChild);
        }
    }

    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return;
    }

    let loadedImg = null;

    function handleImageReady(img) {
        if (!img || !img.naturalWidth || !img.naturalHeight) return;

        loadedImg = img;
        try {
            drawCoverImage(loadedImg);
        } catch (e) {
            // 绘制异常时保持背景色，避免报错打断后续逻辑
        }

        banner.removeAttribute('data-bg');
        banner.__bgLoaded = true;
        banner.__bgLoading = false;
        banner.__pendingBg = false;

        // 图片已经绘制到 canvas 后，再触发从高斯模糊到清晰的线性过渡
        requestAnimationFrame(function () {
            canvas.classList.add('header-image-canvas-loaded');
        });

        // 图片加载完成后，监听窗口尺寸变化，按 cover 规则重新绘制一次
        if (!banner.__headerCanvasResizeBound) {
            banner.__headerCanvasResizeBound = true;
            let resizeTimer = null;
            window.addEventListener('resize', function () {
                if (!loadedImg) return;
                if (resizeTimer) clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function () {
                    drawCoverImage(loadedImg);
                }, 150);
            });
        }
    }

    function drawCoverImage(img) {
        if (!img || !img.naturalWidth || !img.naturalHeight) return;

        const dpr = window.devicePixelRatio || 1;
        const rect = banner.getBoundingClientRect();
        const targetWidth = Math.max(1, Math.round(rect.width * dpr));
        const targetHeight = Math.max(1, Math.round(rect.height * dpr));

        canvas.width = targetWidth;
        canvas.height = targetHeight;

        // 让 CSS 尺寸保持与容器一致，避免高 DPI 下的拉伸模糊
        canvas.style.width = rect.width + 'px';
        canvas.style.height = rect.height + 'px';

        const imgRatio = img.naturalWidth / img.naturalHeight;
        const targetRatio = targetWidth / targetHeight;

        let drawWidth, drawHeight;
        if (imgRatio > targetRatio) {
            // 图片更宽，以高度为基准裁剪
            drawHeight = targetHeight;
            drawWidth = drawHeight * imgRatio;
        } else {
            // 图片更窄，以宽度为基准裁剪
            drawWidth = targetWidth;
            drawHeight = drawWidth / imgRatio;
        }

        const dx = (targetWidth - drawWidth) / 2;
        const dy = (targetHeight - drawHeight) / 2;

        ctx.clearRect(0, 0, targetWidth, targetHeight);
        ctx.drawImage(img, dx, dy, drawWidth, drawHeight);
    }

    // 直接通过 Image 加载：作为 fetch 失败时的兜底方案
    function startLoad() {
        if (banner.__bgLoaded || banner.__bgLoading) return;

        banner.__bgLoading = true;

        const loaderImg = new Image();
        loaderImg.decoding = 'async';

        loaderImg.onload = function () {
            handleImageReady(loaderImg);
        };

        loaderImg.onerror = function () {
            // 出错情况下不反复重试，保留纯黑背景，避免影响其他逻辑
            banner.__bgLoading = false;
            banner.__bgLoaded = true;
            banner.__pendingBg = false;
            banner.removeAttribute('data-bg');
        };

        loaderImg.src = url;
    }

    // 使用 fetch 提前请求大图数据，避免阻塞 window.load，
    // 然后在内存中解码并一次性绘制到 canvas。
    function startFetch() {
        if (banner.__bgLoaded || banner.__bgLoading) return;

        // 浏览器不支持 fetch 等特性时，退回到直接 Image 加载
        if (typeof window.fetch !== 'function' || typeof window.Blob === 'undefined') {
            startLoad();
            return;
        }

        banner.__bgLoading = true;

        fetch(url, { cache: 'force-cache' })
            .then(function (resp) {
                if (!resp.ok) {
                    throw new Error('banner image fetch failed');
                }
                return resp.blob();
            })
            .then(function (blob) {
                const objectUrl = URL.createObjectURL(blob);
                const img = new Image();
                img.decoding = 'async';

                img.onload = function () {
                    URL.revokeObjectURL(objectUrl);
                    handleImageReady(img);
                };

                img.onerror = function () {
                    URL.revokeObjectURL(objectUrl);
                    // fetch 成功但解码失败时，退回到直接 Image 加载
                    banner.__bgLoading = false;
                    banner.__bgLoaded = false;
                    startLoad();
                };

                img.src = objectUrl;
            })
            .catch(function () {
                // fetch 失败时退回到直接 Image 加载
                banner.__bgLoading = false;
                banner.__bgLoaded = false;
                startLoad();
            });
    }

    // 页面脚本初始化后就立即开始通过 fetch 后台加载大图，
    // 不依赖 window.load，也不会阻塞加载动画的结束。
    startFetch();
}

/**
 * 恋爱天数计数动效
 */
function initLoveCounter() {
    const counter = document.querySelector('.counter-number');
    if (!counter) return;

    const target = parseInt(counter.dataset.target || counter.textContent, 10);
    if (!target || isNaN(target)) return;

    let start = 0;
    const duration = 1600; // 动画总时长（ms）
    const startTime = performance.now();

    function update(now) {
        const progress = Math.min((now - startTime) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3); // ease-out
        const value = Math.floor(start + (target - start) * eased);
        counter.textContent = value.toString();
        if (progress < 1) {
            requestAnimationFrame(update);
        } else {
            counter.textContent = target.toString();
        }
    }

    // 轻微延迟，让布局先稳定
    setTimeout(() => requestAnimationFrame(update), 300);
}

/**
 * 统计卡片数字与进度条动效
 */
function initStatCounters() {
    const numbers = document.querySelectorAll('.stat-number');
    if (!numbers.length) return;

    numbers.forEach((el) => {
        const target = parseInt(el.dataset.target || el.textContent, 10);
        if (!target || isNaN(target)) return;

        const bar = el.parentElement.querySelector('.stat-progress-bar');
        const duration = 1200;
        const startTime = performance.now();
        const start = 0;

        function update(now) {
            const progress = Math.min((now - startTime) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            const value = Math.floor(start + (target - start) * eased);
            el.textContent = value.toString();

            if (bar) {
                const percent = Math.min(target, 100);
                bar.style.width = (percent * eased) + '%';
            }

            if (progress < 1) {
                requestAnimationFrame(update);
            }
        }

        requestAnimationFrame(update);
    });
}

/**
 * 显示提示消息
 */
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 1rem 1.5rem;
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(20px);
        border-radius: 10px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
        z-index: 10000;
        animation: slideIn 0.3s ease;
    `;

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

/**
 * 格式化日期
 */
function formatDate(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diff = now - date;

    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(diff / 3600000);
    const days = Math.floor(diff / 86400000);

    if (minutes < 1) return '刚刚';
    if (minutes < 60) return `${minutes}分钟前`;
    if (hours < 24) return `${hours}小时前`;
    if (days < 30) return `${days}天前`;

    return date.toLocaleDateString('zh-CN');
}

// 添加动画样式
const style = document.createElement('style');
style.textContent = `
    /* Toast 动画 */
    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(400px);
            opacity: 0;
        }
    }

    /* 漂浮爱心动画 */
    .floating-heart {
        position: fixed;
        bottom: -40px;
        font-size: 1.2rem;
        color: rgba(255, 138, 181, 0.25);
        pointer-events: none;
        z-index: 1;
        animation: floatUp 12s linear infinite;
    }

    @keyframes floatUp {
        0% {
            transform: translate3d(0, 0, 0) scale(0.8);
            opacity: 0;
        }
        20% {
            opacity: 1;
        }
        80% {
            opacity: 0.8;
        }
        100% {
            transform: translate3d(0, -120vh, 0) scale(1.2);
            opacity: 0;
        }
    }

    /* 页面加载动画：三环旋转加载器 */
    .page-loader {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(248, 250, 252, 0.96);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        transition: opacity 0.6s ease;
        overflow: hidden;
    }

    .page-loader.fade-out {
        opacity: 0;
        pointer-events: none;
    }

    .loader-content {
        position: relative;
        text-align: center;
        z-index: 1;
    }

    .loader-text {
        color: #6b7280;
        font-size: 0.9rem;
        margin-top: 1rem;
        animation: pulse 1.5s ease-in-out infinite;
    }

    @keyframes pulse {
        0%, 100% { opacity: 0.6; transform: translateY(0); }
        50% { opacity: 1; transform: translateY(-1px); }
    }

    /* triple-spinner 样式（基于示例样式，改为浅色主题） */
    .triple-spinner {
        display: block;
        position: relative;
        width: 120px;
        height: 120px;
        border-radius: 50%;
        border: 5px solid transparent;
        border-top: 5px solid #ff8ab5;
        animation: spin 1.8s linear infinite;
    }

    .triple-spinner::before,
    .triple-spinner::after {
        content: "";
        position: absolute;
        border-radius: 50%;
        border: 5px solid transparent;
    }

    .triple-spinner::before {
        top: 10px;
        left: 10px;
        right: 10px;
        bottom: 10px;
        border-top-color: #fb7185;
        animation: spin 2.3s linear infinite;
    }

    .triple-spinner::after {
        top: 22px;
        left: 22px;
        right: 22px;
        bottom: 22px;
        border-top-color: #fbbf24;
        animation: spin 1.4s linear infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    /* 卡片动画基础 */
    .animate-ready {
        opacity: 0;
    }

    /* 淡入上滑 - 使用GPU加速（放慢节奏） */
    .animate-ready.animate-fadeInUp {
        transform: translate3d(0, 40px, 0);
    }
    .animate-ready.animate-in.animate-fadeInUp {
        opacity: 1;
        transform: translate3d(0, 0, 0);
        transition: opacity 1s ease-out, transform 1s ease-out;
    }
    .animate-ready.animate-in.animate-fadeInUp.animation-complete {
        transition: none;
    }

    /* 相册卡片特殊优化 - 使用简单淡入，避免偶发动画失效 */
    .albums-masonry .album-card.animate-ready {
        opacity: 0;
        transform: translate3d(0, 0, 0);
        transition: opacity 0.45s ease-out, transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.4s cubic-bezier(0.34, 1.56, 0.64, 1), border-color 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .albums-masonry .album-card.animate-ready.animate-in {
        opacity: 1;
        transform: translate3d(0, 0, 0);
    }

    /* 确保相册卡片的hover transition始终生效，与文章卡片保持一致 */
    .album-card {
        transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) !important, box-shadow 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) !important, border-color 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
    }

    /* 淡入下滑 */
    .animate-ready.animate-fadeInDown {
        transform: translateY(-30px);
    }
    .animate-ready.animate-in.animate-fadeInDown {
        opacity: 1;
        transform: translateY(0);
        animation: fadeInDown 1.1s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    }

    /* 淡入左滑 */
    .animate-ready.animate-fadeInLeft {
        transform: translateX(-30px);
    }
    .animate-ready.animate-in.animate-fadeInLeft {
        opacity: 1;
        transform: translateX(0);
        animation: fadeInLeft 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    }

    /* 淡入右滑 */
    .animate-ready.animate-fadeInRight {
        transform: translateX(30px);
    }
    .animate-ready.animate-in.animate-fadeInRight {
        opacity: 1;
        transform: translateX(0);
        animation: fadeInRight 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    }

    /* Y轴翻转（上下翻转） */
    .animate-ready.animate-flipInY {
        transform: perspective(1000px) rotateY(90deg);
        opacity: 0;
        visibility: visible;
    }
    .animate-ready.animate-in.animate-flipInY {
        opacity: 1 !important;
        animation: flipInY 0.8s cubic-bezier(0.4, 0, 0.2, 1) forwards !important;
    }


    /* 缩放进入 */
    .animate-ready.animate-zoomIn {
        transform: scale(0.5);
    }
    .animate-ready.animate-in.animate-zoomIn {
        opacity: 1;
        animation: zoomIn 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    }

    /* 缩放进入（另一种） */
    .animate-ready.animate-scaleIn {
        transform: scale(0.8);
    }
    .animate-ready.animate-in.animate-scaleIn {
        opacity: 1;
        animation: scaleIn 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    }

    /* 上滑进入 */
    .animate-ready.animate-slideInUp {
        transform: translateY(50px);
    }
    .animate-ready.animate-in.animate-slideInUp {
        opacity: 1;
        animation: slideInUp 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    }

    /* 下滑进入 */
    .animate-ready.animate-slideInDown {
        transform: translateY(-50px);
    }
    .animate-ready.animate-in.animate-slideInDown {
        opacity: 1;
        animation: slideInDown 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    }

    /* 左滑进入 */
    .animate-ready.animate-slideInLeft {
        transform: translateX(-50px);
    }
    .animate-ready.animate-in.animate-slideInLeft {
        opacity: 1;
        animation: slideInLeft 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    }

    /* 右滑进入 */
    .animate-ready.animate-slideInRight {
        transform: translateX(50px);
    }
    .animate-ready.animate-in.animate-slideInRight {
        opacity: 1;
        animation: slideInRight 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    }


    /* 图片加载动画：仅作用于懒加载图片，避免影响其他普通图片
       占位图和真实图片共用同一个 <img>，进入懒加载阶段时不再把图片隐藏，
       而是通过轻微模糊 + 透明度淡入控制过渡时长 */
    img.lazy-image {
        opacity: 0.6;
        filter: blur(6px);
        transform: scale(1.02);
        transition:
            opacity 0.28s ease,
            filter 0.4s ease,
            transform 0.4s ease;
    }

    /* 懒加载图片加载完成：从轻微模糊逐渐变清晰并缩回 */
    img.lazy-image.image-loaded {
        opacity: 1;
        filter: blur(0);
        transform: scale(1);
    }

    /* 按钮波纹效果 */
    .ripple-effect {
        position: absolute;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.6);
        transform: scale(0);
        animation: ripple 0.6s ease-out;
        pointer-events: none;
    }

    @keyframes ripple {
        to {
            transform: scale(4);
            opacity: 0;
        }
    }

    /* 基础动画关键帧 */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translate3d(0, 30px, 0);
        }
        to {
            opacity: 1;
            transform: translate3d(0, 0, 0);
        }
    }

    @keyframes fadeInDown {
        from {
            opacity: 0;
            transform: translateY(-30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes fadeInLeft {
        from {
            opacity: 0;
            transform: translateX(-30px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    @keyframes fadeInRight {
        from {
            opacity: 0;
            transform: translateX(30px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }


    /* 缩放进入 - 更自然，使用GPU加速 */
    @keyframes zoomIn {
        from {
            opacity: 0;
            transform: scale3d(0.9, 0.9, 1);
        }
        to {
            opacity: 1;
            transform: scale3d(1, 1, 1);
        }
    }

    @keyframes scaleIn {
        from {
            opacity: 0;
            transform: scale3d(0.95, 0.95, 1);
        }
        to {
            opacity: 1;
            transform: scale3d(1, 1, 1);
        }
    }

    /* 滑入动画 */
    @keyframes slideInUp {
        from {
            opacity: 0;
            transform: translateY(50px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes slideInDown {
        from {
            opacity: 0;
            transform: translateY(-50px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes slideInLeft {
        from {
            opacity: 0;
            transform: translateX(-50px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    @keyframes slideInRight {
        from {
            opacity: 0;
            transform: translateX(50px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    /* Y轴翻转动画（上下翻转） */
    @keyframes flipInY {
        from {
            opacity: 0;
            transform: perspective(1000px) rotateY(90deg);
        }
        to {
            opacity: 1;
            transform: perspective(1000px) rotateY(0deg);
        }
    }


    /* 过渡动画增强 */
    .glass-card:not(.home-message-card):not(.message-item),
    .article-card-large {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .btn,
    .btn-view,
    .section-view-btn {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }

    /* 悬停效果增强 */
    .glass-card:not(.home-message-card):not(.message-item):hover,
    .article-card-large:hover {
        box-shadow: 0 12px 40px rgba(255, 138, 181, 0.2);
    }

    /* 加载状态 */
    .btn.loading {
        pointer-events: none;
        opacity: 0.7;
    }

    .btn.loading::after {
        content: '';
        position: absolute;
        width: 16px;
        height: 16px;
        top: 50%;
        left: 50%;
        margin-left: -8px;
        margin-top: -8px;
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-top-color: rgba(255, 255, 255, 1);
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }
`;
document.head.appendChild(style);

// 初始化全局图片错误兜底
document.addEventListener('DOMContentLoaded', function () {
    try {
        initImageErrorFallback();
    } catch (e) {
        console.warn('初始化图片错误兜底失败:', e);
    }
});

/**
 * 桌面端背景漂浮爱心 - 已禁用
 */
function initFloatingHearts() {
    // 已禁用漂浮爱心动画
    return;
}

/**
 * 初始化瀑布流布局 - 使用JavaScript绝对定位，支持多个容器
 */
function initMasonryLayout() {
    // 相册详情页图片瀑布流
    initMasonryForContainer('.album-photo-wall', '.album-photo-item', 24);
    // 相册卡片 Masonry：增加垂直间距，避免上下两行贴太近
    initMasonryForContainer('.albums-masonry', '.album-card');
    // 处理首页留言卡片（使用与留言墙相同的淡入动画，但保持原有栅格布局）
    initMasonryForContainer('.home-messages-masonry', '.message-card');
    // 处理留言墙页面的留言卡片
    initMasonryForContainer('.messages-masonry', '.message-item');
}

/**
 * 为指定容器初始化瀑布流布局
 * @param {string} containerSelector
 * @param {string} cardSelector
 * @param {number} [verticalGap] 可选：垂直间距（px），不传则和水平间距相同
 */
function initMasonryForContainer(containerSelector, cardSelector, verticalGap) {
    const masonryContainer = document.querySelector(containerSelector);
    if (!masonryContainer) return;

    // 移除冲突的类
    if (containerSelector === '.albums-masonry') {
        masonryContainer.classList.remove('albums-grid');
    }

    // 设置容器为相对定位
    masonryContainer.style.position = 'relative';
    masonryContainer.style.width = '100%';

    const cards = Array.from(masonryContainer.querySelectorAll(cardSelector));
    if (cards.length === 0) return;

    // 为卡片添加动画类
    cards.forEach(card => {
        if (!card.classList.contains('animate-ready')) {
            card.classList.add('animate-ready');
        }
    });

    function layoutMasonry() {
        const containerWidth = masonryContainer.offsetWidth;
        let gapH = 32; // 默认水平间距（px）
        // 相册详情页：稍微缩小水平间距，让图片更大、更紧凑
        if (containerSelector === '.album-photo-wall') {
            gapH = 24;
        }
        const gapV = typeof verticalGap === 'number' ? verticalGap : gapH; // 垂直间距（px）
        let columnCount;

        // 根据屏幕宽度确定列数
        if (containerSelector === '.album-photo-wall') {
            // 相册详情页：大屏 3 列，中屏 2 列，小屏 1 列
            if (window.innerWidth >= 1200) {
                columnCount = 3;
            } else if (window.innerWidth >= 768) {
                columnCount = 2;
            } else {
                columnCount = 1;
            }
        } else {
            if (window.innerWidth >= 1200) {
                columnCount = 3;
            } else if (window.innerWidth >= 768) {
                columnCount = 2;
            } else {
                columnCount = 1;
            }
        }

        const columnWidth = (containerWidth - (gapH * (columnCount - 1))) / columnCount;
        const columns = Array(columnCount).fill(0);

        // 使用requestAnimationFrame优化性能
        requestAnimationFrame(() => {
            cards.forEach(function(card, index) {
                // 找到最短的列
                const shortestColumnIndex = columns.indexOf(Math.min(...columns));
                const left = shortestColumnIndex * (columnWidth + gapH);
                const top = columns[shortestColumnIndex];

                // 设置卡片位置和宽度
                card.style.position = 'absolute';
                card.style.left = left + 'px';
                card.style.top = top + 'px';
                card.style.width = columnWidth + 'px';
                card.style.marginBottom = '0';

                // 更新列高度（使用offsetHeight获取实际高度）
                columns[shortestColumnIndex] += card.offsetHeight + gapV;
            });

            // 设置容器高度
            masonryContainer.style.height = Math.max(...columns) + 'px';
        });
    }

    // 等待图片加载并在加载过程中多次重排，避免长图突然出现
    const images = masonryContainer.querySelectorAll('img');
    let layoutScheduled = false;

    function scheduleLayout() {
        if (layoutScheduled) return;
        layoutScheduled = true;
        // 延迟布局，确保DOM已渲染，并将多次触发合并
        setTimeout(() => {
            layoutMasonry();
            layoutScheduled = false;
        }, 60);
    }

    if (images.length === 0) {
        scheduleLayout();
    } else {
        images.forEach(function(img) {
            if (img.complete && img.naturalWidth !== 0) {
                // 已加载的图片立即参与首次布局
                scheduleLayout();
            } else {
                img.addEventListener('load', scheduleLayout);
                img.addEventListener('error', scheduleLayout);
            }
        });
        // 初始也安排一次布局，以便先渲染文字等内容
        scheduleLayout();
    }

    // 相册详情页瀑布流：监听懒加载完成后派发的事件，重新布局，避免懒加载后高度变化导致的重叠
    if (containerSelector === '.album-photo-wall') {
        document.addEventListener('albumPhotoLoaded', scheduleLayout);

        // 额外保险：在用户快速滚动时也触发布局重排，避免极端情况下出现短暂的重叠
        let scrollTimer = null;
        window.addEventListener('scroll', function () {
            if (scrollTimer) return;
            scrollTimer = setTimeout(function () {
                scrollTimer = null;
                scheduleLayout();
            }, 80);
        });
    }

    // 布局完成后触发卡片动画
    function triggerAnimations() {
        setTimeout(() => {
            const containerCards = Array.from(
                masonryContainer.querySelectorAll(cardSelector + '.animate-ready:not(.animate-in)')
            );

            // 为留言卡片打乱一个播放顺序，制造更自然的不规则进入效果
            const shuffledIndexes = containerCards.map((_, i) => i);
            for (let i = shuffledIndexes.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [shuffledIndexes[i], shuffledIndexes[j]] = [shuffledIndexes[j], shuffledIndexes[i]];
            }

            containerCards.forEach((card, index) => {
                // 判断是否为留言卡片（首页或留言墙页面）
                const isMessageCard = card.classList.contains('home-message-card') ||
                                     card.classList.contains('message-item') ||
                                     card.closest('.home-messages-masonry') ||
                                     card.closest('.messages-masonry');

                if (isMessageCard) {
                    // 留言卡片：只做透明度淡入，避免与 hover 位移动画叠加
                    card.style.opacity = '0';
                    card.style.transition = 'opacity 0.6s cubic-bezier(0.34, 1.56, 0.64, 1)';

                    // 基础延迟按打乱后的顺序来，追加少量随机抖动，让出现节奏更自然
                    const sequenceIndex = shuffledIndexes[index];
                    const baseDelay = sequenceIndex * 80;
                    const jitter = Math.random() * 70; // 0-70ms 轻微抖动
                    const delay = baseDelay + jitter;

                    setTimeout(() => {
                        requestAnimationFrame(() => {
                            card.style.opacity = '1';
                            card.classList.add('animate-in');
                            setTimeout(() => {
                                card.classList.add('animation-complete');
                                card.style.removeProperty('transition');
                                void card.offsetHeight;
                            }, 600);
                        });
                    }, delay);
                } else {
                    // 相册卡片：简化为淡入
                    card.style.opacity = '0';
                    card.style.transition = 'opacity 0.4s ease-out';

                    setTimeout(() => {
                        requestAnimationFrame(() => {
                            card.style.opacity = '1';
                            card.classList.add('animate-in');
                            setTimeout(() => {
                                card.classList.add('animation-complete');
                                card.style.transition = '';
                            }, 400);
                        });
                    }, index * 80);
                }
            });
        }, 200);
    }

    // 包裹layoutMasonry函数，在布局完成后触发动画
    const originalLayoutMasonry = layoutMasonry;

    const useScrollTriggeredAnimation =
        containerSelector === '.albums-masonry' ||
        containerSelector === '.home-messages-masonry' ||
        containerSelector === '.messages-masonry';

    if (useScrollTriggeredAnimation) {
        // 相册 & 留言瀑布流：优先做静默布局，等对应区域进入视口后再触发一次淡入动画
        layoutMasonry = function() {
            originalLayoutMasonry();
        };

        if ('IntersectionObserver' in window) {
            const sectionObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        triggerAnimations();
                        observer.unobserve(masonryContainer);
                    }
                });
            }, {
                root: null,
                threshold: 0.1
            });

            sectionObserver.observe(masonryContainer);
        } else {
            // 兼容不支持 IntersectionObserver 的环境：保持原有立即动画行为
            layoutMasonry = function() {
                originalLayoutMasonry();
                setTimeout(triggerAnimations, 300);
            };
        }
    } else {
        // 其他瀑布流容器：保持原有布局完成后立即触发动画
        layoutMasonry = function() {
            originalLayoutMasonry();
            setTimeout(triggerAnimations, 300);
        };
    }

    // 监听窗口大小变化
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            layoutMasonry();
        }, 250);
    });

    if (window.ResizeObserver) {
        const ro = new ResizeObserver(() => {
            scheduleLayout();
        });
        ro.observe(masonryContainer);
    }

    // 字体加载完成后重新布局，避免自定义字体加载导致卡片高度变化而未重新计算瀑布流位置
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready
            .then(() => {
                scheduleLayout();
            })
            .catch(() => {
                // 忽略字体加载错误，避免影响正常布局
            });
    }
}

/**
 * 根据屏幕宽度获取列数
 */
function getColumnCount() {
    const width = window.innerWidth;
    if (width >= 1200) return '3';
    if (width >= 768) return '2';
    return '1';
}

/**
 * 初始化用户交互动画
 */
function initInteractiveAnimations() {
    // 按钮点击波纹效果 - 优化版
    const buttons = document.querySelectorAll('.btn, .btn-view, .section-view-btn, .page-link');
    buttons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            const ripple = document.createElement('span');
            ripple.className = 'ripple-effect';

            const rect = btn.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height) * 2;
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;

            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = x + 'px';
            ripple.style.top = y + 'px';

            if (getComputedStyle(btn).position === 'static') {
                btn.style.position = 'relative';
            }
            btn.style.overflow = 'hidden';
            btn.appendChild(ripple);

            // 使用requestAnimationFrame优化
            requestAnimationFrame(() => {
                ripple.classList.add('ripple-active');
            });

            setTimeout(() => {
                ripple.classList.add('ripple-fade');
                setTimeout(() => ripple.remove(), 400);
            }, 200);
        });
    });

    // 卡片悬停效果增强 - 更流畅的动画
    // 排除文章详情页的内容区域、留言区域和后台页面
    const cards = document.querySelectorAll('.glass-card, .article-card-large, .album-card, .event-card');
    cards.forEach(card => {
        // 排除导航按钮（使用CSS动画）
        if (card.classList.contains('nav-button') ||
            card.closest('.nav-buttons')) {
            return;
        }

        // 排除相册卡片（使用CSS动画，避免冲突）
        if (card.classList.contains('album-card')) {
            return;
        }

        // 排除后台页面
        if (document.body.classList.contains('admin-body') ||
            card.closest('.admin-body') ||
            card.closest('.admin-layout')) {
            return;
        }

        // 排除文章详情页的所有元素（包括所有子元素）
        if (card.classList.contains('article-detail') ||
            card.classList.contains('comments-section') ||
            card.classList.contains('article-detail-content') ||
            card.classList.contains('comment-item') ||
            card.classList.contains('comment-form') ||
            card.classList.contains('article-tags') ||
            card.classList.contains('tag') ||
            card.closest('.article-detail') ||
            card.closest('.comments-section') ||
            card.closest('.article-detail-content') ||
            card.closest('.comment-item') ||
            card.closest('.comment-form') ||
            (document.querySelector('.article-detail') && card.closest('section.content-section'))) {
            return;
        }

        // 排除留言墙页面的元素和首页留言卡片
        if (card.classList.contains('message-form') ||
            card.classList.contains('message-item') ||
            card.classList.contains('home-message-card') ||
            card.closest('.message-form') ||
            card.closest('.messages-list') ||
            card.closest('.message-item') ||
            card.closest('.home-messages-masonry') ||
            card.closest('.messages-masonry')) {
            return;
        }

        // 使用更优雅的缓动函数
        if (!card.style.transition) {
            card.style.transition = 'transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.4s cubic-bezier(0.34, 1.56, 0.64, 1)';
        }

        card.addEventListener('mouseenter', function() {
            requestAnimationFrame(() => {
                this.style.transform = 'translate3d(0, -6px, 0) scale3d(1.02, 1.02, 1)';
                this.style.boxShadow = '0 16px 32px rgba(0, 0, 0, 0.15)';
            });
        });
        card.addEventListener('mouseleave', function() {
            requestAnimationFrame(() => {
                this.style.transform = 'translate3d(0, 0, 0) scale3d(1, 1, 1)';
                this.style.boxShadow = '';
            });
        });
    });

    // 图片加载动画 - 优化版
    const images = document.querySelectorAll('img:not([data-no-animate])');
    images.forEach(img => {
        img.style.opacity = '0';
        img.style.transition = 'opacity 0.5s cubic-bezier(0.4, 0, 0.2, 1)';

        if (img.complete) {
            requestAnimationFrame(() => {
                img.style.opacity = '1';
                img.classList.add('image-loaded');
            });
        } else {
            img.addEventListener('load', function() {
                requestAnimationFrame(() => {
                    this.style.opacity = '1';
                    this.classList.add('image-loaded');
                });
            });
            img.addEventListener('error', function() {
                this.style.opacity = '1';
            });
        }
    });

    // 链接悬停效果
    const links = document.querySelectorAll('a:not(.btn):not(.btn-view)');
    links.forEach(link => {
        link.style.transition = 'color 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
    });
}

/**
 * 首页区块滑入动画 - 区块简单进入，卡片独立动画
 */
function initHomeSectionAnimations() {
    const sections = document.querySelectorAll('.love-day-section, .content-section, .albums-section');

    const sectionObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry, index) => {
            if (entry.isIntersecting) {
                const section = entry.target;

                // 区块本身使用简单的淡入动画，不夸张
                section.style.animation = `fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) both`;
                section.style.opacity = '1';

                // 区块内的卡片会通过 initAnimations 独立动画
                // 这里只需要让区块可见即可

                sectionObserver.unobserve(section);
            }
        });
    }, {
        threshold: 0.05,
        rootMargin: '0px 0px -50px 0px'
    });

    sections.forEach(section => {
        section.style.opacity = '0';
        sectionObserver.observe(section);
    });
}

/**
 * 通用页面动画初始化 - 适用于所有页面
 */
function initPageAnimations() {
    // 页面容器淡入（相册详情页不做整体淡入，避免与图片加载效果叠加）
    const isAlbumDetailPage = document.body && document.body.classList.contains('page-album-detail');
    const mainContent = document.querySelector('.page-main, main, .content-section');
    if (mainContent && !isAlbumDetailPage) {
        mainContent.style.opacity = '0';
        mainContent.style.transform = 'translateY(20px)';
        mainContent.style.transition = 'opacity 0.6s cubic-bezier(0.16, 1, 0.3, 1), transform 0.6s cubic-bezier(0.16, 1, 0.3, 1)';

        requestAnimationFrame(() => {
            mainContent.style.opacity = '1';
            mainContent.style.transform = 'none';
        });
    }

    // 表单元素动画
    const forms = document.querySelectorAll('form');
    forms.forEach((form, index) => {
        form.style.opacity = '0';
        form.style.transform = 'translateY(15px)';
        form.style.transition = `opacity 0.5s cubic-bezier(0.16, 1, 0.3, 1) ${index * 0.1}s, transform 0.5s cubic-bezier(0.16, 1, 0.3, 1) ${index * 0.1}s`;

        setTimeout(() => {
            form.style.opacity = '1';
            form.style.transform = 'translateY(0)';
        }, 100 + index * 100);
    });

    // 输入框聚焦动画
    const inputs = document.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
        input.style.transition = 'border-color 0.3s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.3s cubic-bezier(0.4, 0, 0.2, 1)';

        input.addEventListener('focus', function() {
            this.style.boxShadow = '0 0 0 3px rgba(255, 138, 181, 0.1)';
        });

        input.addEventListener('blur', function() {
            this.style.boxShadow = '';
        });
    });

    // 列表项动画
    // 排除首页动态卡片（.article-card-large），它们由 initAnimations() 单独处理
    const allListItems = document.querySelectorAll('.article-list-large > div, .events-list > div, .message-list > div, .comment-list > div');
    const listItems = Array.from(allListItems).filter(item => {
        // 完全排除包含 article-card-large 的容器
        return !item.querySelector('.article-card-large');
    });

    if (listItems.length > 0) {
        const listObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry, index) => {
                if (entry.isIntersecting) {
                    const item = entry.target;
                    // 双重检查：如果包含 article-card-large，跳过
                    if (item.querySelector('.article-card-large')) {
                        listObserver.unobserve(item);
                        return;
                    }
                    setTimeout(() => {
                        item.style.opacity = '1';
                        item.style.transform = 'translateY(0)';
                    }, index * 50);
                    listObserver.unobserve(item);
                }
            });
        }, {
            threshold: 0.1,
            rootMargin: '0px 0px -30px 0px'
        });

        listItems.forEach((item, index) => {
            // 再次检查：如果包含 article-card-large，跳过
            if (item.querySelector('.article-card-large')) {
                return;
            }
            item.style.opacity = '0';
            item.style.transform = 'translateY(20px)';
            item.style.transition = 'opacity 0.5s cubic-bezier(0.16, 1, 0.3, 1), transform 0.5s cubic-bezier(0.16, 1, 0.3, 1)';
            listObserver.observe(item);
        });
    }

    // 分页按钮动画
    const pagination = document.querySelectorAll('.pagination a, .page-link');
    pagination.forEach((link, index) => {
        link.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
        link.addEventListener('mouseenter', function() {
            this.style.transform = 'translate3d(0, -2px, 0) scale3d(1.05, 1.05, 1)';
            this.style.boxShadow = '0 4px 12px rgba(0, 0, 0, 0.15)';
        });
        link.addEventListener('mouseleave', function() {
            this.style.transform = 'translate3d(0, 0, 0) scale3d(1, 1, 1)';
            this.style.boxShadow = '';
        });
    });

    // 空状态动画
    const emptyStates = document.querySelectorAll('.empty-state');
    emptyStates.forEach(state => {
        state.style.opacity = '0';
        state.style.transform = 'scale(0.9)';
        state.style.transition = 'opacity 0.6s cubic-bezier(0.16, 1, 0.3, 1), transform 0.6s cubic-bezier(0.16, 1, 0.3, 1)';

        setTimeout(() => {
            state.style.opacity = '1';
            state.style.transform = 'scale(1)';
        }, 300);
    });

    // 文章详情页动画
    const articleDetail = document.querySelector('.article-detail');
    if (articleDetail) {
        articleDetail.style.opacity = '0';
        articleDetail.style.transform = 'translateY(20px)';
        articleDetail.style.transition = 'opacity 0.6s cubic-bezier(0.16, 1, 0.3, 1), transform 0.6s cubic-bezier(0.16, 1, 0.3, 1)';

        setTimeout(() => {
            articleDetail.style.opacity = '1';
            articleDetail.style.transform = 'translateY(0)';
        }, 100);
    }

    // 图片网格动画
    const imageGrids = document.querySelectorAll('.album-grid img, .image-grid img');
    imageGrids.forEach((img, index) => {
        img.style.opacity = '0';
        img.style.transform = 'scale(0.95)';
        img.style.transition = `opacity 0.5s cubic-bezier(0.16, 1, 0.3, 1) ${index * 0.05}s, transform 0.5s cubic-bezier(0.16, 1, 0.3, 1) ${index * 0.05}s`;

        if (img.complete) {
            setTimeout(() => {
                img.style.opacity = '1';
                img.style.transform = 'scale(1)';
            }, 200 + index * 50);
        } else {
            img.addEventListener('load', function() {
                setTimeout(() => {
                    this.style.opacity = '1';
                    this.style.transform = 'scale(1)';
                }, index * 50);
            });
        }
    });

    // 评论项动画
    const comments = document.querySelectorAll('.comment-item, .comment');
    comments.forEach((comment, index) => {
        comment.style.opacity = '0';
        comment.style.transform = 'translateX(-20px)';
        comment.style.transition = `opacity 0.5s cubic-bezier(0.16, 1, 0.3, 1) ${index * 0.1}s, transform 0.5s cubic-bezier(0.16, 1, 0.3, 1) ${index * 0.1}s`;

        setTimeout(() => {
            comment.style.opacity = '1';
            comment.style.transform = 'translateX(0)';
        }, 200 + index * 100);
    });

    // 事件卡片动画（events.php页面）
    const eventPills = document.querySelectorAll('.event-pill');
    if (eventPills.length > 0 && !document.querySelector('.love-day-section')) {
        // 只在events.php页面应用，不在首页
        eventPills.forEach((pill, index) => {
            pill.style.opacity = '0';
            pill.style.transform = 'translateY(30px) rotateX(10deg)';
            pill.style.transition = `opacity 0.6s cubic-bezier(0.16, 1, 0.3, 1) ${index * 0.1}s, transform 0.6s cubic-bezier(0.16, 1, 0.3, 1) ${index * 0.1}s`;

            setTimeout(() => {
                pill.style.opacity = '1';
                pill.style.transform = 'translateY(0) rotateX(0deg)';
            }, 100 + index * 100);
        });
    }
}
