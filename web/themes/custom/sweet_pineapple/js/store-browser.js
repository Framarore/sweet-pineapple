(function () {
  'use strict';

  const FILTERS = ['flavor', 'occasion', 'style'];
  const tokenMatch = (value, token) => token === 'all' || (` ${value || ''} `).includes(` ${token} `);

  // Inicializa el filtrado visual del catálogo sin depender de Views AJAX.
  const attachStoreBrowser = () => {
    document.querySelectorAll('[data-sp-store-browser]').forEach((browser) => {
      if (browser.dataset.spStoreBrowserReady === 'true') {
        return;
      }
      browser.dataset.spStoreBrowserReady = 'true';

      const tabs = Array.from(browser.querySelectorAll('[data-sp-family]'));
      const filters = Object.fromEntries(FILTERS.map((name) => [name, browser.querySelector(`[data-sp-filter="${name}"]`)]));
      const reset = browser.querySelector('[data-sp-reset]');
      const status = browser.querySelector('[data-sp-status]');
      const storeSelector = browser.querySelector('[data-sp-store]');
      const assistantLinks = Array.from(document.querySelectorAll('[data-sp-recommender-link]'));
      const cards = Array.from(document.querySelectorAll('[data-sp-product-card]'));
      let family = 'all';
      const urlParams = new URLSearchParams(window.location.search);

      const selectedFilters = () => ({
        family,
        flavor: filters.flavor ? filters.flavor.value : 'all',
        occasion: filters.occasion ? filters.occasion.value : 'all',
        style: filters.style ? filters.style.value : 'all',
        store: storeSelector ? storeSelector.value : 'all',
      });

      // Actualiza contadores de las pestanas por familia.
      const updateCounts = () => {
        const counts = cards.reduce((items, card) => {
          const key = card.dataset.spFamily || 'tartas';
          items.all += 1;
          items[key] = (items[key] || 0) + 1;
          return items;
        }, { all: 0 });

        Object.entries(counts).forEach(([key, count]) => {
          const badge = browser.querySelector(`[data-sp-count="${key}"]`);
          if (badge) {
            badge.textContent = count;
          }
        });
      };

      // Aplica filtros, estado visual y enlaces hacia el recomendador.
      const apply = () => {
        const selected = selectedFilters();
        let visible = 0;

        cards.forEach((card) => {
          const show = (family === 'all' || card.dataset.spFamily === family)
            && tokenMatch(card.dataset.spFlavors, selected.flavor)
            && tokenMatch(card.dataset.spOccasions, selected.occasion)
            && tokenMatch(card.dataset.spStyles, selected.style)
            && tokenMatch(card.dataset.spStores, selected.store);
          (card.closest('[class*="col-"]') || card).hidden = !show;
          visible += show ? 1 : 0;
        });

        tabs.forEach((tab) => {
          const active = tab.dataset.spFamily === family;
          tab.classList.toggle('is-active', active);
          tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        if (status) {
          status.textContent = visible === 1 ? '1 producto encontrado' : `${visible} productos encontrados`;
          status.classList.toggle('is-empty', visible === 0);
        }
        updateAssistantLinks(selected);
      };

      const updateAssistantLinks = (selected) => {
        assistantLinks.forEach((link) => {
          const url = new URL('/tienda/recomendador', window.location.origin);
          Object.entries(selected).forEach(([key, value]) => {
            if (value && value !== 'all') {
              url.searchParams.set(key, value);
            }
          });
          link.href = `${url.pathname}${url.search}`;
        });
      };

      const setInitialStateFromUrl = () => {
        const urlFamily = urlParams.get('family');
        if (urlFamily && tabs.some((tab) => tab.dataset.spFamily === urlFamily)) {
          family = urlFamily;
        }

        FILTERS.forEach((name) => {
          const filter = filters[name];
          const urlValue = urlParams.get(name);
          if (filter && urlValue && Array.from(filter.options).some((option) => option.value === urlValue)) {
            filter.value = urlValue;
          }
        });

        const urlStore = urlParams.get('store');
        if (storeSelector && urlStore && Array.from(storeSelector.options).some((option) => option.value === urlStore)) {
          storeSelector.value = urlStore;
        }
      };

      tabs.forEach((tab) => tab.addEventListener('click', () => {
        family = tab.dataset.spFamily || 'all';
        apply();
      }));
      Object.values(filters).forEach((filter) => filter && filter.addEventListener('change', apply));
      if (storeSelector) {
        storeSelector.addEventListener('change', apply);
      }
      if (reset) {
        reset.addEventListener('click', () => {
          family = 'all';
          Object.values(filters).forEach((filter) => {
            if (filter) {
              filter.value = 'all';
            }
          });
          if (storeSelector) {
            storeSelector.value = 'all';
          }
          apply();
        });
      }

      updateCounts();
      setInitialStateFromUrl();
      apply();
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', attachStoreBrowser);
  }
  else {
    attachStoreBrowser();
  }
})();
