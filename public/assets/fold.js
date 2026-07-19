// スレッド表示の折りたたみ。
// 開閉自体は <details> の標準動作。ここで足すのは次の3つ。
//   1. 開閉状態を次回も覚えておく
//   2. 折りたたまれた中のアンカー(#post-3 など)へ飛ぶとき自動で開く
//   3. 長い返信を途中で切って「続きを読む」を出す
(function () {
  'use strict';

  const STORE_PREFIX = 'ideaforum.fold.';
  const CLAMP_HEIGHT = 320;   // これを超える返信は畳む(px)

  // --- 1. 開閉状態を覚える ---------------------------------------------------

  document.querySelectorAll('details.fold[id]').forEach(el => {
    const key = STORE_PREFIX + el.id;
    let saved = null;
    try { saved = localStorage.getItem(key); } catch (e) { /* 無効化されていても動かす */ }

    if (saved === 'closed') el.open = false;
    if (saved === 'open') el.open = true;

    el.addEventListener('toggle', () => {
      try { localStorage.setItem(key, el.open ? 'open' : 'closed'); } catch (e) { /* 保存できなくても支障なし */ }
    });
  });

  // --- 2. 畳まれた中のアンカーへ飛べるようにする -----------------------------

  function revealHash() {
    const hash = location.hash;
    if (!hash || hash.length < 2) return;

    let target;
    try { target = document.querySelector(hash); } catch (e) { return; }
    if (!target) return;

    // 祖先の <details> をすべて開いてから位置を合わせる
    let node = target.parentElement;
    let opened = false;
    while (node) {
      if (node.tagName === 'DETAILS' && !node.open) { node.open = true; opened = true; }
      node = node.parentElement;
    }
    // 返信直後は本文が畳まれていることがあるので広げておく
    const clamped = target.querySelector('.is-clamped');
    if (clamped) expand(clamped);

    if (opened) target.scrollIntoView({ block: 'start' });
    target.classList.add('is-highlighted');
    setTimeout(() => target.classList.remove('is-highlighted'), 2000);
  }

  window.addEventListener('hashchange', revealHash);
  revealHash();

  // --- 3. 長い返信を畳む -----------------------------------------------------

  function expand(el) {
    el.classList.remove('is-clamped');
    const btn = el.nextElementSibling;
    if (btn && btn.classList.contains('clamp-toggle')) btn.textContent = '折りたたむ';
  }

  function collapse(el) {
    el.classList.add('is-clamped');
    const btn = el.nextElementSibling;
    if (btn && btn.classList.contains('clamp-toggle')) btn.textContent = '続きを読む';
  }

  document.querySelectorAll('.md-body.is-clampable').forEach(el => {
    if (el.scrollHeight <= CLAMP_HEIGHT) return;   // 短い返信はそのまま

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'link-btn clamp-toggle';
    el.after(btn);

    collapse(el);
    btn.addEventListener('click', () => {
      if (el.classList.contains('is-clamped')) {
        expand(el);
      } else {
        collapse(el);
        el.scrollIntoView({ block: 'nearest' });
      }
    });
  });
})();
