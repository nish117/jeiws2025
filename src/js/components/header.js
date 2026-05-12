export function initializeHeader() {
    const header = document.getElementById('header');
    const isGalleryPage = window.location.pathname.includes('gallery.html') || window.location.pathname.includes('area-converter.html') || window.location.pathname.includes('vacancies.html');

    header.innerHTML = `
        <header class="site-header">
            <div class="topbar">
                <div class="topbar-inner">
                    <div class="topbar-left">
                        <a href="tel:+977-9811334479" class="topbar-link">
                            <i class="fas fa-phone-alt"></i> +977-9811334479
                        </a>
                        <a href="mailto:jeiwservices@gmail.com" class="topbar-link">
                            <i class="fas fa-envelope"></i> jeiwservices@gmail.com
                        </a>
                    </div>
                    <div class="topbar-right">
                        <span class="topbar-link"><i class="fas fa-map-marker-alt"></i> Sanepa, Lalitpur, Nepal</span>
                        <div class="topbar-socials">
                            <a href="https://www.facebook.com/JEIWS" target="_blank" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                            <a href="https://www.instagram.com/jeiws__" target="_blank" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                            <a href="https://www.tiktok.com/@jeiws_company" target="_blank" aria-label="TikTok"><i class="fab fa-tiktok"></i></a>
                            <a href="https://wa.me/9779811334479" target="_blank" aria-label="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                        </div>
                    </div>
                </div>
            </div>
            <nav class="navbar">
                <a href="/" class="nav-brand">
                    <div class="logo">
                        <img src="assets/logo.png" alt="JEIWS Logo">
                    </div>
                    <div class="company-title">
                        <span class="company-maintitle">J.E. Infrastructure</span>
                        <span class="company-subtitle">Waterproofing &amp; Services Pvt. Ltd.</span>
                    </div>
                </a>
                <ul class="nav-links" id="nav-links">
                    <li><a href="${isGalleryPage ? './#about' : '#about'}" class="nav-link">About</a></li>
                    <li><a href="${isGalleryPage ? './#services' : '#services'}" class="nav-link">Services</a></li>
                    <li><a href="${isGalleryPage ? './#projects' : '#projects'}" class="nav-link">Projects</a></li>
                    <li><a href="${isGalleryPage ? './#team' : '#team'}" class="nav-link">Team</a></li>
                    <li><a href="${isGalleryPage ? './#why-us' : '#why-us'}" class="nav-link">Why Us</a></li>
                    <li><a href="vacancies.html" class="nav-link"><i class="fas fa-briefcase"></i> Careers</a></li>
                    <li><a href="area-converter.html" class="nav-link nav-link-tool"><i class="fas fa-ruler-combined"></i> Area Tool</a></li>
                    <li><a href="${isGalleryPage ? './#contact' : '#contact'}" class="nav-link nav-link-cta">Contact</a></li>
                </ul>
                <div class="call-us">
                    <a href="tel:+977-9811334479" class="call-button">
                        <i class="fas fa-phone-alt"></i> Call Now
                    </a>
                </div>
                <div class="hamburger" id="hamburger">&#9776;</div>
            </nav>
        </header>
    `;

    const navLinks = header.querySelectorAll('.nav-link');
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
                // Close mobile menu
                const nl = document.getElementById('nav-links');
                const hb = document.getElementById('hamburger');
                if (nl) nl.classList.remove('show');
                if (hb) hb.innerHTML = '&#9776;';
            } else if (href.startsWith('./#')) {
                sessionStorage.setItem('scrollTarget', href.substring(2));
            }
        });
    });
}
