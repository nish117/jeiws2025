import { projects } from './projects.js';
// Create project cards and add them to the carousel
const carouselTrack = document.querySelector('.carousel-track');

// Clone the first three and last three projects for infinite scrolling
const projectsForCarousel = [
  ...projects.slice(-3),
  ...projects,
  ...projects.slice(0, 3)
];

// projectsForCarousel.forEach(project => {
//   const card = document.createElement('div');
//   card.className = 'project-card';
//   card.innerHTML = `
//     <img src="${project.image}" alt="${project.title}">
//     <div class="project-content">
//       <h3>${project.title}</h3>
//       <p>${project.description}</p>
//       <a href="/pages/gallery.html?id=${project.id}" class="view-more">View More</a>
//     </div>
//   `;
//   carouselTrack.appendChild(card);
// });
projectsForCarousel.forEach(project => {
  const card = document.createElement('div');
  card.className = 'project-card';
  card.innerHTML = `
    <img src="${project.image}" alt="${project.title}">
    <div class="project-content">
      <h3>${project.title}</h3>
      <p>${project.description}</p>
      <a href="pages/gallery.html?id=${project.id}" class="view-more">View More</a>
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

// // Create a blob URL for the gallery page
// function createGalleryPage(project) {
//   const html = `
//     <!DOCTYPE html>
//     <html>
//       <head>
//         <meta charset="UTF-8">
//         <meta name="viewport" content="width=device-width, initial-scale=1.0">
//         <title>${project.title} Gallery</title>
//         <style>
//           body { 
//             margin: 0; 
//             padding: 20px; 
//             font-family: Arial, sans-serif;
//             background-color: #f5f5f5;
//           }
//           h1 { 
//             text-align: center;
//             color: #333;
//             margin-bottom: 2rem;
//           }
//           .gallery { 
//             display: grid; 
//             grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
//             gap: 20px;
//             padding: 20px;
//             max-width: 1200px;
//             margin: 0 auto;
//           }
//           .gallery img {
//             width: 100%;
//             height: 300px;
//             object-fit: cover;
//             border-radius: 8px;
//             box-shadow: 0 2px 10px rgba(0,0,0,0.1);
//             transition: transform 0.3s ease;
//           }
//           .gallery img:hover {
//             transform: scale(1.02);
//           }
//         </style>
//       </head>
//       <body>
//         <h1>${project.title} Gallery</h1>
//         <div class="gallery">
//           ${project.gallery.map(img => `<img src="${img}" alt="${project.title}">`).join('')}
//         </div>
//       </body>
//     </html>
//   `;
  
//   const blob = new Blob([html], { type: 'text/html' });
//   return URL.createObjectURL(blob);
//   // return html;
// }

// // Handle "View More" clicks
// document.querySelectorAll('.view-more').forEach(button => {
//   button.addEventListener('click', (e) => {
//     e.preventDefault();
//     const projectId = parseInt(button.dataset.projectId);
//     const project = projects.find(p => p.id === projectId);
    
//     // Create and open the gallery page URL
//     const galleryUrl = createGalleryPage(project);
//     window.open(galleryUrl, '_blank');
    
//     // Clean up the blob URL after the window is opened
//     setTimeout(() => URL.revokeObjectURL(galleryUrl), 100);
//   });
// });