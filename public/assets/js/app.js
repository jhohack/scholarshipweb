/**
 * Enhanced Navigation & Global Animations
 * Smooth scroll, dynamic theme switching, and interactive elements
 */

(function() {
    'use strict';

    // ===== Navigation Management =====
    const header = document.getElementById('main-header');
    if (header) {
        const navItems = header.querySelectorAll('.main-nav-link');
        const navbarCollapse = document.getElementById('navbarNav');
        const navbarToggler = document.querySelector('.navbar-toggler');
        const progressBar = header.querySelector('.nav-progress');
        const homeLink = header.querySelector('.main-nav-link[href="index.php"]');
        const howItWorksLink = header.querySelector('.main-nav-link[href="index.php#how-it-works"]');
        const announcementsLink = header.querySelector('.main-nav-link[href="announcements.php"]');
        const scholarshipsLink = header.querySelector('.main-nav-link[href="scholarships.php"]');
        const contactLink = header.querySelector('.main-nav-link[data-nav="contact"]');
        const howItWorksSection = document.getElementById('how-it-works');
        const announcementsSection = document.getElementById('index-announcements');
        const featuredScholarshipsSection = document.getElementById('index-featured-scholarships');
        const faqSection = document.getElementById('index-faq');
        const contactSection = faqSection || document.getElementById('site-footer') || document.getElementById('footer-contact');
        const mobileMq = window.matchMedia('(max-width: 991.98px)');
        const offcanvasInstance = (window.bootstrap?.Offcanvas && navbarCollapse)
            ? window.bootstrap.Offcanvas.getOrCreateInstance(navbarCollapse)
            : null;
        const currentPage = window.location.pathname.split('/').pop() || 'index.php';
        let lockedScrollY = 0;

        function closeMobileMenu() {
            if (!navbarCollapse || !mobileMq.matches || !navbarCollapse.classList.contains('show')) {
                return;
            }
            if (offcanvasInstance) {
                offcanvasInstance.hide();
            }
        }

        function setAriaCurrent(activeLink) {
            navItems.forEach(item => item.removeAttribute('aria-current'));
            if (!activeLink) {
                return;
            }
            const href = activeLink.getAttribute('href') || '';
            const isSectionLink = href.includes('#') && currentPage === 'index.php';
            activeLink.setAttribute('aria-current', isSectionLink ? 'location' : 'page');
        }

        function setActiveLink(activeLink) {
            navItems.forEach(item => item.classList.remove('active'));
            if (activeLink) {
                activeLink.classList.add('active');
            }
            setAriaCurrent(activeLink);
        }

        function syncActiveLink() {
            let activeLink = null;
            navItems.forEach(item => {
                const href = item.getAttribute('href') || '';
                const isHashMatch = href.includes('#') && currentPage === 'index.php' && !!window.location.hash && href.endsWith(window.location.hash);
                const isCurrentPage = !href.includes('#') && href.startsWith(currentPage);
                if (!activeLink && (isHashMatch || isCurrentPage)) {
                    activeLink = item;
                }
            });
            setActiveLink(activeLink || homeLink || null);
        }

        function syncIndexNavByViewport() {
            if (
                currentPage !== 'index.php' ||
                !homeLink ||
                !howItWorksLink ||
                !announcementsLink ||
                !scholarshipsLink ||
                !contactLink ||
                !howItWorksSection
            ) {
                return;
            }

            const headerHeight = header.offsetHeight || 0;
            const marker = headerHeight + 120;
            const faqReached = !!faqSection && faqSection.getBoundingClientRect().top <= marker;

            if (faqReached) {
                setActiveLink(contactLink);
                return;
            }

            const sections = [
                { section: howItWorksSection, link: howItWorksLink },
                { section: announcementsSection, link: announcementsLink },
                { section: featuredScholarshipsSection, link: scholarshipsLink },
                { section: contactSection, link: contactLink }
            ].filter(item => !!item.section && !!item.link);

            let activeLink = null;
            sections.forEach(item => {
                const rect = item.section.getBoundingClientRect();
                const inView = rect.top <= marker && rect.bottom > marker;
                if (inView && !activeLink) {
                    activeLink = item.link;
                }
            });

            if (activeLink) {
                setActiveLink(activeLink);
            } else {
                setActiveLink(homeLink);
            }
        }

        navItems.forEach(link => {
            link.addEventListener('click', function() {
                setActiveLink(this);
                if (mobileMq.matches && navbarCollapse?.classList.contains('show')) {
                    closeMobileMenu();
                }
            });
        });

        if (contactLink) {
            contactLink.addEventListener('click', function(e) {
                if (currentPage !== 'index.php') {
                    // Let normal navigation go to index.php#index-faq from other pages.
                    return;
                }

                if (!contactSection) return;

                e.preventDefault();

                // Close mobile menu first so header height is accurate.
                if (mobileMq.matches && navbarCollapse?.classList.contains('show')) {
                    closeMobileMenu();
                }

                const scrollToContact = () => {
                    const headerHeight = header.offsetHeight || 0;
                    const targetTop = contactSection.getBoundingClientRect().top + window.pageYOffset - headerHeight - 8;
                    window.scrollTo({ top: Math.max(0, targetTop), behavior: 'smooth' });
                };

                // Run after layout settles (important when collapse animates).
                requestAnimationFrame(() => {
                    requestAnimationFrame(scrollToContact);
                });

                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', '#index-faq');
                }

                setActiveLink(contactLink);
            });
        }

        let ticking = false;
        window.addEventListener('scroll', () => {
            if (ticking) return;
            window.requestAnimationFrame(() => {
                const isScrolled = window.scrollY > 50;
                header.classList.toggle('scrolled', isScrolled);
                syncIndexNavByViewport();
                if (progressBar) {
                    const max = document.documentElement.scrollHeight - window.innerHeight;
                    const ratio = max > 0 ? Math.min(window.scrollY / max, 1) : 0;
                    progressBar.style.transform = `scaleX(${ratio})`;
                }
                ticking = false;
            });
            ticking = true;
        }, { passive: true });

        window.addEventListener('hashchange', syncActiveLink);
        navbarCollapse?.addEventListener('shown.bs.offcanvas', () => {
            navbarToggler?.classList.add('is-open');
            navbarToggler?.setAttribute('aria-expanded', 'true');
            navbarToggler?.setAttribute('aria-label', 'Close navigation menu');
            lockedScrollY = window.scrollY || window.pageYOffset || 0;
            document.body.classList.add('mobile-menu-open');
            document.documentElement.classList.add('mobile-menu-open');
            document.body.style.top = `-${lockedScrollY}px`;
        });
        navbarCollapse?.addEventListener('hidden.bs.offcanvas', () => {
            navbarToggler?.classList.remove('is-open');
            navbarToggler?.setAttribute('aria-expanded', 'false');
            navbarToggler?.setAttribute('aria-label', 'Open navigation menu');
            document.body.classList.remove('mobile-menu-open');
            document.documentElement.classList.remove('mobile-menu-open');
            document.body.style.top = '';
            window.scrollTo(0, lockedScrollY);
            navbarToggler?.focus();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeMobileMenu();
            }
        });

        syncActiveLink();
        syncIndexNavByViewport();
    }

    // ===== Smooth Page Transitions for Anchor Links =====
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            if (this.matches('.main-nav-link[data-nav="contact"]')) {
                return;
            }
            const href = this.getAttribute('href');
            if (href !== '#' && document.querySelector(href)) {
                e.preventDefault();
                document.querySelector(href).scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // ===== Ripple Effect on Buttons =====
    document.querySelectorAll('.btn').forEach(button => {
        button.addEventListener('click', function(e) {
            const ripple = document.createElement('span');
            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;

            ripple.style.width = ripple.style.height = `${size}px`;
            ripple.style.left = `${x}px`;
            ripple.style.top = `${y}px`;
            ripple.classList.add('ripple');

            // Ensure ripple is removed after animation
            const existingRipple = this.querySelector('.ripple');
            if (existingRipple) {
                existingRipple.remove();
            }
            this.appendChild(ripple);
        });
    });

    // ===== Lazy Load Images =====
    if ('IntersectionObserver' in window) {
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                    }
                    observer.unobserve(img);
                }
            });
        });

        document.querySelectorAll('img[data-src]').forEach(img => {
            imageObserver.observe(img);
        });
    }

    // ===== Initialize on DOMContentLoaded =====
    function init() {
        // Any other initializations can go here
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // ===== 3D Tilt Effect for Cards =====
    const tiltCards = document.querySelectorAll('.how-it-works-card');
    tiltCards.forEach(card => {
        card.addEventListener('mousemove', (e) => {
            const rect = card.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            const centerX = rect.width / 2;
            const centerY = rect.height / 2;
            const rotateX = (y - centerY) / 10; // Adjust divisor for sensitivity
            const rotateY = (centerX - x) / 10;

            card.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) scale3d(1.05, 1.05, 1.05)`;
            card.style.setProperty('--x', `${x}px`);
            card.style.setProperty('--y', `${y}px`);
        });

        card.addEventListener('mouseleave', () => {
            card.style.transform = 'perspective(1000px) rotateX(0) rotateY(0) scale3d(1, 1, 1)';
        });
    });

})();

// ===== PWA Service Worker Registration =====
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        const path = window.location.pathname.toLowerCase();
        const isAdminOrStudentArea = path.includes('/admin/') || path.includes('/student/');

        if (isAdminOrStudentArea) {
            return;
        }

        navigator.serviceWorker.register('sw.js', { scope: './' }).catch(() => {
            // Ignore registration errors to avoid interrupting page scripts.
        });
    });
}
