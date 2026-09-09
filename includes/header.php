<?php
/**
 * header.php
 * Espera as variáveis:
 *   string $pageTitle  - título da aba do navegador
 *   string $activePage - 'kanban' ou 'calendario', para destacar o item de nav ativo
 */
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Orbit') ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
</head>
<body>

<header class="app-navbar">
    <div class="app-navbar__inner">
        <a href="index.php" class="app-brand">
            <span class="app-brand__mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none">
                    <circle cx="12" cy="12" r="3.2" fill="currentColor"/>
                    <ellipse cx="12" cy="12" rx="10" ry="4.2" stroke="currentColor" stroke-width="1.6"/>
                </svg>
            </span>
            <span class="app-brand__name">Orbit</span>
        </a>

        <nav class="app-nav" aria-label="Navegação principal">
            <a href="index.php" class="app-nav__link <?= $activePage === 'kanban' ? 'is-active' : '' ?>">
                <i class="bi bi-kanban" aria-hidden="true"></i>
                <span>Kanban</span>
            </a>
            <a href="calendario.php" class="app-nav__link <?= $activePage === 'calendario' ? 'is-active' : '' ?>">
                <i class="bi bi-calendar3" aria-hidden="true"></i>
                <span>Calendário</span>
            </a>
        </nav>

        <div class="app-navbar__actions">
            <button type="button" class="btn btn-primary btn-sm d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#modalNovaTarefa">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                <span class="d-none d-sm-inline">Nova tarefa</span>
            </button>
        </div>
    </div>
</header>

<main class="app-main">
