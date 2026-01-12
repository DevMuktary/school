        </main>
    </div>
</div>
<script>
    // --- CORRECTED Sidebar Toggle Script ---
    const menuToggle = document.getElementById('menu-toggle');
    const sidebarOverlay = document.getElementById('sidebar-overlay');
    const body = document.body;

    function toggleSidebar() {
        body.classList.toggle('sidebar-open');
    }

    if (menuToggle) {
        menuToggle.addEventListener('click', toggleSidebar);
    }
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', toggleSidebar);
    }

    // --- Theme Toggle Script ---
    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
        const setTheme = (theme) => { 
            if (theme === 'dark') { 
                body.classList.add('dark-mode'); 
                themeToggle.textContent = '☀️'; 
            } else { 
                body.classList.remove('dark-mode'); 
                themeToggle.textContent = '🌙'; 
            } 
        };
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme) { 
            setTheme(savedTheme); 
        } else {
            // Optional: set default theme based on system preference
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                setTheme('dark');
            }
        }
        themeToggle.addEventListener('click', () => { 
            const newTheme = body.classList.contains('dark-mode') ? 'light' : 'dark'; 
            setTheme(newTheme); 
            localStorage.setItem('theme', newTheme); 
        });
    }
</script>
</body>
</html>
