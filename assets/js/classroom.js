(() => {
  const room = window.ELEVARO_CLASSROOM || {};
  const onlineList = document.getElementById('onlineList');
  const onlineCount = document.getElementById('onlineCount');
  const activityFeed = document.getElementById('activityFeed');
  const duelList = document.getElementById('duelList');
  const avatarModal = document.getElementById('avatarModal');
  const avatarError = document.getElementById('avatarError');
  const initialsInput = document.getElementById('initialsInput');
  let selectedGradient = 'grad-1';

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));

  function avatarMarkup(avatar, extraClass = '') {
    const data = typeof avatar === 'string' ? {type: 'emoji', value: avatar, gradient: 'grad-1'} : (avatar || {});
    return `<span class="avatar-bubble ${escapeHtml(data.type || 'emoji')} ${escapeHtml(data.gradient || 'grad-1')} ${extraClass}">${escapeHtml(data.value || '🙂')}</span>`;
  }

  async function request(action = 'heartbeat', payload = {}) {
    const options = {headers: {'Accept': 'application/json'}};
    const url = `/api/classroom_heartbeat.php?class_id=${encodeURIComponent(room.classId)}`;
    if (action !== 'heartbeat') {
      const body = new URLSearchParams({class_id: room.classId, action, ...payload});
      options.method = 'POST';
      options.body = body;
    }
    const response = await fetch(url, options);
    const data = await response.json();
    if (!data.ok) throw new Error(data.error || 'Update fehlgeschlagen');
    render(data);
    return data;
  }

  function render(data) {
    if (onlineCount) onlineCount.textContent = String((data.online || []).length);
    if (onlineList) {
      onlineList.innerHTML = (data.online || []).map((p) => `
        <button class="online-person" type="button" data-participant-id="${p.id}" ${p.is_me ? 'disabled' : ''}>
          ${avatarMarkup(p.avatar, 'avatar')}
          <span>${escapeHtml(p.name)}</span><small>${p.is_me ? 'du' : 'Duell'}</small>
        </button>
      `).join('');
    }
    if (activityFeed) {
      activityFeed.innerHTML = (data.activities || []).map((a) => `
        <div class="activity-item">${avatarMarkup(a.avatar)}<div><strong>${escapeHtml(a.title)}</strong><small>${escapeHtml(a.time)}</small></div></div>
      `).join('');
    }
    if (duelList) {
      const duels = data.duels || [];
      duelList.innerHTML = duels.length ? duels.map((d) => {
        let action = '<span class="duel-waiting">wartet …</span>';
        if (d.status === 'pending' && d.is_challenged) {
          action = `<div class="duel-actions"><button class="btn btn-sm btn-primary" data-duel-action="accept" data-duel-id="${d.id}">Annehmen</button><button class="btn btn-sm btn-light" data-duel-action="decline" data-duel-id="${d.id}">Ablehnen</button></div>`;
        } else if (d.status === 'accepted' && d.url) {
          action = `<a class="btn btn-sm btn-primary" href="${escapeHtml(d.url)}">Duell starten</a>`;
        }
        return `<div class="duel-item"><strong>${escapeHtml(d.title)}</strong><small>${escapeHtml(d.quiz_title)}</small>${action}</div>`;
      }).join('') : '<div class="duel-empty">Fordere jemanden aus der Online-Liste heraus.</div>';
    }
    const acceptedDuel = (data.duels || []).find((d) => d.status === 'accepted' && d.url);
    if (acceptedDuel && !sessionStorage.getItem('elevaro_duel_redirect_' + acceptedDuel.id)) {
      sessionStorage.setItem('elevaro_duel_redirect_' + acceptedDuel.id, '1');
      window.location.href = acceptedDuel.url;
      return;
    }

    if (data.me?.avatar) {
      document.querySelectorAll('.me-pill .avatar-bubble').forEach((el) => {
        el.outerHTML = avatarMarkup(data.me.avatar);
      });
    }
  }

  document.addEventListener('click', (event) => {
    const duelButton = event.target.closest('[data-duel-action]');
    if (duelButton) {
      const action = duelButton.dataset.duelAction === 'accept' ? 'duel_accept' : 'duel_decline';
      duelButton.disabled = true;
      request(action, {duel_id: duelButton.dataset.duelId}).then((data) => {
        if (data.duel_start_url && action === 'duel_accept') window.location.href = data.duel_start_url;
      }).catch((error) => showAvatarError(error.message)).finally(() => { duelButton.disabled = false; });
      return;
    }

    const btn = event.target.closest('.online-person:not([disabled])');
    if (btn) {
      btn.classList.add('is-sending');
      request('duel', {target_id: btn.dataset.participantId}).finally(() => btn.classList.remove('is-sending'));
      return;
    }

    if (event.target.closest('.avatar-settings-toggle')) {
      avatarModal.hidden = false;
      return;
    }

    if (event.target.closest('[data-avatar-close]') || event.target === avatarModal) {
      avatarModal.hidden = true;
      return;
    }

    const tab = event.target.closest('[data-avatar-tab]');
    if (tab) {
      document.querySelectorAll('[data-avatar-tab]').forEach((el) => el.classList.toggle('active', el === tab));
      document.querySelectorAll('[data-avatar-panel]').forEach((el) => el.classList.toggle('active', el.dataset.avatarPanel === tab.dataset.avatarTab));
      return;
    }

    const gradient = event.target.closest('[data-gradient]');
    if (gradient) {
      selectedGradient = gradient.dataset.gradient;
      document.querySelectorAll('[data-gradient]').forEach((el) => el.classList.toggle('active', el === gradient));
      return;
    }

    const avatarChoice = event.target.closest('[data-avatar-type="emoji"]');
    if (avatarChoice) {
      saveAvatar('emoji', avatarChoice.dataset.avatarValue, 'grad-1');
      return;
    }

    if (event.target.closest('#saveInitialsAvatar')) {
      saveAvatar('initials', initialsInput?.value || '', selectedGradient);
    }
  });

  function showAvatarError(message) {
    if (!avatarError) return;
    avatarError.textContent = message;
    avatarError.hidden = false;
    setTimeout(() => { avatarError.hidden = true; }, 3500);
  }

  function saveAvatar(type, value, gradient) {
    request('avatar', {avatar_type: type, avatar_value: value, avatar_gradient: gradient})
      .then(() => { avatarModal.hidden = true; })
      .catch((error) => showAvatarError(error.message));
  }

  function syncInitialPreview() {
    const initials = (initialsInput?.value || '').toUpperCase().trim() || '??';
    document.querySelectorAll('.gradient-choice span').forEach((el) => { el.textContent = initials; });
  }

  initialsInput?.addEventListener('input', syncInitialPreview);
  document.querySelector('[data-gradient]')?.classList.add('active');
  syncInitialPreview();
  setInterval(() => request().catch(() => {}), 2000);
})();
