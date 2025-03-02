export function initializeFooter() {
    const footer = document.getElementById('footer');
    footer.innerHTML = `
        <footer class="footer">
            <div class="links">
                <h3>Quick Links</h3>
                <a href="#">Home</a>
                <a href="#about">About</a>
                <a href="#team">Team</a>
                <a href="#projects">Projects</a>
                <a href="#services">Services</a>
                <a href="#contact">Contact</a>
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
}