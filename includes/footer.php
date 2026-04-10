    </main> <!-- This closes the main tag from either public_header.php or the page itself -->
    <footer id="site-footer" class="site-footer bg-dark text-white pt-5 pb-4">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 col-md-6 mb-4 mb-lg-0">
                    <h5 class="footer-heading">Scholarship Hub</h5>
                    <p class="text-white-50">Your central portal for discovering and applying to scholarships at Davao Vision College.</p>
                </div>
                <div class="col-lg-2 col-md-6 mb-4 mb-lg-0">
                    <h5 class="footer-heading">Quick Links</h5>
                    <ul class="list-unstyled footer-links">
                        <li><a href="index.php">Home</a></li>
                        <li><a href="scholarships.php">Scholarships</a></li>
                        <li><a href="index.php#how-it-works">How It Works</a></li>
                        <li><a href="login.php">Login</a></li>
                    </ul>
                </div>
               
                <div id="footer-contact" class="col-lg-3 col-md-6">
                    <h5 class="footer-heading">Connect With Us</h5>
                    <div class="social-icons">
                        <a href="https://web.facebook.com/DVCians" class="social-icon"><i class="bi bi-facebook"></i></a>
                        <a href="dvcregistrar@dvci-edu.com" class="social-icon"><i class="bi bi-google"></i></a>

                    </div>
                </div>
            </div>
            <hr class="my-4 bg-secondary">
            <p class="text-center text-white-50 small mb-0">&copy; <?php echo date("Y"); ?> Scholarship Hub. All Rights Reserved.</p>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- AOS JS -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({
            duration: 800,
            once: true,
        });
    </script>
    <!-- Custom App JS -->
    <script src="assets/js/app.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const navDockWrapper = document.querySelector('.nav-dock-wrapper');
        if (!navDockWrapper) return;

        const indicator = navDockWrapper.querySelector('.nav-dock-indicator');
        const navItems = navDockWrapper.querySelectorAll('.nav-item');
        const activeLink = navDockWrapper.querySelector('.nav-link.active');

        function moveIndicator(element) {
            if (!element) return;
            const link = element.querySelector('.nav-link');
            // Position relative to the nav-dock-wrapper
            const left = element.offsetLeft;
            const width = link.offsetWidth;
            const top = (navDockWrapper.offsetHeight - link.offsetHeight) / 2;
            const height = link.offsetHeight;

            indicator.style.left = `${left}px`;
            indicator.style.width = `${width}px`;
            indicator.style.top = `${top}px`;
            indicator.style.height = `${height}px`;
            indicator.style.opacity = '1';
        }

        // Initial position on page load
        if (activeLink) {
            moveIndicator(activeLink.parentElement);
        }

        // Move on hover
        navItems.forEach(item => {
            item.addEventListener('mouseenter', () => moveIndicator(item));
        });

        // Return to active link when mouse leaves the navigation area
        navDockWrapper.addEventListener('mouseleave', () => moveIndicator(activeLink ? activeLink.parentElement : null));
    });

    // Add scrolled class to header on scroll
    document.addEventListener('scroll', function() {
        const header = document.getElementById('main-header');
        if (!header) return;

        if (window.scrollY > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    });
    </script>
</body>
</html>
