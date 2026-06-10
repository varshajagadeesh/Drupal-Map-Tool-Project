(function (Drupal, once) {
  'use strict';

  const MAX_RESULT_ITEMS = 200;

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

  const detailIcon = (name) => {
    const paths = {
      address: '<path d="M12 21s6-5.4 6-11a6 6 0 1 0-12 0c0 5.6 6 11 6 11Z"/><circle cx="12" cy="10" r="2.2"/>',
      category: '<path d="M7 4h10a2 2 0 0 1 2 2v12H5V6a2 2 0 0 1 2-2Z"/><path d="M8 8h8M8 12h8M9 18v-3h6v3"/>',
      distance: '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>',
      hours: '<circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/>',
      phone: '<path d="M8.5 4.8 6 6.2c-.8.5-.9 1.6-.5 2.5 2 4.3 5.5 7.8 9.8 9.8.9.4 2 .3 2.5-.5l1.4-2.5-4-2-1.4 1.8a12.5 12.5 0 0 1-5.1-5.1l1.8-1.4-2-4Z"/>',
      rating: '<path d="m12 4 2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4-3.9-3.8 5.4-.8L12 4Z"/>',
      website: '<path d="M14 5h5v5M13 11l6-6M18 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>'
    };
    return `<svg class="slm-detail-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${paths[name] || paths.category}</svg>`;
  };

  const detailRow = (icon, content, className = '') => content
    ? `<div class="slm-profile-card__row ${className}">${detailIcon(icon)}<div>${content}</div></div>`
    : '';

  const detailsHtml = (properties) => {
    const address = escapeHtml(formatAddress(properties));
    const hours = escapeHtml(formatHours(properties.hours));
    const websiteUrl = safeHttpUrl(properties.website);
    const website = websiteUrl
      ? `<a href="${escapeHtml(websiteUrl)}" target="_blank" rel="noopener noreferrer">${escapeHtml(websiteLabel(websiteUrl))}</a>`
      : '';
    const phoneText = String(properties.phone || '').trim();
    const phone = phoneText
      ? `<a href="tel:${escapeHtml(phoneText.replace(/[^\d+(). -]/g, ''))}">${escapeHtml(phoneText)}</a>`
      : '';
    const ratingValue = Number(properties.rating);
    const reviewCount = Number(properties.review_count);
    const rating = ratingValue > 0
      ? `<span>${escapeHtml(properties.rating)} stars${reviewCount > 0 ? ` from ${escapeHtml(properties.review_count)} reviews` : ''}</span>`
      : (reviewCount > 0 ? `<span>${escapeHtml(properties.review_count)} reviews</span>` : '');
    const distance = properties.distance_miles !== undefined
      ? `<span>${escapeHtml(properties.distance_miles)} miles from your location</span>`
      : '';
    return `<article class="slm-profile-card">
      <header class="slm-profile-card__header">
        <span class="slm-profile-card__category-icon">${detailIcon('category')}</span>
        <div class="slm-profile-card__heading">
          <h3 title="${escapeHtml(properties.name)}">${escapeHtml(properties.name)}</h3>
          <span class="slm-profile-card__type">${escapeHtml(typeLabel(properties.type || properties.category))}</span>
        </div>
        <button class="slm-profile-card__close" type="button" aria-label="Close location details">&times;</button>
      </header>
      <div class="slm-profile-card__body">
        ${detailRow('address', address)}
        ${detailRow('distance', distance, 'slm-profile-card__distance')}
        ${detailRow('hours', hours)}
        ${detailRow('phone', phone)}
        ${detailRow('rating', rating)}
        ${detailRow('website', website)}
      </div>
      ${websiteUrl ? `<a class="slm-profile-card__action" href="${escapeHtml(websiteUrl)}" target="_blank" rel="noopener noreferrer">View Full Profile</a>` : ''}
    </article>`;
  };

  const debounce = (callback, delay) => {
    let timer;
    return (...args) => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => callback(...args), delay);
    };
  };

  const popupHtml = (properties) => {
    const address = escapeHtml(formatAddress(properties));
    const hours = escapeHtml(formatHours(properties.hours));
    const website = properties.website
      ? `<a href="${escapeHtml(properties.website)}" target="_blank" rel="noopener noreferrer">Website</a>`
      : '';
    const phone = properties.phone ? `<a href="tel:${escapeHtml(properties.phone)}">${escapeHtml(properties.phone)}</a>` : '';
    const ratingValue = Number(properties.rating);
    const reviewCount = Number(properties.review_count);
    const rating = ratingValue > 0
      ? `<span aria-label="${escapeHtml(properties.rating)} rating">&#9733; ${escapeHtml(properties.rating)}${reviewCount > 0 ? ` (${escapeHtml(properties.review_count)} reviews)` : ''}</span>`
      : (reviewCount > 0 ? `<span>${escapeHtml(properties.review_count)} reviews</span>` : '');
    const distance = properties.distance_miles !== undefined ? `<span>${escapeHtml(properties.distance_miles)} miles away</span>` : '';
    return `<article class="slm-popup">
      <span class="slm-badge">${escapeHtml(typeLabel(properties.type || properties.category))}</span>
      <h3>${escapeHtml(properties.name)}</h3>
      ${address ? `<p>${address}</p>` : ''}
      <div class="slm-popup__meta">${rating}${distance}</div>
      ${hours ? `<p class="slm-popup__hours"><strong>Hours:</strong> ${hours}</p>` : ''}
      <div class="slm-popup__links">${website}${phone}</div>
    </article>`;
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
    const detailsElement = app.querySelector('.slm-details');
    const countElement = app.querySelector('.slm-result-count');
    const noteElement = app.querySelector('.slm-results-note');
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

    const showDetails = (properties) => {
      if (!detailsElement) return;
      detailsElement.innerHTML = detailsHtml(properties);
      detailsElement.hidden = false;
      detailsElement.querySelector('.slm-profile-card__close')?.addEventListener('click', () => {
        detailsElement.hidden = true;
        detailsElement.replaceChildren();
        map.closePopup();
        app.querySelectorAll('.slm-result').forEach((result) => result.classList.remove('is-selected'));
      });
    };

    const selectLocation = (id, properties) => {
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
      showDetails(properties);
      app.querySelectorAll('.slm-result').forEach((result) => result.classList.toggle('is-selected', result.dataset.id === String(id)));
    };

    const renderResults = (features) => {
      if (!resultsElement) return;
      resultsElement.replaceChildren();
      const visible = features.slice(0, MAX_RESULT_ITEMS);
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
        button.addEventListener('click', () => selectLocation(properties.id, properties));
        resultsElement.append(button);
      });
      if (noteElement) {
        noteElement.hidden = features.length <= MAX_RESULT_ITEMS;
        noteElement.textContent = features.length > MAX_RESULT_ITEMS
          ? `Showing the first ${MAX_RESULT_ITEMS} result cards. All ${features.length} loaded locations remain on the map. Refine the filters to see fewer results.`
          : '';
      }
    };

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
          marker.bindPopup(popupHtml(feature.properties));
          marker.on('click', () => selectLocation(feature.properties.id, feature.properties));
          markerLayer.addLayer(marker);
          markers.set(String(feature.properties.id), marker);
          bounds.push([lat, lng]);
        });
        renderResults(geojson.features);
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
