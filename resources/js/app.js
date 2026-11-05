import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.data('workspaceNavigation', () => ({
    sidebarOpen: false,
    desktop: window.matchMedia('(min-width: 1024px)').matches,
    openNavigation() {
        this.sidebarOpen = true;
        this.$nextTick(() => this.$refs.sidebarClose.focus());
    },
    closeNavigation() {
        this.sidebarOpen = false;
        this.$nextTick(() => this.$refs.sidebarToggle.focus());
    },
    resizeNavigation() {
        this.desktop = window.matchMedia('(min-width: 1024px)').matches;
        if (this.desktop) {
            this.sidebarOpen = false;
        }
    },
    trapNavigationFocus(event) {
        if (this.desktop || !this.sidebarOpen) {
            return;
        }
        const items = [...this.$refs.sidebarPanel.querySelectorAll('a[href], button:not([disabled])')]
            .filter((item) => item.getClientRects().length > 0);
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    },
}));

Alpine.start();
