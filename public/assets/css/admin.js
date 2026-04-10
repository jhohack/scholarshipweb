document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.sidebar');
    const desktopToggler = document.querySelector('.sidebar-toggle');
    const mobileToggler = document.querySelector('.sidebar-toggle-mobile');

    // Desktop toggle
    if (desktopToggler) {
        desktopToggler.addEventListener('click', () => {
            const isCollapsed = sidebar.getAttribute('data-collapsed') === 'true';
            sidebar.setAttribute('data-collapsed', !isCollapsed);
        });
    }

    // Mobile toggle
    if (mobileToggler) {
        mobileToggler.addEventListener('click', () => {
            sidebar.classList.toggle('is-open');
        });
    }

    // Close mobile sidebar when clicking outside
    document.addEventListener('click', (e) => {
        if (sidebar.classList.contains('is-open') && !sidebar.contains(e.target) && !mobileToggler.contains(e.target)) {
            sidebar.classList.remove('is-open');
        }
    });
});