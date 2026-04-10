    </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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