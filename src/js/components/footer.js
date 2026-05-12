export function initializeFooter() {
    const footer = document.getElementById('footer');
    const isGalleryPage = window.location.pathname.includes('gallery.html') || window.location.pathname.includes('area-converter.html') || window.location.pathname.includes('vacancies.html');

    footer.innerHTML = `
        <div class="footer-top-bar"></div>
        <footer class="footer">
            <div class="footer-main">
                <!-- Brand Column -->
                <div class="footer-brand footer-col">
                    <a href="/">
                        <img src="assets/logo.png" alt="JEIWS Logo" class="logo" style="height:60px;object-fit:contain;opacity:0.9;display:block;margin-bottom:20px;filter:brightness(1.2)">
                    </a>
                    <p>Nepal's trusted partner for construction, waterproofing, and infrastructure services. Building quality structures since 2016.</p>
                    <div class="footer-brand-socials">
                        <a href="https://www.facebook.com/JEIWS" target="_blank" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                        <a href="https://www.instagram.com/jeiws__" target="_blank" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                        <a href="https://www.tiktok.com/@jeiws_company" target="_blank" aria-label="TikTok"><i class="fab fa-tiktok"></i></a>
                        <a href="https://wa.me/9779811334479" target="_blank" aria-label="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="footer-col">
                    <h4>Quick Links</h4>
                    <ul>
                        <li><a href="${isGalleryPage ? './' : '#'}" class="footer-link">Home</a></li>
                        <li><a href="${isGalleryPage ? './#about' : '#about'}" class="footer-link">About Us</a></li>
                        <li><a href="${isGalleryPage ? './#services' : '#services'}" class="footer-link">Our Services</a></li>
                        <li><a href="${isGalleryPage ? './#projects' : '#projects'}" class="footer-link">Projects</a></li>
                        <li><a href="${isGalleryPage ? './#why-us' : '#why-us'}" class="footer-link">Why Choose Us</a></li>
                        <li><a href="${isGalleryPage ? './#team' : '#team'}" class="footer-link">Our Team</a></li>
                        <li><a href="vacancies.html" class="footer-link">Careers</a></li>
                        <li><a href="area-converter.html" class="footer-link">Area Converter</a></li>
                    </ul>
                </div>

                <!-- Services -->
                <div class="footer-col">
                    <h4>Our Services</h4>
                    <ul>
                        <li><a href="${isGalleryPage ? './#services' : '#services'}" class="footer-link">Residential Construction</a></li>
                        <li><a href="${isGalleryPage ? './#services' : '#services'}" class="footer-link">Commercial Construction</a></li>
                        <li><a href="${isGalleryPage ? './#services' : '#services'}" class="footer-link">Waterproofing</a></li>
                        <li><a href="${isGalleryPage ? './#services' : '#services'}" class="footer-link">Renovation</a></li>
                        <li><a href="${isGalleryPage ? './#services' : '#services'}" class="footer-link">Project Management</a></li>
                        <li><a href="${isGalleryPage ? './#services' : '#services'}" class="footer-link">Architectural Design</a></li>
                    </ul>
                </div>

                <!-- Contact -->
                <div class="footer-col">
                    <h4>Get In Touch</h4>
                    <div class="footer-contact-item">
                        <div class="footer-contact-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="footer-contact-text">Sanepa, Lalitpur, Nepal</div>
                    </div>
                    <div class="footer-contact-item">
                        <div class="footer-contact-icon"><i class="fas fa-phone-alt"></i></div>
                        <div class="footer-contact-text"><a href="tel:+977-9811334479">+977-9811334479</a></div>
                    </div>
                    <div class="footer-contact-item">
                        <div class="footer-contact-icon"><i class="fas fa-envelope"></i></div>
                        <div class="footer-contact-text"><a href="mailto:jeiwservices@gmail.com">jeiwservices@gmail.com</a></div>
                    </div>
                    <div class="footer-contact-item">
                        <div class="footer-contact-icon"><i class="fas fa-globe"></i></div>
                        <div class="footer-contact-text"><a href="https://jeiws.com" target="_blank">www.jeiws.com</a></div>
                    </div>
                </div>
            </div>

            <!-- Bottom Bar -->
            <div class="footer-bottom">
                <p>&copy; 2025 J.E. Infrastructure Waterproofing &amp; Services Pvt. Ltd. All rights reserved.</p>
                <div class="footer-bottom-links">
                    <a href="#">Privacy Policy</a>
                    <a href="#">Terms of Service</a>
                    <a href="${isGalleryPage ? './#contact' : '#contact'}" class="footer-link">Contact</a>
                </div>
            </div>
        </footer>
    `;

    const navLinks = footer.querySelectorAll('.footer-link');
    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            const href = link.getAttribute('href');
            if (href.startsWith('#')) {
                e.preventDefault();
                const targetElement = document.querySelector(href);
                if (targetElement) {
                    const headerEl = document.querySelector('.site-header');
                    const offset = headerEl ? headerEl.offsetHeight : 70;
                    const top = targetElement.getBoundingClientRect().top + window.scrollY - offset;
                    window.scrollTo({ top, behavior: 'smooth' });
                }
            } else if (href.startsWith('./#')) {
                sessionStorage.setItem('scrollTarget', href.substring(2));
            }
        });
    });
}
