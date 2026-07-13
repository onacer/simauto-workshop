document.addEventListener("DOMContentLoaded", () => {
    const closeDropdowns = () => {
        document.querySelectorAll(".nav-dropdown.is-open").forEach((dropdown) => {
            dropdown.classList.remove("is-open");
            dropdown.querySelector(".dropdown-toggle")?.setAttribute("aria-expanded", "false");
        });
    };

    document.querySelectorAll(".dropdown-toggle").forEach((button) => {
        button.addEventListener("click", (event) => {
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

    document.addEventListener("click", closeDropdowns);
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            closeDropdowns();
        }
    });

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
