

        </main>
    </div>
</div>

<script>
    // Elements
    const sidebar = document.getElementById('sidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
    const mobileToggle = document.getElementById('mobileToggle');
    const mainContent = document.getElementById('mainContent');
    
    // Desktop Sidebar Toggle
    if (sidebarToggleBtn) {
        sidebarToggleBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            
            // Save state to localStorage
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
        });
    }
    
    // Load saved sidebar state
    const savedState = localStorage.getItem('sidebarCollapsed');
    if (savedState === 'true' && window.innerWidth >= 992) {
        sidebar.classList.add('collapsed');
        mainContent.classList.add('sidebar-collapsed');
    }
    
    // Mobile Sidebar Toggle
    function toggleMobileSidebar() {
        sidebar.classList.toggle('show');
        sidebarBackdrop.classList.toggle('show');
    }
    
    if (mobileToggle) {
        mobileToggle.addEventListener('click', toggleMobileSidebar);
    }
    
    sidebarBackdrop.addEventListener('click', toggleMobileSidebar);
    
    // Submenu Toggle
    document.querySelectorAll('[data-submenu]').forEach(link => {
        link.addEventListener('click', function(e) {
            // Don't toggle submenu if sidebar is collapsed on desktop
            if (sidebar.classList.contains('collapsed') && window.innerWidth >= 992) {
                return;
            }
            
            e.preventDefault();
            const submenuId = this.getAttribute('data-submenu');
            const submenu = document.getElementById(submenuId);
            
            // Toggle current submenu
            this.classList.toggle('expanded');
            submenu.classList.toggle('show');
            
            // Close other submenus
            document.querySelectorAll('[data-submenu]').forEach(otherLink => {
                if (otherLink !== this) {
                    const otherSubmenuId = otherLink.getAttribute('data-submenu');
                    const otherSubmenu = document.getElementById(otherSubmenuId);
                    otherLink.classList.remove('expanded');
                    otherSubmenu.classList.remove('show');
                }
            });
        });
    });
    
    // Handle window resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            // Close mobile sidebar when resizing to desktop
            if (window.innerWidth >= 992) {
                sidebar.classList.remove('show');
                sidebarBackdrop.classList.remove('show');
                
                // Restore saved state
                const savedState = localStorage.getItem('sidebarCollapsed');
                if (savedState === 'true') {
                    sidebar.classList.add('collapsed');
                    mainContent.classList.add('sidebar-collapsed');
                }
            } else {
                // Remove collapsed state on mobile
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('sidebar-collapsed');
            }
        }, 250);
    });
    
    // Prevent body scroll when mobile sidebar is open
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.attributeName === 'class') {
                const hasBackdrop = sidebarBackdrop.classList.contains('show');
                document.body.style.overflow = hasBackdrop ? 'hidden' : '';
            }
        });
    });
    
    observer.observe(sidebarBackdrop, { attributes: true });
    
    // Scroll active menu into view
    const sidebarNav = sidebar.querySelector('.sidebar-nav');
    if (sidebarNav) {
        const activeLink = sidebarNav.querySelector('.submenu .nav-link.active')
                        || sidebarNav.querySelector('.nav-link.active');
        if (activeLink) {
            requestAnimationFrame(() => {
                const navRect = sidebarNav.getBoundingClientRect();
                const linkRect = activeLink.getBoundingClientRect();
                const targetScroll = sidebarNav.scrollTop + (linkRect.top - navRect.top) - (navRect.height / 2) + (linkRect.height / 2);
                sidebarNav.scrollTop = Math.max(0, targetScroll);
            });
        }
    }

    // Add tooltip functionality for collapsed sidebar (optional)
    if (window.innerWidth >= 992) {
        const navLinks = document.querySelectorAll('.sidebar-nav .nav-link');
        
        navLinks.forEach(link => {
            link.addEventListener('mouseenter', function() {
                if (sidebar.classList.contains('collapsed')) {
                    const text = this.querySelector('.sidebar-text');
                    if (text) {
                        // You can add Bootstrap tooltip here if needed
                        link.setAttribute('title', text.textContent);
                    }
                }
            });
        });
    }
</script>