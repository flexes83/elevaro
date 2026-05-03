(function () {
  function getLocalProfile() {
    try {
      return JSON.parse(localStorage.getItem('elevaro_profile') || 'null');
    } catch (error) {
      return null;
    }
  }

  function hasCompleteProfile(profile) {
    const values = profile && profile.values ? profile.values : null;

    return Boolean(
      values &&
      values.state &&
      values.school_type &&
      values.grade &&
      values.subject
    );
  }

  function saveProfileToServer(profile) {
    if (!hasCompleteProfile(profile)) {
      return Promise.resolve(false);
    }

    return fetch('/api/user_profile.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'same-origin',
      body: JSON.stringify({ profile })
    })
      .then(response => {
        if (response.status === 401) return false;
        return response.json();
      })
      .then(data => !!(data && data.success))
      .catch(() => false);
  }

  function loadProfileFromServer() {
    return fetch('/api/user_profile.php', {
      credentials: 'same-origin'
    })
      .then(response => {
        if (response.status === 401) return null;
        return response.json();
      })
      .then(data => data && data.success ? data.profile : null)
      .catch(() => null);
  }

  function syncLocalProfileToServer() {
    return saveProfileToServer(getLocalProfile());
  }

  function hydrateLocalProfileFromServer() {
    return loadProfileFromServer().then(profile => {
      if (hasCompleteProfile(profile)) {
        localStorage.setItem('elevaro_profile', JSON.stringify(profile));
        return true;
      }

      return false;
    });
  }

  window.ElevaroUserProfile = {
    getLocalProfile,
    hasCompleteProfile,
    saveProfileToServer,
    loadProfileFromServer,
    syncLocalProfileToServer,
    hydrateLocalProfileFromServer
  };
})();
