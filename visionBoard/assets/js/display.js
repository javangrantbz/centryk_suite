/* Belize Zoo signage player — cycles through the scheduled playlist. */
(function () {
  'use strict';

  const body    = document.body;
  const API     = body.dataset.api;
  const HEARTBEAT = body.dataset.heartbeat;
  const stage   = document.getElementById('stage');
  const idle    = document.getElementById('idle');
  const idleMsg = document.getElementById('idleMsg');
  const clockEl = document.getElementById('clock');
  const announcement = document.getElementById('announcement');
  const announcementText = document.getElementById('announcementText');
  const weatherWidget = document.getElementById('weatherWidget');
  const weatherTime = document.getElementById('weatherTime');
  const weatherIcon = document.getElementById('weatherIcon');
  const weatherTemp = document.getElementById('weatherTemp');
  const weatherMeta = document.getElementById('weatherMeta');
  const marquee = document.getElementById('marquee');
  const marqueeText = document.getElementById('marqueeText');
  const fsBtn   = document.getElementById('fsBtn');
  const bgAudio = document.getElementById('bgAudio');
  const qrbox   = document.getElementById('qrbox');
  const qrEl    = document.getElementById('qrcode');
  const qrCap   = document.getElementById('qrcap');

  let lastQrKey = null;   // avoids re-rendering the QR on every poll
  let qrTimer = null;     // rotation interval when there are multiple codes
  let qrCodes = [];
  let qrIndex = 0;
  let qrDefaultSeconds = 10;

  let items = [];
  let index = 0;
  let playlistName = null;
  let signature = null;
  let advanceTimer = null;
  let soundAllowed = false;   // becomes true after a user gesture (fullscreen click)
  let activeAnnouncement = null;
  let announcementTimer = null;
  let consecutiveFetchFailures = 0;
  let lastWatchdogTick = Date.now();
  let weatherSettings = null;
  let weatherTimer = null;
  let lastWeatherFetch = 0;
  let backgroundAudioConfig = null;
  const imagePreloadCache = new Map();
  const PLAYER_VERSION = 'ops-2026-07';

  // ---- Data ------------------------------------------------------------
  async function fetchProgram() {
    try {
      const sep = API.includes('?') ? '&' : '?';
      const res = await fetch(API + sep + 't=' + Date.now(), { cache: 'no-store' });
      const data = await res.json();
      consecutiveFetchFailures = 0;
      applySettings(data.settings || {});
      playlistName = data.playlist || null;
      applyAnnouncement(data.announcement || null);
      // Only rebuild the queue if the content actually changed.
      if (data.signature !== signature) {
        signature = data.signature;
        items = data.items || [];
        index = 0;
      }
      return true;
    } catch (e) {
      console.error('Fetch failed', e);
      consecutiveFetchFailures++;
      if (consecutiveFetchFailures >= 10) {
        location.reload();
      }
      return false;
    }
  }

  function applySettings(s) {
    if (s.theme) body.dataset.theme = s.theme;
    if (s.transition) stage.dataset.transition = s.transition;
    const marqueeSeconds = Math.max(8, parseInt(s.marquee_scroll_seconds, 10) || 22);
    marquee.style.setProperty('--marquee-duration', `${marqueeSeconds}s`);

    const marquees = Array.isArray(s.marquees)
      ? s.marquees.filter(m => m && String(m).trim()).map(m => String(m).trim())
      : (s.marquee ? [s.marquee] : []);
    if (marquees.length) {
      marqueeText.textContent = marquees.join('   •   ') + '   •   ';
      marquee.style.display = 'block';
      body.classList.remove('no-marquee');
    } else {
      marquee.style.display = 'none';
      body.classList.add('no-marquee');
    }
    clockEl.style.display = s.show_clock ? 'block' : 'none';
    applyWeatherSettings(s);
    applyBackgroundAudioSettings(s);
    renderQR(s);
  }

  function applyBackgroundAudioSettings(s) {
    const nextConfig = (s.background_audio_enabled && s.background_audio && s.background_audio.url)
      ? {
          id: Number(s.background_audio.id) || 0,
          url: String(s.background_audio.url),
          volume: Math.min(1, Math.max(0, (parseInt(s.background_audio_volume, 10) || 35) / 100))
        }
      : null;

    const changed = JSON.stringify(nextConfig) !== JSON.stringify(backgroundAudioConfig);
    backgroundAudioConfig = nextConfig;

    if (!backgroundAudioConfig) {
      bgAudio.pause();
      bgAudio.removeAttribute('src');
      bgAudio.load();
      return;
    }

    bgAudio.loop = true;
    bgAudio.autoplay = true;
    bgAudio.volume = backgroundAudioConfig.volume;
    if (changed || bgAudio.getAttribute('src') !== backgroundAudioConfig.url) {
      bgAudio.src = backgroundAudioConfig.url;
      bgAudio.load();
    }
    ensureBackgroundAudioPlayback();
  }

  function ensureBackgroundAudioPlayback() {
    if (!backgroundAudioConfig || !bgAudio.getAttribute('src')) return;
    bgAudio.muted = false;
    const playPromise = bgAudio.play();
    if (playPromise && typeof playPromise.catch === 'function') {
      playPromise.catch((e) => {
        console.warn('Background audio play blocked', e);
      });
    }
  }

  function applyWeatherSettings(s) {
    if (!s.weather_widget_enabled) {
      weatherSettings = null;
      weatherWidget.style.display = 'none';
      if (weatherTimer) { clearInterval(weatherTimer); weatherTimer = null; }
      return;
    }

    const nextSettings = {
      lat: Number(s.weather_latitude) || 17.3536,
      lon: Number(s.weather_longitude) || -88.5497,
      label: s.weather_label || 'Local time'
    };
    const changed = !weatherSettings ||
      weatherSettings.lat !== nextSettings.lat ||
      weatherSettings.lon !== nextSettings.lon ||
      weatherSettings.label !== nextSettings.label;

    weatherSettings = nextSettings;
    weatherWidget.style.display = 'block';
    tickBelizeTime();
    if (!weatherTimer) {
      weatherTimer = setInterval(() => {
        tickBelizeTime();
        if (Date.now() - lastWeatherFetch > 15 * 60 * 1000) {
          fetchWeather();
        }
      }, 60000);
    }
    if (changed) {
      fetchWeather();
    }
  }

  function renderQR(s) {
    const list = (s.qr_enabled && Array.isArray(s.qr_codes))
      ? s.qr_codes.filter(q => q && q.url) : [];
    const rotate = Math.max(3, parseInt(s.qr_rotate_seconds, 10) || 10);
    qrDefaultSeconds = rotate;
    const key = JSON.stringify(list) + '|' + rotate;
    if (key === lastQrKey) return;      // nothing changed — leave the current QR as-is
    lastQrKey = key;

    // Reset any running rotation.
    if (qrTimer) { clearTimeout(qrTimer); qrTimer = null; }
    qrCodes = list;
    qrIndex = 0;

    if (!list.length || typeof qrcode !== 'function') {
      qrbox.style.display = 'none';
      return;
    }
    qrbox.style.display = 'block';
    showQR(0);

  }

  function showQR(i) {
    const item = qrCodes[i];
    if (!item) return;
    qrbox.classList.add('swapping');          // fade out
    setTimeout(() => {
      try {
        const qr = qrcode(0, 'M');            // type 0 = auto-size, medium error correction
        qr.addData(item.url);
        qr.make();
        qrEl.innerHTML = qr.createImgTag(6, 8);   // cellSize, margin (in modules)
        qrCap.textContent = item.caption || '';
        qrCap.style.display = item.caption ? 'block' : 'none';
      } catch (e) {
        console.error('QR render failed', e);
      }
      qrbox.classList.remove('swapping');     // fade back in
      if (qrCodes.length > 1) {
        const seconds = Math.max(3, parseInt(item.display_seconds, 10) || qrDefaultSeconds);
        qrTimer = setTimeout(() => {
          qrIndex = (qrIndex + 1) % qrCodes.length;
          showQR(qrIndex);
        }, seconds * 1000);
      }
    }, 250);
  }

  function tickBelizeTime() {
    if (!weatherSettings) return;
    const now = new Date();
    weatherTime.textContent = now.toLocaleTimeString([], {
      timeZone: 'America/Belize',
      hour: 'numeric',
      minute: '2-digit'
    });
  }

  function weatherLabel(code) {
    const map = {
      0: ['Clear', '☀'],
      1: ['Mainly clear', '🌤'],
      2: ['Partly cloudy', '⛅'],
      3: ['Cloudy', '☁'],
      45: ['Fog', '🌫'],
      48: ['Fog', '🌫'],
      51: ['Light drizzle', '🌦'],
      53: ['Drizzle', '🌦'],
      55: ['Heavy drizzle', '🌧'],
      61: ['Light rain', '🌦'],
      63: ['Rain', '🌧'],
      65: ['Heavy rain', '🌧'],
      80: ['Rain showers', '🌦'],
      81: ['Rain showers', '🌧'],
      82: ['Heavy showers', '⛈'],
      95: ['Thunderstorm', '⛈'],
      96: ['Thunderstorm', '⛈'],
      99: ['Thunderstorm', '⛈']
    };
    return map[Number(code)] || ['Weather', '🌤'];
  }

  async function fetchWeather() {
    if (!weatherSettings) return;
    tickBelizeTime();
    lastWeatherFetch = Date.now();
    const url = 'https://api.open-meteo.com/v1/forecast' +
      `?latitude=${encodeURIComponent(weatherSettings.lat)}` +
      `&longitude=${encodeURIComponent(weatherSettings.lon)}` +
      '&current=temperature_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m' +
      '&temperature_unit=fahrenheit&wind_speed_unit=mph&precipitation_unit=inch&timezone=America%2FBelize&forecast_days=1';
    try {
      const res = await fetch(url, { cache: 'no-store' });
      const data = await res.json();
      const current = data.current || {};
      const [label, icon] = weatherLabel(current.weather_code);
      weatherIcon.textContent = icon;
      weatherTemp.textContent = Number.isFinite(current.temperature_2m)
        ? `${Math.round(current.temperature_2m)}°F`
        : '';
      const wind = Number.isFinite(current.wind_speed_10m) ? ` · Wind ${Math.round(current.wind_speed_10m)} mph` : '';
      weatherMeta.textContent = `${weatherSettings.label} · ${label}${wind}`;
    } catch (e) {
      console.error('Weather fetch failed', e);
      weatherIcon.textContent = '🕒';
      weatherTemp.textContent = '';
      weatherMeta.textContent = `${weatherSettings.label} · Belize time`;
    }
  }

  function applyAnnouncement(a) {
    activeAnnouncement = a;
    if (announcementTimer) { clearTimeout(announcementTimer); announcementTimer = null; }
    if (!a || !a.message) {
      announcement.style.display = 'none';
      announcement.dataset.style = '';
      return;
    }
    announcement.dataset.style = a.style || 'notice';
    announcementText.textContent = a.message;
    announcement.style.display = 'flex';

    const expires = Date.parse(a.expires_at || '');
    if (!Number.isNaN(expires)) {
      const ms = Math.max(1000, expires - Date.now());
      announcementTimer = setTimeout(() => {
        activeAnnouncement = null;
        announcement.style.display = 'none';
        fetchProgram();
      }, ms);
    }
  }

  // ---- Playback --------------------------------------------------------
  function clearTimer() {
    if (advanceTimer) { clearTimeout(advanceTimer); advanceTimer = null; }
  }

  function next() {
    clearTimer();
    index++;
    if (index >= items.length) {
      index = 0;
      // Loop finished — refresh program, then continue.
      fetchProgram().finally(play);
      return;
    }
    play();
  }

  function scheduleAdvance(seconds) {
    clearTimer();
    advanceTimer = setTimeout(next, Math.max(1, seconds) * 1000);
  }

  function preloadImage(url) {
    if (!url) {
      return Promise.reject(new Error('Missing image URL'));
    }
    if (imagePreloadCache.has(url)) {
      return imagePreloadCache.get(url);
    }

    const promise = new Promise((resolve, reject) => {
      const img = new Image();
      img.decoding = 'async';
      img.onload = async () => {
        try {
          if (typeof img.decode === 'function') {
            await img.decode();
          }
        } catch (_) {
          // Some browsers reject decode even after onload. The pixels are usable.
        }
        resolve(img);
      };
      img.onerror = () => {
        imagePreloadCache.delete(url);
        reject(new Error(`Failed to load image: ${url}`));
      };
      img.src = url;
    });

    imagePreloadCache.set(url, promise);
    return promise;
  }

  function warmUpcomingImage() {
    const upcoming = nextItem();
    if (upcoming && upcoming.type === 'image' && upcoming.url) {
      preloadImage(upcoming.url).catch(() => {});
    }
  }

  function swapIn(el) {
    el.classList.add('slide');
    const old = stage.querySelector('.slide');
    stage.appendChild(el);
    // Force reflow then fade in.
    requestAnimationFrame(() => el.classList.add('show'));
    if (old) {
      old.classList.remove('show');
      setTimeout(() => old.remove(), 900);
    }
  }

  async function play() {
    if (!items.length) { showIdle('No content is scheduled right now.'); return; }
    hideIdle();

    const item = items[index];
    sendHeartbeat(activeAnnouncement ? 'announcement' : 'playing');
    let el;

    if (item.type === 'image') {
      const text = displayText(item);
      el = document.createElement('div');
      el.className = 'media-slide';
      const backdrop = document.createElement('div');
      backdrop.className = 'media-backdrop';
      backdrop.style.backgroundImage = `url("${item.url}")`;
      let loaded;
      try {
        loaded = await preloadImage(item.url);
      } catch (e) {
        console.error('Image preload failed', e);
        scheduleAdvance(Math.min(item.duration || 10, 5));
        return;
      }
      if (items[index] !== item) {
        return;
      }
      const img = loaded.cloneNode(false);
      img.alt = text.title || text.subtitle || '';
      el.appendChild(backdrop);
      el.appendChild(img);
      if (text.title || text.subtitle) el.appendChild(caption(text));
      swapIn(el);
      scheduleAdvance(item.duration);
      warmUpcomingImage();

    } else if (item.type === 'video') {
      const text = displayText(item);
      el = document.createElement('div');
      el.className = 'media-slide';
      const v = document.createElement('video');
      v.src = item.url;
      v.autoplay = true;
      v.muted = backgroundAudioConfig ? true : !soundAllowed;
      v.playsInline = true;
      v.addEventListener('ended', next, { once: true });
      // Safety net: if the video stalls, don't freeze the display forever.
      v.addEventListener('error', () => scheduleAdvance(item.duration || 10), { once: true });
      el.appendChild(v);
      if (text.title || text.subtitle) el.appendChild(caption(text));
      swapIn(el);
      v.play().catch(() => { v.muted = true; v.play().catch(()=>{}); });
      // Fallback advance in case 'ended' never fires (e.g. corrupt file).
      scheduleAdvance((item.duration || 60) + 600);
      warmUpcomingImage();

    } else { // biography / text card
      const text = displayText(item);
      el = document.createElement('div');
      el.className = 'bio-slide';
      el.innerHTML =
        (text.title ? `<h1>${escapeHtml(text.title)}</h1>` : '') +
        (text.subtitle ? `<h2>${escapeHtml(text.subtitle)}</h2>` : '') +
        (item.body ? `<div class="bio-body">${escapeHtml(item.body).replace(/\n/g, '<br>')}</div>` : '');
      swapIn(el);
      scheduleAdvance(item.duration);
      warmUpcomingImage();
    }
  }

  function caption(item) {
    const c = document.createElement('div');
    c.className = 'caption';
    c.innerHTML = (item.title ? `<span class="c-title">${escapeHtml(item.title)}</span>` : '') +
                  (item.subtitle ? `<span class="c-sub">${escapeHtml(item.subtitle)}</span>` : '');
    return c;
  }

  function showIdle(msg) { idle.style.display = 'flex'; idleMsg.textContent = msg; }
  function hideIdle() { idle.style.display = 'none'; }

  function cleanCaptionText(value) {
    const text = String(value || '').trim();
    if (!text) return '';
    const looksLikeFile = /^[^\\\/]+\.(avif|bmp|gif|jpe?g|png|svg|webp|mp4|mov|m4v|webm|avi)$/i.test(text);
    return looksLikeFile ? '' : text;
  }

  function normalizeName(value) {
    return String(value || '')
      .trim()
      .toLowerCase()
      .replace(/\.[a-z0-9]+$/i, '')
      .replace(/[%_+\-.]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function displayText(item) {
    if (!item) return { title: '', subtitle: '' };
    const title = cleanCaptionText(item.title);
    const subtitle = cleanCaptionText(item.subtitle);
    const originalStem = normalizeName(item.original_name);
    const titleMatchesOriginal = title && originalStem && normalizeName(title) === originalStem;

    return {
      title: titleMatchesOriginal ? '' : title,
      subtitle
    };
  }

  function currentItem() {
    return items[index] || null;
  }

  function nextItem() {
    if (!items.length) return null;
    return items[(index + 1) % items.length] || null;
  }

  function itemLabel(item) {
    if (!item) return null;
    return item.title || item.subtitle || item.type || 'Untitled item';
  }

  async function sendHeartbeat(state, error) {
    if (!HEARTBEAT) return;
    const current = currentItem();
    const upcoming = nextItem();
    try {
      await fetch(HEARTBEAT, {
        method: 'POST',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          current_title: activeAnnouncement ? 'Announcement override' : itemLabel(current),
          current_type: activeAnnouncement ? 'announcement' : (current && current.type),
          current_index: items.length ? index + 1 : null,
          next_title: itemLabel(upcoming),
          next_type: upcoming && upcoming.type,
          playlist_name: playlistName,
          item_count: items.length,
          player_state: state || (activeAnnouncement ? 'announcement' : 'playing'),
          client_time: new Date().toISOString(),
          client_version: PLAYER_VERSION,
          last_error: error || null
        })
      });
    } catch (e) {
      console.error('Heartbeat failed', e);
    }
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  // ---- Clock -----------------------------------------------------------
  function tickClock() {
    const now = new Date();
    clockEl.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }
  setInterval(tickClock, 1000); tickClock();

  // ---- Fullscreen ------------------------------------------------------
  fsBtn.addEventListener('click', () => {
    soundAllowed = true; // user gesture — future videos may play with sound
    ensureBackgroundAudioPlayback();
    const el = document.documentElement;
    if (!document.fullscreenElement) (el.requestFullscreen || el.webkitRequestFullscreen || function(){}).call(el);
    else document.exitFullscreen && document.exitFullscreen();
  });
  // Auto-hide the fullscreen button after a few seconds of no mouse movement.
  let hideBtn;
  function pokeBtn() {
    fsBtn.classList.remove('hidden');
    clearTimeout(hideBtn);
    hideBtn = setTimeout(() => fsBtn.classList.add('hidden'), 4000);
  }
  document.addEventListener('mousemove', pokeBtn); pokeBtn();

  // ---- Periodic refresh (catches schedule changes even mid-playlist) ---
  setInterval(fetchProgram, 15000);
  setInterval(() => sendHeartbeat(activeAnnouncement ? 'announcement' : (items.length ? 'playing' : 'idle')), 10000);

  // If the browser sleeps, freezes, or the PC resumes after a long pause, start
  // from a clean page state instead of trusting stale timers/media elements.
  setInterval(() => {
    const now = Date.now();
    if (now - lastWatchdogTick > 180000) {
      location.reload();
      return;
    }
    lastWatchdogTick = now;
  }, 30000);

  // ---- Boot ------------------------------------------------------------
  (async function boot() {
    const ok = await fetchProgram();
    if (!ok) {
      showIdle('Cannot reach the server. Retrying...');
      sendHeartbeat('offline', 'Cannot reach the server');
      setTimeout(boot, 8000);
      return;
    }
    play();
  })();
})();
