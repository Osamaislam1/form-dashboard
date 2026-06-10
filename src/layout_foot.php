    </main>
</div>
<script>
(function(){
    var KEY     = 'fdash_theme';
    var html    = document.documentElement;
    var sidebar  = document.getElementById('sidebar');
    var overlay  = document.getElementById('sidebar-overlay');
    var closeBtn = document.getElementById('sidebar-close');

    /* ── Theme ──────────────────────────────────────── */
    function applyTheme(t){
        t === 'light'
            ? html.setAttribute('data-theme','light')
            : html.removeAttribute('data-theme');
    }

    /* Sync icon to the theme already applied by the head script */
    var icon = document.querySelector('.theme-icon');
    if(icon) icon.textContent = html.getAttribute('data-theme') === 'light' ? '☽' : '☀';

    /* ── Sidebar helpers ─────────────────────────────── */
    function openSidebar(){
        if(!sidebar) return;
        sidebar.classList.add('open');
        if(overlay){ overlay.classList.add('active'); }
        document.body.style.overflow = 'hidden'; // prevent body scroll while sidebar open
    }
    function closeSidebar(){
        if(!sidebar) return;
        sidebar.classList.remove('open');
        if(overlay){ overlay.classList.remove('active'); }
        document.body.style.overflow = '';
    }
    function isMobile(){ return window.innerWidth <= 768; }

    /* ── Global click handler ────────────────────────── */
    document.addEventListener('click', function(e){

        /* 1. Theme toggle */
        if(e.target.closest('[data-theme-toggle]')){
            var cur  = html.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
            var next = cur === 'light' ? 'dark' : 'light';
            applyTheme(next);
            localStorage.setItem(KEY, next);
            var ic = document.querySelector('.theme-icon');
            if(ic) ic.textContent = next === 'light' ? '☽' : '☀';
            return;
        }

        /* 2. Hamburger — open sidebar */
        if(e.target.closest('[data-sidebar-toggle]')){
            sidebar && sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
            return;
        }

        /* 3. Close button inside sidebar */
        if(e.target.closest('#sidebar-close')){
            closeSidebar();
            return;
        }

        /* 4. Backdrop overlay tap */
        if(e.target === overlay){
            closeSidebar();
            return;
        }

        /* 5. Nav link tap — auto-close sidebar on mobile */
        if(isMobile() && e.target.closest('.nav a')){
            closeSidebar();
            /* allow the link navigation to proceed naturally */
        }
    });

    /* Close sidebar when window resizes back to desktop */
    window.addEventListener('resize', function(){
        if(!isMobile()) closeSidebar();
    });
})();
</script>
</body>
</html>
