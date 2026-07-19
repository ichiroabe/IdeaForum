// 付箋ボード。外部ライブラリなし。
// 付箋は絶対配置の div、つなぐ線は背面の SVG で描く。
(function () {
  'use strict';

  const root = document.getElementById('board');
  if (!root) return;

  const ideaId = root.dataset.ideaId;
  const base = root.dataset.base || '';
  const csrf = root.dataset.csrf;
  const api = base + '/ideas/' + ideaId;

  const canvas = root.querySelector('.board-canvas');
  const svg = root.querySelector('.board-lines');
  // 見出し行や凡例は #board の外にあるので document から探す
  const status = document.querySelector('.board-status');
  const legend = document.querySelector('.board-legend');
  const panel = document.querySelector('.board-panel');

  const NOTE_W = 180;
  const NOTE_H = 110;

  let notes = [];
  let links = [];
  let colors = {};
  let canEdit = false;
  let isAdmin = false;
  let me = null;
  let linkFrom = null;

  function say(message, isError) {
    if (!status) return;
    status.textContent = message || '';
    status.classList.toggle('is-error', !!isError);
    if (message && !isError) {
      setTimeout(() => { if (status.textContent === message) status.textContent = ''; }, 2500);
    }
  }

  async function call(path, options) {
    const res = await fetch(api + path, Object.assign({
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': csrf
      },
      credentials: 'same-origin'
    }, options));

    let data = null;
    try { data = await res.json(); } catch (e) { /* 本文が空の場合 */ }
    if (!res.ok) {
      const err = new Error((data && data.error) || ('通信に失敗しました (' + res.status + ')'));
      err.reasonRequired = !!(data && data.reasonRequired);
      throw err;
    }
    return data;
  }

  const isMine = n => me !== null && n.userId === me;

  // 他人の付箋を触るときは理由を必ず聞く。null を返したら操作中止。
  function askReason(n, what) {
    if (isMine(n)) return '';
    const r = prompt(
      '他の人が作った付箋を' + what + 'します。\n' +
      '理由を書いてください(履歴に残り、他の人にも見えます)',
      ''
    );
    if (r === null) return null;
    const t = r.trim();
    if (t === '') { say('理由が未入力のため中止しました。', true); return null; }
    return t;
  }

  // --- 描画 -----------------------------------------------------------------

  function render() {
    canvas.querySelectorAll('.sticky').forEach(el => el.remove());
    notes.forEach(n => canvas.appendChild(buildNote(n)));
    drawLines();
    resizeCanvas();
  }

  function buildNote(n) {
    const el = document.createElement('div');
    el.className = 'sticky sticky-' + n.color + (n.deleted ? ' is-deleted' : '');
    el.dataset.id = n.id;
    el.style.left = n.x + 'px';
    el.style.top = n.y + 'px';
    if (linkFrom === n.id) el.classList.add('is-link-source');

    const body = document.createElement('div');
    body.className = 'sticky-body';
    body.textContent = n.body;
    el.appendChild(body);

    const meta = document.createElement('div');
    meta.className = 'sticky-meta';
    meta.textContent = n.author + (n.postId ? ' (返信より)' : '') + (n.deleted ? ' [削除済]' : '');
    el.appendChild(meta);

    const tools = document.createElement('div');
    tools.className = 'sticky-tools';
    if (n.events > 0) {
      // 件数を出して「押せば履歴が開く」と分かるようにする
      const h = toolButton('履' + n.events, 'この付箋の履歴を見る (' + n.events + '件)',
        e => { e.stopPropagation(); showHistory(n); });
      h.classList.add('has-history');
      tools.appendChild(h);
    }
    if (canEdit && !n.deleted) {
      tools.appendChild(toolButton('連', 'ここから線をつなぐ', e => { e.stopPropagation(); startLink(n.id); }));
      tools.appendChild(toolButton('編', '文面を変える', e => { e.stopPropagation(); editNote(n); }));
      tools.appendChild(toolButton('色', '色で分類する', e => { e.stopPropagation(); openPalette(n, el); }));
      tools.appendChild(toolButton('×', 'この付箋を削除', e => { e.stopPropagation(); removeNote(n); }));
      makeDraggable(el, n);
    }
    if (isAdmin && n.deleted) {
      tools.appendChild(toolButton('復', 'この付箋を復元', e => { e.stopPropagation(); restoreNote(n); }));
    }
    if (tools.children.length) el.appendChild(tools);

    el.addEventListener('click', () => {
      if (linkFrom !== null && linkFrom !== n.id && !n.deleted) finishLink(n.id);
    });
    return el;
  }

  function toolButton(label, title, onClick) {
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = label;
    b.title = title;
    b.addEventListener('click', onClick);
    return b;
  }

  function drawLines() {
    svg.innerHTML =
      '<defs><marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" ' +
      'markerWidth="6" markerHeight="6" orient="auto-start-reverse">' +
      '<path d="M 0 0 L 10 5 L 0 10 z" fill="#94a3b8"/></marker></defs>';

    links.forEach(l => {
      const a = notes.find(n => n.id === l.from);
      const b = notes.find(n => n.id === l.to);
      if (!a || !b) return;

      const x1 = a.x + NOTE_W / 2, y1 = a.y + NOTE_H / 2;
      const x2 = b.x + NOTE_W / 2, y2 = b.y + NOTE_H / 2;

      const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
      line.setAttribute('x1', x1); line.setAttribute('y1', y1);
      line.setAttribute('x2', x2); line.setAttribute('y2', y2);
      line.setAttribute('class', 'board-line');
      line.setAttribute('marker-end', 'url(#arrow)');
      if (canEdit) {
        line.style.cursor = 'pointer';
        line.addEventListener('click', () => removeLink(l));
        const t = document.createElementNS('http://www.w3.org/2000/svg', 'title');
        t.textContent = 'クリックでこの線を削除';
        line.appendChild(t);
      }
      svg.appendChild(line);

      if (l.label) {
        const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        text.setAttribute('x', (x1 + x2) / 2);
        text.setAttribute('y', (y1 + y2) / 2 - 4);
        text.setAttribute('class', 'board-line-label');
        text.setAttribute('text-anchor', 'middle');
        text.textContent = l.label;
        svg.appendChild(text);
      }
    });
  }

  function resizeCanvas() {
    let maxX = 900, maxY = 500;
    notes.forEach(n => {
      maxX = Math.max(maxX, n.x + NOTE_W + 60);
      maxY = Math.max(maxY, n.y + NOTE_H + 60);
    });
    canvas.style.width = maxX + 'px';
    canvas.style.height = maxY + 'px';
    svg.setAttribute('width', maxX);
    svg.setAttribute('height', maxY);
    svg.setAttribute('viewBox', '0 0 ' + maxX + ' ' + maxY);
  }

  function buildLegend() {
    if (!legend) return;
    legend.innerHTML = '';
    Object.keys(colors).forEach(key => {
      const item = document.createElement('span');
      item.className = 'legend-item';
      const swatch = document.createElement('i');
      swatch.className = 'legend-swatch sticky-' + key;
      item.appendChild(swatch);
      item.appendChild(document.createTextNode(colors[key]));
      legend.appendChild(item);
    });
  }

  // --- 操作 -----------------------------------------------------------------

  function makeDraggable(el, n) {
    let startX, startY, originX, originY, moved = false;

    el.addEventListener('pointerdown', ev => {
      if (ev.target.closest('.sticky-tools')) return;
      el.setPointerCapture(ev.pointerId);
      startX = ev.clientX; startY = ev.clientY;
      originX = n.x; originY = n.y;
      moved = false;
      el.classList.add('is-dragging');
    });

    el.addEventListener('pointermove', ev => {
      if (!el.hasPointerCapture(ev.pointerId)) return;
      const dx = ev.clientX - startX, dy = ev.clientY - startY;
      if (Math.abs(dx) > 3 || Math.abs(dy) > 3) moved = true;
      n.x = Math.max(0, originX + dx);
      n.y = Math.max(0, originY + dy);
      el.style.left = n.x + 'px';
      el.style.top = n.y + 'px';
      drawLines();
    });

    el.addEventListener('pointerup', async ev => {
      if (!el.hasPointerCapture(ev.pointerId)) return;
      el.releasePointerCapture(ev.pointerId);
      el.classList.remove('is-dragging');
      if (!moved) return;
      resizeCanvas();
      // 位置だけの移動は理由も履歴も不要
      try {
        await call('/notes/' + n.id, { method: 'PATCH', body: JSON.stringify({ pos_x: n.x, pos_y: n.y }) });
      } catch (e) {
        say(e.message, true);
      }
    });
  }

  async function addNote(body, sourcePostId) {
    const x = 40 + (notes.length % 5) * 200;
    const y = 40 + Math.floor(notes.length / 5) * 140;
    try {
      const data = await call('/notes', {
        method: 'POST',
        body: JSON.stringify({ body: body, pos_x: x, pos_y: y, source_post_id: sourcePostId || null })
      });
      notes.push(data.note);
      render();
      say('付箋を追加しました。');
    } catch (e) {
      say(e.message, true);
    }
  }

  async function editNote(n) {
    const body = prompt('付箋の内容', n.body);
    if (body === null) return;
    const trimmed = body.trim();
    if (trimmed === '') { say('内容が空です。', true); return; }
    if (trimmed === n.body) return;

    const reason = askReason(n, '変更');
    if (reason === null) return;

    try {
      await call('/notes/' + n.id, {
        method: 'PATCH',
        body: JSON.stringify({ body: trimmed, reason: reason })
      });
      n.body = trimmed;
      n.events++;
      render();
      say('変更しました。');
    } catch (e) {
      say(e.message, true);
    }
  }

  // 色のパレットを付箋のそばに開く
  function openPalette(n, el) {
    closePalette();
    const box = document.createElement('div');
    box.className = 'palette';
    Object.keys(colors).forEach(key => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'palette-swatch sticky-' + key + (n.color === key ? ' is-current' : '');
      b.title = colors[key];
      b.textContent = colors[key];
      b.addEventListener('click', ev => { ev.stopPropagation(); closePalette(); setColor(n, key); });
      box.appendChild(b);
    });
    el.appendChild(box);
    setTimeout(() => document.addEventListener('click', closePalette, { once: true }), 0);
  }

  function closePalette() {
    document.querySelectorAll('.palette').forEach(p => p.remove());
  }

  async function setColor(n, color) {
    if (color === n.color) return;
    const reason = askReason(n, '色替え');
    if (reason === null) return;
    try {
      await call('/notes/' + n.id, {
        method: 'PATCH',
        body: JSON.stringify({ color: color, reason: reason })
      });
      n.color = color;
      n.events++;
      render();
      say('色を変えました。');
    } catch (e) {
      say(e.message, true);
    }
  }

  async function removeNote(n) {
    const reason = askReason(n, '削除');
    if (reason === null) return;
    if (isMine(n) && !confirm('この付箋を削除します。よろしいですか?')) return;

    try {
      await call('/notes/' + n.id, { method: 'DELETE', body: JSON.stringify({ reason: reason }) });
      if (isAdmin) {
        n.deleted = true;
        n.events++;
      } else {
        notes = notes.filter(x => x.id !== n.id);
      }
      links = links.filter(l => l.from !== n.id && l.to !== n.id);
      render();
      say('削除しました。管理者が復元できます。');
    } catch (e) {
      say(e.message, true);
    }
  }

  async function restoreNote(n) {
    try {
      await call('/notes/' + n.id + '/restore', { method: 'POST', body: '{}' });
      n.deleted = false;
      n.events++;
      render();
      say('復元しました。');
    } catch (e) {
      say(e.message, true);
    }
  }

  function startLink(id) {
    linkFrom = (linkFrom === id) ? null : id;
    say(linkFrom ? 'つなぎ先の付箋をクリックしてください(もう一度「連」で取消)' : '');
    render();
  }

  async function finishLink(toId) {
    const fromId = linkFrom;
    linkFrom = null;
    try {
      const data = await call('/links', { method: 'POST', body: JSON.stringify({ from: fromId, to: toId }) });
      links.push(data.link);
      render();
      say('つなぎました。');
    } catch (e) {
      render();
      say(e.message, true);
    }
  }

  async function removeLink(l) {
    if (!confirm('この線を削除しますか?')) return;
    try {
      await call('/links/' + l.id, { method: 'DELETE' });
      links = links.filter(x => x.id !== l.id);
      render();
    } catch (e) {
      say(e.message, true);
    }
  }

  // --- 履歴 -----------------------------------------------------------------

  const ACTION_LABEL = {
    create: '作成', edit: '文面変更', color: '色変更', delete: '削除', restore: '復元'
  };

  async function showHistory(n) {
    if (!panel) return;
    panel.innerHTML = '<p class="note">読み込み中...</p>';
    panel.hidden = false;

    try {
      const data = await call('/notes/' + n.id + '/history', { method: 'GET' });
      panel.innerHTML = '';

      const head = document.createElement('div');
      head.className = 'panel-head';
      const h = document.createElement('h3');
      h.textContent = '付箋の履歴';
      head.appendChild(h);
      const close = document.createElement('button');
      close.type = 'button';
      close.className = 'btn';
      close.textContent = '閉じる';
      close.addEventListener('click', () => { panel.hidden = true; });
      head.appendChild(close);
      panel.appendChild(head);

      const quote = document.createElement('p');
      quote.className = 'panel-quote';
      quote.textContent = n.body;
      panel.appendChild(quote);

      const list = document.createElement('ol');
      list.className = 'history';
      data.events.forEach(ev => {
        const li = document.createElement('li');

        const line = document.createElement('div');
        line.className = 'history-head';
        line.textContent = ev.at + ' ' + ev.actor + ' が' + (ACTION_LABEL[ev.action] || ev.action);
        li.appendChild(line);

        if (ev.reason) {
          const r = document.createElement('div');
          r.className = 'history-reason';
          r.textContent = '理由: ' + ev.reason;
          li.appendChild(r);
        }
        if (ev.action === 'edit' && ev.beforeBody !== null) {
          const d = document.createElement('div');
          d.className = 'history-diff';
          d.textContent = '変更前: ' + ev.beforeBody;
          li.appendChild(d);
        }
        if (ev.action === 'color') {
          const d = document.createElement('div');
          d.className = 'history-diff';
          d.textContent = (colors[ev.beforeColor] || ev.beforeColor) + ' → ' + (colors[ev.afterColor] || ev.afterColor);
          li.appendChild(d);
        }
        if (ev.action === 'delete' && ev.beforeBody !== null) {
          const d = document.createElement('div');
          d.className = 'history-diff';
          d.textContent = '削除時の内容: ' + ev.beforeBody;
          li.appendChild(d);
        }
        list.appendChild(li);
      });
      panel.appendChild(list);
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (e) {
      panel.innerHTML = '';
      const p = document.createElement('p');
      p.className = 'note';
      p.textContent = e.message;
      panel.appendChild(p);
    }
  }

  // --- 起動 -----------------------------------------------------------------

  document.querySelector('.board-add')?.addEventListener('click', () => {
    const body = prompt('付箋の内容');
    if (body === null) return;
    const trimmed = body.trim();
    if (trimmed === '') { say('内容が空です。', true); return; }
    addNote(trimmed, null);
  });

  document.querySelectorAll('[data-note-from-post]').forEach(btn => {
    btn.addEventListener('click', () => {
      addNote((btn.dataset.noteBody || '').slice(0, 500), btn.dataset.noteFromPost);
      root.scrollIntoView({ behavior: 'smooth' });
    });
  });

  (async function load() {
    try {
      const data = await call('/notes', { method: 'GET' });
      canEdit = data.canEdit;
      isAdmin = data.isAdmin;
      me = data.me;
      colors = data.colors;
      notes = data.notes;
      links = data.links;
      root.classList.toggle('can-edit', canEdit);
      buildLegend();
      render();
    } catch (e) {
      say(e.message, true);
    }
  })();
})();
