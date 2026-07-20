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

    const syncReportFilters = () => {
        document.querySelectorAll("[data-custom-report-toggle]").forEach((button) => {
            const form = document.querySelector("[data-custom-report-form]");
            button.addEventListener("click", () => {
                form?.classList.toggle("is-hidden", false);
            });
        });
    };

    syncReportFilters();

    const normalize = (value) => (value || "").toString().toLowerCase().trim().replace(/\s+/g, " ");

    const enhanceCombobox = (root = document) => {
        root.querySelectorAll("select[data-combobox]").forEach((select) => {
            if (select.classList.contains("is-combobox-native")) return;
            const minimum = Number.parseInt(select.dataset.comboboxMin || "0", 10);
            if (minimum > 0 && select.options.length <= minimum) return;

            select.classList.add("is-combobox-native");
            select.tabIndex = -1;

            const combo = document.createElement("div");
            combo.className = "combobox";
            combo.dir = document.documentElement.dir || "rtl";

            const input = document.createElement("input");
            input.type = "text";
            input.className = "combobox-input";
            input.autocomplete = "off";
            input.setAttribute("role", "combobox");
            input.setAttribute("aria-expanded", "false");
            input.placeholder = select.options[0]?.textContent?.trim() || "";

            const list = document.createElement("div");
            list.className = "combobox-list";
            list.setAttribute("role", "listbox");

            combo.append(input, list);
            select.insertAdjacentElement("afterend", combo);

            let activeIndex = -1;

            const visibleOptions = () => Array.from(select.options).filter((option) => !option.hidden && !option.disabled);
            const selectedText = () => select.selectedOptions[0]?.textContent?.trim() || "";
            const syncInput = () => {
                input.value = selectedText();
            };
            const close = () => {
                combo.classList.remove("is-open");
                input.setAttribute("aria-expanded", "false");
                activeIndex = -1;
            };
            const open = () => {
                combo.classList.add("is-open");
                input.setAttribute("aria-expanded", "true");
                render(input.value);
            };
            const choose = (option) => {
                select.value = option.value;
                select.dispatchEvent(new Event("change", { bubbles: true }));
                syncInput();
                close();
            };
            const render = (query = "") => {
                const term = normalize(query);
                const matches = visibleOptions().filter((option) => {
                    const haystack = normalize(`${option.dataset.search || ""} ${option.textContent || ""}`);
                    return option.value === "" || haystack.includes(term);
                }).slice(0, 80);

                list.innerHTML = "";
                matches.forEach((option, index) => {
                    const item = document.createElement("button");
                    item.type = "button";
                    item.className = "combobox-option";
                    item.setAttribute("role", "option");
                    item.textContent = option.textContent.trim();
                    item.dataset.value = option.value;
                    if (option.value === select.value) item.classList.add("is-selected");
                    if (index === activeIndex) item.classList.add("is-active");
                    item.addEventListener("mousedown", (event) => {
                        event.preventDefault();
                        choose(option);
                    });
                    list.appendChild(item);
                });
            };

            input.addEventListener("focus", open);
            input.addEventListener("input", () => {
                activeIndex = -1;
                open();
            });
            input.addEventListener("keydown", (event) => {
                const items = Array.from(list.querySelectorAll(".combobox-option"));
                if (event.key === "Escape") {
                    event.preventDefault();
                    syncInput();
                    close();
                    return;
                }
                if (event.key === "ArrowDown" || event.key === "ArrowUp") {
                    event.preventDefault();
                    if (!combo.classList.contains("is-open")) open();
                    const direction = event.key === "ArrowDown" ? 1 : -1;
                    activeIndex = items.length ? (activeIndex + direction + items.length) % items.length : -1;
                    render(input.value);
                    return;
                }
                if (event.key === "Enter" && combo.classList.contains("is-open")) {
                    event.preventDefault();
                    const option = visibleOptions().find((candidate) => candidate.value === items[activeIndex]?.dataset.value);
                    if (option) choose(option);
                }
            });

            select.addEventListener("change", syncInput);
            select.addEventListener("combobox:refresh", () => {
                syncInput();
                render(input.value);
            });
            document.addEventListener("click", (event) => {
                if (!combo.contains(event.target)) close();
            });
            syncInput();
        });
    };

    const syncDependentSelects = () => {
        document.querySelectorAll("[data-vehicle-select]").forEach((vehicleSelect) => {
            const form = vehicleSelect.closest("form") || document;
            const clientSelect = form.querySelector("[name='client_id']");
            const sync = () => {
                const clientId = clientSelect?.value || "";
                Array.from(vehicleSelect.options).forEach((option) => {
                    const matches = !option.dataset.clientId || !clientId || option.dataset.clientId === clientId;
                    option.hidden = !matches;
                    option.disabled = !matches;
                });
                if (vehicleSelect.selectedOptions[0]?.hidden) vehicleSelect.value = "";
                vehicleSelect.dispatchEvent(new Event("combobox:refresh"));
            };
            clientSelect?.addEventListener("change", sync);
            sync();
        });

        document.querySelectorAll("[data-model-select]").forEach((modelSelect) => {
            const form = modelSelect.closest("form") || document;
            const brandSelect = form.querySelector("[data-brand-select]");
            const sync = () => {
                const brandId = brandSelect?.value || "";
                Array.from(modelSelect.options).forEach((option) => {
                    const matches = !option.dataset.brandId || !brandId || option.dataset.brandId === brandId;
                    option.hidden = !matches;
                    option.disabled = !matches;
                });
                if (modelSelect.selectedOptions[0]?.hidden) modelSelect.value = "";
                modelSelect.dispatchEvent(new Event("combobox:refresh"));
            };
            brandSelect?.addEventListener("change", sync);
            sync();
        });
    };

    const syncLiveSearch = () => {
        document.querySelectorAll("[data-live-search]").forEach((input) => {
            const targetSelector = input.dataset.liveTarget;
            const target = targetSelector ? document.querySelector(targetSelector) : input.closest(".panel")?.querySelector(".data-table");
            if (!target) return;

            input.addEventListener("input", () => {
                const term = normalize(input.value);
                target.querySelectorAll("[data-live-row]").forEach((row) => {
                    row.classList.toggle("is-live-hidden", term !== "" && !normalize(row.dataset.search || row.textContent).includes(term));
                });
            });
        });
    };

    enhanceCombobox();
    syncDependentSelects();
    syncLiveSearch();

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
            clone.querySelectorAll(".combobox").forEach((combo) => combo.remove());
            clone.querySelectorAll("select").forEach((select) => {
                select.classList.remove("is-combobox-native");
                select.removeAttribute("tabindex");
                select.selectedIndex = 0;
            });
            clone.querySelectorAll("input").forEach((input) => {
                input.value = input.classList.contains("line-qty") ? "1" : "0";
                if (input.classList.contains("line-label")) input.value = "";
            });
            container.appendChild(clone);
            enhanceCombobox(clone);
            attach();
            syncOperationLines(form);
        });
        attach();
        enhanceCombobox(form);
        syncOperationLines(form);
    });
});
