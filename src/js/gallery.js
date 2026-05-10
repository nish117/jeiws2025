const { projects } = await import('./projects.js?v=' + Date.now());

const urlParams = new URLSearchParams(window.location.search);
const projectId = parseInt(urlParams.get('id'));
const project = projects.find(p => p.id === projectId);

const galleryGrid = document.getElementById('gallery-grid');
const galleryTitle = document.getElementById('gallery-title');

function escHtml(str) {
  const d = document.createElement('div');
  d.appendChild(document.createTextNode(String(str)));
  return d.innerHTML;
}

if (!project) {
    document.title = 'JEIWS — Project Not Found';
    const container = galleryGrid?.closest('.gallery-container');
    if (container) {
        container.innerHTML = `
            <div class="gallery-not-found">
                <div class="gallery-nf-icon"><i class="fas fa-folder-open"></i></div>
                <h1 class="gallery-nf-title">Project Not Found</h1>
                <p class="gallery-nf-text">The project you're looking for doesn't exist or may have been removed.</p>
                <a href="index.html#projects" class="gallery-back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Projects
                </a>
            </div>`;
    }
} else {
    document.title = `JEIWS — ${escHtml(project.title)}`;

    // ── Build hero header ──────────────────────────────
    const container = galleryGrid.closest('.gallery-container');

    container.innerHTML = `
        <!-- Project Hero -->
        <div class="gallery-hero">
            <div class="gallery-hero-inner">
                <a href="index.html#projects" class="gallery-back-link"><i class="fas fa-arrow-left"></i> All Projects</a>
                <div class="gallery-hero-meta">
                    <div class="section-eyebrow">Project Gallery</div>
                    <h1 id="gallery-title">${escHtml(project.title)}</h1>
                    <p class="gallery-description">${escHtml(project.description).replace(/&lt;\/?br\s*\/?&gt;/gi, '<br>')}</p>
                </div>
                <div class="gallery-hero-stats">
                    <div class="gallery-stat"><span>${escHtml(project.gallery.length)}</span><small>Photos</small></div>
                </div>
            </div>
        </div>

        <!-- Filter / Count bar -->
        <div class="gallery-toolbar">
            <div class="gallery-toolbar-inner">
                <span class="gallery-count"><i class="fas fa-images"></i> ${escHtml(project.gallery.length)} images</span>
                <div class="gallery-view-toggle">
                    <button class="gallery-view-btn" data-view="masonry" title="Masonry"><i class="fas fa-th"></i></button>
                    <button class="gallery-view-btn active" data-view="grid" title="Grid"><i class="fas fa-th-large"></i></button>
                </div>
            </div>
        </div>

        <!-- Gallery Grid -->
        <div class="gallery gallery-uniform" id="gallery-grid"></div>

        <!-- Lightbox -->
        <div class="lightbox" id="lightbox" role="dialog" aria-modal="true">
            <div class="lightbox-backdrop"></div>
            <button class="lightbox-close" id="lb-close" aria-label="Close"><i class="fas fa-times"></i></button>
            <button class="lightbox-nav lightbox-prev" id="lb-prev" aria-label="Previous"><i class="fas fa-chevron-left"></i></button>
            <button class="lightbox-nav lightbox-next" id="lb-next" aria-label="Next"><i class="fas fa-chevron-right"></i></button>
            <div class="lightbox-content">
                <img id="lb-img" src="" alt="">
                <div class="lightbox-caption">
                    <span id="lb-counter"></span>
                    <span id="lb-title">${escHtml(project.title)}</span>
                </div>
            </div>
        </div>
    `;

    // ── Render images ─────────────────────────────────
    const grid = document.getElementById('gallery-grid');
    const validImages = [];

    project.gallery.forEach((src, i) => {
        const item = document.createElement('div');
        item.className = 'gallery-item-wrap';
        item.style.animationDelay = `${(i % 12) * 0.04}s`;
        item.innerHTML = `
            <div class="gallery-item-inner">
                <img src="${escHtml(src)}" alt="${escHtml(project.title)} — Photo ${i + 1}" class="gallery-item" loading="lazy">
                <div class="gallery-item-overlay">
                    <i class="fas fa-expand-alt"></i>
                </div>
            </div>
        `;
        item.addEventListener('click', () => openLightbox(i));
        grid.appendChild(item);
        validImages.push(src);
    });

    // ── Lightbox logic ────────────────────────────────
    const lightbox  = document.getElementById('lightbox');
    const lbImg     = document.getElementById('lb-img');
    const lbCounter = document.getElementById('lb-counter');
    const lbClose   = document.getElementById('lb-close');
    const lbPrev    = document.getElementById('lb-prev');
    const lbNext    = document.getElementById('lb-next');
    const lbBackdrop = lightbox.querySelector('.lightbox-backdrop');
    let current = 0;

    function openLightbox(index) {
        current = index;
        showImage(current);
        lightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        lightbox.classList.remove('active');
        document.body.style.overflow = '';
    }

    function showImage(index) {
        lbImg.style.opacity = '0';
        lbImg.style.transform = 'scale(0.96)';
        setTimeout(() => {
            lbImg.src = validImages[index];
            lbCounter.textContent = `${index + 1} / ${validImages.length}`;
            lbImg.style.opacity = '1';
            lbImg.style.transform = 'scale(1)';
        }, 150);
    }

    function prevImage() { current = (current - 1 + validImages.length) % validImages.length; showImage(current); }
    function nextImage() { current = (current + 1) % validImages.length; showImage(current); }

    lbClose.addEventListener('click', closeLightbox);
    lbBackdrop.addEventListener('click', closeLightbox);
    lbPrev.addEventListener('click', prevImage);
    lbNext.addEventListener('click', nextImage);

    document.addEventListener('keydown', e => {
        if (!lightbox.classList.contains('active')) return;
        if (e.key === 'ArrowLeft')  prevImage();
        if (e.key === 'ArrowRight') nextImage();
        if (e.key === 'Escape')     closeLightbox();
    });

    // Touch swipe
    let touchStartX = 0;
    lightbox.addEventListener('touchstart', e => { touchStartX = e.touches[0].clientX; }, { passive: true });
    lightbox.addEventListener('touchend', e => {
        const dx = e.changedTouches[0].clientX - touchStartX;
        if (Math.abs(dx) > 50) dx < 0 ? nextImage() : prevImage();
    });

    // ── View toggle ───────────────────────────────────
    document.querySelectorAll('.gallery-view-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.gallery-view-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const view = btn.dataset.view;
            grid.className = view === 'grid' ? 'gallery gallery-uniform' : 'gallery gallery-masonry';
        });
    });
}
