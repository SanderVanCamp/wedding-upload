<?php

require_once __DIR__ . '/request_guard.php';
$allowedMethods = ['GET', 'HEAD'];
guardRequest($allowedMethods);
applySecurityHeaders();
$sharedPhotoId = preg_match('/^[a-f0-9]{40}$/', (string) ($_GET['photo'] ?? '')) ? (string) $_GET['photo'] : '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'vooraltijdmijnliefje.be';
$baseUrl = $scheme . '://' . $host;
$ogImageWidth = 1200;
$ogImageHeight = 630;
if ($sharedPhotoId !== '') {
  $dbPath = __DIR__ . '/media.sqlite';
  if (file_exists($dbPath)) {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $columns = [];
    foreach ($db->query('PRAGMA table_info(uploads)') as $column) {
      $columns[$column['name']] = TRUE;
    }
    if (isset($columns['thumb_width'], $columns['thumb_height'])) {
      $stmt = $db->prepare('SELECT thumb_width, thumb_height FROM uploads WHERE local_key = :local_key LIMIT 1');
      $stmt->execute([':local_key' => $sharedPhotoId]);
      $row = $stmt->fetch();
      if ($row) {
        $ogImageWidth = (int) ($row['thumb_width'] ?: $ogImageWidth);
        $ogImageHeight = (int) ($row['thumb_height'] ?: $ogImageHeight);
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
  <title>Sander & Silvie</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link
    href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600&display=swap"
    rel="stylesheet"
  />
  <link rel="shortcut icon" href="./favicon.ico"/>

  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, notranslate">
  <meta name="color-scheme" content="light">

  <meta property="og:type" content="website">
  <meta property="og:url"
        content="<?php echo htmlspecialchars($baseUrl . '/index.php' . ($sharedPhotoId ? '?photo=' . rawurlencode($sharedPhotoId) : ''), ENT_QUOTES, 'UTF-8'); ?>">
  <meta property="og:title" content="Sander & Silvie">
  <meta property="og:description"
        content="Heb je foto's genomen op ons trouwfeest? Deel ze hier met ons, zodat we samen nog eens kunnen nagenieten van die mooie dag.">
  <meta property="og:image"
        content="<?php echo htmlspecialchars($baseUrl . '/' . ($sharedPhotoId ? 'thumb.php?photo=' . rawurlencode($sharedPhotoId) . '&variant=display' : 'share/share.jpg'), ENT_QUOTES, 'UTF-8'); ?>">
  <meta property="og:image:width" content="<?php echo (int) $ogImageWidth; ?>">
  <meta property="og:image:height"
        content="<?php echo (int) $ogImageHeight; ?>">

  <link href="/dist/output.css?v=7" rel="stylesheet">
  <style>
    html,
    body {
      overflow-x: hidden;
    }
  </style>
</head>
<body class="bg-[#ecd0b5] relative min-h-screen sm:px-6 lg:px-8">
<main>

  <div class="mx-auto max-w-7xl">
    <header id="heroHeader"
            class="relative flex h-[50vh] items-center justify-center overflow-hidden bg-[#c77452] px-4 py-8 font-body text-[#f2d8c5] sm:py-10">
      <div id="heroParallax"
           class="flex w-full flex-col items-center gap-12 sm:gap-6 will-change-transform">
        <img src="/hero-names.svg" alt="Sander & Silvie"
             class="h-auto max-h-[28vh] w-full max-w-220 object-contain sm:max-h-[30vh]"
             loading="eager" decoding="async">
        <input id="fileInput" type="file" accept="image/*,video/*" multiple
               class="hidden">
        <label for="fileInput"
               class="whitespace-nowrap inline-flex min-h-10 items-center justify-center rounded-xl bg-[#f3d7c2] px-4 sm:px-8 text-center font-semibold uppercase tracking-wide md:tracking-wider text-[#798060] shadow-[8px_8px_0_0_rgba(235,191,161,0.55)] transition hover:translate-y-[1px] cursor-pointer">
          Upload
        </label>
      </div>
    </header>

    <div class="grid gap-5 p-0.5 -mx-0.5">
      <div id="galleryState" class="hidden"></div>
      <div id="galleryGrid"
           class="grid grid-cols-3 gap-0.5 sm:grid-cols-5 xl:grid-cols-6"></div>
      <div id="gallerySentinel" class="h-px"></div>
    </div>
  </div>

  <div id="uploadProgressWrap"
       class="fixed inset-x-0 bottom-4 z-40 hidden px-4 sm:bottom-6 sm:px-6 lg:px-8">
    <div
      class="mx-auto w-full max-w-3xl rounded-2xl bg-white/90 px-4 py-3 shadow-[0_18px_50px_rgba(31,26,23,0.16)] backdrop-blur-md">
      <div class="flex items-center gap-3">
        <div class="h-2 flex-1 overflow-hidden rounded-full bg-[#efe6dd]">
          <div id="uploadProgressBar"
               class="h-full w-0 rounded-full bg-[#1f1a17] transition-[width] duration-200"></div>
        </div>
        <div id="uploadStatus"
             class="shrink-0 flex items-center gap-2 text-sm text-[#6f6258]">
          <svg id="uploadSpinner" viewBox="0 0 24 24" aria-hidden="true"
               class="hidden h-4 w-4 animate-spin fill-none stroke-current stroke-[2]">
            <circle cx="12" cy="12" r="8" class="opacity-25"></circle>
            <path d="M20 12a8 8 0 0 0-8-8"></path>
          </svg>
          <span id="uploadStatusText">No files uploading.</span>
        </div>
      </div>
    </div>
  </div>

  <div id="viewer"
       class="fixed inset-0 z-50 hidden bg-[#140f0b]/92 backdrop-blur-md"
       style="touch-action: none; overscroll-behavior: none; position: fixed; height: 100%; width: 100%; top: 0; left: 0;">
    <div class="flex h-full flex-col">
      <div class="flex items-center px-4 py-4 sm:px-6">
        <div class="ml-auto flex items-center gap-2">
          <a id="downloadViewerMedia" href="#" target="_blank" download
             class="inline-flex items-center justify-center rounded-full bg-white/10 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/18">
            Download
          </a>
          <button id="closeViewer" type="button"
                  class="inline-flex cursor-pointer h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/18"
                  aria-label="Close viewer">
            <svg viewBox="0 0 24 24" aria-hidden="true"
                 class="h-5 w-5 stroke-current stroke-2 fill-none">
              <path d="M6 6l12 12M18 6 6 18"></path>
            </svg>
          </button>
        </div>
      </div>
      <div id="viewerList" class="relative flex-1 overflow-hidden"></div>
    </div>
  </div>

  <script>
    const fileInput = document.getElementById('fileInput');
    const uploadProgressWrap = document.getElementById('uploadProgressWrap');
    const uploadProgressBar = document.getElementById('uploadProgressBar');
    const uploadStatus = document.getElementById('uploadStatus');
    const uploadSpinner = document.getElementById('uploadSpinner');
    const uploadStatusText = document.getElementById('uploadStatusText');
    const galleryState = document.getElementById('galleryState');
    const galleryGrid = document.getElementById('galleryGrid');
    const gallerySentinel = document.getElementById('gallerySentinel');
    const viewer = document.getElementById('viewer');
    const viewerList = document.getElementById('viewerList');
    const downloadViewerMedia = document.getElementById('downloadViewerMedia');
    const closeViewer = document.getElementById('closeViewer');
    const heroHeader = document.getElementById('heroHeader');
    const heroParallax = document.getElementById('heroParallax');
    const initialSharedPhotoId = <?php echo json_encode($sharedPhotoId); ?>;

    let uploadInProgress = false;
    let uploadSuccessTimer = null;
    let galleryFiles = [];
    let galleryNextPageToken = null;
    let galleryLoading = false;
    let galleryHasMore = true;
    let viewerOpenIndex = 0;
    let viewerTouchStartX = 0;
    let viewerTouchStartY = 0;
    let viewerZoomScale = 1;
    let viewerZoomTranslateX = 0;
    let viewerZoomTranslateY = 0;
    let viewerGesture = null;
    let viewerLastTapAt = 0;
    let viewerLastTapTarget = '';
    let galleryTouchStartX = 0;
    let galleryTouchStartY = 0;
    let galleryTouchMoved = false;
    let galleryTouchStartScrollY = 0;

    // NEW: Variable to store scroll position
    let scrollPosition = 0;

    const getSharedPhotoId = () => {
      const queryMatch = window.location.search.match(/[?&]photo=([^&]+)/);
      if (queryMatch) {
        return decodeURIComponent(queryMatch[1]);
      }
      const hashMatch = window.location.hash.match(/photo=([^&]+)/);
      return hashMatch ? decodeURIComponent(hashMatch[1]) : '';
    };

    const syncSharedPhotoState = (photoId) => {
      if (photoId) {
        const url = new URL(window.location.href);
        if (url.searchParams.get('photo') !== photoId) {
          url.searchParams.set('photo', photoId);
          // Use pushState so back button works
          history.pushState({ photoId }, '', `${url.pathname}${url.search}${url.hash}`);
        }
      } else if (window.location.search.includes('photo=')) {
        // If we are clearing, we usually want to go back in history if possible
        // but closeViewerPanel handles manual clearing via replaceState
      }
    };

    let pendingSharedPhotoId = getSharedPhotoId() || initialSharedPhotoId;
    let activeSharedPhotoId = pendingSharedPhotoId;
    let modalRestorePhotoId = '';
    let sharedPhotoResolved = false;

    const escapeHtml = (value) => value
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;');

    const setUploadState = (current, total) => {
      if (!total) {
        uploadProgressWrap.classList.add('hidden');
        uploadProgressBar.style.width = '0%';
        uploadProgressBar.className = 'h-full w-0 rounded-full bg-[#1f1a17] transition-[width] duration-200';
        uploadStatus.classList.add('hidden');
        uploadSpinner.classList.add('hidden');
        uploadStatusText.textContent = 'No files uploading.';
        return;
      }
      uploadProgressWrap.classList.remove('hidden');
      uploadStatus.classList.remove('hidden');
      uploadSpinner.classList.remove('hidden');
      uploadStatusText.textContent = `Uploading ${current} van ${total}`;
      uploadProgressBar.className = 'h-full rounded-full bg-[#1f1a17] transition-[width] duration-200';
      const percent = Math.round((current / total) * 100);
      uploadProgressBar.style.width = `${percent}%`;
    };

    const showUploadSuccess = (count) => {
      window.clearTimeout(uploadSuccessTimer);
      uploadProgressWrap.classList.remove('hidden');
      uploadStatus.classList.remove('hidden');
      uploadSpinner.classList.add('hidden');
      uploadStatusText.textContent = `${count} bestand${count === 1 ? '' : 'en'} geüpload`;
      uploadProgressBar.className = 'h-full rounded-full bg-emerald-500 transition-[width] duration-200';
      uploadProgressBar.style.width = '100%';
      uploadSuccessTimer = window.setTimeout(() => {
        uploadProgressWrap.classList.add('hidden');
        uploadProgressBar.style.width = '0%';
        uploadProgressBar.className = 'h-full w-0 rounded-full bg-[#1f1a17] transition-[width] duration-200';
        uploadStatus.classList.add('hidden');
        uploadSpinner.classList.add('hidden');
      }, 2400);
    };

    const resetViewerZoom = () => {
      viewerZoomScale = 1;
      viewerZoomTranslateX = 0;
      viewerZoomTranslateY = 0;
      viewerGesture = null;
    };

    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

    const applyViewerZoom = () => {
      const zoomTarget = viewerList.querySelector('[data-zoom-target]');
      if (!zoomTarget) {
        return;
      }
      zoomTarget.style.transform = `translate(${viewerZoomTranslateX}px, ${viewerZoomTranslateY}px) scale(${viewerZoomScale})`;
    };

    const updateViewerZoom = (nextScale, nextX = viewerZoomTranslateX, nextY = viewerZoomTranslateY) => {
      viewerZoomScale = clamp(nextScale, 1, 4);
      viewerZoomTranslateX = nextX;
      viewerZoomTranslateY = nextY;
      applyViewerZoom();
    };

    const toggleViewerZoom = () => {
      if (viewerZoomScale > 1) {
        resetViewerZoom();
      } else {
        updateViewerZoom(2);
      }
      applyViewerZoom();
    };

    const isViewerActive = () => !viewer.classList.contains('hidden');

    const fetchPhotoDetails = async (photoId) => {
      const response = await fetch(`./photo.php?photo=${encodeURIComponent(photoId)}`, { cache: 'no-store' });
      if (!response.ok) {
        return null;
      }
      return response.json();
    };

    const loadViewerFile = async (index) => {
      const file = galleryFiles[index];
      if (!file?.id) {
        return null;
      }
      if (file.src && file.displaySrc) {
        return file;
      }
      const details = await fetchPhotoDetails(file.id);
      if (!details) {
        return null;
      }
      file.src = details.src || file.src || '';
      file.displaySrc = details.displaySrc || file.displaySrc || '';
      file.thumbSrc = details.thumbSrc || file.thumbSrc || '';
      file.kind = details.kind || file.kind || 'image';
      file.name = details.name || file.name || '';
      file.mimeType = details.mimeType || file.mimeType || '';
      return file;
    };

    const ensureGalleryIndexLoaded = async (index) => {
      while (index >= galleryFiles.length && galleryHasMore) {
        await loadGalleryPage();
      }
      return index < galleryFiles.length;
    };

    const scrollToSharedPhotoTile = async (photoId) => {
      if (!photoId) {
        return false;
      }

      let attempts = 0;
      while (attempts < 12) {
        const tile = galleryGrid.querySelector(`button[data-id="${photoId}"]`);
        if (tile) {
          const html = document.documentElement;
          const previousScrollBehavior = html.style.scrollBehavior;
          html.style.scrollBehavior = 'auto';
          tile.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'auto' });
          html.style.scrollBehavior = previousScrollBehavior;
          return true;
        }
        if (!galleryHasMore) {
          break;
        }
        await loadGalleryPage();
        attempts += 1;
      }
      return false;
    };

    const tryOpenSharedPhoto = () => {
      if (sharedPhotoResolved || !pendingSharedPhotoId) {
        return false;
      }
      const targetIndex = galleryFiles.findIndex((file) => file.id === pendingSharedPhotoId);
      if (targetIndex === -1) {
        return false;
      }
      sharedPhotoResolved = true;
      activeSharedPhotoId = pendingSharedPhotoId;
      openViewer(targetIndex);
      return true;
    };

    const appendGallery = (html) => {
      if (!html.trim()) {
        return;
      }
      const beforeCount = galleryGrid.querySelectorAll('button[data-index]').length;
      galleryGrid.insertAdjacentHTML('beforeend', html);
      const buttons = Array.from(galleryGrid.querySelectorAll('button[data-index]')).slice(beforeCount);
      buttons.forEach((button, index) => {
        button.dataset.index = String(beforeCount + index);
        galleryFiles.push({
          id: button.dataset.id || '',
          name: button.dataset.name || '',
          kind: button.dataset.kind || 'image',
          mimeType: button.dataset.mimeType || '',
          thumbSrc: button.dataset.thumbSrc || '',
        });
      });
      observeGalleryImages();
      tryOpenSharedPhoto();
    };

    const upgradeGalleryImage = (img) => {
      const thumbSrc = img.dataset.thumbSrc;
      if (!thumbSrc || img.dataset.upgraded === '1') {
        return;
      }
      img.dataset.upgraded = '1';
      img.src = thumbSrc;
    };

    let imageObserver = null;
    const observeGalleryImages = () => {
      if (!imageObserver) {
        galleryGrid.querySelectorAll('img[data-thumb-src]:not([data-upgraded="1"])').forEach((img) => {
          upgradeGalleryImage(img);
        });
        return;
      }
      galleryGrid.querySelectorAll('img[data-thumb-src]:not([data-upgraded="1"])').forEach((img) => {
        imageObserver.observe(img);
      });
    };

    const loadGalleryPage = async (reset = false) => {
      if (galleryLoading || (!galleryHasMore && !reset)) {
        return;
      }
      galleryLoading = true;
      if (reset) {
        galleryFiles = [];
        galleryNextPageToken = null;
        galleryHasMore = true;
        galleryGrid.innerHTML = '';
      }
      try {
        const url = new URL('./gallery.php', window.location.href);
        if (galleryNextPageToken) {
          url.searchParams.set('pageToken', galleryNextPageToken);
        }
        if (reset) {
          url.searchParams.set('_ts', String(Date.now()));
        }

        const response = await fetch(url, { cache: reset ? 'no-store' : 'default' });
        if (!response.ok) {
          throw new Error('Gallery request failed');
        }

        const html = await response.text();
        appendGallery(html);
        galleryNextPageToken = response.headers.get('X-Next-Page-Token') || null;
        galleryHasMore = Boolean(galleryNextPageToken);

        if (pendingSharedPhotoId && !sharedPhotoResolved && galleryHasMore) {
          loadGalleryPage();
        }
      } catch (error) {
        galleryState.textContent = 'Could not load pictures.';
      } finally {
        galleryLoading = false;
      }
    };

    const renderViewer = (files, startIndex = 0, slideDirection = 0) => {
      const file = files[startIndex];
      if (!file) {
        viewerList.innerHTML = '';
        if (downloadViewerMedia) {
          downloadViewerMedia.removeAttribute('href');
          downloadViewerMedia.removeAttribute('download');
          downloadViewerMedia.setAttribute('aria-disabled', 'true');
        }
        return;
      }

      if (downloadViewerMedia) {
        downloadViewerMedia.href = file.src || '#';
        downloadViewerMedia.download = file.name || 'download';
        downloadViewerMedia.setAttribute('aria-disabled', 'false');
      }

      viewerList.innerHTML = `
        <button id="viewerPrev" type="button" class="absolute left-0 top-0 z-10 h-full w-1/3" aria-label="Previous image"></button>
        <button id="viewerNext" type="button" class="absolute right-0 top-0 z-10 h-full w-1/3" aria-label="Next image"></button>
        <div class="flex h-full items-center justify-center px-3 py-4 sm:px-6">
          <figure data-viewer-frame class="mx-auto flex h-full w-full max-w-5xl flex-col overflow-hidden rounded-[28px] bg-[#0f0b08] shadow-[0_20px_90px_rgba(0,0,0,0.35)] transition-[transform,opacity] duration-200 ease-out">
            ${file.kind === 'video'
        ? `<video src="${file.src}" class="h-full min-h-0 w-full flex-1 bg-black object-contain" controls playsinline autoplay preload="metadata"></video>`
        : `<div data-zoom-target class="relative h-full min-h-0 w-full flex-1 bg-black touch-none will-change-transform" style="touch-action: none; overscroll-behavior: none;">
              <img src="${file.thumbSrc || file.src}" alt="${file.name.replaceAll('"', '&quot;')}" class="absolute inset-0 h-full w-full object-contain transition-opacity duration-150 opacity-100" loading="eager" decoding="async" fetchpriority="high">
              <img src="${file.thumbSrc || file.src}" data-display-src="${file.displaySrc || file.src}" alt="${file.name.replaceAll('"', '&quot;')}" class="absolute inset-0 h-full w-full object-contain transition-opacity duration-150 opacity-0" loading="eager" decoding="async" fetchpriority="high">
            </div>`
      }
          </figure>
        </div>
      `;

      document.getElementById('viewerPrev').addEventListener('click', () => {
        if (viewerOpenIndex > 0) {
          openViewer(viewerOpenIndex - 1, -1);
        }
      });
      document.getElementById('viewerNext').addEventListener('click', () => {
        void openViewer(viewerOpenIndex + 1, 1);
      });

      const thumbImage = viewerList.querySelector('img:not([data-display-src])');
      const displayImage = viewerList.querySelector('img[data-display-src]');
      if (displayImage) {
        const displaySrc = displayImage.dataset.displaySrc;
        if (displaySrc) {
          const upgraded = new Image();
          upgraded.decoding = 'async';
          upgraded.onload = () => {
            displayImage.src = displaySrc;
            requestAnimationFrame(() => {
              displayImage.classList.remove('opacity-0');
              displayImage.classList.add('opacity-100');
              if (thumbImage) {
                thumbImage.classList.add('opacity-0');
              }
            });
          };
          upgraded.src = displaySrc;
        }
      }

      resetViewerZoom();
      applyViewerZoom();
      const viewerFrame = viewerList.querySelector('[data-viewer-frame]');
      if (viewerFrame) {
        if (slideDirection !== 0) {
          viewerFrame.style.opacity = '0';
          viewerFrame.style.transform = `translateX(${slideDirection > 0 ? '24px' : '-24px'}) scale(0.985)`;
          void viewerFrame.offsetWidth;
          requestAnimationFrame(() => {
            viewerFrame.style.opacity = '1';
            viewerFrame.style.transform = 'translateX(0) scale(1)';
          });
        } else {
          viewerFrame.style.opacity = '1';
          viewerFrame.style.transform = 'translateX(0) scale(1)';
        }
      }
    };

    const openViewer = async (index, slideDirection = 0) => {
      const hasTarget = await ensureGalleryIndexLoaded(index);
      if (!hasTarget) {
        return;
      }

      const previousIndex = viewerOpenIndex;
      viewerOpenIndex = Math.max(0, Math.min(index, galleryFiles.length - 1));
      const nextPhotoId = galleryFiles[viewerOpenIndex]?.id || '';
      const url = new URL(window.location.href);
      modalRestorePhotoId = url.searchParams.get('photo') || activeSharedPhotoId || nextPhotoId;

      if (slideDirection === 0 && viewerOpenIndex !== previousIndex) {
        slideDirection = viewerOpenIndex > previousIndex ? 1 : -1;
      }

      const file = await loadViewerFile(viewerOpenIndex);
      if (!file) {
        return;
      }

      renderViewer([file], 0, slideDirection);

      // Only lock the scroll if the viewer isn't already open
      if (viewer.classList.contains('hidden')) {
        // 1. Capture exact scroll position
        scrollPosition = window.pageYOffset || document.documentElement.scrollTop;

        // 2. Fix the body in place at the current offset
        // This prevents the "jump to top" behavior
        document.body.style.overflow = 'hidden';
        document.body.style.position = 'fixed';
        document.body.style.top = `-${scrollPosition}px`;
        document.body.style.width = '100%';

        // 3. History management for back button
        if (nextPhotoId) {
          if (url.searchParams.get('photo') !== nextPhotoId) {
            url.searchParams.set('photo', nextPhotoId);
            history.pushState({ viewerOpen: true }, '', `${url.pathname}${url.search}${url.hash}`);
          }
        }
      } else {
        // Update URL hash for the current photo without adding new history entries
        if (nextPhotoId) {
          url.searchParams.set('photo', nextPhotoId);
          history.replaceState({ viewerOpen: true }, '', `${url.pathname}${url.search}${url.hash}`);
        }
      }

      viewer.classList.remove('hidden');
      activeSharedPhotoId = nextPhotoId;
      pendingSharedPhotoId = '';
      sharedPhotoResolved = true;
    };

    const openSharedPhotoDirectly = async () => {
      if (sharedPhotoResolved || !pendingSharedPhotoId) {
        return false;
      }

      try {
        const file = await fetchPhotoDetails(pendingSharedPhotoId);
        if (!file || !file.id) {
          return false;
        }
        viewerOpenIndex = 0;
        sharedPhotoResolved = true;
        modalRestorePhotoId = pendingSharedPhotoId;
        activeSharedPhotoId = pendingSharedPhotoId;
        viewer.classList.remove('hidden');
        renderViewer([file], 0, 0);
        return true;
      } catch (error) {
        return false;
      }
    };

    const closeViewerPanel = () => {
      if (viewer.classList.contains('hidden')) {
        return;
      }

      viewerList.querySelectorAll('video').forEach((video) => {
        video.pause();
        video.currentTime = 0;
      });

      viewer.classList.add('hidden');
      if (downloadViewerMedia) {
        downloadViewerMedia.removeAttribute('href');
        downloadViewerMedia.removeAttribute('download');
        downloadViewerMedia.setAttribute('aria-disabled', 'true');
      }
      const restorePhotoId = modalRestorePhotoId;

      // 1. Temporarily disable smooth scrolling to prevent "sliding" back
      const html = document.documentElement;
      html.classList.remove('scroll-smooth');
      const previousScrollBehavior = html.style.scrollBehavior;
      html.style.scrollBehavior = 'auto';

      // 2. Remove the "fixed" constraints
      document.body.style.removeProperty('overflow');
      document.body.style.removeProperty('position');
      document.body.style.removeProperty('top');
      document.body.style.removeProperty('width');

      // 3. Reset document-level locks
      html.style.overscrollBehavior = '';
      html.style.touchAction = '';
      document.body.classList.remove('overscroll-none');

      // 4. Immediately jump back to the saved position
      if (restorePhotoId) {
        requestAnimationFrame(() => {
          scrollToSharedPhotoTile(restorePhotoId);
        });
      } else {
        window.scrollTo(0, scrollPosition);
      }

      // 5. Re-enable smooth scrolling after a tiny delay
      requestAnimationFrame(() => {
        html.style.scrollBehavior = previousScrollBehavior;
        html.classList.add('scroll-smooth');
      });

      // 6. Clean up the URL hash
      if (restorePhotoId) {
        const url = new URL(window.location.href);
        url.searchParams.delete('photo');
        history.replaceState(null, '', `${url.pathname}${url.search}${url.hash}`);
        activeSharedPhotoId = '';
        modalRestorePhotoId = '';
      }
    };

    const uploadFiles = async () => {
      if (uploadInProgress) {
        return;
      }
      const files = Array.from(fileInput.files || []);
      if (!files.length) {
        return;
      }
      uploadInProgress = true;
      fileInput.disabled = true;
      setUploadState(0, files.length);
      let successCount = 0;
      try {
        const concurrency = Math.min(3, files.length);
        let nextIndex = 0;
        let completedCount = 0;

        const uploadOne = async (file, index) => {
          setUploadState(index, files.length);
          const formData = new FormData();
          formData.append('file', file, file.name);
          const response = await fetch('./upload.php', { method: 'POST', body: formData });
          if (!response.ok) {
            throw new Error(`Upload failed for ${file.name}`);
          }
          successCount += 1;
          completedCount += 1;
          setUploadState(completedCount, files.length);
        };

        const workers = Array.from({ length: concurrency }, async () => {
          while (true) {
            const index = nextIndex;
            if (index >= files.length) {
              break;
            }
            nextIndex += 1;
            await uploadOne(files[index], index);
          }
        });

        await Promise.all(workers);
        fileInput.value = '';
        showUploadSuccess(successCount);
        await loadGalleryPage(true);
      } catch (error) {
        uploadProgressWrap.classList.remove('hidden');
        uploadStatus.classList.remove('hidden');
        uploadStatus.textContent = error.message || 'Upload failed';
        uploadProgressBar.className = 'h-full rounded-full bg-rose-500 transition-[width] duration-200';
        uploadProgressBar.style.width = '100%';
      } finally {
        fileInput.disabled = false;
        uploadInProgress = false;
      }
    };

    fileInput.addEventListener('change', uploadFiles);
    galleryGrid.addEventListener('touchstart', (event) => {
      if (event.touches.length !== 1) {
        return;
      }
      const touch = event.touches[0];
      galleryTouchStartX = touch.clientX;
      galleryTouchStartY = touch.clientY;
      galleryTouchMoved = false;
      galleryTouchStartScrollY = window.scrollY;
    }, { passive: true });

    galleryGrid.addEventListener('touchmove', (event) => {
      if (event.touches.length !== 1) {
        galleryTouchMoved = true;
        return;
      }
      const touch = event.touches[0];
      if (Math.abs(touch.clientX - galleryTouchStartX) > 10 || Math.abs(touch.clientY - galleryTouchStartY) > 10) {
        galleryTouchMoved = true;
        return;
      }
      if (window.scrollY !== galleryTouchStartScrollY) {
        galleryTouchMoved = true;
      }
    }, { passive: true });

    galleryGrid.addEventListener('click', (event) => {
      if (galleryTouchMoved) {
        galleryTouchMoved = false;
        return;
      }
      const button = event.target.closest('button[data-index]');
      if (!button) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      void openViewer(Number(button.dataset.index || 0));
    });

    closeViewer.addEventListener('click', closeViewerPanel);
    viewer.addEventListener('click', (event) => {
      if (event.target === viewer) {
        closeViewerPanel();
      }
    });

    // Handle Hardware Back Button / Browser Back
    window.addEventListener('popstate', () => {
      const sharedPhotoId = getSharedPhotoId();
      if (!sharedPhotoId) {
        closeViewerPanel();
      } else {
        const targetIndex = galleryFiles.findIndex((file) => file.id === sharedPhotoId);
        if (targetIndex !== -1 && viewerOpenIndex !== targetIndex) {
          void openViewer(targetIndex);
        }
      }
    });

    // Touch and Gesture logic
    viewer.addEventListener('touchstart', (event) => {
      if (!viewer.classList.contains('hidden') && event.touches.length >= 2) {
        const [first, second] = event.touches;
        const distance = Math.hypot(second.clientX - first.clientX, second.clientY - first.clientY);
        viewerGesture = { mode: 'pinch', startDistance: distance, startScale: viewerZoomScale, startX: viewerZoomTranslateX, startY: viewerZoomTranslateY };
        return;
      }
      if (viewerZoomScale > 1 && event.touches.length === 1) {
        const touch = event.touches[0];
        viewerGesture = { mode: 'pan', startClientX: touch.clientX, startClientY: touch.clientY, startScale: viewerZoomScale, startX: viewerZoomTranslateX, startY: viewerZoomTranslateY };
        return;
      }
      const touch = event.changedTouches[0];
      viewerTouchStartX = touch.clientX;
      viewerTouchStartY = touch.clientY;
    }, { passive: true });

    viewer.addEventListener('touchmove', (event) => {
      // This is the key: prevent the browser from handling the swipe
      if (event.cancelable) {
        event.preventDefault();
      }

      if (event.touches.length >= 2) {
        const [first, second] = event.touches;
        const distance = Math.hypot(second.clientX - first.clientX, second.clientY - first.clientY);
        if (!viewerGesture || viewerGesture.mode !== 'pinch') {
          viewerGesture = {
            mode: 'pinch',
            startDistance: distance,
            startScale: viewerZoomScale,
            startX: viewerZoomTranslateX,
            startY: viewerZoomTranslateY,
          };
          return;
        }

        const nextScale = viewerGesture.startScale * (distance / viewerGesture.startDistance);
        updateViewerZoom(nextScale, viewerZoomTranslateX, viewerZoomTranslateY);
        return;
      }

      if (viewerZoomScale > 1 && viewerGesture && viewerGesture.mode === 'pan') {
        const touch = event.touches[0];
        const deltaX = touch.clientX - viewerGesture.startClientX;
        const deltaY = touch.clientY - viewerGesture.startClientY;
        updateViewerZoom(viewerGesture.startScale, viewerGesture.startX + deltaX, viewerGesture.startY + deltaY);
      }
    }, { passive: false });

    viewer.addEventListener('touchend', (event) => {
      if (event.touches.length === 0 && viewerGesture && viewerGesture.mode === 'pinch') {
        viewerGesture = null;
        return;
      }
      const touch = event.changedTouches[0];
      const tappedImage = Boolean(event.target.closest('[data-zoom-target]'));
      const now = Date.now();
      if (tappedImage && now - viewerLastTapAt < 280 && viewerLastTapTarget === 'image') {
        viewerLastTapAt = 0;
        viewerLastTapTarget = '';
        toggleViewerZoom();
        event.preventDefault();
        return;
      }
      viewerLastTapAt = now;
      viewerLastTapTarget = tappedImage ? 'image' : '';
      const deltaX = touch.clientX - viewerTouchStartX;
      const deltaY = touch.clientY - viewerTouchStartY;
      if (viewerZoomScale > 1) {
        return;
      }
      if (Math.abs(deltaX) < 40 || Math.abs(deltaY) > 80) {
        return;
      }
      if (deltaX < 0) {
        void openViewer(viewerOpenIndex + 1, 1);
      } else if (deltaX > 0 && viewerOpenIndex > 0) {
        void openViewer(viewerOpenIndex - 1, -1);
      }
    }, { passive: true });

    viewer.addEventListener('wheel', (event) => {
      if (!viewerList.querySelector('[data-zoom-target]')) {
        return;
      }
      event.preventDefault();
      const nextScale = viewerZoomScale + (event.deltaY < 0 ? 0.15 : -0.15);
      updateViewerZoom(nextScale);
    }, { passive: false });

    document.addEventListener('keydown', (event) => {
      if (viewer.classList.contains('hidden')) {
        return;
      }
      if (event.key === 'ArrowLeft' && viewerOpenIndex > 0) {
        event.preventDefault();
        void openViewer(viewerOpenIndex - 1, -1);
      } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        void openViewer(viewerOpenIndex + 1, 1);
      } else if (event.key === 'Escape') {
        event.preventDefault();
        closeViewerPanel();
      }
    });

    const maybeLoadMore = () => {
      if (galleryLoading || !galleryHasMore) {
        return;
      }
      const distanceToBottom = document.documentElement.scrollHeight - (window.scrollY + window.innerHeight);
      if (distanceToBottom < 800) {
        loadGalleryPage();
      }
    };

    const updateHeroParallax = () => {
      if (!heroHeader || !heroParallax || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
      }
      const headerHeight = heroHeader.offsetHeight || 1;
      const progress = clamp(window.scrollY / headerHeight, 0, 1);
      const translateY = Math.round(progress * headerHeight * 0.2);
      heroParallax.style.transform = `translate3d(0, ${translateY}px, 0)`;
    };

    if ('IntersectionObserver' in window) {
      const galleryObserver = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          loadGalleryPage();
        }
      }, { rootMargin: '800px 0px' });
      galleryObserver.observe(gallerySentinel);
      imageObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            upgradeGalleryImage(entry.target);
          }
        });
      }, { rootMargin: '200px 0px' });
      observeGalleryImages();
    } else {
      galleryGrid.querySelectorAll('img[data-thumb-src]').forEach(upgradeGalleryImage);
    }

    window.addEventListener('scroll', maybeLoadMore, { passive: true });
    window.addEventListener('scroll', updateHeroParallax, { passive: true });
    window.addEventListener('resize', maybeLoadMore);
    window.addEventListener('resize', updateHeroParallax);
    updateHeroParallax();
    loadGalleryPage(true);
    if (pendingSharedPhotoId) {
      void openSharedPhotoDirectly();
    }
  </script>
</main>
</body>
</html>
