// Kasse: Artikel antippen, Summe sehen, kassieren.
// Abgeschlossene Bestellungen werden für die Auswertung gespeichert. Ist das WLAN
// kurz weg, landen sie in einer Warteschlange und werden später nachgesendet.
(function () {
  'use strict';

  const { products, csrf } = window.KASSE;
  const byId = new Map(products.map((p) => [p.id, p]));
  const cart = new Map(); // productId -> Anzahl

  const $ = (id) => document.getElementById(id);
  const linesEl = $('lines'), totalEl = $('total'), checkoutBtn = $('checkout'), clearBtn = $('clear');
  const dialog = $('pay'), givenEl = $('given'), changeEl = $('change');
  const tiles = new Map([...document.querySelectorAll('.tile')].map((t) => [Number(t.dataset.id), t]));

  const euro = (cents) =>
    (cents / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
  const total = () => [...cart].reduce((sum, [id, qty]) => sum + byId.get(id).price * qty, 0);

  // ---------------------------------------------------------------- Warenkorb
  function change(id, delta) {
    const qty = (cart.get(id) || 0) + delta;
    if (qty > 0) cart.set(id, qty); else cart.delete(id);
    render();
    if (delta > 0) {
      const t = tiles.get(id);
      t.classList.remove('bump'); void t.offsetWidth; t.classList.add('bump');
      if (navigator.vibrate) navigator.vibrate(8);
    }
  }

  function render() {
    for (const [id, tile] of tiles) {
      const qty = cart.get(id) || 0;
      tile.classList.toggle('selected', qty > 0);
      tile.querySelector('.tile-badge').hidden = qty === 0;
      tile.querySelector('.tile-badge').textContent = qty;
      tile.querySelector('.tile-minus').hidden = qty === 0;
    }

    linesEl.replaceChildren();
    if (cart.size === 0) {
      const li = document.createElement('li');
      li.className = 'ticket-empty';
      li.textContent = 'Tippe auf einen Artikel.';
      linesEl.append(li);
    }
    for (const [id, qty] of cart) {
      const p = byId.get(id);
      const li = document.createElement('li');
      li.innerHTML =
        '<span class="line-qty"><button type="button" data-d="-1" aria-label="weniger">−</button>' +
        '<span></span><button type="button" data-d="1" aria-label="mehr">+</button></span>' +
        '<span class="line-name"></span><span class="line-sum"></span>';
      li.querySelector('.line-qty span').textContent = qty;
      const nameEl = li.querySelector('.line-name');
      nameEl.innerHTML = p.iconHtml; // vom Server erzeugt (SVG-Bild aus fester Liste oder escaptes Emoji)
      nameEl.append(' ' + p.name);
      li.querySelector('.line-sum').textContent = euro(p.price * qty);
      li.querySelectorAll('button').forEach((b) => b.addEventListener('click', () => change(id, Number(b.dataset.d))));
      linesEl.append(li);
    }

    const sum = total();
    totalEl.textContent = euro(sum);
    checkoutBtn.disabled = clearBtn.disabled = cart.size === 0;
  }

  for (const [id, tile] of tiles) {
    tile.addEventListener('click', (e) => change(id, e.target.closest('.tile-minus') ? -1 : 1));
  }
  clearBtn.addEventListener('click', () => { cart.clear(); render(); });

  // ------------------------------------------------------------ Kassieren
  checkoutBtn.addEventListener('click', () => {
    const sum = total();
    $('pay-total').textContent = euro(sum);
    changeEl.hidden = true;

    // "Passend" + Betrag aufgerundet auf den nächsten 5er, 10er, 20er, 50er und 100er
    // (z. B. 22,50 € → 25, 30, 40, 50, 100)
    const rounded = [500, 1000, 2000, 5000, 10000].map((step) => Math.ceil(sum / step) * step);
    const options = [sum, ...new Set(rounded.filter((v) => v > sum))];
    givenEl.replaceChildren(...options.map((v, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = i === 0 ? 'Passend' : euro(v).replace(',00', '');
      b.addEventListener('click', () => {
        givenEl.querySelectorAll('button').forEach((x) => x.classList.toggle('on', x === b));
        customEl.value = '';
        showChange(v - sum);
      });
      return b;
    }));
    customEl.value = '';
    dialog.showModal();
  });

  // Freie Eingabe für jeden anderen Betrag
  const customEl = $('given-custom');
  customEl.addEventListener('input', () => {
    givenEl.querySelectorAll('button').forEach((x) => x.classList.remove('on'));
    const cents = Math.round(parseFloat(customEl.value.replace(',', '.')) * 100);
    if (Number.isFinite(cents) && cents > 0) showChange(cents - total());
    else changeEl.hidden = true;
  });
  customEl.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });

  function showChange(back) {
    changeEl.hidden = false;
    changeEl.classList.toggle('short', back < 0);
    changeEl.querySelector('span').textContent = back < 0 ? 'Fehlt noch' : 'Rückgeld';
    $('change-amount').textContent = euro(Math.abs(back));
  }

  dialog.addEventListener('close', () => {
    if (dialog.returnValue !== 'done' || cart.size === 0) return;
    enqueue({
      client_id: uuid(),
      created_at: Date.now(),
      items: [...cart].map(([id, qty]) => ({ id, qty })),
    });
    cart.clear();
    render();
    toast('Bestellung gespeichert');
  });

  // ------------------------------------------------- Speichern / Warteschlange
  const KEY = 'sgr_queue';
  let memoryQueue = [];
  const readQueue = () => { try { return JSON.parse(localStorage.getItem(KEY)) || []; } catch { return memoryQueue; } };
  const writeQueue = (q) => { memoryQueue = q; try { localStorage.setItem(KEY, JSON.stringify(q)); } catch { /* privat */ } };

  function enqueue(order) {
    writeQueue([...readQueue(), order]);
    flush();
  }

  let flushing = false;
  async function flush() {
    if (flushing) return;
    flushing = true;
    try {
      for (const order of readQueue()) {
        const res = await fetch('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
          body: JSON.stringify(order),
          credentials: 'same-origin',
        });
        if (res.status === 401) { toast('Bitte neu anmelden – Bestellungen sind zwischengespeichert'); break; }
        if (!res.ok && res.status !== 422) break; // später erneut versuchen
        writeQueue(readQueue().filter((o) => o.client_id !== order.client_id));
      }
    } catch {
      const n = readQueue().length;
      if (n) toast(`Offline – ${n} Bestellung${n > 1 ? 'en' : ''} wird später gesendet`);
    } finally {
      flushing = false;
    }
  }
  setInterval(flush, 30000);
  window.addEventListener('online', flush);
  flush();

  // ------------------------------------------- Bildschirm an der Theke wach halten
  async function keepAwake() {
    try {
      if ('wakeLock' in navigator && document.visibilityState === 'visible') await navigator.wakeLock.request('screen');
    } catch { /* nicht unterstützt oder abgelehnt – kein Problem */ }
  }
  document.addEventListener('visibilitychange', keepAwake);
  keepAwake();

  // --------------------------------------------------------------- Helfer
  function uuid() {
    if (crypto.randomUUID) return crypto.randomUUID();
    return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
  }

  let toastTimer;
  function toast(msg) {
    const t = $('toast');
    t.textContent = msg;
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.hidden = true; }, 2200);
  }

  render();
})();
