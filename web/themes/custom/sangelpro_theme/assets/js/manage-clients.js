"use strict";

(function (Drupal, once) {
  const HIDDEN_CLASS = "hidden";
  const DISABLED_CLASSES = ["pointer-events-none", "opacity-60"];

  const getActiveClasses = (element) => {
    const list = element?.dataset?.activeClasses;
    return list ? list.split(/\s+/).filter(Boolean) : [];
  };

  const applyActiveState = (element, enabled) => {
    if (!element) {
      return;
    }
    getActiveClasses(element).forEach((cls) => {
      element.classList.toggle(cls, enabled);
    });
  };

  const actionMap = {
    view: "view_url",
    cart: "cart_url",
    orders: "orders_url",
    catalog: "catalog_url",
    edit: "edit_url",
    delete: "delete_url",
  };

  function toNumberArray(value) {
    if (Array.isArray(value)) {
      return value.map((item) => parseInt(item, 10)).filter((num) => !Number.isNaN(num));
    }
    if (typeof value === "string" && value.length) {
      return value
        .split(",")
        .map((item) => parseInt(item, 10))
        .filter((num) => !Number.isNaN(num));
    }
    return [];
  }

  function safeParse(json) {
    if (!json) {
      return {};
    }
    try {
      return JSON.parse(json);
    } catch (error) {
      console.warn("Unable to parse client payload:", error);
      return {};
    }
  }

  function toggle(element, show) {
    if (!element) {
      return;
    }
    element.classList.toggle(HIDDEN_CLASS, !show);
  }

  function setText(element, value) {
    if (!element) {
      return;
    }
    element.textContent = value || "";
  }

  function disableLink(link, disabled) {
    if (!link) {
      return;
    }
    DISABLED_CLASSES.forEach((cls) => link.classList.toggle(cls, disabled));
    if (disabled) {
      link.removeAttribute("href");
    }
  }

  function init(container) {
    const rows = Array.from(container.querySelectorAll("[data-client-row]"));
    const panel = container.querySelector("[data-client-panel]");
    const panelEmpty = container.querySelector("[data-client-panel-empty]");
    const noResults = container.querySelector("[data-role=\"no-results\"]");
    const searchInput = container.querySelector("[data-role=\"search\"]");
    const statusButtons = Array.from(container.querySelectorAll("[data-role=\"status-filter\"]"));
    const typeFilter = container.querySelector("[data-role=\"type-filter\"]");
    const rowsContainer = container.querySelector("[data-role=\"rows\"]");

    const parsedRows = rows.map((row) => {
      const payload = safeParse(row.getAttribute("data-client"));
      payload.client_type_ids = toNumberArray(payload.client_type_ids || row.getAttribute("data-client-types"));
      payload.search_index = (payload.search_index || "").toString();
      row.__clientData = payload;
      return row;
    });

    let activeRow = parsedRows.find((row) => row.getAttribute("data-selected") === "true") || parsedRows[0] || null;
    let statusFilter = "all";
    let typeFilterValue = "";
    let searchValue = "";

    if (statusButtons.length) {
      let hasActive = false;
      statusButtons.forEach((button) => {
        const isActive = button.getAttribute("aria-pressed") === "true";
        if (isActive) {
          statusFilter = button.getAttribute("data-status") || "all";
          hasActive = true;
        }
        applyActiveState(button, isActive);
      });
      if (!hasActive && statusButtons[0]) {
        statusButtons[0].setAttribute("aria-pressed", "true");
        applyActiveState(statusButtons[0], true);
      }
    }

    function showEmptyState() {
      if (panel) {
        panel.classList.add(HIDDEN_CLASS);
      }
      toggle(panelEmpty, true);
    }

    function showPanel() {
      if (panel) {
        panel.classList.remove(HIDDEN_CLASS);
      }
      toggle(panelEmpty, false);
    }

    function updatePanel(client) {
      if (!panel || !client) {
        showEmptyState();
        return;
      }

      showPanel();

      setText(panel.querySelector('[data-client-field="title"]'), client.title || "");
      if (client.title) {
        panel.classList.remove("hidden");
      }
      const companyWrapper = panel.querySelector('[data-client-field="company-wrapper"]');
      setText(panel.querySelector('[data-client-field="company"]'), client.company || "");
      toggle(companyWrapper, !!client.company);

      const contactWrapper = panel.querySelector('[data-client-field="contact-wrapper"]');
      const hasContact = !!(client.owner_name || client.email || client.phone);
      toggle(contactWrapper, hasContact);
      setText(panel.querySelector('[data-client-field="contact-name"]'), client.owner_name || client.email || "");

      const emailWrapper = panel.querySelector('[data-client-field="email-wrapper"]');
      setText(panel.querySelector('[data-client-field="email"]'), client.email || "");
      toggle(emailWrapper, !!client.email);

      const phoneWrapper = panel.querySelector('[data-client-field="phone-wrapper"]');
      setText(panel.querySelector('[data-client-field="phone"]'), client.phone || "");
      toggle(phoneWrapper, !!client.phone);

      const typeField = panel.querySelector('[data-client-field="type"]');
      const typeEmpty = panel.querySelector('[data-client-field="type-empty"]');
      const hasType = !!client.client_type;
      toggle(typeField, hasType);
      toggle(typeEmpty, !hasType);
      setText(typeField, client.client_type || "");

      setText(panel.querySelector('[data-client-field="created"]'), client.created || "");
      setText(panel.querySelector('[data-client-field="updated"]'), client.updated || "");

      const lastAccessWrapper = panel.querySelector('[data-client-field="last-access-wrapper"]');
      setText(panel.querySelector('[data-client-field="last-access"]'), client.owner_last_access || "");
      toggle(lastAccessWrapper, !!client.owner_last_access);

      setText(panel.querySelector('[data-client-field="status-label"]'), client.status_label || "");

      Object.entries(actionMap).forEach(([action, key]) => {
        const link = panel.querySelector(`[data-client-link="${action}"]`);
        const url = client[key] || "";
        if (!link) {
          return;
        }
        if (action === "view" || action === "edit" || action === "delete") {
          if (url) {
            link.setAttribute("href", url);
            disableLink(link, false);
          }
          else {
            disableLink(link, true);
          }
        }
        else {
          if (url) {
            link.setAttribute("href", url);
            toggle(link, true);
          }
          else {
            link.removeAttribute("href");
            toggle(link, false);
          }
        }
      });
    }

    function highlightRow(row, isActive) {
      applyActiveState(row, isActive);
      row.setAttribute("aria-selected", isActive ? "true" : "false");
      if (isActive) {
        row.setAttribute("data-selected", "true");
      }
      else {
        row.removeAttribute("data-selected");
      }
    }

    function selectRow(row, focus, force = false) {
      if (!row || (!force && row === activeRow)) {
        return;
      }

      parsedRows.forEach((item) => highlightRow(item, false));
      highlightRow(row, true);

      activeRow = row;
      updatePanel(row.__clientData);

      if (focus) {
        row.scrollIntoView({ block: "nearest", behavior: "smooth" });
      }
    }

    function ensureActiveRowVisible() {
      const visibleRows = parsedRows.filter((row) => !row.classList.contains(HIDDEN_CLASS));
      if (!visibleRows.length) {
        activeRow = null;
        showEmptyState();
        if (noResults) {
          noResults.classList.remove(HIDDEN_CLASS);
        }
        return;
      }

      if (noResults) {
        noResults.classList.add(HIDDEN_CLASS);
      }

      if (!activeRow || activeRow.classList.contains(HIDDEN_CLASS)) {
        selectRow(visibleRows[0], false, true);
      }
      else if (activeRow) {
        selectRow(activeRow, false, true);
      }
    }

    function applyFilters() {
      const loweredSearch = searchValue.toLowerCase();
      const typeFilterId = typeFilterValue ? parseInt(typeFilterValue, 10) : null;
      parsedRows.forEach((row) => {
        const client = row.__clientData || {};
        const matchesSearch = !loweredSearch || (client.search_index || "").includes(loweredSearch);
        const matchesStatus = statusFilter === "all" || client.status === statusFilter;
        const matchesType = !typeFilterId || (client.client_type_ids || []).includes(typeFilterId);
        const isVisible = matchesSearch && matchesStatus && matchesType;
        row.classList.toggle(HIDDEN_CLASS, !isVisible);
        row.style.display = isVisible ? "" : "none";
      });

      ensureActiveRowVisible();
    }

    if (rowsContainer) {
      rowsContainer.addEventListener("click", (event) => {
        const targetRow = event.target.closest("[data-client-row]");
        if (targetRow) {
          selectRow(targetRow, false);
        }
      });
    }

    parsedRows.forEach((row) => {
      row.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") {
          event.preventDefault();
          selectRow(row, false);
        }
      });
      row.setAttribute("tabindex", "0");
    });

    if (searchInput) {
      searchInput.addEventListener("input", (event) => {
        searchValue = event.target.value.trim();
        applyFilters();
      });
    }

    statusButtons.forEach((button) => {
      button.addEventListener("click", () => {
        statusFilter = button.getAttribute("data-status") || "all";
        statusButtons.forEach((other) => {
          const isActive = other === button;
          other.setAttribute("aria-pressed", isActive ? "true" : "false");
          applyActiveState(other, isActive);
        });
        applyFilters();
      });
    });

    if (typeFilter) {
      typeFilter.addEventListener("change", (event) => {
        typeFilterValue = event.target.value;
        applyFilters();
      });
    }

    if (!parsedRows.length) {
      showEmptyState();
    }
    else {
      selectRow(activeRow, false, true);
      applyFilters();
    }
  }

  Drupal.behaviors.manageClientsDashboard = {
    attach(context) {
      once("manage-clients-dashboard", "[data-manage-clients]", context).forEach(init);
    },
  };
})(Drupal, once);
