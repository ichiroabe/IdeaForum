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
  // 見出し行にあるため #board の外。document から探す。
  const status = document.querySelector('.board-status');

  const NOTE_W = 180;
  const NOTE_H = 110;

  let notes = [];
  let links = [];
  let canEdit = false;
  let linkFrom = null; // 連結モードで始点に選ばれた付箋ID

  function say(message, isError) {
    if (!status) return;   // 表示先が無くても操作自体は続行させる
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
      throw new Error((data && data.error) || ('通信に失敗しました (' + res.status + ')'));
    }
    return data;
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
    el.className = 'sticky sticky-' + n.color;
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
    meta.textContent = n.author + (n.postId ? ' (返信より)' : '');
    el.appendChild(meta);

    if (canEdit) {
      const tools = document.createElement('div');
      tools.className = 'sticky-tools';
      tools.appendChild(toolButton('連', 'ここから線をつなぐ', e => { e.stopPropagation(); startLink(n.id); }));
      tools.appendChild(toolButton('編', '文面を変える', e => { e.stopPropagation(); editNote(n); }));
      tools.appendChild(toolButton('色', '色を変える', e => { e.stopPropagation(); cycleColor(n); }));
      tools.appendChild(toolButton('×', 'この付箋を削除', e => { e.stopPropagation(); removeNote(n); }));
      el.appendChild(tools);
      makeDraggable(el, n);
    }

    el.addEventListener('click', () => {
      if (linkFrom !== null && linkFrom !== n.id) finishLink(n.id);
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

  // 付箋が右下へ広がってもスクロールで追えるようにする
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
      try {
        await call('/notes/' + n.id, { method: 'PATCH', body: JSON.stringify({ pos_x: n.x, pos_y: n.y }) });
      } catch (e) {
        say(e.message, true);
      }
    });
  }

  async function addNote(body, sourcePostId) {
    // 既存の付箋と重ならない位置を適当に選ぶ
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
    try {
      await call('/notes/' + n.id, { method: 'PATCH', body: JSON.stringify({ body: trimmed }) });
      n.body = trimmed;
      render();
    } catch (e) {
      say(e.message, true);
    }
  }

  async function cycleColor(n) {
    const colors = ['yellow', 'blue', 'green', 'pink', 'gray'];
    const next = colors[(colors.indexOf(n.color) + 1) % colors.length];
    try {
      await call('/notes/' + n.id, { method: 'PATCH', body: JSON.stringify({ color: next }) });
      n.color = next;
      render();
    } catch (e) {
      say(e.message, true);
    }
  }

  async function removeNote(n) {
    if (!confirm('この付箋を削除します。つながっている線も消えます。')) return;
    try {
      await call('/notes/' + n.id, { method: 'DELETE' });
      notes = notes.filter(x => x.id !== n.id);
      links = links.filter(l => l.from !== n.id && l.to !== n.id);
      render();
      say('削除しました。');
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

  // --- 起動 -----------------------------------------------------------------

  // 追加ボタンは #board の外(見出し行)にあるので document から探す
  document.querySelector('.board-add')?.addEventListener('click', () => {
    const body = prompt('付箋の内容');
    if (body === null) return;
    const trimmed = body.trim();
    if (trimmed === '') { say('内容が空です。', true); return; }
    addNote(trimmed, null);
  });

  // 返信の「付箋にする」ボタン
  document.querySelectorAll('[data-note-from-post]').forEach(btn => {
    btn.addEventListener('click', () => {
      const postId = btn.dataset.noteFromPost;
      const text = btn.dataset.noteBody || '';
      addNote(text.slice(0, 500), postId);
      document.getElementById('board')?.scrollIntoView({ behavior: 'smooth' });
    });
  });

  (async function load() {
    try {
      const data = await call('/notes', { method: 'GET' });
      canEdit = data.canEdit;
      notes = data.notes;
      links = data.links;
      root.classList.toggle('can-edit', canEdit);
      render();
    } catch (e) {
      say(e.message, true);
    }
  })();
})();
