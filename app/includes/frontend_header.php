<?php

require_once __DIR__ . '/auth.php';

function elevaro_frontend_user(): ?array
{
    return auth_user();
}

function elevaro_frontend_initials(?array $user): string
{
    if (!$user) {
        return '';
    }

    $name = trim((string)($user['display_name'] ?: $user['username'] ?: $user['email']));

    if ($name === '') {
        return '?';
    }

    $parts = preg_split('/\s+/', $name);
    $first = mb_substr($parts[0] ?? '?', 0, 1, 'UTF-8');
    $second = count($parts) > 1 ? mb_substr(end($parts), 0, 1, 'UTF-8') : '';

    return mb_strtoupper($first . $second, 'UTF-8');
}

function elevaro_frontend_dashboard_url(?array $user = null): string
{
    $user = $user ?: auth_user();
    $role = $user ? auth_effective_role() : null;

    return match ($role) {
        'admin' => '/admin/index.php',
        'lehrer' => '/teacher_dashboard.php',
        'schueler' => '/student_dashboard.php',
        default => '/login.php',
    };
}

function elevaro_frontend_header(string $variant = 'light', array $options = []): void
{
    $user = elevaro_frontend_user();
    $effectiveRole = auth_effective_role();
    $realRole = auth_real_role();

    $name = $user ? trim((string)($user['display_name'] ?: $user['username'] ?: $user['email'])) : '';
    $initials = elevaro_frontend_initials($user);

    $isGlass = $variant === 'glass';

    $classes = $isGlass
        ? 'navbar navbar-expand-lg elevaro-topbar elevaro-topbar-glass fixed-top'
        : 'navbar navbar-expand-lg elevaro-topbar elevaro-topbar-light';
    ?>

<nav class="<?= auth_h($classes) ?>">
  <div class="container">

    <a class="navbar-brand fw-bold" href="/">Elevaro</a>

    <button
      class="navbar-toggler"
      type="button"
      data-bs-toggle="collapse"
      data-bs-target="#elevaroNavbar"
    >
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="elevaroNavbar">

      <?php if (!$user): ?>
        <!-- Marketing Navigation -->
        <ul class="navbar-nav mx-lg-auto mt-3 mt-lg-0 gap-lg-3">
          <li class="nav-item">
            <a class="nav-link" href="/#schueler">Für Schüler</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="/#eltern">Für Eltern</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="/#lehrer">Für Lehrer</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="/paywall.php">Preise</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="/recommendations.php">Quizze</a>
          </li>
        </ul>
      <?php else: ?>
        <!-- Produkt Navigation -->
        <ul class="navbar-nav me-auto mt-3 mt-lg-0">
          <li class="nav-item">
            <a class="nav-link" href="/recommendations.php">Quizze</a>
          </li>
        </ul>
      <?php endif; ?>

      <!-- Actions -->
      <div class="d-flex align-items-center gap-2 ms-lg-auto mt-3 mt-lg-0">

        <?php if ($user): ?>
          <div class="dropdown elevaro-user-menu">
            <button
              class="elevaro-user-button"
              type="button"
              data-bs-toggle="dropdown"
            >
              <span class="elevaro-avatar"><?= auth_h($initials) ?></span>
              <span class="elevaro-user-name d-none d-sm-inline"><?= auth_h($name) ?></span>
            </button>

            <ul class="dropdown-menu dropdown-menu-end">
              <li class="dropdown-header">
                <strong><?= auth_h($name) ?></strong>
                <span><?= auth_h(auth_role_label((string)$effectiveRole)) ?></span>
              </li>

              <li><a class="dropdown-item" href="/account.php">Mein Konto</a></li>
              <li><a class="dropdown-item" href="<?= auth_h(elevaro_frontend_dashboard_url($user)) ?>">Dashboard</a></li>

              <?php if ($realRole === 'admin'): ?>
                <li><a class="dropdown-item" href="/admin/index.php">Admin</a></li>
              <?php endif; ?>

              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="/logout.php">Logout</a></li>
            </ul>
          </div>

        <?php else: ?>

          <a href="/login.php" class="btn btn-sm btn-outline-primary">
            Login
          </a>

          <a href="/onboarding.php" class="btn btn-sm btn-primary">
            Jetzt loslegen
          </a>

        <?php endif; ?>

      </div>
    </div>
  </div>
</nav>
<?php
}