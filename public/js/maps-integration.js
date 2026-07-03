/**
 * AidFleet Google Maps Integration
 * Handles Google Maps JS API initialization, Directions routing, Places autocomplete,
 * Distance Matrix for ETA, Geocoding, and smooth marker animation (glide).
 *
 * Cost-optimized: Directions/ETA calls are throttled, Places uses session tokens,
 * and the API key is loaded securely from a backend proxy.
 *
 * Required Google Cloud APIs:
 *  - Maps JavaScript API
 *  - Directions API
 *  - Places API (New)
 *  - Distance Matrix API
 *  - Geocoding API
 */

window.AidFleetMaps = {
  map: null,
  routePolyline: null,
  _apiKey: null,
  _libLoaded: false,
  _libLoading: false,
  _loadCallbacks: [],

  _lastRouteKey: null,
  _lastETAKey: null,
  _lastETAResult: null,

  _isPageVisible: true,
  _etaCallCount: 0,
  _etaBudgetCap: 200,          // Max Distance Matrix calls per page session
  _lastETAOriginLat: null,
  _lastETAOriginLng: null,
  _lastETADestKey: null,          // Track destination to detect route changes
  _movementThresholdM: 50,

  icons: {
    // Top-view ambulance van with red cross
    ambulance: {
      url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
        '<svg width="56" height="56" viewBox="0 0 56 56" xmlns="http://www.w3.org/2000/svg">' +
        '<circle cx="28" cy="28" r="26" fill="%23e11d48" stroke="%23fff" stroke-width="2"/>' +
        // Van body (top view, white)
        '<rect x="16" y="8" width="24" height="38" rx="5" fill="white"/>' +
        // Windshield
        '<rect x="18" y="10" width="20" height="7" rx="2" fill="%23bfdbfe"/>' +
        // Rear window
        '<rect x="18" y="38" width="20" height="5" rx="2" fill="%23bfdbfe"/>' +
        // Red cross horizontal
        '<rect x="20" y="25" width="16" height="5" rx="1" fill="%23e11d48"/>' +
        // Red cross vertical
        '<rect x="25.5" y="20" width="5" height="15" rx="1" fill="%23e11d48"/>' +
        '</svg>'
      ),
      scaledSize: null
    },
    // Scene/requester: bright red circle with white hazard warning sign
    requester: {
      url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
        '<svg width="48" height="48" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">' +
        '<circle cx="24" cy="24" r="22" fill="%23ef4444" stroke="%23fff" stroke-width="2.5"/>' +
        // Hazard triangle outline (white)
        '<polygon points="24,10 40,38 8,38" fill="none" stroke="white" stroke-width="3" stroke-linejoin="round"/>' +
        // Exclamation bar
        '<rect x="22.5" y="20" width="3" height="10" rx="1.5" fill="white"/>' +
        // Exclamation dot
        '<circle cx="24" cy="34" r="2" fill="white"/>' +
        '</svg>'
      ),
      scaledSize: null
    },
    hospital: {
      url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
        '<svg width="44" height="44" viewBox="0 0 44 44" xmlns="http://www.w3.org/2000/svg">' +
        '<circle cx="22" cy="22" r="20" fill="%233b82f6" stroke="%23fff" stroke-width="2"/>' +
        '<text x="22" y="29" font-size="20" text-anchor="middle" fill="white">H</text></svg>'
      ),
      scaledSize: null
    }
  },

  _mapStyles: [
    { elementType: 'geometry', stylers: [{ color: '#1d2c4d' }] },
    { elementType: 'labels.text.fill', stylers: [{ color: '#8ec3b9' }] },
    { elementType: 'labels.text.stroke', stylers: [{ color: '#1a3646' }] },
    { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#304a7d' }] },
    { featureType: 'road', elementType: 'geometry.stroke', stylers: [{ color: '#255763' }] },
    { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#2c6675' }] },
    { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#0e1626' }] },
    { featureType: 'poi.medical', elementType: 'geometry', stylers: [{ color: '#263c3f' }] },
    { featureType: 'poi', elementType: 'labels.text.fill', stylers: [{ color: '#6f9ba5' }] },
    { featureType: 'transit', elementType: 'labels.text.fill', stylers: [{ color: '#98a5be' }] },
  ],

  /**
   * Fetch the Google Maps API key from the secure backend proxy.
   * Caches in sessionStorage to avoid repeated network calls.
   */
  _fetchApiKey: async function() {
    if (this._apiKey) return this._apiKey;

    // Check sessionStorage cache first
    try {
      const cached = sessionStorage.getItem('_aidfleet_mk');
      if (cached) {
        this._apiKey = cached;
        return cached;
      }
    } catch(e) {}

    try {
      const root = window.APP_ROOT || '';
      const res = await fetch(root + 'api/maps-config.php', { credentials: 'include' });
      const data = await res.json();
      if (data.success && data.key) {
        this._apiKey = data.key;
        try { sessionStorage.setItem('_aidfleet_mk', data.key); } catch(e) {}
        return data.key;
      }
    } catch(e) {
      console.error('AidFleetMaps: Failed to fetch API key', e);
    }
    return null;
  },

  /**
   * Dynamically load the Google Maps JavaScript API.
   * Uses the modern async loading approach with callback queue.
   */
  loadMapLib: function(callback) {
    if (this._libLoaded && window.google && window.google.maps) {
      if (callback) callback();
      return;
    }

    if (callback) this._loadCallbacks.push(callback);

    if (this._libLoading) return; // Already loading
    this._libLoading = true;

    const self = this;
    this._fetchApiKey().then(function(key) {
      if (!key) {
        console.error('AidFleetMaps: No API key available');
        self._libLoading = false;
        return;
      }

      // Check if script is already present (e.g., from a previous page load in SPA)
      if (window.google && window.google.maps) {
        self._libLoaded = true;
        self._libLoading = false;
        self._loadCallbacks.forEach(function(cb) { cb(); });
        self._loadCallbacks = [];
        return;
      }

      // Define global callback
      window._aidfleetMapsReady = function() {
        self._libLoaded = true;
        self._libLoading = false;
        self._loadCallbacks.forEach(function(cb) { cb(); });
        self._loadCallbacks = [];
      };

      const script = document.createElement('script');
      script.src = 'https://maps.googleapis.com/maps/api/js?key=' + key +
        '&libraries=places&callback=_aidfleetMapsReady&v=weekly&loading=async';
      script.async = true;
      script.defer = true;
      script.onerror = function() {
        console.error('AidFleetMaps: Failed to load Google Maps SDK');
        self._libLoading = false;
      };
      document.head.appendChild(script);
    });
  },

  /**
   * Initialize a Google Map inside the given container element ID.
   * Returns the google.maps.Map instance.
   */
  initMap: function(containerId, centerLat, centerLng, zoom) {
    zoom = zoom || 14;
    const container = document.getElementById(containerId);
    if (!container) return null;

    // Destroy existing map
    if (this.map) {
      this.map = null;
    }
    if (this.routePolyline) {
      this.routePolyline.setMap(null);
      this.routePolyline = null;
    }

    // Clear placeholder content
    container.innerHTML = '';

    // Create Google Map with styled theme
    this.map = new google.maps.Map(container, {
      center: { lat: parseFloat(centerLat), lng: parseFloat(centerLng) },
      zoom: zoom,
      disableDefaultUI: true,    // Minimal UI — no zoom controls, streetview, etc.
      zoomControl: true,
      mapTypeControl: false,
      streetViewControl: false,
      fullscreenControl: false,
      gestureHandling: 'greedy', // Single-finger scrolling on mobile
      styles: this._mapStyles
      // Note: mapId is intentionally omitted — it conflicts with styles and
      // requires Cloud Console setup. Standard Markers are used instead.
    });

    return this.map;
  },

  /**
   * Draw a route from origin to destination using Google Directions API.
   * Renders a polyline on the map and returns route data (duration, distance).
   */
  drawRoute: async function(originLat, originLng, destLat, destLng) {
    if (!this.map) return null;

    // Cache key to avoid duplicate calls for the same route
    const routeKey = [originLat, originLng, destLat, destLng].map(function(v) {
      return parseFloat(v).toFixed(4);
    }).join(',');

    if (routeKey === this._lastRouteKey && this.routePolyline) {
      return; // Same route, skip
    }

    try {
      const directionsService = new google.maps.DirectionsService();
      const routeRequest = {
        origin: { lat: parseFloat(originLat), lng: parseFloat(originLng) },
        destination: { lat: parseFloat(destLat), lng: parseFloat(destLng) },
        travelMode: google.maps.TravelMode.DRIVING,
        avoidTolls: false,
        provideRouteAlternatives: false,
        // Use real-time traffic to pick the fastest route (avoids congested roads)
        drivingOptions: {
          departureTime: new Date(),
          trafficModel: google.maps.TrafficModel.BEST_GUESS
        }
      };

      let result;
      try {
        result = await directionsService.route(routeRequest);
      } catch (trafficErr) {
        // Fallback: some billing plans don't support drivingOptions
        console.warn('AidFleetMaps: Traffic-aware routing unavailable, using basic', trafficErr.message || trafficErr);
        delete routeRequest.drivingOptions;
        result = await directionsService.route(routeRequest);
      }

      if (result.routes && result.routes.length > 0) {
        // Clear existing route polyline
        if (this.routePolyline) {
          this.routePolyline.setMap(null);
        }

        // Decode and draw the route path
        const path = result.routes[0].overview_path;
        this.routePolyline = new google.maps.Polyline({
          path: path,
          geodesic: true,
          strokeColor: '#0ea5e9',
          strokeOpacity: 0.85,
          strokeWeight: 5,
          map: this.map
        });

        this._lastRouteKey = routeKey;

        // Return leg data for potential ETA use (avoids a second API call)
        const leg = result.routes[0].legs[0];
        // Prefer traffic-adjusted duration when available
        const duration = leg.duration_in_traffic || leg.duration;
        return {
          durationSec: duration.value,
          distanceMeters: leg.distance.value,
          durationText: duration.text,
          distanceText: leg.distance.text
        };
      }
    } catch (e) {
      console.warn('AidFleetMaps: Directions API error', e);
    }
    return null;
  },

  /**
   * Create a Google Maps AdvancedMarkerElement with custom SVG icon.
   * Falls back to standard Marker if AdvancedMarkerElement is not available.
   *
   * The marker exposes a compatible API surface:
   *   marker.position — { lat, lng } (AdvancedMarker) or LatLng
   *   marker.getLatLng() — added for Leaflet compat (returns {lat, lng})
   *   marker.setLatLng([lat, lng]) — added for smooth animation compat
   */
  createMarker: function(lat, lng, title, type) {
    if (!this.map) return null;

    const position = { lat: parseFloat(lat), lng: parseFloat(lng) };

    // Select icon definition
    let iconDef = this.icons.hospital;
    if (type === 'ambulance') iconDef = this.icons.ambulance;
    if (type === 'requester') iconDef = this.icons.requester;

    // Ambulance is larger (56x56), others are 44x44
    const sz  = (type === 'ambulance') ? 56 : (type === 'requester' ? 48 : 44);
    const anc = sz / 2;

    // Build icon object with scaled size
    const icon = {
      url: iconDef.url,
      scaledSize: new google.maps.Size(sz, sz),
      anchor: new google.maps.Point(anc, anc)
    };

    const marker = new google.maps.Marker({
      map: this.map,
      position: position,
      title: title || '',
      icon: icon
    });

    // Add helper methods for consistent API surface
    marker.getLatLng = function() {
      const pos = this.getPosition();
      return { lat: pos.lat(), lng: pos.lng() };
    };
    marker.setLatLng = function(coords) {
      let lat, lng;
      if (Array.isArray(coords)) {
        lat = coords[0]; lng = coords[1];
      } else {
        lat = coords.lat; lng = coords.lng;
      }
      this.setPosition({ lat: parseFloat(lat), lng: parseFloat(lng) });
    };

    return marker;
  },

  /**
   * Smooth Glide Animation — Animates a marker from its current position
   * to a new position using requestAnimationFrame with ease-out cubic easing.
   *
   * This creates a smooth, natural-looking movement instead of jerky jumps
   * when the GPS position updates.
   *
   * @param {Object} marker - Google Maps marker with getLatLng/setLatLng
   * @param {number} targetLat - Destination latitude
   * @param {number} targetLng - Destination longitude
   * @param {number} durationMs - Animation duration in milliseconds (default: 3000)
   */
  animateMarker: function(marker, targetLat, targetLng, durationMs, autoPan) {
    durationMs = durationMs || 3000;

    const startLatLng = marker.getLatLng();
    const startLat = startLatLng.lat;
    const startLng = startLatLng.lng;

    const dLat = parseFloat(targetLat) - startLat;
    const dLng = parseFloat(targetLng) - startLng;
    const roughDist = Math.sqrt(dLat * dLat + dLng * dLng);

    // If distance is too large (>~5km), skip animation — just jump
    if (roughDist > 0.05) {
      marker.setLatLng([targetLat, targetLng]);
      if (autoPan && this.map) this.map.panTo({ lat: targetLat, lng: targetLng });
      return;
    }

    // Cancel any existing animation on this marker
    if (marker._animFrame) {
      cancelAnimationFrame(marker._animFrame);
      marker._animFrame = null;
    }

    const startTime = performance.now();

    function step(currentTime) {
      let progress = (currentTime - startTime) / durationMs;
      if (progress > 1) progress = 1;

      // Ease-out cubic for smooth deceleration
      const easeProgress = 1 - Math.pow(1 - progress, 3);

      const currentLat = startLat + dLat * easeProgress;
      const currentLng = startLng + dLng * easeProgress;

      marker.setLatLng([currentLat, currentLng]);
      if (autoPan && AidFleetMaps.map) {
        AidFleetMaps.map.panTo({ lat: currentLat, lng: currentLng });
      }

      if (progress < 1) {
        marker._animFrame = requestAnimationFrame(step);
      } else {
        marker.setLatLng([targetLat, targetLng]); // Exact finish
        if (autoPan && AidFleetMaps.map) {
          AidFleetMaps.map.panTo({ lat: targetLat, lng: targetLng });
        }
        marker._animFrame = null;
      }
    }

    marker._animFrame = requestAnimationFrame(step);
  },

  /**
   * Check whether the page is currently visible to the user.
   * Used by consumers to gate expensive API calls (ETA, location sync).
   */
  isPageActive: function() {
    return this._isPageVisible;
  },

  /**
   * Haversine distance in meters between two lat/lng pairs.
   * Used internally to check if the driver moved enough to justify a new ETA call.
   */
  _haversineM: function(lat1, lng1, lat2, lng2) {
    const R = 6371000;
    const toRad = function(d) { return d * Math.PI / 180; };
    const dLat = toRad(lat2 - lat1);
    const dLng = toRad(lng2 - lng1);
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
              Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  },

  /**
   * Calculate ETA using Google Distance Matrix API.
   *
   * Cost guards:
   *  - Skips if page is not visible (tab backgrounded)
   *  - Skips if driver hasn't moved >50m since last ETA call
   *  - Skips if session budget (200 calls) is exhausted
   *  - Caches results and skips identical origin/destination
   *
   * @param {number} originLat
   * @param {number} originLng
   * @param {number} destLat
   * @param {number} destLng
   * @param {function} callback - Receives { success, text, distanceMeters }
   */
  getLiveETA: async function(originLat, originLng, destLat, destLng, callback) {
    // Guard 1: Page Visibility — no API calls while tab is hidden
    if (!this._isPageVisible) {
      if (this._lastETAResult) callback(this._lastETAResult);
      return;
    }

    // Guard 2: Session budget cap
    if (this._etaCallCount >= this._etaBudgetCap) {
      console.warn('AidFleetMaps: ETA session budget exhausted (' + this._etaBudgetCap + ' calls)');
      if (this._lastETAResult) callback(this._lastETAResult);
      return;
    }

    // Build destination key to detect route changes (patient → hospital)
    var destKey = parseFloat(destLat).toFixed(3) + ',' + parseFloat(destLng).toFixed(3);
    var destChanged = (destKey !== this._lastETADestKey);

    // Guard 3: Movement threshold — skip if driver hasn't moved >50m AND destination is same
    if (!destChanged && this._lastETAOriginLat !== null && this._lastETAOriginLng !== null) {
      var moved = this._haversineM(
        this._lastETAOriginLat, this._lastETAOriginLng,
        parseFloat(originLat), parseFloat(originLng)
      );
      if (moved < this._movementThresholdM && this._lastETAResult) {
        callback(this._lastETAResult);
        return;
      }
    }

    // If destination changed, clear stale cache
    if (destChanged) {
      this._lastETAKey = null;
      this._lastETAResult = null;
      this._lastETADestKey = destKey;
    }

    // Build cache key (rounded to ~100m to avoid near-duplicate calls)
    var etaKey = [originLat, originLng, destLat, destLng].map(function(v) {
      return parseFloat(v).toFixed(3);
    }).join(',');

    // Return cached if available and key matches
    if (etaKey === this._lastETAKey && this._lastETAResult) {
      callback(this._lastETAResult);
      return;
    }

    var self = this;

    // Helper to parse Distance Matrix result
    function parseResult(result) {
      if (result.rows && result.rows[0] && result.rows[0].elements[0]) {
        var element = result.rows[0].elements[0];
        if (element.status === 'OK') {
          var duration = element.duration_in_traffic || element.duration;
          var durationSec = duration.value;
          var distanceM = element.distance.value;

          var text = '';
          var mins = Math.ceil(durationSec / 60);
          if (mins >= 60) {
            text = Math.floor(mins / 60) + ' hr ' + (mins % 60) + ' min';
          } else {
            text = mins + ' min';
          }

          return { success: true, text: text, distanceMeters: distanceM };
        }
      }
      return null;
    }

    try {
      this._etaCallCount++;
      var service = new google.maps.DistanceMatrixService();
      var origins = [{ lat: parseFloat(originLat), lng: parseFloat(originLng) }];
      var destinations = [{ lat: parseFloat(destLat), lng: parseFloat(destLng) }];

      var result;
      try {
        // Try with real-time traffic first (requires premium/pay-as-you-go billing)
        result = await service.getDistanceMatrix({
          origins: origins,
          destinations: destinations,
          travelMode: google.maps.TravelMode.DRIVING,
          drivingOptions: {
            departureTime: new Date(),
            trafficModel: google.maps.TrafficModel.BEST_GUESS
          }
        });
      } catch (trafficErr) {
        // Fallback: basic Distance Matrix without traffic (works on all billing plans)
        console.warn('AidFleetMaps: Traffic ETA unavailable, falling back to basic', trafficErr.message || trafficErr);
        result = await service.getDistanceMatrix({
          origins: origins,
          destinations: destinations,
          travelMode: google.maps.TravelMode.DRIVING
        });
      }

      var parsed = parseResult(result);
      if (parsed) {
        self._lastETAKey = etaKey;
        self._lastETAResult = parsed;
        self._lastETAOriginLat = parseFloat(originLat);
        self._lastETAOriginLng = parseFloat(originLng);
        callback(parsed);
        return;
      }

      console.warn('AidFleetMaps: Distance Matrix returned no valid results');
      callback({ success: false, error: 'NO_RESULTS' });
    } catch (e) {
      console.warn('AidFleetMaps: Distance Matrix error', e);
      callback({ success: false, error: 'NETWORK_ERROR' });
    }
  },

  /**
   * Initialize Places Autocomplete on an input field.
   * Uses session tokens to bundle autocomplete keystrokes + selection into one billing session.
   *
   * @param {string} inputId - DOM ID of the text input
   * @param {function} onSelectCallback - Called with { name, lat, lng } when a place is selected
   */
  initAutocomplete: function(inputId, onSelectCallback) {
    const input = document.getElementById(inputId);
    if (!input) return null;

    input.setAttribute('autocomplete', 'off');

    // Create dropdown appended to body (escapes all stacking contexts)
    const dropdown = document.createElement('div');
    dropdown.style.position = 'fixed';
    dropdown.style.background = 'rgba(30, 41, 59, 0.97)';
    dropdown.style.border = '1px solid rgba(255,255,255,0.15)';
    dropdown.style.borderRadius = '8px';
    dropdown.style.boxShadow = '0 10px 25px -3px rgba(0,0,0,0.4)';
    dropdown.style.zIndex = '99999';
    dropdown.style.maxHeight = '250px';
    dropdown.style.overflowY = 'auto';
    dropdown.style.display = 'none';
    document.body.appendChild(dropdown);

    function positionDropdown() {
      const rect = input.getBoundingClientRect();
      dropdown.style.top = (rect.bottom + 2) + 'px';
      dropdown.style.left = rect.left + 'px';
      dropdown.style.width = rect.width + 'px';
    }

    let timeoutId = null;
    // Session token — reused across keystrokes, refreshed on selection
    let sessionToken = new google.maps.places.AutocompleteSessionToken();
    const autocompleteService = new google.maps.places.AutocompleteService();
    const placesService = new google.maps.places.PlacesService(
      document.createElement('div') // Hidden attribution container
    );

    input.addEventListener('input', function(e) {
      const val = e.target.value.trim();
      dropdown.innerHTML = '';
      if (val.length < 3) {
        dropdown.style.display = 'none';
        return;
      }

      clearTimeout(timeoutId);
      // 500ms debounce — balances responsiveness with cost
      timeoutId = setTimeout(function() {
        autocompleteService.getPlacePredictions({
          input: val,
          sessionToken: sessionToken,
          // Prioritize hospitals/medical — but still show other results
          types: ['establishment', 'geocode']
        }, function(predictions, status) {
          dropdown.innerHTML = '';
          if (status !== google.maps.places.PlacesServiceStatus.OK || !predictions) {
            dropdown.innerHTML = '<div style="padding:8px 12px;color:#94a3b8;font-size:14px;">No results found.</div>';
            positionDropdown();
            dropdown.style.display = 'block';
            return;
          }

          predictions.forEach(function(prediction) {
            const item = document.createElement('div');
            item.style.padding = '10px 12px';
            item.style.cursor = 'pointer';
            item.style.borderBottom = '1px solid rgba(255,255,255,0.08)';
            item.style.fontSize = '14px';
            item.style.color = '#e2e8f0';
            item.style.transition = 'background 0.15s';
            item.textContent = prediction.description;

            item.addEventListener('mouseenter', function() { item.style.background = 'rgba(255,255,255,0.08)'; });
            item.addEventListener('mouseleave', function() { item.style.background = 'transparent'; });

            item.addEventListener('click', function() {
              // Get place details (lat/lng) — billed as one session with the predictions
              placesService.getDetails({
                placeId: prediction.place_id,
                fields: ['name', 'geometry'],
                sessionToken: sessionToken
              }, function(place, detailStatus) {
                if (detailStatus === google.maps.places.PlacesServiceStatus.OK && place.geometry) {
                  input.value = place.name || prediction.structured_formatting.main_text;
                  dropdown.style.display = 'none';
                  onSelectCallback({
                    name: input.value,
                    lat: place.geometry.location.lat(),
                    lng: place.geometry.location.lng()
                  });
                  // Refresh session token for next search session
                  sessionToken = new google.maps.places.AutocompleteSessionToken();
                } else {
                  console.warn('AidFleetMaps: Place details failed', detailStatus);
                }
              });
            });

            dropdown.appendChild(item);
          });

          positionDropdown();
          dropdown.style.display = 'block';
        });
      }, 500);
    });

    // Reposition on scroll/resize
    window.addEventListener('scroll', positionDropdown, true);
    window.addEventListener('resize', positionDropdown);

    // Close dropdown on click outside
    document.addEventListener('click', function(e) {
      if (e.target !== input && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
      }
    });

    return input;
  }
};

(function() {
  if (typeof document.hidden !== 'undefined') {
    document.addEventListener('visibilitychange', function() {
      AidFleetMaps._isPageVisible = !document.hidden;
      if (!document.hidden) {
        // Tab came back — reset ETA cache to force a fresh call on next tick
        AidFleetMaps._lastETAKey = null;
        AidFleetMaps._lastETAResult = null;
      }
    });
  }
})();
