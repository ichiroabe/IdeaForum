// 設定画面の見本を、保存前にその場で更新する。
(function () {
  'use strict';

  const preview = document.querySelector('.settings-preview .avatar');
  const previewName = document.getElementById('preview-name');
  const nameInput = document.querySelector('input[name="display_name"]');
  if (!preview) return;

  function currentName() {
    return (nameInput.value || '').trim();
  }

  function update() {
    const emoji = document.querySelector('input[name="avatar_emoji"]:checked');
    const color = document.querySelector('input[name="avatar_color"]:checked');
    const name = currentName();

    // 絵文字が未選択なら頭文字。名前も空なら「?」。
    preview.textContent = (emoji && emoji.value) ? emoji.value : (name ? Array.from(name)[0] : '?');

    if (color && color.value) {
      const swatch = color.parentElement.querySelector('.picker-color');
      if (swatch) preview.style.background = getComputedStyle(swatch).backgroundColor;
    } else {
      preview.style.background = preview.dataset.autoColor || preview.style.background;
    }
    previewName.textContent = name || '(名前が未入力です)';
  }

  preview.dataset.autoColor = preview.style.background;
  document.querySelectorAll('input[name="avatar_emoji"], input[name="avatar_color"]')
    .forEach(el => el.addEventListener('change', update));
  nameInput.addEventListener('input', update);
})();
