export function initializeHeader() {
    const header = document.getElementById('header');
    const isGalleryPage = window.location.pathname.includes('gallery.html');
    header.innerHTML = `
        <header class"site-header>
            <nav class="navbar">
                <a href="/">
                    <div class="logo">
                        <img src="assets/logo.png" alt="Company Logo"> 
                    </div>
                </a>
                <a id="anchor-no-underline" href="/">
                    <div class="company-title">
                        <span class="company-maintitle"><Strong>J.E. Infrastructrure</Strong></span>
                        <br> <span class="company-subtitle">Waterproofing & Services Pvt. Ltd.</span> 
                    </div>
                </a>
                <ul class="nav-links" id="nav-links">
                    <li><a href="${isGalleryPage ? './#about' : '#about'}" class="nav-link">About Us</a></li>
                    <li><a href="${isGalleryPage ? './#team' : '#team'}" class="nav-link">Our Team</a></li>
                    <li><a href="${isGalleryPage ? './#projects' : '#projects'}" class="nav-link">Our Projects</a></li>
                    <li><a href="${isGalleryPage ? './#services' : '#services'}" class="nav-link">Our Services</a></li>
                    <li><a href="${isGalleryPage ? './#contact' : '#contact'}" class="nav-link">Contact Us</a></li>
                </ul>
                <div class="call-us">
                    <a href="tel:+977-9841618841" class="call-button">Call Now</a> <!-- Replace with your phone number -->
                </div>
                <div class="hamburger" id="hamburger">
                    &#9776; <!-- Hamburger icon -->
                </div>
            </nav>
        </header>
    `;
    // Add click handler for navigation links
    const navLinks = header.querySelectorAll('.nav-link');
    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            const href = link.getAttribute('href');
            
            // If it's a hash link on the current page
            if (href.startsWith('#')) {
                e.preventDefault();
                const targetElement = document.querySelector(href);
                if (targetElement) {
                    targetElement.scrollIntoView({ behavior: 'smooth' });
                }
            }
            // If it's a link to the main page with a hash
            else if (href.startsWith('./#')) {
                // Store the hash to scroll after page load
                sessionStorage.setItem('scrollTarget', href.substring(2));
            }
        });
    });
}
