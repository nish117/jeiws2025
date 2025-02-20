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

  const carouselContainer = document.querySelector('.carousel-container');
  const feedbackItems = document.querySelectorAll('.feedback-item');
  const prevBtn = document.getElementById('prevBtn');
  const nextBtn = document.getElementById('nextBtn');

  let currentIndex = 0;

  function updateCarousel() {
      const itemWidth = feedbackItems[0].clientWidth; // Get the width of a feedback item
      const offset = -currentIndex * itemWidth; // Calculate the offset
      carouselContainer.style.transform = `translateX(${offset}px)`; // Move the carousel
  }

  document.getElementById("contactForm").addEventListener("submit", function(event) {
    event.preventDefault(); // Prevent default form submission
    let formData = new FormData(this);

    fetch("send_email.php", {
        method: "POST",
        body: formData
    })
    .then(response => response.text())
    .then(data => {
        if (data.trim() === "success") {
            document.getElementById("popup").style.display = "block";
            document.getElementById("contactForm").reset();
        } else {
            alert("Something went wrong. Please try again.");
        }
    })
    .catch(error => console.error("Error:", error));
});

// Close Popup
function closePopup() {
    document.getElementById("popup").style.display = "none";
}


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
document.getElementById("bg-video").src = randomVideo;

// Show loader until the entire document (including images, videos, etc.) is fully loaded
window.onload = function () {
    setTimeout(() => {
        document.getElementById("loader").style.display = "none"; // Hide loader
        let content = document.getElementById("content");
        content.style.display = "block"; // Show content
        setTimeout(() => {
            content.style.opacity = "1"; // Fade-in effect
        }, 200); 
    }, 1100); // Ensure loader is visible for at least 2 seconds
};
