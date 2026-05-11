    </main>
        </div>
    </div>
    <script src="../public/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="../public/assets/vendor/aos/aos.js"></script>
    <script src="../public/assets/vendor/chartjs/chart.umd.min.js"></script>
    <script>
        // Initialize Animate on Scroll
        AOS.init({
            duration: 600,
            once: true
        });

        // Sidebar Toggle Functionality
        document.addEventListener('DOMContentLoaded', function () {
            const sidebarToggle = document.getElementById('sidebar-toggle');
            const layout = document.querySelector('.admin-layout');

            if (sidebarToggle && layout) {
                sidebarToggle.addEventListener('click', function () {
                    layout.classList.toggle('sidebar-collapsed');
                });
            }
        });
    </script>
</body>
</html>
