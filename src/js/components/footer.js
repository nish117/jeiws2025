export function initializeFooter() {
    const footer = document.getElementById('footer');
    const isGalleryPage = window.location.pathname.includes('gallery.html') || window.location.pathname.includes('area-converter.html');
    footer.innerHTML = `
        <footer class="footer">
            <a href="/">
                <div class="logo">
                    <img src="assets/logo.png" alt="Company Logo"> 
                </div>
            </a>
            <div class="footer-links">
                <h3>Quick Links</h3>
                    <a href="${isGalleryPage ? './' : '#'}" class="footer-link">Home</a>
                    <a href="${isGalleryPage ? './#about' : '#about'}" class="footer-link">About</a>
                    <a href="${isGalleryPage ? './#team' : '#team'}" class="footer-link">Team</a>
                    <a href="${isGalleryPage ? './#projects' : '#projects'}" class="footer-link">Projects</a>
                    <a href="${isGalleryPage ? './#services' : '#services'}" class="footer-link">Services</a>
                    <a href="area-converter.html" class="footer-link">Area Converter</a>
                    <a href="${isGalleryPage ? './#contact' : '#contact'}" class="footer-link">Contact</a>
            </div>
            <div class="social">
                <h3>Follow Us</h3>
                <a href="https://www.facebook.com/JEIWS" target="_blank"><i class="fab fa-facebook-f"></i></a>
                <a href="https://www.instagram.com/jeiws__" target="_blank"><i class="fab fa-instagram"></i></a>
                <a href="https://wa.me/9779811334479" target="_blank"><i class="fab fa-whatsapp"></i></a>
            </div>
            <div class="company-info">
                <p>&copy; 2025 J.E. Infrastructure Waterproofing & Services Pvt. Ltd. All rights reserved.</p>
                <p>Sanepa, Lalitpur, Nepal </p>
                <p>Email: <a href="mailto:jeiwservices@gmail.com">jeiwservices@gmail.com</a></p>
                <p>Phone: <a href="tel:+977-9811334479">+977-9811334479</a></p>
            </div>
        </footer>
    `;
    // Add click handler for navigation links
    const navLinks = footer.querySelectorAll('.footer-link');
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