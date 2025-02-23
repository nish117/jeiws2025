import { projects } from './projects.js';
// Create project cards and add them to the carousel
const carouselTrack = document.querySelector('.carousel-track');

// Clone the first three and last three projects for infinite scrolling
const projectsForCarousel = [
  ...projects.slice(-3),
  ...projects,
  ...projects.slice(0, 3)
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
// Carousel navigation
let currentIndex = 3; // Start at index 3 (first real item)
const visibleCards = 3;
const cardWidth = document.querySelector('.project-card').offsetWidth + 24; // card width + gap
const totalSlides = projectsForCarousel.length;

// Initialize position to show first three actual projects
carouselTrack.style.transform = `translateX(-${currentIndex * cardWidth}px)`;

document.querySelector('.prev').addEventListener('click', () => {
  currentIndex--;
  updateCarouselPosition(true);
});

document.querySelector('.next').addEventListener('click', () => {
  currentIndex++;
  updateCarouselPosition(true);
});

function updateCarouselPosition(animate = false) {
  if (!animate) {
    carouselTrack.style.transition = 'none';
  } else {
    carouselTrack.style.transition = 'transform 0.5s ease-in-out';
  }
  
  carouselTrack.style.transform = `translateX(-${currentIndex * cardWidth}px)`;

  // Check if we need to jump to the other end
  if (currentIndex <= 2) {
    setTimeout(() => {
      carouselTrack.style.transition = 'none';
      currentIndex = totalSlides - 4;
      carouselTrack.style.transform = `translateX(-${currentIndex * cardWidth}px)`;
    }, 500);
  } else if (currentIndex >= totalSlides - 3) {
    setTimeout(() => {
      carouselTrack.style.transition = 'none';
      currentIndex = 3;
      carouselTrack.style.transform = `translateX(-${currentIndex * cardWidth}px)`;
    }, 500);
  }
}