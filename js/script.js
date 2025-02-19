  // Get the hamburger icon and navigation links
  const hamburger = document.getElementById('hamburger');
  const navLinks = document.getElementById('nav-links');

  // Toggle the 'active' class on the nav-links when the hamburger is clicked
  hamburger.addEventListener('click', () => {
      navLinks.classList.toggle('show');
      hamburger.innerHTML = navLinks.classList.contains('show') ? '&#10006;' : '&#9776;';
      navLinks.classList.toggle('active');
  });
  // hamburger.addEventListener('click', () => {
  //     navLinks.classList.toggle('active');
  // });

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

//   nextBtn.addEventListener('click', () => {
//       if (currentIndex < feedbackItems.length - 1) {
//           currentIndex++;
//       } else {
//           currentIndex = 0; // Loop back to the first item
//       }
//       updateCarousel();
//   });

//   prevBtn.addEventListener('click', () => {
//       if (currentIndex > 0) {
//           currentIndex--;
//       } else {
//           currentIndex = feedbackItems.length - 1; // Loop back to the last item
//       }
//       updateCarousel();
//   });

//   // Optional: Auto-slide functionality
//   setInterval(() => {
//       nextBtn.click();
//   }, 5000); // Change slide every 5 seconds


  document.getElementById("contactForm").addEventListener("submit", function(event) {
    event.preventDefault(); // Prevent default form submission
debugger;
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