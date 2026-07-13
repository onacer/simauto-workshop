document.addEventListener("DOMContentLoaded", () => {
    const topbar = document.querySelector("[data-topbar]");
    const mobileToggle = document.querySelector(".mobile-menu-toggle");
    const topbarNav = document.querySelector(".topbar-nav");

    const closeDropdowns = () => {
        document.querySelectorAll(".nav-dropdown.is-open").forEach((dropdown) => {
            dropdown.classList.remove("is-open");
            dropdown.querySelector(".dropdown-toggle")?.setAttribute("aria-expanded", "false");
        });
    };

    const syncMobileNavHeight = () => {
        if (topbar && topbarNav) {
            topbar.style.setProperty("--mobile-nav-height", `${topbarNav.offsetHeight}px`);
        }
    };

    mobileToggle?.addEventListener("click", (event) => {
        event.stopPropagation();
        const isOpen = topbar?.classList.toggle("is-mobile-open") || false;
        mobileToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
        closeDropdowns();
        requestAnimationFrame(syncMobileNavHeight);
    });

    document.querySelectorAll(".dropdown-toggle").forEach((button) => {
        button.addEventListener("click", (event) => {
            event.preventDefault();
            event.stopPropagation();
            const dropdown = button.closest(".nav-dropdown");
            const wasOpen = dropdown?.classList.contains("is-open");
            closeDropdowns();
            if (dropdown && !wasOpen) {
                dropdown.classList.add("is-open");
                button.setAttribute("aria-expanded", "true");
            }
        });

        button.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
                closeDropdowns();
                button.focus();
            }
        });
    });

    topbar?.addEventListener("click", (event) => event.stopPropagation());

    document.addEventListener("click", () => {
        closeDropdowns();
        topbar?.classList.remove("is-mobile-open");
        mobileToggle?.setAttribute("aria-expanded", "false");
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            closeDropdowns();
            topbar?.classList.remove("is-mobile-open");
            mobileToggle?.setAttribute("aria-expanded", "false");
        }
    });

    window.addEventListener("resize", syncMobileNavHeight);
    syncMobileNavHeight();

    const syncCompanyFields = () => {
        document.querySelectorAll(".client-type").forEach((select) => {
            const form = select.closest("form") || document;
            const isCompany = select.value === "company";
            form.querySelectorAll(".company-field").forEach((field) => {
                field.classList.toggle("is-hidden", !isCompany);
            });
        });
    };

    document.querySelectorAll(".client-type").forEach((select) => {
        select.addEventListener("change", syncCompanyFields);
    });

    syncCompanyFields();
});
