export function initializePreloader() {
    const preloader = document.getElementById('preloader');
    preloader.innerHTML = `
        <div class="loader">
            <img src="assets/preloader.gif" alt="Loading...">
        </div>
    `;
}