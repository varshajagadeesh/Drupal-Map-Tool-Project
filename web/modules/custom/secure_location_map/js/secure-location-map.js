(function (Drupal, once) {
  'use strict';

  const RESULTS_PER_PAGE = 50;
  const FULL_MAP_REQUEST_LIMIT = 50000;

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  })[character]);

  const parseJson = (value, fallback) => {
    try {
      return JSON.parse(value);
    }
    catch (error) {
      return fallback;
    }
  };

  const typeLabel = (value) => String(value || 'Location').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

  const formatAddress = (properties) => {
    const address = String(properties.address || '').trim();
    const addressLower = address.toLocaleLowerCase();
    const city = String(properties.city || '').trim();
    const state = String(properties.state || '').trim();
    const zip = String(properties.zip || '').trim();
    if (address && (
      (zip && addressLower.includes(zip.toLocaleLowerCase()))
      || (city && state && addressLower.includes(city.toLocaleLowerCase()) && addressLower.includes(state.toLocaleLowerCase()))
    )) {
      return address;
    }
    const parts = address ? [address] : [];
    [city, state, zip].filter(Boolean).forEach((value) => {
      const text = String(value).trim();
      if (!parts.join(' ').toLocaleLowerCase().includes(text.toLocaleLowerCase())) {
        parts.push(text);
      }
    });
    return parts.join(', ');
  };

  const formatHours = (value) => {
    if (!value) return '';
    const parsed = parseJson(value, null);
    if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') {
      return String(value);
    }
    return Object.entries(parsed).map(([day, hours]) => {
      const displayHours = Array.isArray(hours) ? hours.join(', ') : String(hours);
      return `${day}: ${displayHours}`;
    }).join(' | ');
  };

  const safeHttpUrl = (value) => {
    if (!value) return '';
    try {
      const rawValue = String(value).trim();
      const url = new URL(/^[a-z][a-z\d+.-]*:/i.test(rawValue) ? rawValue : `https://${rawValue}`);
      return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
    }
    catch (error) {
      return '';
    }
  };

  const websiteLabel = (value) => {
    try {
      return new URL(value).hostname.replace(/^www\./, '');
    }
    catch (error) {
      return 'Website';
    }
  };

  const formatPhone = (value) => String(value || '').trim().replace(/\)\s*/g, ') ');

  const detailIcon = (name) => {
    const paths = {
      address: '<path d="M12 21s6-5.4 6-11a6 6 0 1 0-12 0c0 5.6 6 11 6 11Z"/><circle cx="12" cy="10" r="2.2"/>',
      category: '<path d="M7 4h10a2 2 0 0 1 2 2v12H5V6a2 2 0 0 1 2-2Z"/><path d="M8 8h8M8 12h8M9 18v-3h6v3"/>',
      description: '<path d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5"/>',
      distance: '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>',
      email: '<path d="M4 6h16v12H4zM4 7l8 6 8-6"/>',
      features: '<path d="m12 3 2.2 4.5 5 .7-3.6 3.5.9 5-4.5-2.4-4.5 2.4.9-5-3.6-3.5 5-.7L12 3Z"/>',
      hours: '<circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/>',
      order: '<path d="M5 8h14l-1 11H6L5 8ZM8 8a4 4 0 0 1 8 0"/>',
      phone: '<path d="M8.5 4.8 6 6.2c-.8.5-.9 1.6-.5 2.5 2 4.3 5.5 7.8 9.8 9.8.9.4 2 .3 2.5-.5l1.4-2.5-4-2-1.4 1.8a12.5 12.5 0 0 1-5.1-5.1l1.8-1.4-2-4Z"/>',
      plus: '<path d="M12 4v16M4 12h16"/>',
      coordinates: '<circle cx="12" cy="12" r="8"/><path d="M12 4v16M4 12h16"/>',
      price: '<path d="M12 3v18M16.5 7H9.8a3.3 3.3 0 0 0 0 6.5h4.4a3.3 3.3 0 0 1 0 6.5H7"/>',
      rating: '<path d="m12 4 2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4-3.9-3.8 5.4-.8L12 4Z"/>',
      social: '<circle cx="12" cy="12" r="8"/><path d="M4.5 10h15M4.5 14h15M12 4c2 2.2 3 4.9 3 8s-1 5.8-3 8c-2-2.2-3-4.9-3-8s1-5.8 3-8Z"/>',
      timezone: '<circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/>',
      updated: '<path d="M4 12a8 8 0 1 0 2.3-5.7L4 8.5M4 4v4.5h4.5"/>',
      website: '<path d="M14 5h5v5M13 11l6-6M18 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>'
    };
    return `<svg class="slm-detail-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${paths[name] || paths.category}</svg>`;
  };

  const detailRow = (icon, content, className = '') => content
    ? `<div class="slm-profile-card__row ${className}">${detailIcon(icon)}<div class="slm-profile-card__row-content">${content}</div></div>`
    : '';

  const linkList = (links) => Array.isArray(links)
    ? links.map((link) => {
      const url = safeHttpUrl(link?.url);
      return url ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(link.label || websiteLabel(url))}</a>` : '';
    }).filter(Boolean).join('<span class="slm-profile-card__separator"> · </span>')
    : '';

  const sourceDate = (value) => {
    if (!value) return '';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? escapeHtml(value) : escapeHtml(date.toLocaleString());
  };

  const ratingDistribution = (value) => {
    if (!value || typeof value !== 'object') return '';
    return ['5_star', '4_star', '3_star', '2_star', '1_star']
      .filter((key) => Number(value[key]) > 0)
      .map((key) => `${key.charAt(0)}★ ${Number(value[key]).toLocaleString()}`)
      .join(' · ');
  };

  const ratingStars = (locationId, ratingValue) => {
    const safeId = String(locationId || 'location').replace(/[^\w-]/g, '');
    const starPath = 'M12 2.7 14.9 8.6 21.4 9.5 16.7 14.1 17.8 20.6 12 17.5 6.2 20.6 7.3 14.1 2.6 9.5 9.1 8.6 12 2.7Z';
    return Array.from({length: 5}, (_, index) => {
      const fillPercent = Math.min(100, Math.max(0, (ratingValue - index) * 100));
      const clipWidth = (24 * fillPercent / 100).toFixed(3);
      const clipId = `slm-star-clip-${safeId}-${index}`;
      return `<svg class="slm-rating-star" data-fill="${fillPercent.toFixed(1)}" aria-hidden="true" viewBox="0 0 24 24">
        <defs><clipPath id="${clipId}"><rect x="0" y="0" width="${clipWidth}" height="24"></rect></clipPath></defs>
        <path class="slm-rating-star__base" d="${starPath}"></path>
        <path class="slm-rating-star__fill" d="${starPath}" clip-path="url(#${clipId})"></path>
      </svg>`;
    }).join('');
  };

  const detailsHtml = (properties) => {
    const address = escapeHtml(formatAddress(properties));
    const hours = escapeHtml(formatHours(properties.hours));
    const priceRange = escapeHtml(String(properties.price_range || '').trim());
    const country = escapeHtml(String(properties.country || '').trim());
    const description = escapeHtml(String(properties.description || '').trim());
    const attributes = Array.isArray(properties.attributes)
      ? properties.attributes.map(escapeHtml).join(' · ')
      : '';
    const socials = linkList(properties.socials);
    const reservationLinks = linkList(properties.reservation_links);
    const orderOnlineLinks = linkList(properties.order_online_links);
    const publicEmail = String(properties.email || '').trim();
    const email = publicEmail
      ? `<a href="mailto:${escapeHtml(publicEmail)}">${escapeHtml(publicEmail)}</a>`
      : '';
    const plusCode = escapeHtml(String(properties.plus_code || '').trim());
    const timezone = escapeHtml(String(properties.timezone || '').trim());
    const updated = sourceDate(properties.source_updated_at);
    const created = sourceDate(properties.source_created_at);
    const breakdown = escapeHtml(ratingDistribution(properties.rating_distribution));
    const websiteUrl = safeHttpUrl(properties.website);
    const website = websiteUrl
      ? `<a href="${escapeHtml(websiteUrl)}" target="_blank" rel="noopener noreferrer">${escapeHtml(websiteLabel(websiteUrl))}</a>`
      : '';
    const phoneText = String(properties.phone || '').trim();
    const phone = phoneText
      ? `<a href="tel:${escapeHtml(phoneText.replace(/[^\d+(). -]/g, ''))}">${escapeHtml(formatPhone(phoneText))}</a>`
      : '';
    const ratingValue = Math.min(5, Math.max(0, Number(properties.rating) || 0));
    const reviewCount = Math.max(0, Number(properties.review_count) || 0);
    const rating = `<span class="slm-rating-value">${escapeHtml(ratingValue.toFixed(1))}</span>
      <span class="slm-rating-stars" aria-label="${escapeHtml(ratingValue.toFixed(1))} out of 5 stars">
        ${ratingStars(properties.id, ratingValue)}
      </span>`;
    const reviews = `<span class="slm-rating-reviews">(${escapeHtml(reviewCount.toLocaleString())})</span>`;
    const topPrice = priceRange ? `<span class="slm-profile-card__top-price">${priceRange}</span>` : '';
    const ratingLine = `<div class="slm-profile-card__rating-line">${rating}${reviews}${topPrice}</div>`;
    const category = Array.isArray(properties.categories) && properties.categories.length
      ? properties.categories.map(escapeHtml).join(', ')
      : escapeHtml(String(properties.category || typeLabel(properties.type)).trim());
    const distance = properties.distance_miles !== undefined
      ? `<span class="slm-profile-card__secondary">${escapeHtml(properties.distance_miles)} miles from your location</span>`
      : '';
    const addressDetails = `${address}${country && !address.toLocaleLowerCase().includes(country.toLocaleLowerCase()) ? `<span class="slm-profile-card__secondary">${country}</span>` : ''}${distance}`;
    const menu = properties.has_menu
      ? '<span>Menu information available</span>'
      : '';
    const coordinates = Number.isFinite(Number(properties.latitude)) && Number.isFinite(Number(properties.longitude))
      ? `<strong>Coordinates</strong><span>${escapeHtml(Number(properties.latitude).toFixed(6))}, ${escapeHtml(Number(properties.longitude).toFixed(6))}</span>`
      : '';
    const sourceDates = created || updated
      ? `<strong>Source dates</strong>${created ? `<span>Created: ${created}</span>` : ''}${updated ? `<span>Updated: ${updated}</span>` : ''}`
      : '';
    return `<article class="slm-profile-card">
      <header class="slm-profile-card__header">
        <div class="slm-profile-card__heading">
          <h3 title="${escapeHtml(properties.name)}">${escapeHtml(properties.name)}</h3>
          ${ratingLine}
          <span class="slm-profile-card__type">${category}</span>
        </div>
        <button class="slm-profile-card__close" type="button" aria-label="Close location details">&times;</button>
      </header>
      <div class="slm-profile-card__body">
        ${detailRow('description', description)}
        ${detailRow('address', addressDetails)}
        ${detailRow('hours', hours ? `<strong>Hours</strong><span>${hours}</span>` : '')}
        ${detailRow('price', priceRange ? `<strong>${priceRange}</strong><span>Price range</span>` : '')}
        ${detailRow('features', attributes ? `<strong>Features</strong><span>${attributes}</span>` : '')}
        ${detailRow('order', reservationLinks ? `<strong>Reservations</strong><span>${reservationLinks}</span>` : '')}
        ${detailRow('order', orderOnlineLinks ? `<strong>Order online</strong><span>${orderOnlineLinks}</span>` : '')}
        ${detailRow('category', menu)}
        ${detailRow('website', website)}
        ${detailRow('phone', phone)}
        ${detailRow('email', email)}
        ${detailRow('social', socials ? `<strong>Social links</strong><span>${socials}</span>` : '')}
        ${detailRow('plus', plusCode ? `<strong>Plus code</strong><span>${plusCode}</span>` : '')}
        ${detailRow('coordinates', coordinates)}
        ${detailRow('timezone', timezone ? `<strong>Timezone</strong><span>${timezone}</span>` : '')}
        ${detailRow('rating', breakdown ? `<strong>Rating breakdown</strong><span>${breakdown}</span>` : '')}
        ${detailRow('updated', sourceDates)}
      </div>
    </article>`;
  };

  const debounce = (callback, delay) => {
    let timer;
    return (...args) => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => callback(...args), delay);
    };
  };

  const initialize = (app) => {
    if (typeof window.L === 'undefined') {
      app.querySelector('.slm-loading').hidden = true;
      app.querySelector('.slm-error').hidden = false;
      return;
    }
    const data = app.dataset;
    const allowedTypes = parseJson(data.allowedTypes, []);
    const markerColors = parseJson(data.markerColors, {});
    const mapElement = app.querySelector('.slm-map');
    const resultsElement = app.querySelector('.slm-results');
    const countElement = app.querySelector('.slm-result-count');
    const paginationElement = app.querySelector('.slm-results-pagination');
    const previousPageButton = app.querySelector('.slm-page-previous');
    const nextPageButton = app.querySelector('.slm-page-next');
    const pageInput = app.querySelector('.slm-page-input');
    const pageTotalElement = app.querySelector('.slm-page-total');
    const loadingElement = app.querySelector('.slm-loading');
    const emptyElement = app.querySelector('.slm-empty');
    const errorElement = app.querySelector('.slm-error');
    const searchInput = app.querySelector('.slm-search__input');
    const radiusInput = app.querySelector('.slm-radius');
    const state = {
      q: new URLSearchParams(window.location.search).get('q') || '',
      type: new URLSearchParams(window.location.search).get('type') || '',
      city: new URLSearchParams(window.location.search).get('city') || '',
      zip: new URLSearchParams(window.location.search).get('zip') || '',
      category: new URLSearchParams(window.location.search).get('category') || '',
      bbox: new URLSearchParams(window.location.search).get('bbox') || '',
      limit: new URLSearchParams(window.location.search).get('limit') || '',
      radius: new URLSearchParams(window.location.search).get('radius') || data.defaultRadius || '',
      lat: new URLSearchParams(window.location.search).get('lat') || '',
      lng: new URLSearchParams(window.location.search).get('lng') || ''
    };
    if (searchInput) searchInput.value = state.q;
    if (radiusInput) radiusInput.value = state.radius;

    const map = window.L.map(mapElement, {scrollWheelZoom: true}).setView([Number(data.defaultLat), Number(data.defaultLng)], Number(data.defaultZoom));
    window.L.tileLayer(data.tileUrl, {attribution: data.attribution, maxZoom: 19}).addTo(map);
    const markerLayer = data.clustering === '1' && typeof window.L.markerClusterGroup === 'function'
      ? window.L.markerClusterGroup()
      : window.L.layerGroup();
    markerLayer.addTo(map);
    let markers = new Map();
    let hasFitBounds = false;
    let currentFeatures = [];
    let currentPage = 1;

    const markerIcon = (type) => {
      const configuredColor = markerColors[type] || '#2563eb';
      const color = /^#[0-9a-f]{3,8}$/i.test(configuredColor) ? configuredColor : '#2563eb';
      return window.L.divIcon({
      className: 'slm-marker-wrap',
      html: `<span class="slm-marker" style="--slm-marker-color:${color}"><span class="visually-hidden">${escapeHtml(typeLabel(type))}</span></span>`,
      iconSize: [28, 36],
      iconAnchor: [14, 34],
      popupAnchor: [0, -30]
      });
    };

    const selectLocation = (id) => {
      const marker = markers.get(String(id));
      if (marker) {
        const reveal = () => {
          map.setView(marker.getLatLng(), Math.max(map.getZoom(), 13));
          marker.openPopup();
        };
        if (typeof markerLayer.zoomToShowLayer === 'function') {
          markerLayer.zoomToShowLayer(marker, reveal);
        }
        else {
          reveal();
        }
      }
      app.querySelectorAll('.slm-result').forEach((result) => result.classList.toggle('is-selected', result.dataset.id === String(id)));
    };

    app.addEventListener('click', (event) => {
      if (event.target.closest('.slm-profile-card__close')) {
        map.closePopup();
      }
    });
    map.on('popupclose', () => {
      app.querySelectorAll('.slm-result').forEach((result) => result.classList.remove('is-selected'));
    });

    const renderResults = (features, requestedPage = 1) => {
      if (!resultsElement) return;
      resultsElement.replaceChildren();
      currentFeatures = features;
      const totalPages = Math.max(1, Math.ceil(features.length / RESULTS_PER_PAGE));
      currentPage = Math.min(totalPages, Math.max(1, requestedPage));
      const start = (currentPage - 1) * RESULTS_PER_PAGE;
      const visible = features.slice(start, start + RESULTS_PER_PAGE);
      visible.forEach((feature) => {
        const properties = feature.properties;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'slm-result';
        button.dataset.id = properties.id;
        const badge = document.createElement('span');
        badge.className = 'slm-badge';
        badge.textContent = typeLabel(properties.type || properties.category);
        const name = document.createElement('strong');
        name.textContent = properties.name;
        const location = document.createElement('span');
        location.textContent = [properties.city, properties.state, properties.zip].filter(Boolean).join(', ');
        const distance = document.createElement('span');
        distance.className = 'slm-result__distance';
        distance.textContent = properties.distance_miles !== undefined ? `${properties.distance_miles} miles away` : '';
        button.append(badge, name, location, distance);
        button.addEventListener('click', () => selectLocation(properties.id));
        resultsElement.append(button);
      });
      if (paginationElement) {
        paginationElement.hidden = features.length <= RESULTS_PER_PAGE;
      }
      if (previousPageButton) {
        previousPageButton.disabled = currentPage <= 1;
      }
      if (nextPageButton) {
        nextPageButton.disabled = currentPage >= totalPages;
      }
      if (pageInput) {
        pageInput.value = currentPage;
        pageInput.max = totalPages;
      }
      if (pageTotalElement) {
        pageTotalElement.textContent = `of ${totalPages}`;
      }
      resultsElement.scrollTop = 0;
    };

    previousPageButton?.addEventListener('click', () => renderResults(currentFeatures, currentPage - 1));
    nextPageButton?.addEventListener('click', () => renderResults(currentFeatures, currentPage + 1));
    const navigateToEnteredPage = () => {
      if (!pageInput) return;
      const totalPages = Math.max(1, Math.ceil(currentFeatures.length / RESULTS_PER_PAGE));
      const requestedPage = Number.parseInt(pageInput.value, 10);
      renderResults(currentFeatures, Number.isFinite(requestedPage) ? Math.min(totalPages, Math.max(1, requestedPage)) : currentPage);
    };
    pageInput?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        navigateToEnteredPage();
        pageInput.blur();
      }
    });
    pageInput?.addEventListener('blur', navigateToEnteredPage);

    const updateStatus = (count, datasetTotal = count) => {
      if (countElement) countElement.textContent = `${count} loaded`;
      app.querySelectorAll('.slm-footer-total').forEach((element) => { element.textContent = count; });
      app.querySelectorAll('.slm-footer-dataset-total').forEach((element) => { element.textContent = datasetTotal.toLocaleString(); });
      app.closest('.slm-report')?.querySelectorAll('.slm-report-visible-count').forEach((element) => { element.textContent = count; });
      app.querySelectorAll('.slm-footer-radius').forEach((element) => {
        element.textContent = `Radius: ${state.radius && state.lat && state.lng ? `${state.radius} miles` : 'Any distance'}`;
      });
    };

    const updateUrl = () => {
      if (!data.reportUrl || !app.classList.contains('slm-app--report')) return;
      const params = new URLSearchParams();
      Object.entries(state).forEach(([key, value]) => { if (value) params.set(key, value); });
      window.history.replaceState({}, '', `${data.reportUrl}${params.toString() ? `?${params}` : ''}`);
    };

    const load = async () => {
      loadingElement.hidden = false;
      emptyElement.hidden = true;
      errorElement.hidden = true;
      const params = new URLSearchParams();
      if (state.q) params.set('q', state.q);
      if (state.type) params.set('type', state.type);
      ['city', 'zip', 'category', 'bbox', 'limit'].forEach((key) => {
        if (state[key]) params.set(key, state[key]);
      });
      if (!state.limit) {
        params.set('limit', FULL_MAP_REQUEST_LIMIT);
      }
      if (state.radius && state.lat && state.lng) {
        params.set('radius', state.radius);
        params.set('lat', state.lat);
        params.set('lng', state.lng);
      }
      try {
        const response = await fetch(`${data.apiUrl}?${params}`, {headers: {'Accept': 'application/geo+json'}});
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const geojson = await response.json();
        markerLayer.clearLayers();
        markers = new Map();
        const bounds = [];
        geojson.features.forEach((feature) => {
          const [lng, lat] = feature.geometry.coordinates;
          const marker = window.L.marker([lat, lng], {icon: markerIcon(feature.properties.type)});
          marker.bindPopup(detailsHtml(feature.properties), {
            className: 'slm-profile-popup',
            closeButton: false,
            maxWidth: 370,
            minWidth: 330
          });
          marker.on('click', () => selectLocation(feature.properties.id));
          markerLayer.addLayer(marker);
          markers.set(String(feature.properties.id), marker);
          bounds.push([lat, lng]);
        });
        renderResults(geojson.features, 1);
        updateStatus(geojson.features.length, geojson.meta?.dataset_total || geojson.features.length);
        if (geojson.meta?.last_updated) {
          app.querySelectorAll('.slm-updated').forEach((element) => {
            element.textContent = new Date(geojson.meta.last_updated * 1000).toLocaleDateString();
          });
        }
        const report = app.closest('.slm-report');
        report?.querySelectorAll('[data-legend-type]').forEach((dot) => {
          const configuredColor = markerColors[dot.dataset.legendType] || '#2563eb';
          dot.style.backgroundColor = /^#[0-9a-f]{3,8}$/i.test(configuredColor) ? configuredColor : '#2563eb';
        });
        emptyElement.hidden = geojson.features.length !== 0;
        if (bounds.length && (!hasFitBounds || state.q || state.type || state.radius)) {
          map.fitBounds(bounds, {padding: [35, 35], maxZoom: 13});
          hasFitBounds = true;
        }
        updateUrl();
      }
      catch (error) {
        errorElement.hidden = false;
      }
      finally {
        loadingElement.hidden = true;
      }
    };
    const delayedLoad = debounce(load, 300);

    app.querySelector('.slm-search__button')?.addEventListener('click', () => {
      state.q = searchInput.value.trim();
      load();
    });
    searchInput?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        state.q = searchInput.value.trim();
        load();
      }
    });
    searchInput?.addEventListener('input', () => {
      state.q = searchInput.value.trim();
      delayedLoad();
    });
    app.querySelectorAll('.slm-filter').forEach((button) => {
      button.classList.toggle('is-active', button.dataset.type === state.type);
      button.setAttribute('aria-pressed', button.dataset.type === state.type ? 'true' : 'false');
      button.addEventListener('click', () => {
        state.type = button.dataset.type;
        app.querySelectorAll('.slm-filter').forEach((candidate) => {
          const active = candidate === button;
          candidate.classList.toggle('is-active', active);
          candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        load();
      });
    });
    radiusInput?.addEventListener('change', () => {
      state.radius = radiusInput.value;
      load();
    });
    app.querySelector('.slm-locate')?.addEventListener('click', () => {
      if (!navigator.geolocation) {
        errorElement.textContent = 'Geolocation is not supported by this browser.';
        errorElement.hidden = false;
        return;
      }
      navigator.geolocation.getCurrentPosition((position) => {
        state.lat = position.coords.latitude.toFixed(6);
        state.lng = position.coords.longitude.toFixed(6);
        if (!state.radius) {
          state.radius = radiusInput?.options[1]?.value || '25';
          if (radiusInput) radiusInput.value = state.radius;
        }
        map.setView([state.lat, state.lng], 11);
        load();
      }, () => {
        errorElement.textContent = 'Your location could not be used. Check browser location permission and try again.';
        errorElement.hidden = false;
      }, {enableHighAccuracy: false, timeout: 10000});
    });
    const reportContainer = app.closest('.slm-report');
    reportContainer?.querySelector('.slm-print')?.addEventListener('click', () => window.print());
    reportContainer?.querySelector('.slm-copy-url')?.addEventListener('click', async (event) => {
      try {
        await navigator.clipboard.writeText(window.location.href);
        event.currentTarget.textContent = 'Copied';
      }
      catch (error) {
        event.currentTarget.textContent = 'Copy unavailable';
      }
    });
    load();
  };

  Drupal.behaviors.secureLocationMap = {
    attach(context) {
      once('secure-location-map', '.slm-app', context).forEach(initialize);
    }
  };
})(Drupal, once);
