// 付箋ボード。外部ライブラリなし。
// 付箋は絶対配置の div、つなぐ線は背面の SVG で描く。
//
// 入力は prompt()/confirm() を使わず、すべて付箋の中に出すインラインフォームで行う。
// prompt では改行が打てず(付箋は500文字まで持てる)、JSの実行を止めるため
// 自動操作・支援技術・アプリ内ブラウザから扱えなくなるため。
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
  let openForm = null;   // いま開いている編集フォームまたは確認UI

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

  // --- 入力部品 --------------------------------------------------------------

  function el(tag, className, props) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    Object.assign(node, props || {});
    return node;
  }

  function button(label, className, onClick) {
    const b = el('button', className, { type: 'button', textContent: label });
    b.addEventListener('click', ev => { ev.stopPropagation(); onClick(ev); });
    return b;
  }

  function closeForm() {
    if (!openForm) return;
    const f = openForm;
    openForm = null;
    if (f.onClose) f.onClose();
    if (f.node && f.node.parentNode) f.node.remove();
  }

  // Esc の扱いは1箇所にまとめる。ハンドラを分けると、
  // stopPropagation() は同じ要素の他のリスナーを止めないため、
  // 1回押しただけで入力の取消と縮小が同時に起きてしまう。
  document.addEventListener('keydown', ev => {
    if (ev.key !== 'Escape') return;
    if (openForm) {            // 入力中なら、まずそれを取り消す
      closeForm();
      render();
    } else if (root.classList.contains('is-expanded')) {
      setExpanded(false);      // 何も開いていなければ拡大を解除
    }
  });

  /**
   * 付箋の中に出す編集フォーム。
   * 他人の付箋を変更する場合は理由欄も同じフォームの中に出す
   * (色はパレット、理由だけ prompt という不整合を無くすため)。
   */
  function buildEditForm(opts) {
    const form = el('form', 'sticky-form');
    form.setAttribute('aria-label', opts.ariaLabel);

    const text = el('textarea', 'sticky-input', {
      value: opts.value || '',
      rows: 4,
      maxLength: 500,
      placeholder: opts.placeholder || '付箋の内容 (改行できます)'
    });
    text.setAttribute('aria-label', opts.ariaLabel);
    form.appendChild(text);

    let reason = null;
    if (opts.needReason) {
      reason = el('input', 'sticky-reason', {
        type: 'text',
        maxLength: 200,
        placeholder: '変更する理由(履歴に残ります)'
      });
      reason.setAttribute('aria-label', '変更する理由');
      form.appendChild(reason);
    }

    const err = el('p', 'sticky-error');
    err.setAttribute('role', 'alert');
    form.appendChild(err);

    const row = el('div', 'sticky-form-row');
    row.appendChild(button('保存', 'btn btn-primary', () => submit()));
    row.appendChild(button('取消', 'btn', () => { closeForm(); render(); }));
    form.appendChild(row);

    function submit() {
      const body = text.value.trim();
      if (body === '') {
        err.textContent = '内容を入力してください。';
        text.focus();
        return;
      }
      if (reason && reason.value.trim() === '') {
        err.textContent = '他の人の付箋を変更するには理由が必要です。';
        reason.focus();
        return;
      }
      opts.onSave(body, reason ? reason.value.trim() : '', msg => { err.textContent = msg; });
    }

    form.addEventListener('submit', ev => { ev.preventDefault(); submit(); });
    // 改行を打てる場所なので Enter は改行のまま。Ctrl+Enter で保存。
    form.addEventListener('keydown', ev => {
      if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); submit(); }
    });
    form.addEventListener('pointerdown', ev => ev.stopPropagation()); // ドラッグを誤発動させない

    return { node: form, focus: () => text.focus() };
  }

  /** 削除など後戻りしにくい操作の確認。理由が要る場合は同じ枠内で聞く。 */
  function openConfirm(opts) {
    closeForm();

    const box = el('div', 'confirm-pop');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', opts.title);

    box.appendChild(el('p', 'confirm-message', { textContent: opts.message }));

    let reason = null;
    if (opts.needReason) {
      reason = el('input', 'sticky-reason', {
        type: 'text', maxLength: 200, placeholder: '理由(履歴に残ります)'
      });
      reason.setAttribute('aria-label', '理由');
      box.appendChild(reason);
    }
    const err = el('p', 'sticky-error');
    err.setAttribute('role', 'alert');
    box.appendChild(err);

    const row = el('div', 'sticky-form-row');
    const okBtn = button(opts.okLabel || '実行', 'btn btn-warn', () => {
      if (reason && reason.value.trim() === '') {
        err.textContent = '理由を入力してください。';
        reason.focus();
        return;
      }
      opts.onOk(reason ? reason.value.trim() : '', msg => { err.textContent = msg; });
    });
    row.appendChild(okBtn);
    row.appendChild(button('キャンセル', 'btn', () => { closeForm(); render(); }));
    box.appendChild(row);

    box.addEventListener('pointerdown', ev => ev.stopPropagation());

    // 開いている間はこの枠の中だけをタブで巡回する
    box.addEventListener('keydown', ev => {
      if (ev.key !== 'Tab') return;
      const focusables = box.querySelectorAll('input, button');
      if (!focusables.length) return;
      const first = focusables[0], last = focusables[focusables.length - 1];
      if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
      else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
    });

    (opts.anchor || canvas).appendChild(box);
    if (opts.position) {
      box.style.left = opts.position.x + 'px';
      box.style.top = opts.position.y + 'px';
      box.classList.add('is-floating');
    }

    const restore = document.activeElement;
    openForm = { node: box, onClose: () => { if (restore && restore.focus) restore.focus(); } };
    (reason || okBtn).focus();
  }

  // --- 描画 -----------------------------------------------------------------

  function render() {
    canvas.querySelectorAll('.sticky').forEach(node => node.remove());
    notes.forEach(n => canvas.appendChild(buildNote(n)));
    drawLines();
    resizeCanvas();
  }

  function buildNote(n) {
    const node = el('div', 'sticky sticky-' + n.color
      + (n.deleted ? ' is-deleted' : '') + (n.target ? ' is-target' : ''));
    node.dataset.id = n.id;
    node.style.left = n.x + 'px';
    node.style.top = n.y + 'px';
    if (linkFrom === n.id) node.classList.add('is-link-source');

    node.appendChild(el('div', 'sticky-body', { textContent: n.body }));

    const meta = el('div', 'sticky-meta');
    if (n.avatar) {
      const av = el('span', 'avatar avatar-xs', { textContent: n.avatar.label });
      av.style.background = n.avatar.color;
      meta.appendChild(av);
    }
    meta.appendChild(document.createTextNode(
      n.author + (n.postId ? ' (返信より)' : '') + (n.deleted ? ' [削除済]' : '')
    ));
    node.appendChild(meta);

    const tools = el('div', 'sticky-tools');
    if (n.events > 0) {
      const h = button('履' + n.events, 'has-history', () => showHistory(n));
      h.title = 'この付箋の履歴を見る (' + n.events + '件)';
      tools.appendChild(h);
    }
    if (canEdit && !n.deleted) {
      const target = button('的', n.target ? 'is-on' : '', () => toggleTarget(n));
      target.title = n.target ? '実装対象から外す' : '実装対象にする (指示書に載ります)';
      tools.appendChild(target);
      const link = button('連', '', () => startLink(n.id));
      link.title = 'ここから線をつなぐ';
      const edit = button('編', '', () => editNote(n, node));
      edit.title = '文面を変える';
      const color = button('色', '', ev => openPalette(n, node, ev.currentTarget));
      color.title = '色で分類する';
      const del = button('×', '', () => removeNote(n, node));
      del.title = 'この付箋を削除';
      tools.append(link, edit, color, del);
      makeDraggable(node, n);
    }
    if (isAdmin && n.deleted) {
      const r = button('復', '', () => restoreNote(n));
      r.title = 'この付箋を復元';
      tools.appendChild(r);
    }
    if (tools.children.length) node.appendChild(tools);

    node.addEventListener('click', () => {
      if (linkFrom !== null && linkFrom !== n.id && !n.deleted) finishLink(n.id);
    });
    return node;
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
        line.addEventListener('click', () => removeLink(l, { x: (x1 + x2) / 2, y: (y1 + y2) / 2 }));
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
    // 下限は「いま見えている窓の大きさ」。固定値にすると、窓の方が
    // 小さいときに常にスクロールが出てしまう。
    const view = root.querySelector('.board-scroll');
    let maxX = view ? view.clientWidth : 900;
    let maxY = view ? view.clientHeight : 500;
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
      const item = el('span', 'legend-item');
      item.appendChild(el('i', 'legend-swatch sticky-' + key));
      item.appendChild(document.createTextNode(colors[key]));
      legend.appendChild(item);
    });
  }

  // --- 操作 -----------------------------------------------------------------

  function makeDraggable(node, n) {
    let startX, startY, originX, originY, moved = false;

    node.addEventListener('pointerdown', ev => {
      if (ev.target.closest('.sticky-tools, .sticky-form, .confirm-pop, .palette')) return;
      node.setPointerCapture(ev.pointerId);
      startX = ev.clientX; startY = ev.clientY;
      originX = n.x; originY = n.y;
      moved = false;
      node.classList.add('is-dragging');
    });

    node.addEventListener('pointermove', ev => {
      if (!node.hasPointerCapture(ev.pointerId)) return;
      const dx = ev.clientX - startX, dy = ev.clientY - startY;
      if (Math.abs(dx) > 3 || Math.abs(dy) > 3) moved = true;
      n.x = Math.max(0, originX + dx);
      n.y = Math.max(0, originY + dy);
      node.style.left = n.x + 'px';
      node.style.top = n.y + 'px';
      drawLines();
    });

    node.addEventListener('pointerup', async ev => {
      if (!node.hasPointerCapture(ev.pointerId)) return;
      node.releasePointerCapture(ev.pointerId);
      node.classList.remove('is-dragging');
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

  /**
   * 次に置く場所を決める。
   *
   * いま見えている範囲の空きを探す。枚数から機械的に決めると、
   * 窓より広い位置や、はるか下の画面外に置かれてしまうため。
   */
  function nextPosition() {
    const view = root.querySelector('.board-scroll');
    const vw = view ? view.clientWidth : 900;
    const vh = view ? view.clientHeight : 500;
    const left = view ? view.scrollLeft : 0;
    const top = view ? view.scrollTop : 0;

    const stepX = NOTE_W + 20;
    const stepY = NOTE_H + 30;
    const cols = Math.max(1, Math.floor((vw - 40) / stepX));
    const rows = Math.max(1, Math.floor((vh - 40) / stepY));

    const taken = (x, y) => notes.some(n => Math.abs(n.x - x) < NOTE_W && Math.abs(n.y - y) < NOTE_H);

    // まず見えている範囲を左上から順に探す
    for (let r = 0; r < rows; r++) {
      for (let c = 0; c < cols; c++) {
        const x = left + 20 + c * stepX;
        const y = top + 20 + r * stepY;
        if (!taken(x, y)) return { x, y };
      }
    }
    // 見えている範囲が埋まっていれば、その少し下に置く
    return { x: left + 20, y: top + vh - NOTE_H - 20 };
  }

  /** 新しい付箋を編集モードで置く。確定して初めてサーバーに送る。 */
  function openNewNote(initialText, sourcePostId) {
    closeForm();
    const { x, y } = nextPosition();

    const node = el('div', 'sticky sticky-yellow is-editing');
    node.style.left = x + 'px';
    node.style.top = y + 'px';

    const form = buildEditForm({
      ariaLabel: '新しい付箋の内容',
      value: initialText || '',
      needReason: false,
      onSave: async (body, _reason, showError) => {
        try {
          const data = await call('/notes', {
            method: 'POST',
            body: JSON.stringify({ body, pos_x: x, pos_y: y, source_post_id: sourcePostId || null })
          });
          notes.push(data.note);
          closeForm();
          render();
          say('付箋を追加しました。');
        } catch (e) {
          showError(e.message);
        }
      }
    });

    node.appendChild(form.node);
    canvas.appendChild(node);
    openForm = { node: node };
    resizeCanvas();
    node.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    form.focus();
  }

  /** その付箋を直接テキスト編集に切り替える */
  function editNote(n, node) {
    closeForm();
    ['.sticky-body', '.sticky-meta', '.sticky-tools'].forEach(sel => {
      const x = node.querySelector(sel);
      if (x) x.style.display = 'none';
    });
    node.classList.add('is-editing');

    const form = buildEditForm({
      ariaLabel: '付箋の内容を編集',
      value: n.body,
      needReason: !isMine(n),
      onSave: async (newBody, reason, showError) => {
        if (newBody === n.body) { closeForm(); render(); return; }
        try {
          await call('/notes/' + n.id, {
            method: 'PATCH',
            body: JSON.stringify({ body: newBody, reason: reason })
          });
          n.body = newBody;
          n.events++;
          closeForm();
          render();
          say('変更しました。');
        } catch (e) {
          showError(e.message);
        }
      }
    });

    node.appendChild(form.node);
    openForm = { node: form.node, onClose: () => node.classList.remove('is-editing') };
    form.focus();
  }

  /** 色のパレット。他人の付箋なら理由欄も同じ枠に出す。 */
  function openPalette(n, node, anchorBtn) {
    closeForm();
    const box = el('div', 'palette');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-label', '色で分類する');

    let reason = null;
    if (!isMine(n)) {
      reason = el('input', 'sticky-reason', {
        type: 'text', maxLength: 200, placeholder: '理由(履歴に残ります)'
      });
      reason.setAttribute('aria-label', '色を変える理由');
      box.appendChild(reason);
    }
    const err = el('p', 'sticky-error');
    err.setAttribute('role', 'alert');

    Object.keys(colors).forEach(key => {
      const b = button(colors[key],
        'palette-swatch sticky-' + key + (n.color === key ? ' is-current' : ''),
        () => {
          if (key === n.color) { closeForm(); return; }
          if (reason && reason.value.trim() === '') {
            err.textContent = '理由を入力してください。';
            reason.focus();
            return;
          }
          setColor(n, key, reason ? reason.value.trim() : '', msg => { err.textContent = msg; });
        });
      b.title = colors[key];
      box.appendChild(b);
    });
    box.appendChild(err);
    box.appendChild(button('閉じる', 'btn', () => closeForm()));
    box.addEventListener('pointerdown', ev => ev.stopPropagation());

    node.appendChild(box);
    openForm = { node: box, onClose: () => { if (anchorBtn) anchorBtn.focus(); } };
    (reason || box.querySelector('.palette-swatch')).focus();
  }

  // 実装対象の印。内容を変えるわけではないので理由も履歴も求めない。
  async function toggleTarget(n) {
    try {
      await call('/notes/' + n.id, {
        method: 'PATCH',
        body: JSON.stringify({ is_target: !n.target })
      });
      n.target = !n.target;
      render();
      say(n.target ? '実装対象にしました。' : '実装対象から外しました。');
    } catch (e) {
      say(e.message, true);
    }
  }

  async function setColor(n, color, reason, showError) {
    try {
      await call('/notes/' + n.id, {
        method: 'PATCH',
        body: JSON.stringify({ color: color, reason: reason })
      });
      n.color = color;
      n.events++;
      closeForm();
      render();
      say('色を変えました。');
    } catch (e) {
      showError(e.message);
    }
  }

  function removeNote(n, node) {
    openConfirm({
      anchor: node,
      title: '付箋の削除',
      message: isMine(n)
        ? 'この付箋を削除します。つながっている線も消えます。'
        : '他の人が作った付箋を削除します。理由が履歴に残ります。',
      okLabel: '削除する',
      needReason: !isMine(n),
      onOk: async (reason, showError) => {
        try {
          await call('/notes/' + n.id, { method: 'DELETE', body: JSON.stringify({ reason: reason }) });
          if (isAdmin) {
            n.deleted = true;
            n.events++;
          } else {
            notes = notes.filter(x => x.id !== n.id);
          }
          links = links.filter(l => l.from !== n.id && l.to !== n.id);
          closeForm();
          render();
          say('削除しました。管理者が復元できます。');
        } catch (e) {
          showError(e.message);
        }
      }
    });
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
    closeForm();
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

  function removeLink(l, position) {
    openConfirm({
      title: '線の削除',
      message: 'この線を削除しますか?',
      okLabel: '削除する',
      needReason: false,
      position: position,
      onOk: async (_reason, showError) => {
        try {
          await call('/links/' + l.id, { method: 'DELETE' });
          links = links.filter(x => x.id !== l.id);
          closeForm();
          render();
        } catch (e) {
          showError(e.message);
        }
      }
    });
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

      const head = el('div', 'panel-head');
      head.appendChild(el('h3', null, { textContent: '付箋の履歴' }));
      head.appendChild(button('閉じる', 'btn', () => { panel.hidden = true; }));
      panel.appendChild(head);

      panel.appendChild(el('p', 'panel-quote', { textContent: n.body }));

      const list = el('ol', 'history');
      data.events.forEach(ev => {
        const li = el('li');
        li.appendChild(el('div', 'history-head', {
          textContent: ev.at + ' ' + ev.actor + ' が' + (ACTION_LABEL[ev.action] || ev.action)
        }));
        if (ev.reason) {
          li.appendChild(el('div', 'history-reason', { textContent: '理由: ' + ev.reason }));
        }
        if (ev.action === 'edit' && ev.beforeBody !== null) {
          li.appendChild(el('div', 'history-diff', { textContent: '変更前: ' + ev.beforeBody }));
        }
        if (ev.action === 'color') {
          li.appendChild(el('div', 'history-diff', {
            textContent: (colors[ev.beforeColor] || ev.beforeColor) + ' → ' + (colors[ev.afterColor] || ev.afterColor)
          }));
        }
        if (ev.action === 'delete' && ev.beforeBody !== null) {
          li.appendChild(el('div', 'history-diff', { textContent: '削除時の内容: ' + ev.beforeBody }));
        }
        list.appendChild(li);
      });
      panel.appendChild(list);
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (e) {
      panel.innerHTML = '';
      panel.appendChild(el('p', 'note', { textContent: e.message }));
    }
  }

  // --- 起動 -----------------------------------------------------------------

  document.querySelector('.board-add')?.addEventListener('click', () => openNewNote('', null));

  // 画面いっぱいに広げる。狭い窓の中でスクロールし続けずに済むように。
  const expandBtn = document.querySelector('.board-expand');
  function setExpanded(on) {
    root.classList.toggle('is-expanded', on);
    document.body.classList.toggle('board-expanded', on);
    if (expandBtn) expandBtn.textContent = on ? '元に戻す' : '広げる';

    let hint = document.querySelector('.board-expand-hint');
    if (on && !hint) {
      hint = el('div', 'board-expand-hint');
      hint.appendChild(document.createTextNode('Esc で戻る　'));
      hint.appendChild(button('元に戻す', 'link-btn', () => setExpanded(false)));
      document.body.appendChild(hint);
    } else if (!on && hint) {
      hint.remove();
    }
    resizeCanvas();
    if (!on) root.scrollIntoView({ block: 'nearest' });
  }
  expandBtn?.addEventListener('click', () => setExpanded(!root.classList.contains('is-expanded')));

  // 返信の本文を引き継いだ付箋を編集モードで開く(長すぎる場合にその場で削れるように)
  document.querySelectorAll('[data-note-from-post]').forEach(btn => {
    btn.addEventListener('click', () => {
      root.scrollIntoView({ behavior: 'smooth' });
      openNewNote((btn.dataset.noteBody || '').slice(0, 500), btn.dataset.noteFromPost);
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
