(function () {
  'use strict';

  var scriptEl = document.currentScript;

  var apiUrl = (function () {
    if (scriptEl && scriptEl.dataset.api) return scriptEl.dataset.api;
    var src = (scriptEl && scriptEl.src) || '';
    return src.replace(/\/ct\.js(\?.*)?$/, '/api/assign.php');
  })();

  // ── Форматирование номера ─────────────────────────────────────────
  function formatPhone(raw) {
    var d = raw.replace(/\D/g, '');
    if (d.length === 11 && d[0] === '7') {
      return {
        full:    '+7 (' + d.slice(1,4) + ') ' + d.slice(4,7) + '-' + d.slice(7,9) + '-' + d.slice(9,11),
        visible: '+7 (',
        hidden:  d.slice(1,4) + ') ' + d.slice(4,7) + '-' + d.slice(7,9) + '-' + d.slice(9,11)
      };
    }
    return { full: raw, visible: raw, hidden: '' };
  }

  // ── UTM: сохраняем при первом заходе, читаем из sessionStorage ───
  var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
  var SS_KEY   = 'ct_utms';

  function getUtms() {
    var p    = new URLSearchParams(window.location.search);
    var fromUrl = {};
    UTM_KEYS.forEach(function (k) { fromUrl[k] = p.get(k) || ''; });

    // Если в URL есть хотя бы один UTM — сохраняем/перезаписываем
    var hasUtm = UTM_KEYS.some(function (k) { return fromUrl[k]; });
    try {
      if (hasUtm) {
        sessionStorage.setItem(SS_KEY, JSON.stringify(fromUrl));
      } else {
        // Нет UTM в URL — пробуем взять сохранённые
        var saved = sessionStorage.getItem(SS_KEY);
        if (saved) return JSON.parse(saved);
      }
    } catch (e) {}

    return fromUrl;
  }

  // ── ClientID Яндекс.Метрики ───────────────────────────────────────
  function getClientId(cb) {
    try {
      var lsVal = localStorage.getItem('_ym_uid');
      if (lsVal) {
        var digits = lsVal.replace(/"/g, '').match(/\d+/);
        if (digits) { cb(digits[0]); return; }
      }
    } catch (e) {}

    var m = document.cookie.match(/_ym_uid=(\d+)/);
    if (m) { cb(m[1]); return; }

    var counterId = scriptEl && scriptEl.dataset.counter;
    if (counterId && window.ym) {
      try {
        // Таймаут 1.5с: если Метрика заблокирована — callback не придёт никогда
        var done = false;
        var t = setTimeout(function () { if (!done) { done = true; cb(''); } }, 1500);
        ym(counterId, 'getClientID', function (id) {
          if (!done) { done = true; clearTimeout(t); cb(id || ''); }
        });
        return;
      } catch (e) {}
    }
    cb('');
  }

  // ── SVG иконки ────────────────────────────────────────────────────
  var PHONE_SVG =
    '<svg class="ct-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
    'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
    '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 ' +
    '19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 ' +
    '12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 ' +
    '2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>' +
    '</svg>';

  // ── Стили ─────────────────────────────────────────────────────────
  function injectStyles() {
    if (document.getElementById('ct-styles')) return;
    var css = [
      /* Обёртка */
      '.ct-phone-reveal{position:relative;display:inline-flex;align-items:center;' +
        'font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Courier New",monospace;' +
        'font-weight:500;letter-spacing:.02em;user-select:none}',

      /* Текст номера */
      '.ct-phone-text{white-space:nowrap}',
      /* Видимая часть */
      '.ct-phone-visible{}',
      /* Fade-часть: последние цифры уходят в прозрачность под кнопку */
      '.ct-phone-fade{display:inline-block;-webkit-mask-image:linear-gradient(to right,rgba(0,0,0,.68) 0%,transparent 53%);mask-image:linear-gradient(to right,rgba(0,0,0,.68) 0%,transparent 53%);padding-right:6.5ch}',
      /* После раскрытия — маска снимается */
      '.ct-phone-reveal.ct-revealed .ct-phone-fade{-webkit-mask-image:none;mask-image:none;padding-right:0;transition:padding .3s}',

      /* Кнопка «Показать» — абсолютно поверх хвоста номера */
      '.ct-phone-btn{position:absolute;right:0;top:50%;transform:translateY(-50%);' +
        'display:inline-flex;align-items:center;gap:4px;' +
        'padding:4px 10px;' +
        'background:#f3f4f6;color:#2563eb;' +
        'border-radius:999px;border:1px solid #d1d5db;' +
        'font-size:.8em;font-weight:500;font-family:inherit;' +
        'cursor:pointer;white-space:nowrap;' +
        'transition:background .15s,border-color .15s,opacity .25s,transform .1s;' +
        'z-index:2}',
      '.ct-phone-btn:hover{background:#e5e7eb;border-color:#93c5fd}',
      '.ct-phone-btn:active{transform:translateY(-50%) translateY(1px)}',

      /* Иконка с пульсом */
      '.ct-btn-icon-wrap{position:relative;display:inline-flex;align-items:center;justify-content:center}',
      '.ct-btn-icon{width:13px;height:13px;animation:ct-ring 1.4s ease-in-out infinite}',
      '.ct-btn-icon-wrap::before,.ct-btn-icon-wrap::after{content:"";position:absolute;' +
        'width:20px;height:20px;border-radius:50%;border:2px solid rgba(37,99,235,.4);' +
        'transform:scale(.4);opacity:0;pointer-events:none;animation:ct-pulse 1.8s ease-out infinite}',
      '.ct-btn-icon-wrap::after{animation-delay:.6s}',

      /* После раскрытия — кнопка уходит */
      '.ct-phone-reveal.ct-revealed .ct-phone-btn{opacity:0;pointer-events:none}',

      /* Анимации */
      '@keyframes ct-ring{0%{transform:rotate(0)}15%{transform:rotate(-12deg) scale(1.05)}' +
        '30%{transform:rotate(10deg) scale(1.05)}45%{transform:rotate(-8deg) scale(1.05)}' +
        '60%,100%{transform:rotate(0)}}',
      '@keyframes ct-pulse{0%{transform:scale(.4);opacity:0}20%{opacity:.6}' +
        '100%{transform:scale(1.1);opacity:0}}',

      /* Toast */
      '.ct-toast{position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(16px);' +
        'background:#1d4ed8;color:#fff;font-family:monospace;font-size:11px;letter-spacing:.1em;' +
        'padding:10px 28px;border-radius:100px;opacity:0;' +
        'transition:opacity .3s,transform .3s;pointer-events:none;z-index:9999}',
      '.ct-toast.ct-show{opacity:1;transform:translateX(-50%) translateY(0)}'
    ].join('');

    var s = document.createElement('style');
    s.id = 'ct-styles';
    s.textContent = css;
    document.head.appendChild(s);
  }

  // ── Toast ─────────────────────────────────────────────────────────
  var toastEl = null;
  function showToast(msg) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'ct-toast';
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = msg;
    toastEl.classList.add('ct-show');
    setTimeout(function () { toastEl.classList.remove('ct-show'); }, 2000);
  }

  // ── Разметка виджета ─────────────────────────────────────────────
  function renderWidget(el) {
    var fallback = (el.dataset.ctPhone || '').trim();
    var fmt = fallback ? formatPhone(fallback) : null;

    el.innerHTML =
      '<div class="ct-phone-reveal" data-ct-widget>' +
        '<span class="ct-phone-text">' +
          '<span class="ct-phone-visible">' + (fmt ? fmt.visible : '+7 (') + '</span>' +
          '<span class="ct-phone-fade">'   + (fmt ? fmt.hidden  : '•••) •••-••-••') + '</span>' +
        '</span>' +
        '<button type="button" class="ct-phone-btn" aria-expanded="false" aria-label="Показать номер">' +
          '<span class="ct-btn-label">Показать</span>' +
          '<span class="ct-btn-icon-wrap">' + PHONE_SVG + '</span>' +
        '</button>' +
      '</div>';

    var widget = el.querySelector('[data-ct-widget]');
    // Сохраняем fallback-номер на виджете для показа при ошибке API/сети
    if (fmt) widget.dataset.fallback = fmt.full;
    widget.addEventListener('click', function () {
      handleClick(widget);
    });
  }

  // ── Раскрытие номера ──────────────────────────────────────────────
  function revealPhone(widget, phone) {
    var fmt = formatPhone(phone);
    widget.dataset.fullPhone = fmt.full;
    widget.querySelector('.ct-phone-visible').textContent = fmt.visible;
    widget.querySelector('.ct-phone-fade').textContent    = fmt.hidden;
    widget.querySelector('.ct-phone-btn').setAttribute('aria-expanded', 'true');
    widget.classList.add('ct-revealed');
  }

  // ── Кэш clientId: получаем при загрузке страницы, до клика ──────
  var _cachedClientId  = '';
  var _clientIdFetched = false;

  function prefetchClientId() {
    getClientId(function (id) {
      _cachedClientId  = id || '';
      _clientIdFetched = true;
    });
  }

  // ── Клик: запрос к API → раскрытие ───────────────────────────────
  function handleClick(widget) {
    // Уже раскрыт — копируем
    if (widget.classList.contains('ct-revealed')) {
      var full = widget.dataset.fullPhone;
      if (!full) return;
      if (navigator.clipboard) {
        navigator.clipboard.writeText(full)
          .then(function () { showToast('Номер скопирован'); })
          .catch(function () { showToast(full); });
      } else {
        showToast(full);
      }
      return;
    }

    if (widget.dataset.loading) return;
    widget.dataset.loading = '1';

    // clientId уже получен на старте — идём в API без задержки.
    // Если по какой-то причине ещё не готов — используем пустую строку.
    var clientId = _cachedClientId;
    var utms     = getUtms();
    var params   = new URLSearchParams({
      client_id:    clientId,
      landing_page: window.location.href,
      referrer:     document.referrer || ''
    });
    Object.keys(utms).forEach(function (k) { params.set(k, utms[k]); });

    fetch(apiUrl + '?' + params.toString())
      .then(function (r) { return r.json(); })
      .then(function (data) {
        delete widget.dataset.loading;
        // Показываем номер из API или fallback из data-ct-phone
        var phone = data.phone || widget.dataset.fallback;
        if (phone) revealPhone(widget, phone);
      })
      .catch(function () {
        delete widget.dataset.loading;
        // Сеть/сервер недоступны — показываем fallback номер
        if (widget.dataset.fallback) revealPhone(widget, widget.dataset.fallback);
      });
  }

  // ── Инициализация ─────────────────────────────────────────────────
  function init() {
    injectStyles();
    prefetchClientId(); // получаем clientId в фоне, до клика пользователя
    var els = document.querySelectorAll('[data-ct-phone]');
    for (var i = 0; i < els.length; i++) renderWidget(els[i]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
