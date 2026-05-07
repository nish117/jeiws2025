import { projects } from './projects.js';

const carouselTrack = document.querySelector('.carousel-track');
const carouselEl    = document.querySelector('.carousel');
const carouselContainer = document.querySelector('.carousel-container');

// Clone last 3 + real + first 3 for seamless infinite loop
const projectsForCarousel = [
  ...projects.slice(-3),
  ...projects,
  ...projects.slice(0, 3),
];

projectsForCarousel.forEach(project => {
  const card = document.createElement('div');
  card.className = 'project-card';
  card.innerHTML = `
    <img src="${project.image}" alt="${project.title}">
    <div class="project-content">
      <h3>${project.title}</h3>
      <p>${project.description}</p>
      <a href="gallery.html?id=${project.id}" class="view-more">View More</a>
    </div>
  `;
  carouselTrack.appendChild(card);
});

let currentIndex = 3; // first real slide
const totalSlides = projectsForCarousel.length;

// Always reads current rendered width so it stays correct after resize
function getCardWidth() {
  const card = document.querySelector('.project-card');
  return card ? card.offsetWidth + 22 : 0; // 22px = CSS gap
}

function setPosition(animate) {
  carouselTrack.style.transition = animate
    ? 'transform 0.55s cubic-bezier(0.4,0,0.2,1)'
    : 'none';
  carouselTrack.style.transform = `translateX(-${currentIndex * getCardWidth()}px)`;
}

// Init
setPosition(false);

// ── Dot pagination ────────────────────────────────────────────
const dotsWrapper = document.createElement('div');
dotsWrapper.className = 'carousel-dots';

projects.forEach((_, i) => {
  const dot = document.createElement('button');
  dot.className = 'carousel-dot';
  dot.setAttribute('aria-label', `Go to project ${i + 1}`);
  dot.addEventListener('click', () => {
    currentIndex = 3 + i;
    updateCarousel(true);
  });
  dotsWrapper.appendChild(dot);
});

carouselContainer.insertAdjacentElement('afterend', dotsWrapper);

function updateDots() {
  const realIndex = ((currentIndex - 3) % projects.length + projects.length) % projects.length;
  document.querySelectorAll('.carousel-dot').forEach((dot, i) => {
    dot.classList.toggle('active', i === realIndex);
  });
}
updateDots();

// ── Core update ────────────────────────────────────────────────
function updateCarousel(animate) {
  setPosition(animate);
  updateDots();

  // Seamless jump at the clone boundaries
  if (currentIndex <= 2) {
    setTimeout(() => {
      currentIndex = totalSlides - 4;
      setPosition(false);
      updateDots();
    }, 560);
  } else if (currentIndex >= totalSlides - 3) {
    setTimeout(() => {
      currentIndex = 3;
      setPosition(false);
      updateDots();
    }, 560);
  }
}

// ── Button navigation ──────────────────────────────────────────
document.querySelector('.prev').addEventListener('click', () => {
  currentIndex--;
  updateCarousel(true);
});
document.querySelector('.next').addEventListener('click', () => {
  currentIndex++;
  updateCarousel(true);
});

// ── Touch / swipe ──────────────────────────────────────────────
let touchStartX = 0;
let touchStartY = 0;
let swipeDetected = false;

carouselEl.addEventListener('touchstart', e => {
  touchStartX = e.touches[0].clientX;
  touchStartY = e.touches[0].clientY;
  swipeDetected = false;
}, { passive: true });

carouselEl.addEventListener('touchmove', e => {
  const dx = Math.abs(e.touches[0].clientX - touchStartX);
  const dy = Math.abs(e.touches[0].clientY - touchStartY);
  if (dx > dy && dx > 8) swipeDetected = true;
}, { passive: true });

carouselEl.addEventListener('touchend', e => {
  if (!swipeDetected) return;
  const diff = touchStartX - e.changedTouches[0].clientX;
  if (Math.abs(diff) > 48) {
    currentIndex += diff > 0 ? 1 : -1;
    updateCarousel(true);
  }
}, { passive: true });

// ── Resize: recalculate position without animation ─────────────
window.addEventListener('resize', () => setPosition(false));
