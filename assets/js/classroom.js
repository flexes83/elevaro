(() => {
  const room = window.ELEVARO_CLASSROOM || {};
  const onlineList = document.getElementById('onlineList');
  const activityFeed = document.getElementById('activityFeed');
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));

  async function request(action = 'heartbeat', targetId = null) {
    const options = {headers: {'Accept': 'application/json'}};
    let url = `/api/classroom_heartbeat.php?class_id=${encodeURIComponent(room.classId)}`;
    if (action === 'duel') {
      const body = new URLSearchParams({class_id: room.classId, action: 'duel', target_id: targetId});
      options.method = 'POST';
      options.body = body;
    }
    const response = await fetch(url, options);
    const data = await response.json();
    if (!data.ok) throw new Error(data.error || 'Update fehlgeschlagen');
    render(data);
  }

  function render(data) {
    if (onlineList) {
      onlineList.innerHTML = (data.online || []).map((p) => `
        <button class="online-person" type="button" data-participant-id="${p.id}" ${p.is_me ? 'disabled' : ''}>
          <span class="avatar">${escapeHtml(p.avatar)}</span><span>${escapeHtml(p.name)}</span><small>${p.is_me ? 'du' : 'Duell'}</small>
        </button>
      `).join('');
    }
    if (activityFeed) {
      activityFeed.innerHTML = (data.activities || []).map((a) => `
        <div class="activity-item"><span>${escapeHtml(a.avatar)}</span><div><strong>${escapeHtml(a.title)}</strong><small>${escapeHtml(a.time)}</small></div></div>
      `).join('');
    }
  }

  document.addEventListener('click', (event) => {
    const btn = event.target.closest('.online-person:not([disabled])');
    if (!btn) return;
    btn.classList.add('is-sending');
    request('duel', btn.dataset.participantId).finally(() => btn.classList.remove('is-sending'));
  });

  setInterval(() => request().catch(() => {}), 12000);
})();
