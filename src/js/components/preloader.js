export function initializePreloader() {
    const preloader = document.getElementById('preloader');
    preloader.innerHTML = `
        <div class="loader">
            <div class="loader-inner">
                <svg class="loader-svg" viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Animated building rising from ground -->

                    <!-- Ground line -->
                    <line class="ground-line" x1="10" y1="100" x2="110" y2="100" stroke="rgba(27,103,153,0.3)" stroke-width="1.5" stroke-linecap="round"/>

                    <!-- Building floors — each rises up -->
                    <rect class="floor floor-1" x="38" y="82" width="44" height="16" rx="1" fill="rgba(27,103,153,0.25)" stroke="#1B6799" stroke-width="1.2"/>
                    <rect class="floor floor-2" x="42" y="65" width="36" height="16" rx="1" fill="rgba(27,103,153,0.25)" stroke="#1B6799" stroke-width="1.2"/>
                    <rect class="floor floor-3" x="46" y="49" width="28" height="15" rx="1" fill="rgba(27,103,153,0.25)" stroke="#1B6799" stroke-width="1.2"/>
                    <rect class="floor floor-4" x="50" y="34" width="20" height="14" rx="1" fill="rgba(27,103,153,0.25)" stroke="#1B6799" stroke-width="1.2"/>

                    <!-- Windows -->
                    <rect class="win" x="44" y="85" width="6" height="7" rx="0.5" fill="#2B82BC" opacity="0"/>
                    <rect class="win" x="55" y="85" width="6" height="7" rx="0.5" fill="#2B82BC" opacity="0"/>
                    <rect class="win" x="66" y="85" width="6" height="7" rx="0.5" fill="#2B82BC" opacity="0"/>
                    <rect class="win" x="47" y="68" width="5" height="6" rx="0.5" fill="#2B82BC" opacity="0"/>
                    <rect class="win" x="57" y="68" width="5" height="6" rx="0.5" fill="#2B82BC" opacity="0"/>
                    <rect class="win" x="67" y="68" width="5" height="6" rx="0.5" fill="#2B82BC" opacity="0"/>
                    <rect class="win" x="50" y="53" width="5" height="5" rx="0.5" fill="#2B82BC" opacity="0"/>
                    <rect class="win" x="59" y="53" width="5" height="5" rx="0.5" fill="#2B82BC" opacity="0"/>
                    <rect class="win" x="54" y="38" width="4" height="5" rx="0.5" fill="#2B82BC" opacity="0"/>
                    <rect class="win" x="62" y="38" width="4" height="5" rx="0.5" fill="#2B82BC" opacity="0"/>

                    <!-- Crane arm -->
                    <line class="crane-pole" x1="90" y1="100" x2="90" y2="18" stroke="#F0A030" stroke-width="2.5" stroke-linecap="round"/>
                    <line class="crane-arm" x1="90" y1="22" x2="52" y2="22" stroke="#F0A030" stroke-width="2" stroke-linecap="round"/>
                    <line class="crane-arm-r" x1="90" y1="22" x2="105" y2="30" stroke="#F0A030" stroke-width="1.5" stroke-linecap="round"/>

                    <!-- Crane hook line (animated) -->
                    <line class="crane-rope" x1="60" y1="22" x2="60" y2="50" stroke="rgba(240,160,48,0.6)" stroke-width="1" stroke-dasharray="2 2"/>
                    <circle class="crane-hook" cx="60" cy="52" r="3" stroke="#F0A030" stroke-width="1.5" fill="none"/>

                    <!-- Progress bar at bottom -->
                    <rect x="20" y="111" width="80" height="3" rx="1.5" fill="rgba(255,255,255,0.06)"/>
                    <rect class="progress-bar" x="20" y="111" width="0" height="3" rx="1.5" fill="#1B6799"/>
                </svg>

                <!-- Brand -->
                <div class="loader-brand">
                    <img src="assets/logo.png" alt="JEIWS" class="loader-logo">
                    <div class="loader-text">
                        <span class="loader-title">J.E. Infrastructure</span>
                        <span class="loader-subtitle">Building Your Dreams</span>
                    </div>
                </div>

                <!-- Dots -->
                <div class="loader-dots">
                    <span></span><span></span><span></span>
                </div>
            </div>
        </div>
    `;
}
