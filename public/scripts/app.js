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

    const syncCheckFields = () => {
        document.querySelectorAll(".payment-method").forEach((select) => {
            const form = select.closest("form") || document;
            const isCheck = select.value === "CHQ";
            form.querySelectorAll(".check-field").forEach((field) => {
                field.classList.toggle("is-hidden", !isCheck);
            });
        });
    };

    document.querySelectorAll(".payment-method").forEach((select) => {
        select.addEventListener("change", syncCheckFields);
    });

    syncCheckFields();

    const syncProductPricing = () => {
        document.querySelectorAll(".product-pricing-form").forEach((form) => {
            const purchase = form.querySelector(".purchase-price");
            const sale = form.querySelector(".sale-price");
            const margin = form.querySelector(".margin-mode");
            const type = form.querySelector(".product-type");
            const stockFields = form.querySelectorAll(".stock-field");
            const recalc = () => {
                if (!purchase || !sale || !margin || margin.value === "manual") {
                    return;
                }
                const price = Number.parseFloat(purchase.value || "0");
                const rate = Number.parseFloat(margin.value || "0");
                sale.value = (price * (rate / 100)).toFixed(2);
            };
            const syncType = () => {
                const isService = type?.value === "service";
                stockFields.forEach((field) => field.classList.toggle("is-hidden", isService));
            };
            purchase?.addEventListener("input", recalc);
            margin?.addEventListener("change", recalc);
            type?.addEventListener("change", syncType);
            recalc();
            syncType();
        });
    };

    syncProductPricing();

    const syncOperationLines = (form) => {
        const vatInput = form.querySelector(".operation-vat-rate");
        const totalHtNode = form.querySelector("[data-total-ht]");
        const totalVatNode = form.querySelector("[data-total-vat]");
        const totalTtcNode = form.querySelector("[data-total-ttc]");
        let totalTtc = 0;

        form.querySelectorAll("[data-line]").forEach((line) => {
            const product = line.querySelector(".line-product");
            const label = line.querySelector(".line-label");
            const qty = line.querySelector(".line-qty");
            const price = line.querySelector(".line-price");
            const discount = line.querySelector(".line-discount");
            const total = line.querySelector(".line-total");
            const quantity = Number.parseFloat(qty?.value || "0");
            const unitPrice = Number.parseFloat(price?.value || "0");
            const discountRate = Number.parseFloat(discount?.value || "0");
            const lineTotalTtc = Math.max(0, quantity * unitPrice * (1 - discountRate / 100));
            const vatRate = Number.parseFloat(vatInput?.value || "20");
            const divisor = 1 + Math.max(0, vatRate) / 100;
            const lineTotalHt = divisor > 0 ? lineTotalTtc / divisor : lineTotalTtc;
            totalTtc += lineTotalTtc;
            if (total) {
                total.textContent = `${lineTotalHt.toFixed(2)} DH`;
            }

            product?.addEventListener("change", () => {
                const option = product.selectedOptions[0];
                if (price && option?.dataset.price) {
                    price.value = Number.parseFloat(option.dataset.price || "0").toFixed(2);
                }
                if (label && option?.dataset.name && !label.value) {
                    label.value = option.dataset.name;
                }
                syncOperationLines(form);
            }, { once: true });
        });

        const vatRate = Number.parseFloat(vatInput?.value || "20");
        const divisor = 1 + Math.max(0, vatRate) / 100;
        const totalHt = divisor > 0 ? totalTtc / divisor : totalTtc;
        const vat = totalTtc - totalHt;
        if (totalHtNode) totalHtNode.textContent = `${totalHt.toFixed(2)} DH`;
        if (totalVatNode) totalVatNode.textContent = `${vat.toFixed(2)} DH`;
        if (totalTtcNode) totalTtcNode.textContent = `${totalTtc.toFixed(2)} DH`;
    };

    document.querySelectorAll(".dynamic-operation-form").forEach((form) => {
        const container = form.querySelector("[data-operation-lines]");
        const addLine = form.querySelector("[data-add-line]");
        const attach = () => {
            form.querySelectorAll(".line-qty, .line-price, .line-discount, .operation-vat-rate").forEach((input) => {
                input.oninput = () => syncOperationLines(form);
            });
            form.querySelectorAll(".line-product").forEach((select) => {
                select.onchange = () => {
                    const line = select.closest("[data-line]");
                    const option = select.selectedOptions[0];
                    const price = line?.querySelector(".line-price");
                    const label = line?.querySelector(".line-label");
                    if (price && option?.dataset.price) {
                        price.value = Number.parseFloat(option.dataset.price || "0").toFixed(2);
                    }
                    if (label && option?.dataset.name && !label.value) {
                        label.value = option.dataset.name;
                    }
                    syncOperationLines(form);
                };
            });
        };
        addLine?.addEventListener("click", () => {
            const first = container?.querySelector("[data-line]");
            if (!first || !container) return;
            const clone = first.cloneNode(true);
            clone.querySelectorAll("input").forEach((input) => {
                input.value = input.classList.contains("line-qty") ? "1" : "0";
                if (input.classList.contains("line-label")) input.value = "";
            });
            clone.querySelectorAll("select").forEach((select) => select.selectedIndex = 0);
            container.appendChild(clone);
            attach();
            syncOperationLines(form);
        });
        attach();
        syncOperationLines(form);
    });
});
