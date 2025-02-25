import { projects } from './projects.js';

// Get the project ID from the URL query parameters
const urlParams = new URLSearchParams(window.location.search);
const projectId = parseInt(urlParams.get('id'));

// Find the project
const project = projects.find(p => p.id === projectId);

if (project) {
    // Set the page title
    document.title = `JEIWS-${project.title} Gallery`;
    
    // Set the gallery title
    document.getElementById('gallery-title').textContent = `${project.title} Gallery`;
    
    // Create the gallery
    const galleryGrid = document.getElementById('gallery-grid');
    project.gallery.forEach(imageUrl => {
        const img = document.createElement('img');
        img.src = imageUrl;
        img.alt = project.title;
        img.className = 'gallery-item';
        galleryGrid.appendChild(img);
    });
} else {
    // Handle case where project is not found
    document.getElementById('gallery-title').textContent = 'Gallery Not Found';
}