    </main>
        </div>
    </div>
    <script src="../public/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="../public/assets/vendor/aos/aos.js"></script>
    <script src="../public/assets/vendor/chartjs/chart.umd.min.js"></script>
    <script>
        function moveAdminModalsToBody() {
            document.querySelectorAll('.modal').forEach(function(modal) {
                if (modal.parentElement !== document.body) {
                    document.body.appendChild(modal);
                }
            });
        }

        function cleanupStaleModalBackdrop() {
            const openModal = document.querySelector('.modal.show');
            if (openModal) {
                return;
            }

            document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
                backdrop.remove();
            });
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }

        moveAdminModalsToBody();

        // Initialize Animate on Scroll
        AOS.init({
            duration: 600,
            once: true
        });

        // Sidebar Toggle Functionality
        document.addEventListener('DOMContentLoaded', function () {
            moveAdminModalsToBody();
            cleanupStaleModalBackdrop();

            document.addEventListener('show.bs.modal', moveAdminModalsToBody);
            document.addEventListener('hidden.bs.modal', cleanupStaleModalBackdrop);

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
