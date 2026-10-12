/* Account menu (top right): avatar + name → Hồ sơ của bạn, Cài đặt, Đăng xuất. First sign-in (password or Google)
   asks for the profile before the dashboard can be used. Settings: theme (light / dark / system) and password change.
   Server: api.php profile_save, avatar_upload, avatar_remove, password_change, GET avatar. Uses api(), notify() from app.js. */
'use strict';
const Account = (() => {
  const $ = id => document.getElementById(id);
  const T = s => (typeof I18N !== 'undefined' ? I18N.t(s) : s);
  let me = null, ready = false, avatarUrl = '';

  /* default avatars in the brand colours, like the grey silhouettes of social apps */
  const svg = body => 'data:image/svg+xml;utf8,' + encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96">${body}</svg>`);
  const DEFAULT = {
    nam: svg('<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#17816E"/><stop offset="1" stop-color="#0F2E3A"/></linearGradient></defs><rect width="96" height="96" fill="url(#g)"/><circle cx="48" cy="38" r="17" fill="#E4F5ED"/><path d="M30 32c0-12 8-18 18-18s18 6 18 16c-3-4-9-6-18-6s-15 3-18 8z" fill="#0A222D"/><path d="M14 96c2-20 16-30 34-30s32 10 34 30z" fill="#E4F5ED"/><path d="M40 66l8 10 8-10" fill="#F3B862"/>'),
    nu: svg('<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#F3B862"/><stop offset="1" stop-color="#17816E"/></linearGradient></defs><rect width="96" height="96" fill="url(#g)"/><path d="M26 44c0-20 10-30 22-30s22 10 22 30c0 14-4 26-6 30H32c-2-4-6-16-6-30z" fill="#0A222D"/><circle cx="48" cy="40" r="15" fill="#FFF6EC"/><path d="M33 36c4-10 10-12 15-12s11 2 15 12c-6-3-10-6-15-6s-9 3-15 6z" fill="#0A222D"/><path d="M14 96c2-19 16-28 34-28s32 9 34 28z" fill="#FFF6EC"/>'),
    khac: svg('<rect width="96" height="96" fill="#0F2E3A"/><circle cx="48" cy="38" r="16" fill="#7DD3B8"/><path d="M16 96c2-19 15-28 32-28s30 9 32 28z" fill="#7DD3B8"/>'),
  };
  const avatar = () => me?.has_avatar ? avatarUrl : DEFAULT[me?.gender || 'khac'];

  /* ---------- theme: light, dark, or follow the device (green brand tone in both) ---------- */
  const media = matchMedia('(prefers-color-scheme: dark)');
  function theme(pick) {
    if (pick) try { localStorage.setItem('clicker-theme', pick); } catch {}
    let t = 'light'; try { t = localStorage.getItem('clicker-theme') || 'light'; } catch {}   // default = today's light look
    document.documentElement.dataset.theme = t === 'system' ? (media.matches ? 'dark' : 'light') : t;
    for (const r of document.querySelectorAll('input[name=theme]')) r.checked = r.value === t;
  }
  media.addEventListener?.('change', () => theme());

  /* ---------- header ---------- */
  function paint() {
    const name = me?.full_name || $('teacher-name').dataset.username || 'Giảng viên';
    $('teacher-name').textContent = name; $('acct-full').textContent = name;
    $('acct-meta').textContent = me ? [me.subject, me.school].filter(Boolean).join(' · ') || T('Chưa ghi trường / môn') : '';
    $('acct-avatar').src = $('acct-avatar-big').src = $('pf-avatar').src = avatar();
    const hello = $('hero-hello');
    if (hello && me) { hello.innerHTML = ''; hello.append(T('Chào') + ' ' + me.full_name.trim() + ','); hello.append(document.createElement('br')); const em = document.createElement('em'); em.textContent = T('kiểm tra thông minh, học vui hơn.'); hello.append(em); }
  }

  /* ---------- profile ---------- */
  let required = false;
  function openProfile(force) {
    required = !!force;
    $('profile-x').hidden = $('pf-cancel').hidden = required;
    $('profile-eyebrow').textContent = T(required ? 'BƯỚC CUỐI' : 'HỒ SƠ CỦA BẠN');
    $('profile-title').textContent = T(required ? 'Hoàn thiện hồ sơ giáo viên' : 'Hồ sơ giáo viên');
    $('profile-sub').textContent = T(required ? 'Điền một lần để bắt đầu dùng Clicker. Sửa lại bất cứ lúc nào ở góc trên bên phải.' : 'Thông tin hiển thị trên tài khoản của bạn.');
    const p = me || {};
    $('pf-name').value = p.full_name || ''; $('pf-school').value = p.school || ''; $('pf-subject').value = p.subject || '';
    $('pf-grades').value = p.grades || ''; $('pf-phone').value = p.phone || ''; $('pf-bio').value = p.bio || '';
    for (const r of document.querySelectorAll('input[name=pf-gender]')) r.checked = r.value === (p.gender || 'khac');
    $('pf-remove').hidden = !p.has_avatar; $('pf-err').textContent = '';
    $('pf-avatar').src = avatar();
    $('profile-dialog').showModal();
    setTimeout(() => $('pf-name').focus(), 50);
  }
  async function saveProfile(e) {
    e.preventDefault();
    const gender = document.querySelector('input[name=pf-gender]:checked')?.value || 'khac';
    const body = {full_name:$('pf-name').value.trim(), gender, school:$('pf-school').value.trim(), subject:$('pf-subject').value.trim(),
      grades:$('pf-grades').value.trim(), phone:$('pf-phone').value.trim(), bio:$('pf-bio').value.trim()};
    if (!body.full_name) { $('pf-err').textContent = T('Nhập họ và tên.'); return $('pf-name').focus(); }
    if (required && (!body.school || !body.subject)) { $('pf-err').textContent = T('Nhập trường và môn dạy.'); return; }
    $('pf-save').disabled = true;
    try { me = (await api('profile_save', body)).profile; avatarUrl = `api.php?action=avatar&v=${me.v}`; paint(); $('profile-dialog').close(); notify(T('Đã lưu hồ sơ.')); }
    catch (error) { $('pf-err').textContent = error.message; }
    finally { $('pf-save').disabled = false; }
  }
  // photo: crop to a centred square, shrink to 256 px JPEG in the browser, then upload
  async function pickPhoto() {
    const file = $('pf-file').files[0]; $('pf-file').value = '';
    if (!file) return;
    if (!me) { $('pf-err').textContent = T('Hãy lưu hồ sơ trước khi đổi ảnh.'); return; }
    if (file.size > 15 * 2 ** 20) { $('pf-err').textContent = T('Ảnh quá lớn (tối đa 15 MB).'); return; }
    try {
      const img = await createImageBitmap(file), side = Math.min(img.width, img.height), c = document.createElement('canvas');
      c.width = c.height = 256;
      c.getContext('2d').drawImage(img, (img.width - side) / 2, (img.height - side) / 2, side, side, 0, 0, 256, 256);
      me = (await api('avatar_upload', {image:c.toDataURL('image/jpeg', .86)})).profile;
      avatarUrl = `api.php?action=avatar&v=${me.v}-${Date.now()}`; paint(); $('pf-remove').hidden = false; notify(T('Đã đổi ảnh đại diện.'));
    } catch (error) { $('pf-err').textContent = error.message || T('Không đọc được ảnh này.'); }
  }

  /* ---------- settings ---------- */
  function openSettings() {
    theme();
    const google = !!me?.google;
    $('pwc-old-row').querySelector('input').value = ''; $('pwc-new').value = ''; $('pwc-new2').value = ''; $('pwc-err').textContent = '';
    $('pwc-note').textContent = google ? T('Tài khoản đăng nhập bằng Google: bỏ trống mật khẩu hiện tại nếu bạn chưa từng đặt mật khẩu.') : '';
    $('settings-dialog').showModal();
  }
  async function changePassword(e) {
    e.preventDefault();
    const old = $('pwc-old').value, p1 = $('pwc-new').value, p2 = $('pwc-new2').value, err = $('pwc-err');
    err.textContent = '';
    if (!/^[0-9]{8}$/.test(p1)) { err.textContent = T('Mật khẩu mới cần đúng 8 chữ số.'); return; }
    if (p1 !== p2) { err.textContent = T('Hai lần nhập mật khẩu mới không giống nhau.'); return; }
    const b = e.submitter; b.disabled = true;
    try { await api('password_change', {old_password:old, password:p1, password2:p2}); $('pw-change').reset(); notify(T('Đã đổi mật khẩu.')); $('settings-dialog').close(); }
    catch (error) { err.textContent = error.message; }
    finally { b.disabled = false; }
  }

  /* ---------- wiring ---------- */
  function menu(open) { $('acct-menu').hidden = !open; $('acct-btn').setAttribute('aria-expanded', String(open)); }
  document.addEventListener('DOMContentLoaded', () => {
    theme();
    $('acct-btn').addEventListener('click', e => { e.stopPropagation(); menu($('acct-menu').hidden); });
    $('acct-menu').addEventListener('click', e => e.stopPropagation());
    document.addEventListener('click', () => menu(false));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') menu(false); });
    $('acct-profile').addEventListener('click', () => { menu(false); openProfile(false); });
    $('acct-settings').addEventListener('click', () => { menu(false); openSettings(); });
    $('profile-form').addEventListener('submit', saveProfile);
    $('profile-x').addEventListener('click', () => $('profile-dialog').close());
    $('pf-cancel').addEventListener('click', () => $('profile-dialog').close());
    $('profile-dialog').addEventListener('cancel', e => { if (required) e.preventDefault(); });   // Esc cannot skip the first profile
    $('pf-file').addEventListener('change', pickPhoto);
    for (const r of document.querySelectorAll('input[name=pf-gender]')) r.addEventListener('change', () => { if (!me?.has_avatar) $('pf-avatar').src = DEFAULT[r.value]; });
    $('pf-remove').addEventListener('click', async () => { try { me = (await api('avatar_remove', {})).profile; paint(); $('pf-remove').hidden = true; } catch (error) { $('pf-err').textContent = error.message; } });
    $('settings-x').addEventListener('click', () => $('settings-dialog').close());
    for (const r of document.querySelectorAll('input[name=theme]')) r.addEventListener('change', () => theme(r.value));
    $('pw-change').addEventListener('submit', changePassword);
  });

  return {
    defaultAvatar: g => DEFAULT[g] || DEFAULT.khac,
    // called by app.js once the session is known
    init(auth) {
      $('teacher-name').dataset.username = auth.username || '';
      me = auth.profile || null; ready = !!auth.profile_ready;
      if (me) avatarUrl = `api.php?action=avatar&v=${me.v}`;
      paint();
      if (ready && !me && !auth.view_as) openProfile(true);
    },
  };
})();
