import { initializeHeader } from './components/header.js';
import { initializeFooter } from './components/footer.js';
import { initializePreloader } from './components/preloader.js';

// Initialize components
initializePreloader();
initializeHeader();
initializeFooter();

// Close modal when clicking outside the modal content
window.addEventListener("click", function(event) {
    var modal = document.getElementById("modal");
    if (event.target === modal) {
        modal.style.display = "none"; // Hide modal
    }
});
// Scroll to top when page loads/reloads
window.onload = () => {
    // Check if there's a stored scroll target
    const scrollTarget = sessionStorage.getItem('scrollTarget');
    if (scrollTarget) {
        // Clear the stored target
        sessionStorage.removeItem('scrollTarget');
        // Find the element and scroll to it
        const targetElement = document.querySelector(`${scrollTarget}`);
        if (targetElement) {
            setTimeout(() => {
                targetElement.scrollIntoView({ behavior: 'smooth' });
            }, 100);
        }
    } else {
        window.scrollTo(0, 0);
    }
};

// Handle history navigation (back/forward buttons)
window.onpageshow = function(event) {
    if (event.persisted) {
        window.scrollTo(0, 0);
    }
};

// Remove preloader after page loads
window.addEventListener('load', () => {
    const preloader = document.getElementById('preloader');
    preloader.style.opacity = '0';
    setTimeout(() => {
        preloader.style.display = 'none';
    }, 500);
    
});
// Get the hamburger icon and navigation links
const hamburger = document.getElementById('hamburger');
const navLinks = document.getElementById('nav-links');

// Toggle the 'active' class on the nav-links when the hamburger is clicked
hamburger.addEventListener('click', () => {
    navLinks.classList.toggle('show');
    hamburger.innerHTML = navLinks.classList.contains('show') ? '&#10006;' : '&#9776;';
    navLinks.classList.toggle('active');
});
// Ensure nav-links are visible on larger screens and not toggled accidentally
window.addEventListener('resize', () => {
    if (window.innerWidth > 768) {
        navLinks.classList.remove('active'); // Reset to default state
    }
});
  
// JavaScript for "Go to Top" button
const goToTopButton = document.getElementById('goToTop');
if(goToTopButton) {
    // Show the button when the user scrolls down 100px from the top of the document
    window.onscroll = function() {
        if (document.body.scrollTop > 100 || document.documentElement.scrollTop > 100) {
            goToTopButton.style.display = "block";
        } else {
            goToTopButton.style.display = "none";
        }
    };

    // When the user clicks on the button, scroll to the top of the document

    goToTopButton.addEventListener('click', function() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

const contactForm = document.getElementById("contactForm");
if(contactForm) {
    contactForm.addEventListener("submit", function(event) {
        event.preventDefault(); // Prevent default form submission
        let formData = new FormData(this);
        
        fetch("send_email.php", {
            method: "POST",
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            if (data.trim() === "success") {
                document.getElementById("modal").style.display = "flex";
                document.getElementById("contactForm").reset();
            } else {
                alert("Something went wrong. Please try again.");
            }
        })
        .catch(error => console.error("Error:", error));
    });
}




const siteVideo= document.getElementById("bg-video");
if(siteVideo) {
    // Array of video sources
    const videos = [
        "assets/videos/your-video1.mp4",
        "assets/videos/your-video2.mp4",
        "assets/videos/your-video3.mp4",
        "assets/videos/your-video4.mp4",
    ];

    // Select a random video
    const randomVideo = videos[Math.floor(Math.random() * videos.length)];

    // Set the video source
    siteVideo.src = randomVideo;
}
