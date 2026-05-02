(function () {
  const box = document.getElementById('returningBox');
  const title = document.getElementById('returningTitle');
  const text = document.getElementById('returningText');

  if (!box || !title || !text) return;

  let profile = null;

  try {
    profile = JSON.parse(localStorage.getItem('elevaro_profile') || 'null');
  } catch (e) {
    profile = null;
  }

  if (!profile || !profile.values) return;

  const name = localStorage.getItem('elevaro_profile_name');
  const labels = profile.labels || {};
  const values = profile.values || {};

  title.textContent = name ? `Weiterlernen, ${name}?` : 'Weiterlernen?';

  const parts = [
    labels.subject || values.subject,
    labels.grade || (values.grade ? `${values.grade}. Klasse` : null),
    labels.school_type || values.school_type
  ].filter(Boolean);

  text.textContent = parts.length
    ? `Deine letzte Auswahl: ${parts.join(' · ')}`
    : 'Wir haben deine letzte Auswahl gespeichert.';

  box.classList.remove('d-none');
})();
