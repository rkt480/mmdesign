(() => {
  if (window.__crmNavigationReady) {
    return;
  }

  window.__crmNavigationReady = true;

  const overlay = document.createElement("div");
  overlay.className = "crm-navigation-overlay";
  overlay.setAttribute("aria-hidden", "true");
  overlay.innerHTML = '<span class="crm-navigation-spinner" role="status" aria-label="Carregando"></span>';
  document.body.appendChild(overlay);

  const hideNavigationState = () => {
    document.body.classList.remove("is-navigating");
    document.body.removeAttribute("aria-busy");
    overlay.setAttribute("aria-hidden", "true");
  };

  const showNavigationState = () => {
    document.body.classList.add("is-navigating");
    document.body.setAttribute("aria-busy", "true");
    overlay.setAttribute("aria-hidden", "false");
  };

  const isNavigableInternalLink = (link, event) => {
    if (!link || event.defaultPrevented || event.button !== 0) {
      return false;
    }

    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return false;
    }

    if (link.target && link.target !== "_self") {
      return false;
    }

    if (link.hasAttribute("download") || link.dataset.noNavigationTransition !== undefined) {
      return false;
    }

    const href = link.getAttribute("href") || "";

    if (href === "" || href.startsWith("#") || href.startsWith("mailto:") || href.startsWith("tel:")) {
      return false;
    }

    const destination = new URL(link.href, window.location.href);

    if (destination.origin !== window.location.origin) {
      return false;
    }

    return destination.href !== window.location.href;
  };

  document.addEventListener("click", (event) => {
    const link = event.target.closest?.("a[href]");

    if (isNavigableInternalLink(link, event)) {
      showNavigationState();
    }
  }, true);

  window.addEventListener("pageshow", hideNavigationState);
  window.addEventListener("pagehide", () => {
    window.setTimeout(hideNavigationState, 0);
  });
})();
