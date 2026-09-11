<?php
/**
 * Planner - Gestão Visual de Tarefas, Equipes e Calendário
 */
require_once __DIR__ . '/functions.php';

$usuarioAtual = $usuarioAtual ?? plannerGetUsuarioAtual();

// Controle de acesso simplificado via chaves estáticas de administrador
$adminKeys = ['1001', '1002'];
$userKey = (string) ($usuarioAtual['matricula'] ?? $usuarioAtual['chave'] ?? $usuarioAtual['id'] ?? '');
$ehAdmin = in_array($userKey, $adminKeys, true);

// Estado dos filtros
$viewsValidas = ['kanban', 'calendario', 'dashboard', 'lista', 'minhas-tarefas'];
$viewAtual = in_array($_GET['view'] ?? 'kanban', $viewsValidas, true) ? ($_GET['view'] ?? 'kanban') : 'kanban';

$filtros = [
    'view' => $viewAtual,
    'equipe' => (int) ($_GET['equipe'] ?? 0),
    'usuario' => (string) ($_GET['usuario'] ?? ''),
    'prioridade' => in_array($_GET['prioridade'] ?? '', ['', 'baixa', 'media', 'alta', 'urgente'], true)
        ? ($_GET['prioridade'] ?? '') : '',
    'data_inicio' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data_inicio'] ?? '') ? $_GET['data_inicio'] : '',
    'data_fim' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data_fim'] ?? '') ? $_GET['data_fim'] : '',
    'prazo' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['prazo'] ?? '') ? $_GET['prazo'] : '',
    'texto' => htmlspecialchars(mb_substr($_GET['q'] ?? '', 0, 200), ENT_QUOTES),
];

// Estado dos filtros
$usuarios = plannerGetUsuarios();
$equipes = plannerGetEquipes();
$colunas = plannerGetColunas();
$tarefas = plannerGetTarefas($filtros);
$todasTarefas = plannerGetTarefas();   // sem filtros — para JSON blob e Dashboard
$comentarios = plannerGetComentarios();
$atividades = plannerGetAtividades();

// Minhas tarefas: filtradas pelo usuário atual, sem outros filtros
$minhasTarefas = array_values(array_filter(
    $todasTarefas,
    fn($t) => in_array($userKey, array_map('strval', $t['responsaveis']), true)
));

// Mapas por ID (acesso rápido)
$usuariosMapa = array_column($usuarios, null, 'id');
$equipesMapa = array_column($equipes, null, 'id');

// Calendário: mês atual
$calAno = (int) date('Y');
$calMes = (int) date('n');
$mesesPt = [
    'Janeiro',
    'Fevereiro',
    'Março',
    'Abril',
    'Maio',
    'Junho',
    'Julho',
    'Agosto',
    'Setembro',
    'Outubro',
    'Novembro',
    'Dezembro'
];
$hojeIso = date('Y-m-d');

// Mapa coluna por ID (acesso rápido)
$colunasMapa = array_column($colunas, null, 'id');

// KPIs para o Dashboard
$kpiTotal = count($todasTarefas);
$kpiAtrasadas = count(array_filter($todasTarefas, fn($t) => $t['status_prazo'] === 'atrasada'));
$kpiFechadas = count(array_filter($todasTarefas, fn($t) => (int)$t['coluna_id'] === 4 || $t['coluna_id'] === 'concluido'));
$kpiUrgentes = count(array_filter($todasTarefas, fn($t) => $t['prioridade'] === 'urgente'));

// JSON blob para o planner.js (tarefas SEM filtros para o cliente filtrar)
$plannerData = json_encode([
    'usuarioAtual' => $usuarioAtual,
    'usuarios' => $usuarios,
    'equipes' => $equipes,
    'colunas' => $colunas,
    'tarefas' => $todasTarefas,
    'comentarios' => $comentarios,
    'atividades' => $atividades,
    'filtros' => $filtros,
    'ehAdmin' => $ehAdmin,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="pt-BR" data-planner-view="<?= htmlspecialchars($viewAtual) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Planner — Módulo de gestão de equipes e tarefas.">
    <title>Planner ·
        <?= htmlspecialchars(match ($viewAtual) {
            'kanban' => 'Kanban',
            'calendario' => 'Calendário',
            'dashboard' => 'Dashboard',
            'lista' => 'Lista',
            'minhas-tarefas' => 'Minhas Tarefas',
            default => 'Planner',
        }) ?>
    </title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap"
        rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <!-- Chart.js (CDN com fallback local garantido) -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
        if (typeof Chart === 'undefined') {
            document.write('<script src="assets/js/chart.umd.min.js"><\/script>');
        }
    </script>

    <link href="assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
</head>

<body class="planner-body">

    <div class="planner-module-container" id="planner-module-root">
        <div class="planner-app-layout">



            <aside class="planner-floating-nav" id="planner-floating-nav" role="navigation" aria-label="Menu principal">

                <?php if ($ehAdmin): ?>
                <div class="planner-floating-nav__actions">
                    <button type="button" class="planner-btn-gold w-100" id="btnNovaTarefa" data-bs-toggle="modal"
                        data-bs-target="#planner-modal-nova-tarefa" title="Criar nova tarefa">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        <span>Nova tarefa</span>
                    </button>
                </div>
                <?php endif; ?>

                <nav class="planner-floating-nav__menu" aria-label="Navegação de visões">
                    <?php
                    $navItems = [
                        ['view' => 'kanban', 'icon' => 'bi-kanban', 'label' => 'Kanban'],
                        ['view' => 'calendario', 'icon' => 'bi-calendar3', 'label' => 'Calendário'],
                        ['view' => 'dashboard', 'icon' => 'bi-bar-chart-line', 'label' => 'Dashboard'],
                        ['view' => 'lista', 'icon' => 'bi-list-task', 'label' => 'Lista'],
                        ['view' => 'minhas-tarefas', 'icon' => 'bi-person-check', 'label' => 'Minhas tarefas', 'badge' => count($minhasTarefas)],
                    ];
                    foreach ($navItems as $nav):
                        $isActive = ($viewAtual === $nav['view']);
                        $qs = http_build_query(array_merge($filtros, ['view' => $nav['view']]));
                        ?>
                        <a href="?<?= $qs ?>" class="planner-floating-nav__link <?= $isActive ? 'is-active' : '' ?>"
                            data-view-link="<?= htmlspecialchars($nav['view']) ?>"
                            aria-current="<?= $isActive ? 'page' : 'false' ?>">
                            <i class="bi <?= $nav['icon'] ?> planner-floating-nav__icon" aria-hidden="true"></i>
                            <span class="planner-floating-nav__label"><?= $nav['label'] ?></span>
                            <?php if (!empty($nav['badge'])): ?>
                                <span class="planner-floating-nav__count"><?= $nav['badge'] ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </aside>


            <div class="planner-main-wrapper">

                <!-- BARRA DE FILTROS FLUTUANTE -->
                <div class="planner-floating-filters-wrapper">
                    <div id="planner-filtros" class="planner-filtros-centered planner-floating-bar" role="search"
                        aria-label="Filtros de tarefas">

                        <!-- 1. Busca textual -->
                        <div class="planner-filtros__search-wrap">
                            <i class="bi bi-search planner-filtros__search-icon" aria-hidden="true"></i>
                            <label for="filtroTexto" class="visually-hidden">Buscar tarefas</label>
                            <input type="search" id="filtroTexto" class="planner-filtros__input"
                                placeholder="Buscar tarefas..." value="<?= htmlspecialchars($filtros['texto']) ?>"
                                data-filter="texto" autocomplete="off" maxlength="200">
                        </div>

                        <!-- 2. Dropdown multi-selecionável: Equipes -->
                        <div class="planner-multiselect" id="msEquipes">
                            <button type="button" class="planner-multiselect__btn" id="btnFiltroEquipes"
                                aria-expanded="false" aria-haspopup="true">
                                <i class="bi bi-people-fill" aria-hidden="true"></i>
                                <span class="planner-multiselect__label">Equipes</span>
                                <span class="planner-multiselect__badge d-none" id="badgeFiltroEquipes">0</span>
                                <i class="bi bi-chevron-down planner-multiselect__arrow ms-auto" aria-hidden="true"></i>
                            </button>
                            <div class="planner-multiselect__dropdown" id="dropdownFiltroEquipes" hidden>
                                <div class="planner-multiselect__header">
                                    <span class="planner-multiselect__title">Filtrar por Equipe</span>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($ehAdmin): ?>
                                        <button type="button" class="planner-multiselect__create-link"
                                            data-bs-toggle="modal" data-bs-target="#planner-modal-nova-equipe"
                                            title="Criar nova equipe">+ Nova</button>
                                        <?php endif; ?>
                                        <button type="button" class="planner-multiselect__clear-link"
                                            data-clear="equipes">Limpar</button>
                                    </div>
                                </div>
                                <div class="planner-multiselect__list">
                                    <?php foreach ($equipes as $eq):
                                        $depth = plannerGetEquipeDepth($equipes, (int) $eq['id']);
                                        ?>
                                        <label class="planner-multiselect__item">
                                            <input type="checkbox" name="filtro_equipes[]" value="<?= (int) $eq['id'] ?>">
                                            <span class="planner-multiselect__check-custom"></span>
                                            <span class="planner-multiselect__text">
                                                <?= str_repeat('&nbsp;&nbsp;', $depth) ?>
                                                <?= htmlspecialchars($eq['nome']) ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Filtro Único para Responsáveis (Multi-selecionável com foto + nome) -->
                        <div class="planner-multiselect" id="msResponsaveis">
                            <button type="button" class="planner-multiselect__btn" id="btnFiltroResponsaveis"
                                aria-expanded="false" aria-haspopup="true">
                                <i class="bi bi-person-fill" aria-hidden="true"></i>
                                <span class="planner-multiselect__label">Responsáveis</span>
                                <span class="planner-multiselect__badge d-none" id="badgeFiltroResponsaveis">0</span>
                                <i class="bi bi-chevron-down planner-multiselect__arrow ms-auto" aria-hidden="true"></i>
                            </button>
                            <div class="planner-multiselect__dropdown" id="dropdownFiltroResponsaveis" hidden>
                                <div class="planner-multiselect__header">
                                    <span class="planner-multiselect__title">Filtrar Responsáveis</span>
                                    <button type="button" class="planner-multiselect__clear-link"
                                        data-clear="responsaveis">Limpar</button>
                                </div>
                                <div class="planner-multiselect__list">
                                    <?php foreach ($usuarios as $u): ?>
                                        <label class="planner-multiselect__item planner-multiselect__item--user">
                                            <input type="checkbox" name="filtro_responsaveis[]"
                                                value="<?= htmlspecialchars($u['id']) ?>">
                                            <span class="planner-multiselect__check-custom"></span>
                                            <?= plannerRenderAvatar($u, 'sm') ?>
                                            <div class="planner-multiselect__user-info">
                                                <span
                                                    class="planner-multiselect__user-name"><?= htmlspecialchars($u['nome']) ?></span>
                                                <span
                                                    class="planner-multiselect__user-role"><?= htmlspecialchars($u['cargo']) ?></span>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- 4. Dropdown multi-selecionável: Prioridades -->
                        <div class="planner-multiselect" id="msPrioridades">
                            <button type="button" class="planner-multiselect__btn" id="btnFiltroPrioridades"
                                aria-expanded="false" aria-haspopup="true">
                                <i class="bi bi-flag-fill" aria-hidden="true"></i>
                                <span class="planner-multiselect__label">Prioridades</span>
                                <span class="planner-multiselect__badge d-none" id="badgeFiltroPrioridades">0</span>
                                <i class="bi bi-chevron-down planner-multiselect__arrow ms-auto" aria-hidden="true"></i>
                            </button>
                            <div class="planner-multiselect__dropdown" id="dropdownFiltroPrioridades" hidden>
                                <div class="planner-multiselect__header">
                                    <span class="planner-multiselect__title">Filtrar por Prioridade</span>
                                    <button type="button" class="planner-multiselect__clear-link"
                                        data-clear="prioridades">Limpar</button>
                                </div>
                                <div class="planner-multiselect__list">
                                    <label class="planner-multiselect__item">
                                        <input type="checkbox" name="filtro_prioridades[]" value="urgente">
                                        <span class="planner-multiselect__check-custom"></span>
                                        <span class="planner-prio-tag prio-urgente">Urgente</span>
                                    </label>
                                    <label class="planner-multiselect__item">
                                        <input type="checkbox" name="filtro_prioridades[]" value="alta">
                                        <span class="planner-multiselect__check-custom"></span>
                                        <span class="planner-prio-tag prio-alta">Alta</span>
                                    </label>
                                    <label class="planner-multiselect__item">
                                        <input type="checkbox" name="filtro_prioridades[]" value="media">
                                        <span class="planner-multiselect__check-custom"></span>
                                        <span class="planner-prio-tag prio-media">Média</span>
                                    </label>
                                    <label class="planner-multiselect__item">
                                        <input type="checkbox" name="filtro_prioridades[]" value="baixa">
                                        <span class="planner-multiselect__check-custom"></span>
                                        <span class="planner-prio-tag prio-baixa">Baixa</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- 5. Filtro de Data: Data Início -->
                        <div class="planner-date-range planner-date-range--inicio">
                            <label for="filtroDataInicio" class="planner-date-range__tag">INÍCIO</label>
                            <input type="date" id="filtroDataInicio" class="planner-date-range__input"
                                aria-label="Data Início" title="Data Início"
                                value="<?= htmlspecialchars($filtros['data_inicio']) ?>">
                        </div>

                        <!-- Seta separadora -->
                        <span class="planner-date-sep" aria-hidden="true">→</span>

                        <!-- 6. Filtro de Data: Data Fim -->
                        <div class="planner-date-range planner-date-range--fim">
                            <label for="filtroDataFim" class="planner-date-range__tag">FIM</label>
                            <input type="date" id="filtroDataFim" class="planner-date-range__input"
                                aria-label="Data Fim" title="Data Fim"
                                value="<?= htmlspecialchars($filtros['data_fim']) ?>">
                        </div>

                        <!-- 7. Botão Limpar -->
                        <button type="button" class="planner-filtros__clear" id="btnLimparFiltros"
                            aria-label="Limpar todos os filtros" title="Limpar todos os filtros">
                            <i class="bi bi-x-circle" aria-hidden="true"></i>
                            <span class="d-none d-xl-inline">Limpar</span>
                        </button>

                    </div>
                </div>

                <main class="planner-main" id="planner-main">

                    <section id="planner-board" data-view="kanban"
                        class="planner-view <?= $viewAtual === 'kanban' ? 'is-active' : '' ?>"
                        aria-label="Quadro Kanban">

                        <div class="planner-board__inner">
                            <?php foreach ($colunas as $coluna):
                                $cardsColuna = array_values(
                                    array_filter($tarefas, fn($t) => (int)$t['coluna_id'] === (int)$coluna['id'])
                                );
                                ?>
                                <section class="board-col" data-column-id="<?= htmlspecialchars($coluna['id']) ?>"
                                    aria-labelledby="col-<?= htmlspecialchars($coluna['id']) ?>-title">

                                    <header class="board-col__header">
                                        <span class="board-col__dot"
                                            style="background-color:<?= htmlspecialchars($coluna['cor']) ?>"
                                            aria-hidden="true"></span>
                                        <h2 class="board-col__title" id="col-<?= htmlspecialchars($coluna['id']) ?>-title">
                                            <?= htmlspecialchars($coluna['titulo']) ?>
                                        </h2>
                                        <span class="board-col__count"
                                            data-count-for="<?= htmlspecialchars($coluna['id']) ?>">
                                            <?= count($cardsColuna) ?>
                                        </span>
                                    </header>

                                    <div class="board-col__body" data-column-body="<?= htmlspecialchars($coluna['id']) ?>"
                                        role="list" aria-label="Tarefas em <?= htmlspecialchars($coluna['titulo']) ?>">

                                        <!-- ESTADO VAZIO INICIAL QUANDO NÃO HOUVER TAREFAS NA COLUNA -->
                                        <div class="planner-empty-column" role="status" <?= !empty($cardsColuna) ? 'style="display:none;"' : '' ?>>
                                            <i class="bi bi-inbox text-muted" aria-hidden="true"></i>
                                            <span>Sem tarefas</span>
                                        </div>

                                        <?php foreach ($cardsColuna as $tarefa):
                                            $pm = plannerPriorityMeta($tarefa['prioridade']);
                                            $sp = $tarefa['status_prazo'];
                                            $totalCom = count(array_filter(
                                                $comentarios,
                                                fn($c) => $c['tarefa_id'] === $tarefa['id']
                                            ));
                                            ?>
                                            <article class="task-card <?= $pm['classe'] ?> sp-<?= $sp ?>" role="listitem"
                                                draggable="true" tabindex="0" data-task-id="<?= (int) $tarefa['id'] ?>"
                                                data-column="<?= htmlspecialchars($tarefa['coluna_id']) ?>"
                                                data-priority="<?= htmlspecialchars($tarefa['prioridade']) ?>"
                                                data-assignees="<?= htmlspecialchars(implode(',', $tarefa['responsaveis'])) ?>"
                                                data-teams="<?= htmlspecialchars(implode(',', $tarefa['equipes'] ?? [])) ?>"
                                                aria-label="<?= htmlspecialchars($tarefa['titulo']) ?>, prioridade <?= $pm['rotulo'] ?>">

                                                <div class="task-card__top">
                                                    <span class="task-card__badge badge-<?= $pm['classe'] ?>">
                                                        <?= $pm['rotulo'] ?>
                                                    </span>
                                                    <div class="task-card__actions" role="group" aria-label="Ações da tarefa">
                                                        <button type="button" class="task-card__action-btn" data-action="mover"
                                                            data-task-id="<?= (int) $tarefa['id'] ?>"
                                                            data-bs-toggle="tooltip" data-bs-placement="top" title="Mover coluna"
                                                            aria-label="Mover tarefa para outra coluna" aria-haspopup="menu"
                                                            aria-expanded="false" tabindex="-1">
                                                            <i class="bi bi-arrows-move" aria-hidden="true"></i>
                                                        </button>
                                                        <button type="button" class="task-card__action-btn"
                                                            data-action="detalhe" data-task-id="<?= (int) $tarefa['id'] ?>"
                                                            data-bs-toggle="tooltip" data-bs-placement="top" title="Editar / Ver detalhes"
                                                            aria-label="Ver detalhes da tarefa" tabindex="-1">
                                                            <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                                        </button>
                                                        <button type="button" class="task-card__action-btn text-danger"
                                                            data-action="excluir-tarefa" data-task-id="<?= (int) $tarefa['id'] ?>"
                                                            data-bs-toggle="tooltip" data-bs-placement="top" title="Excluir tarefa"
                                                            aria-label="Excluir tarefa" tabindex="-1">
                                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                                        </button>
                                                    </div>
                                                </div>

                                                <h3 class="task-card__title"><?= htmlspecialchars($tarefa['titulo']) ?></h3>

                                                <?php if (!empty($tarefa['equipes'])): ?>
                                                    <div class="task-card__teams">
                                                        <?php foreach ($tarefa['equipes'] as $eqId):
                                                            if (isset($equipesMapa[$eqId])): ?>
                                                                <span
                                                                    class="task-card__team-badge"><?= htmlspecialchars($equipesMapa[$eqId]['nome']) ?></span>
                                                            <?php endif; endforeach; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($tarefa['pessoas_soltas'])): ?>
                                                    <div class="task-card__pessoas-soltas">
                                                        <span class="task-card__ps-badge" title="Grupo avulso (pessoas soltas)">
                                                            <i class="bi bi-people" aria-hidden="true"></i> Grupo avulso (<?= count($tarefa['pessoas_soltas']) ?>)
                                                        </span>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($tarefa['descricao'])): ?>
                                                    <p class="task-card__desc"><?= htmlspecialchars($tarefa['descricao']) ?></p>
                                                <?php endif; ?>

                                                <footer class="task-card__footer">
                                                    <span
                                                        class="task-card__date <?= in_array($sp, ['atrasada', 'hoje'], true) ? 'is-' . $sp : '' ?>">
                                                        <i class="bi bi-calendar-event" aria-hidden="true"></i>
                                                        <?= plannerFormatDateShort($tarefa['prazo']) ?>
                                                    </span>
                                                    <span class="task-card__meta">
                                                        <?= plannerRenderAvatarStack($usuarios, $tarefa['responsaveis']) ?>
                                                        <?php if ($totalCom > 0): ?>
                                                            <span class="task-card__comments"
                                                                aria-label="<?= $totalCom ?> comentário(s)">
                                                                <i class="bi bi-chat" aria-hidden="true"></i><?= $totalCom ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </span>
                                                </footer>
                                            </article>
                                        <?php endforeach; ?>

                                        <?php if (count($cardsColuna) === 0): ?>
                                            <p class="board-col__empty" aria-live="polite">
                                                Nenhuma tarefa aqui ainda.
                                            </p>
                                        <?php endif; ?>

                                    </div><!-- /.board-col__body -->
                                </section>
                            <?php endforeach; ?>
                        </div><!-- /.planner-board__inner -->
                    </section>

                    <section data-view="calendario"
                        class="planner-view <?= $viewAtual === 'calendario' ? 'is-active' : '' ?>"
                        aria-label="Calendário de prazos">

                        <div class="planner-calendar">
                            <div class="planner-calendar__toolbar">
                                <button type="button" class="planner-btn-icon" id="calBtnAnterior"
                                    aria-label="Mês anterior">
                                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                                </button>
                                <h2 class="planner-calendar__label" id="calendarLabel" data-year="<?= $calAno ?>"
                                    data-month="<?= $calMes ?>">
                                    <?= htmlspecialchars($mesesPt[$calMes - 1]) ?> de <?= $calAno ?>
                                </h2>
                                <button type="button" class="planner-btn-icon" id="calBtnProximo"
                                    aria-label="Próximo mês">
                                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="planner-btn-ghost planner-btn-ghost--sm" id="calBtnHoje">
                                    Hoje
                                </button>
                            </div>

                            <div class="planner-calendar__grid" id="plannerCalendarGrid" role="grid"
                                aria-labelledby="calendarLabel">
                                <?php foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $ds): ?>
                                    <div class="cal-weekday" role="columnheader"><?= $ds ?></div>
                                <?php endforeach; ?>

                                <?php
                                $primeiroDia = (int) date('w', mktime(0, 0, 0, $calMes, 1, $calAno));
                                $diasNoMes = (int) date('t', mktime(0, 0, 0, $calMes, 1, $calAno));
                                for ($i = 0; $i < $primeiroDia; $i++): ?>
                                    <div class="cal-day is-empty" aria-hidden="true"></div>
                                <?php endfor; ?>

                                <?php for ($d = 1; $d <= $diasNoMes; $d++):
                                    $iso = sprintf('%04d-%02d-%02d', $calAno, $calMes, $d);
                                    $isHoje = ($iso === $hojeIso);
                                    $tsDia = array_filter($todasTarefas, fn($t) => $t['prazo'] === $iso);
                                    $numTs = count($tsDia);
                                    ?>
                                    <button type="button" class="cal-day <?= $isHoje ? 'is-today' : '' ?>"
                                        data-date="<?= $iso ?>" role="gridcell"
                                        aria-label="<?= $d ?> de <?= $mesesPt[$calMes - 1] ?><?= $numTs ? ", $numTs tarefa(s)" : '' ?>">
                                        <span class="cal-day__num"><?= $d ?></span>
                                        <span class="cal-day__dots" aria-hidden="true">
                                            <?php foreach (array_slice(array_values($tsDia), 0, 3) as $td): ?>
                                                <span class="cal-dot priority-<?= htmlspecialchars($td['prioridade']) ?>"
                                                    title="<?= htmlspecialchars($td['titulo']) ?>"></span>
                                            <?php endforeach; ?>
                                            <?php if ($numTs > 3): ?>
                                                <span class="cal-day__more">+<?= $numTs - 3 ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </button>
                                <?php endfor; ?>
                            </div><!-- /.planner-calendar__grid -->
                        </div>
                    </section>

                    <section data-view="dashboard"
                        class="planner-view <?= $viewAtual === 'dashboard' ? 'is-active' : '' ?>"
                        aria-label="Dashboard">

                        <div class="planner-dashboard">
                            <div class="planner-dashboard__header">
                                <h2 class="planner-dashboard__title">Visão geral</h2>
                                <button type="button" class="planner-btn-ghost" id="btnExportCSV">
                                    <i class="bi bi-download" aria-hidden="true"></i>
                                    Exportar CSV
                                </button>
                            </div>

                            <!-- KPIS DINÂMICOS SINCRONIZADOS COM FILTROS -->
                            <div class="planner-kpis" role="list">
                                <div class="kpi-card" role="listitem">
                                    <i class="bi bi-list-check kpi-card__icon" aria-hidden="true"></i>
                                    <span class="kpi-card__value" id="kpiTotalVal"><?= $kpiTotal ?></span>
                                    <span class="kpi-card__label">Total de tarefas</span>
                                </div>
                                <div class="kpi-card kpi-card--danger" role="listitem">
                                    <i class="bi bi-clock-history kpi-card__icon" aria-hidden="true"></i>
                                    <span class="kpi-card__value" id="kpiAtrasadasVal"><?= $kpiAtrasadas ?></span>
                                    <span class="kpi-card__label">Atrasadas</span>
                                </div>
                                <div class="kpi-card kpi-card--success" role="listitem">
                                    <i class="bi bi-check-circle kpi-card__icon" aria-hidden="true"></i>
                                    <span class="kpi-card__value" id="kpiFechadasVal"><?= $kpiFechadas ?></span>
                                    <span class="kpi-card__label">Concluídas</span>
                                </div>
                                <div class="kpi-card kpi-card--warning" role="listitem">
                                    <i class="bi bi-exclamation-triangle kpi-card__icon" aria-hidden="true"></i>
                                    <span class="kpi-card__value" id="kpiUrgentesVal"><?= $kpiUrgentes ?></span>
                                    <span class="kpi-card__label">Urgentes</span>
                                </div>
                            </div>

                            <!-- GRÁFICOS ANALÍTICOS (4 PAINÉIS ESTRATÉGICOS E RESPONSIVOS) -->
                            <div class="planner-charts">
                                <!-- GRÁFICO 1: FUNIL DE COLUNAS / STATUS -->
                                <div class="planner-chart-card">
                                    <h3 class="planner-chart-card__title">
                                        <i class="bi bi-columns-gap" aria-hidden="true"></i>
                                        Distribuição por Status
                                    </h3>
                                    <div class="planner-chart-card__body">
                                        <canvas id="chartPorColuna" role="img"
                                            aria-label="Gráfico de barras: tarefas por coluna"></canvas>
                                    </div>
                                </div>

                                <!-- GRÁFICO 2: SEVERIDADE DAS PRIORIDADES -->
                                <div class="planner-chart-card">
                                    <h3 class="planner-chart-card__title">
                                        <i class="bi bi-pie-chart" aria-hidden="true"></i>
                                        Prioridades das Tarefas
                                    </h3>
                                    <div class="planner-chart-card__body">
                                        <canvas id="chartPorPrioridade" role="img"
                                            aria-label="Gráfico de rosca: prioridades das tarefas"></canvas>
                                    </div>
                                </div>

                                <!-- GRÁFICO 3: SAÚDE DOS PRAZOS OPERACIONAIS -->
                                <div class="planner-chart-card">
                                    <h3 class="planner-chart-card__title">
                                        <i class="bi bi-clock-history" aria-hidden="true"></i>
                                        Saúde das Entregas
                                    </h3>
                                    <div class="planner-chart-card__body">
                                        <canvas id="chartSaudePrazos" role="img"
                                            aria-label="Gráfico de rosca: saúde dos prazos"></canvas>
                                    </div>
                                </div>

                                <!-- GRÁFICO 4: CARGA DE TRABALHO POR EQUIPE -->
                                <div class="planner-chart-card">
                                    <h3 class="planner-chart-card__title">
                                        <i class="bi bi-people-fill" aria-hidden="true"></i>
                                        Carga por Equipe
                                    </h3>
                                    <div class="planner-chart-card__body">
                                        <canvas id="chartCargaEquipes" role="img"
                                            aria-label="Gráfico de barras: tarefas por equipe"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section data-view="lista" class="planner-view <?= $viewAtual === 'lista' ? 'is-active' : '' ?>"
                        aria-label="Lista de tarefas">

                        <div class="planner-lista">
                            <div class="planner-lista__header">
                                <div>
                                    <h2 class="planner-lista__title">Todas as tarefas</h2>
                                    <p class="text-muted small mb-0">Gerencie, ordene e filtre tarefas em formato de tabela interativa.</p>
                                </div>
                                <span class="planner-lista__count" id="listaCount" aria-live="polite">
                                    <?= count($todasTarefas) ?> tarefas
                                </span>
                            </div>

                            <!-- ABAS DE FILTRAGEM RÁPIDA DA LISTA -->
                            <div class="planner-lista__tabs mb-3" role="tablist" aria-label="Filtros rápidos de lista">
                                <button type="button" class="planner-lista__tab is-active" data-lista-tab="todas">Todas (<?= count($todasTarefas) ?>)</button>
                                <button type="button" class="planner-lista__tab" data-lista-tab="pendentes">Pendentes (<?= count(array_filter($todasTarefas, fn($t) => (int)$t['coluna_id'] !== 4)) ?>)</button>
                                <button type="button" class="planner-lista__tab" data-lista-tab="atrasadas">Atrasadas (<?= count(array_filter($todasTarefas, fn($t) => $t['status_prazo'] === 'atrasada')) ?>)</button>
                                <button type="button" class="planner-lista__tab" data-lista-tab="concluidas">Concluídas (<?= count(array_filter($todasTarefas, fn($t) => (int)$t['coluna_id'] === 4)) ?>)</button>
                            </div>

                            <div class="planner-table-wrapper">
                                <table class="planner-table" id="plannerTabela" aria-label="Lista detalhada de tarefas">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="planner-table__th" data-sort="titulo" tabindex="0"
                                                aria-sort="none">
                                                Título <i class="bi bi-chevron-expand" aria-hidden="true"></i>
                                            </th>
                                            <th scope="col" class="planner-table__th" data-sort="priority" tabindex="0"
                                                aria-sort="none">
                                                Prioridade <i class="bi bi-chevron-expand" aria-hidden="true"></i>
                                            </th>
                                            <th scope="col" class="planner-table__th" data-sort="coluna_id" tabindex="0"
                                                aria-sort="none">
                                                Status <i class="bi bi-chevron-expand" aria-hidden="true"></i>
                                            </th>
                                            <th scope="col" class="planner-table__th" data-sort="prazo" tabindex="0"
                                                aria-sort="none">
                                                Prazo <i class="bi bi-chevron-expand" aria-hidden="true"></i>
                                            </th>
                                            <th scope="col" class="planner-table__th">Responsáveis</th>
                                            <th scope="col" class="planner-table__th planner-table__th--actions">
                                                <span>Ações</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody id="plannerTabelaBody">
                                        <!-- ESTADO VAZIO DA TABELA -->
                                        <tr id="plannerTabelaEmptyRow" class="planner-table__empty-row" <?= empty($todasTarefas) ? '' : 'style="display:none;"' ?>>
                                            <td colspan="6" class="text-center py-5 text-muted">
                                                <i class="bi bi-inbox mb-2 d-block" style="font-size:2rem;"></i>
                                                <p class="mb-0">Nenhuma tarefa encontrada.</p>
                                            </td>
                                        </tr>
                                        <?php foreach ($todasTarefas as $tarefa):
                                            $pm = plannerPriorityMeta($tarefa['prioridade']);
                                            $col = $colunasMapa[$tarefa['coluna_id']] ?? null;
                                            $sp = $tarefa['status_prazo'];
                                            ?>
                                            <tr class="planner-table__row sp-<?= $sp ?>" data-task-id="<?= (int) $tarefa['id'] ?>"
                                                data-priority="<?= htmlspecialchars($tarefa['prioridade']) ?>"
                                                data-coluna="<?= htmlspecialchars($tarefa['coluna_id']) ?>"
                                                data-prazo="<?= htmlspecialchars($tarefa['prazo'] ?? '') ?>"
                                                data-titulo="<?= htmlspecialchars(mb_strtolower($tarefa['titulo'])) ?>"
                                                data-assignees="<?= htmlspecialchars(implode(',', $tarefa['responsaveis'])) ?>">

                                                <td class="planner-table__td planner-table__td--title">
                                                    <button type="button" class="planner-table__title-btn"
                                                        data-action="detalhe" data-task-id="<?= (int) $tarefa['id'] ?>"
                                                        data-bs-toggle="tooltip" data-bs-placement="top" title="Clique para ver ou editar detalhes">
                                                        <?= htmlspecialchars($tarefa['titulo']) ?>
                                                    </button>
                                                </td>
                                                <td class="planner-table__td">
                                                    <span class="task-card__badge badge-<?= $pm['classe'] ?>">
                                                        <?= $pm['rotulo'] ?>
                                                    </span>
                                                </td>
                                                <td class="planner-table__td">
                                                    <span class="planner-status-dot"
                                                        style="background:<?= htmlspecialchars($col['cor'] ?? '#94A3B8') ?>"></span>
                                                    <span class="fw-medium"><?= htmlspecialchars($col['titulo'] ?? $tarefa['coluna_id']) ?></span>
                                                </td>
                                                <td class="planner-table__td">
                                                    <span
                                                        class="task-card__date <?= in_array($sp, ['atrasada', 'hoje'], true) ? 'is-' . $sp : '' ?>">
                                                        <i class="bi <?= $sp === 'atrasada' ? 'bi-exclamation-octagon-fill text-danger' : 'bi-calendar-event' ?>" aria-hidden="true"></i>
                                                        <?= plannerFormatDateShort($tarefa['prazo']) ?>
                                                    </span>
                                                </td>
                                                <td class="planner-table__td">
                                                    <?= plannerRenderAvatarStack($usuarios, $tarefa['responsaveis']) ?>
                                                    <?php if (!empty($tarefa['pessoas_soltas'])): ?>
                                                        <div class="mt-1" title="Pessoas soltas (grupo avulso)">
                                                            <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle" style="font-size:0.7rem;">
                                                                <i class="bi bi-people"></i> <?= count($tarefa['pessoas_soltas']) ?> avulso(s)
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="planner-table__td planner-table__td--actions">
                                                    <button type="button" class="planner-btn-icon-sm" data-action="detalhe"
                                                        data-task-id="<?= (int) $tarefa['id'] ?>"
                                                        data-bs-toggle="tooltip" data-bs-placement="top" title="Ver / Editar detalhes">
                                                        <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                                    </button>
                                                    <button type="button" class="planner-btn-icon-sm" data-action="mover"
                                                        data-task-id="<?= (int) $tarefa['id'] ?>"
                                                        data-bs-toggle="tooltip" data-bs-placement="top" title="Mover tarefa"
                                                        aria-haspopup="menu" aria-expanded="false">
                                                        <i class="bi bi-arrows-move" aria-hidden="true"></i>
                                                    </button>
                                                    <button type="button" class="planner-btn-icon-sm text-danger" data-action="excluir-tarefa"
                                                        data-task-id="<?= (int) $tarefa['id'] ?>"
                                                        data-bs-toggle="tooltip" data-bs-placement="top" title="Excluir tarefa">
                                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <section data-view="minhas-tarefas"
                        class="planner-view <?= $viewAtual === 'minhas-tarefas' ? 'is-active' : '' ?>"
                        aria-label="Minhas tarefas" id="viewMinhasTarefas">

                        <div class="planner-minhas">

                            <!-- Cabeçalho do usuário -->
                            <div class="planner-minhas__header">
                                <?= plannerRenderAvatar($usuarioAtual, 'lg') ?>
                                <div>
                                    <h2 class="planner-minhas__title">
                                        Olá, <?= htmlspecialchars(explode(' ', $usuarioAtual['nome'])[0]) ?>
                                    </h2>
                                    <p class="planner-minhas__subtitle" id="minhasSubtitle">
                                        <?= count($minhasTarefas) ?> tarefa(s) atribuída(s) a você
                                    </p>
                                </div>
                            </div>

                            <!-- Grid de cards com estado vazio -->
                            <div class="planner-minhas__grid" id="minhasGrid">
                                <div class="planner-empty-state w-100 text-center py-5" id="minhasEmptyState" <?= empty($minhasTarefas) ? '' : 'style="display:none;"' ?>>
                                    <i class="bi bi-clipboard-check text-success" aria-hidden="true" style="font-size: 2.8rem;"></i>
                                    <h3 class="h5 mt-3 mb-1">Nenhuma tarefa atribuída</h3>
                                    <p class="text-muted small mb-0">Você não possui tarefas pendentes no momento.</p>
                                </div>
                                <?php foreach ($minhasTarefas as $tarefa):
                                    $pm  = plannerPriorityMeta($tarefa['prioridade']);
                                    $sp  = $tarefa['status_prazo'];
                                    $col = $colunasMapa[$tarefa['coluna_id']] ?? null;
                                    $totalComMinhas = count(array_filter(
                                        $comentarios,
                                        fn($c) => $c['tarefa_id'] === $tarefa['id']
                                    ));
                                    ?>
                                    <article class="planner-minhas__card <?= $pm['classe'] ?> sp-<?= $sp ?>"
                                        data-task-id="<?= (int) $tarefa['id'] ?>"
                                        data-priority="<?= htmlspecialchars($tarefa['prioridade']) ?>"
                                        data-assignees="<?= htmlspecialchars(implode(',', $tarefa['responsaveis'])) ?>">

                                        <!-- Topo do card: prioridade + status -->
                                        <div class="planner-minhas__card-top">
                                            <span class="task-card__badge badge-<?= $pm['classe'] ?>"><?= $pm['rotulo'] ?></span>
                                            <span class="planner-minhas__col-badge"
                                                style="border-color: <?= htmlspecialchars($col['cor'] ?? '#94A3B8') ?>; color: <?= htmlspecialchars($col['cor'] ?? '#94A3B8') ?>">
                                                <?= htmlspecialchars($col['titulo'] ?? $tarefa['coluna_id']) ?>
                                            </span>
                                        </div>

                                        <!-- Título -->
                                        <button type="button" class="planner-minhas__card-title" data-action="detalhe"
                                            data-task-id="<?= (int) $tarefa['id'] ?>">
                                            <?= htmlspecialchars($tarefa['titulo']) ?>
                                        </button>

                                        <!-- Descrição (se houver) -->
                                        <?php if (!empty($tarefa['descricao'])): ?>
                                            <p class="planner-minhas__card-desc"><?= htmlspecialchars(mb_substr($tarefa['descricao'], 0, 100)) ?><?= strlen($tarefa['descricao']) > 100 ? '…' : '' ?></p>
                                        <?php endif; ?>

                                        <!-- Rodapé: prazo + comentários -->
                                        <footer class="planner-minhas__card-footer">
                                            <span class="task-card__date <?= in_array($sp, ['atrasada', 'hoje'], true) ? 'is-' . $sp : '' ?>">
                                                <i class="bi bi-calendar-event" aria-hidden="true"></i>
                                                <?= plannerFormatDateShort($tarefa['prazo']) ?>
                                            </span>
                                            <?php if ($totalComMinhas > 0): ?>
                                                <span class="task-card__comments">
                                                    <i class="bi bi-chat" aria-hidden="true"></i><?= $totalComMinhas ?>
                                                </span>
                                            <?php endif; ?>
                                        </footer>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                        </div>
                    </section>

                </main>
            </div>
        </div>
    </div>


    <div id="planner-mover-menu" class="planner-move-menu" role="menu" aria-labelledby="plannerMoverMenuLabel"
        data-task-id="" hidden>
        <p class="planner-move-menu__label" id="plannerMoverMenuLabel">Mover para:</p>
        <?php foreach ($colunas as $coluna): ?>
            <button type="button" class="planner-move-menu__item" role="menuitem"
                data-move-to="<?= htmlspecialchars($coluna['id']) ?>" tabindex="-1">
                <span class="planner-move-menu__dot" style="background:<?= htmlspecialchars($coluna['cor']) ?>"></span>
                <?= htmlspecialchars($coluna['titulo']) ?>
            </button>
        <?php endforeach; ?>
    </div>


    <div class="modal fade" id="planner-modal-tarefa" tabindex="-1" aria-labelledby="plannerModalTarefaLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content planner-modal">

                <div class="modal-header planner-modal__header">
                    <span class="task-card__badge" id="plannerModalPrioBadge"></span>
                    <h2 class="modal-title" id="plannerModalTarefaLabel">Carregando&hellip;</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body planner-modal__body">

                    <!-- Meta: coluna · prazo · criador -->
                    <div class="planner-modal__meta" id="plannerModalMeta">
                        <span class="planner-modal__meta-item" id="plannerModalColuna">
                            <i class="bi bi-columns-gap" aria-hidden="true"></i>
                            <span></span>
                        </span>
                        <span class="planner-modal__meta-item" id="plannerModalPrazo">
                            <i class="bi bi-calendar-event" aria-hidden="true"></i>
                            <span></span>
                        </span>
                        <span class="planner-modal__meta-item" id="plannerModalCriador">
                            <i class="bi bi-person" aria-hidden="true"></i>
                            <span></span>
                        </span>
                    </div>

                    <!-- Descrição -->
                    <section class="planner-modal__section">
                        <h3 class="planner-modal__section-title">Descrição</h3>
                        <p id="plannerModalDesc" class="planner-modal__desc text-muted">—</p>
                    </section>

                    <!-- 3 ACORDEONS FECHADOS POR PADRÃO NO MODAL DE DETALHES -->
                    <div class="planner-accordion-group mb-3" id="detalheTarefaAccordionGroup">
                        <!-- Acordeon 1: Equipes Vinculadas (FECHADO POR PADRÃO) -->
                        <div class="planner-accordion" id="accModalEquipes">
                            <button type="button" class="planner-accordion__header" aria-expanded="false" aria-controls="accModalEquipesContent">
                                <div class="planner-accordion__title-wrap">
                                    <i class="bi bi-briefcase-fill text-warning planner-accordion__icon" aria-hidden="true"></i>
                                    <span class="planner-accordion__title">Equipes</span>
                                    <span class="planner-accordion__badge d-none" id="badgeModalEquipes">0</span>
                                </div>
                                <i class="bi bi-chevron-down planner-accordion__chevron" aria-hidden="true"></i>
                            </button>
                            <div class="planner-accordion__content" id="accModalEquipesContent" hidden>
                                <div class="planner-modal__teams d-flex flex-wrap gap-1 mt-1" id="plannerModalEquipes"></div>
                                <button type="button" class="planner-btn-ghost planner-btn-ghost--sm mt-2" id="btnEditarEquipes">
                                    <i class="bi bi-briefcase" aria-hidden="true"></i>
                                    Editar equipes
                                </button>
                                <div class="planner-assignee-picker" id="plannerEquipesPicker" hidden role="group" aria-label="Selecionar equipes vinculadas">
                                    <div class="planner-teams-picker mb-2">
                                        <?php foreach ($equipes as $eq): ?>
                                            <label class="planner-team-option" data-team-id="<?= (int) $eq['id'] ?>">
                                                <input type="checkbox" name="eq_picker[]" value="<?= (int) $eq['id'] ?>">
                                                <span><?= htmlspecialchars($eq['nome']) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="planner-assignee-picker__footer">
                                        <button type="button" class="btn btn-sm btn-primary" id="btnSalvarEquipes">Salvar equipes</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelarEquipes">Cancelar</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Acordeon 2: Responsáveis Formais (FECHADO POR PADRÃO) -->
                        <div class="planner-accordion" id="accModalResponsaveis">
                            <button type="button" class="planner-accordion__header" aria-expanded="false" aria-controls="accModalResponsaveisContent">
                                <div class="planner-accordion__title-wrap">
                                    <i class="bi bi-person-fill text-primary planner-accordion__icon" aria-hidden="true"></i>
                                    <span class="planner-accordion__title">Responsáveis Formais</span>
                                    <span class="planner-accordion__badge d-none" id="badgeModalResponsaveis">0</span>
                                </div>
                                <i class="bi bi-chevron-down planner-accordion__chevron" aria-hidden="true"></i>
                            </button>
                            <div class="planner-accordion__content" id="accModalResponsaveisContent" hidden>
                                <div class="planner-modal__assignees" id="plannerModalAssignees"></div>
                                <button type="button" class="planner-btn-ghost planner-btn-ghost--sm mt-2" id="btnEditarResponsaveis">
                                    <i class="bi bi-person-plus" aria-hidden="true"></i>
                                    Editar responsáveis
                                </button>
                                <div class="planner-assignee-picker" id="plannerAssigneePicker" hidden role="group" aria-label="Selecionar responsáveis">
                                    <?php foreach ($usuarios as $u): ?>
                                        <label class="planner-assignee-option">
                                            <input type="checkbox" name="resp_picker[]" value="<?= htmlspecialchars($u['id']) ?>" class="visually-hidden">
                                            <?= plannerRenderAvatar($u, 'sm') ?>
                                            <span class="planner-assignee-option__nome"><?= htmlspecialchars($u['nome']) ?></span>
                                            <span class="planner-assignee-option__cargo"><?= htmlspecialchars($u['cargo']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                    <div class="planner-assignee-picker__footer">
                                        <button type="button" class="btn btn-sm btn-primary" id="btnSalvarResponsaveis">Salvar responsáveis</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelarResponsaveis">Cancelar</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Acordeon 3: Pessoas Avulsas (FECHADO POR PADRÃO) -->
                        <div class="planner-accordion" id="accModalPessoasSoltas">
                            <button type="button" class="planner-accordion__header" aria-expanded="false" aria-controls="accModalPessoasSoltasContent">
                                <div class="planner-accordion__title-wrap">
                                    <i class="bi bi-people-fill text-success planner-accordion__icon" aria-hidden="true"></i>
                                    <span class="planner-accordion__title">Pessoas Avulsas</span>
                                    <span class="planner-accordion__badge d-none" id="badgeModalPessoasSoltas">0</span>
                                </div>
                                <i class="bi bi-chevron-down planner-accordion__chevron" aria-hidden="true"></i>
                            </button>
                            <div class="planner-accordion__content" id="accModalPessoasSoltasContent" hidden>
                                <div class="planner-modal__assignees" id="plannerModalPessoasSoltas"></div>
                                <button type="button" class="planner-btn-ghost planner-btn-ghost--sm mt-2" id="btnEditarPessoasSoltas">
                                    <i class="bi bi-people" aria-hidden="true"></i>
                                    Editar grupo avulso
                                </button>
                                <div class="planner-assignee-picker" id="plannerPessoasSoltasPicker" hidden role="group" aria-label="Selecionar pessoas soltas para grupo avulso">
                                    <?php foreach ($usuarios as $u): ?>
                                        <label class="planner-assignee-option">
                                            <input type="checkbox" name="ps_picker[]" value="<?= htmlspecialchars($u['id']) ?>" class="visually-hidden">
                                            <?= plannerRenderAvatar($u, 'sm') ?>
                                            <span class="planner-assignee-option__nome"><?= htmlspecialchars($u['nome']) ?></span>
                                            <span class="planner-assignee-option__cargo"><?= htmlspecialchars($u['cargo']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                    <div class="planner-assignee-picker__footer">
                                        <button type="button" class="btn btn-sm btn-primary" id="btnSalvarPessoasSoltas">Salvar grupo avulso</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelarPessoasSoltas">Cancelar</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Thread de comentários -->
                    <section class="planner-modal__section">
                        <h3 class="planner-modal__section-title">Comentários</h3>
                        <div class="planner-comments" id="plannerCommentThread" aria-live="polite"
                            aria-label="Thread de comentários">
                            <p class="text-muted small">Nenhum comentário ainda.</p>
                        </div>

                        <form class="planner-comment-form" id="plannerCommentForm" novalidate>
                            <input type="hidden" name="tarefa_id" id="plannerCommentTarefaId">
                            <div class="planner-comment-form__row">
                                <?= plannerRenderAvatar($usuarioAtual, 'sm') ?>
                                <label for="plannerCommentText" class="visually-hidden">Novo comentário</label>
                                <textarea class="planner-textarea" id="plannerCommentText" name="texto"
                                    placeholder="Escreva um comentário..." rows="2" maxlength="2000" required
                                    aria-required="true"></textarea>
                            </div>
                            <div class="planner-comment-form__actions">
                                <button type="submit" class="btn btn-primary btn-sm">Comentar</button>
                            </div>
                        </form>
                    </section>

                    <!-- Log de atividade -->
                    <section class="planner-modal__section planner-modal__section--activity">
                        <h3 class="planner-modal__section-title">Atividade</h3>
                        <ol class="planner-activity-log" id="plannerActivityLog" reversed aria-label="Log de atividade">
                            <!-- Populado pelo planner.js -->
                        </ol>
                    </section>

                </div><!-- /.modal-body -->

                <div class="modal-footer planner-modal__footer d-flex justify-content-between">
                    <div>
                        <button type="button" class="btn btn-outline-danger btn-sm d-inline-flex align-items-center gap-1" id="plannerModalBtnExcluir"
                            data-bs-toggle="tooltip" data-bs-placement="top" title="Excluir esta tarefa permanentemente">
                            <i class="bi bi-trash" aria-hidden="true"></i>
                            <span>Excluir</span>
                        </button>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="planner-btn-ghost" id="plannerModalBtnMover" data-action="mover"
                            aria-haspopup="menu" aria-expanded="false" data-bs-toggle="tooltip" data-bs-placement="top" title="Mover para outra coluna">
                            <i class="bi bi-arrows-move" aria-hidden="true"></i>
                            Mover para&hellip;
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            Fechar
                        </button>
                    </div>
                </div>

            </div><!-- /.modal-content -->
        </div>
    </div>


    <div class="modal fade" id="planner-modal-nova-tarefa" tabindex="-1" aria-labelledby="plannerModalNovaTarefaLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content planner-modal" id="plannerFormNovaTarefa" novalidate>

                <div class="modal-header planner-modal__header">
                    <h2 class="modal-title" id="plannerModalNovaTarefaLabel">Nova tarefa</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label for="novaTarefaTitulo" class="form-label">
                            Título <span aria-hidden="true" class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="novaTarefaTitulo" name="titulo" required
                            maxlength="200" autocomplete="off">
                        <div class="invalid-feedback">O título é obrigatório.</div>
                    </div>

                    <div class="mb-3">
                        <label for="novaTarefaDescricao" class="form-label">Descrição</label>
                        <textarea class="form-control" id="novaTarefaDescricao" name="descricao" rows="2"
                            maxlength="1000"></textarea>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="novaTarefaColuna" class="form-label">Coluna</label>
                            <select class="form-select" id="novaTarefaColuna" name="coluna_id">
                                <?php foreach ($colunas as $c): ?>
                                    <option value="<?= htmlspecialchars($c['id']) ?>">
                                        <?= htmlspecialchars($c['titulo']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label for="novaTarefaPrioridade" class="form-label">Prioridade</label>
                            <select class="form-select" id="novaTarefaPrioridade" name="prioridade">
                                <option value="baixa">Baixa</option>
                                <option value="media" selected>Média</option>
                                <option value="alta">Alta</option>
                                <option value="urgente">Urgente</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="novaTarefaPrazo" class="form-label">Prazo</label>
                        <input type="date" class="form-control" id="novaTarefaPrazo" name="prazo">
                    </div>

                    <!-- 3 Acordeons de Atribuição: Equipes, Responsáveis e Pessoas Soltas -->
                    <div class="planner-accordion-group mb-3" id="novaTarefaAccordionGroup">
                        <span class="form-label d-block mb-2">Atribuição de Executores (Selecione Equipe, Responsáveis ou Pessoas Soltas)</span>

                        <!-- Acordeon 1: Equipes -->
                        <div class="planner-accordion" id="accEquipes">
                            <button type="button" class="planner-accordion__header" aria-expanded="false" aria-controls="accEquipesContent">
                                <div class="planner-accordion__title-wrap">
                                    <i class="bi bi-briefcase-fill text-warning planner-accordion__icon" aria-hidden="true"></i>
                                    <span class="planner-accordion__title">Equipes</span>
                                    <span class="planner-accordion__badge d-none" id="badgeAccEquipes">0</span>
                                </div>
                                <div class="planner-accordion__summary" id="summaryAccEquipes">
                                    <span class="text-muted small">Nenhuma selecionada</span>
                                </div>
                                <i class="bi bi-chevron-down planner-accordion__chevron" aria-hidden="true"></i>
                            </button>
                            <div class="planner-accordion__content" id="accEquipesContent" hidden>
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="text-muted small">Equipes cadastradas:</span>
                                    <?php if ($ehAdmin): ?>
                                    <button type="button" class="btn btn-sm btn-link text-warning p-0 text-decoration-none"
                                        data-bs-toggle="modal" data-bs-target="#planner-modal-nova-equipe"
                                        style="font-size: 0.78rem;">+ Nova equipe</button>
                                    <?php endif; ?>
                                </div>
                                <div class="planner-teams-picker" id="novaTarefaTeamsPicker" role="group" aria-label="Equipes responsáveis">
                                    <?php foreach ($equipes as $eq): ?>
                                        <label class="planner-team-option" data-team-id="<?= (int) $eq['id'] ?>">
                                            <input type="checkbox" name="equipes[]" value="<?= (int) $eq['id'] ?>">
                                            <span><?= htmlspecialchars($eq['nome']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Acordeon 2: Responsáveis Formais -->
                        <div class="planner-accordion" id="accResponsaveis">
                            <button type="button" class="planner-accordion__header" aria-expanded="false" aria-controls="accResponsaveisContent">
                                <div class="planner-accordion__title-wrap">
                                    <i class="bi bi-person-fill text-primary planner-accordion__icon" aria-hidden="true"></i>
                                    <span class="planner-accordion__title">Responsáveis Formais</span>
                                    <span class="planner-accordion__badge d-none" id="badgeAccResponsaveis">0</span>
                                </div>
                                <div class="planner-accordion__summary" id="summaryAccResponsaveis">
                                    <span class="text-muted small">Nenhum selecionado</span>
                                </div>
                                <i class="bi bi-chevron-down planner-accordion__chevron" aria-hidden="true"></i>
                            </button>
                            <div class="planner-accordion__content" id="accResponsaveisContent" hidden>
                                <div class="planner-assignee-picker" role="group" aria-label="Responsáveis formais">
                                    <?php foreach ($usuarios as $u): ?>
                                        <label class="planner-assignee-option">
                                            <input type="checkbox" name="responsaveis[]" value="<?= htmlspecialchars($u['id']) ?>"
                                                class="visually-hidden">
                                            <?= plannerRenderAvatar($u, 'sm') ?>
                                            <span class="planner-assignee-option__nome"><?= htmlspecialchars($u['nome']) ?></span>
                                            <span class="planner-assignee-option__cargo"><?= htmlspecialchars($u['cargo']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Acordeon 3: Pessoas Soltas (Grupo Avulso) -->
                        <div class="planner-accordion" id="accPessoasSoltas">
                            <button type="button" class="planner-accordion__header" aria-expanded="false" aria-controls="accPessoasSoltasContent">
                                <div class="planner-accordion__title-wrap">
                                    <i class="bi bi-people-fill text-success planner-accordion__icon" aria-hidden="true"></i>
                                    <span class="planner-accordion__title">Pessoas Soltas (Grupo Avulso)</span>
                                    <span class="planner-accordion__badge d-none" id="badgeAccPessoasSoltas">0</span>
                                </div>
                                <div class="planner-accordion__summary" id="summaryAccPessoasSoltas">
                                    <span class="text-muted small">Nenhuma selecionada</span>
                                </div>
                                <i class="bi bi-chevron-down planner-accordion__chevron" aria-hidden="true"></i>
                            </button>
                            <div class="planner-accordion__content" id="accPessoasSoltasContent" hidden>
                                <p class="text-muted small mb-2">Selecione pessoas avulsas que atuarão juntas nesta tarefa sem necessidade de formar uma equipe cadastrada.</p>
                                <div class="planner-assignee-picker" role="group" aria-label="Pessoas soltas para grupo avulso">
                                    <?php foreach ($usuarios as $u): ?>
                                        <label class="planner-assignee-option">
                                            <input type="checkbox" name="pessoas_soltas[]" value="<?= htmlspecialchars($u['id']) ?>"
                                                class="visually-hidden">
                                            <?= plannerRenderAvatar($u, 'sm') ?>
                                            <span class="planner-assignee-option__nome"><?= htmlspecialchars($u['nome']) ?></span>
                                            <span class="planner-assignee-option__cargo"><?= htmlspecialchars($u['cargo']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="plannerBtnCriarTarefa">Criar tarefa</button>
                </div>
            </form>
        </div>
    </div>


    <div class="modal fade" id="planner-modal-nova-equipe" tabindex="-1" aria-labelledby="modalNovaEquipeLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content planner-modal-content" id="plannerFormNovaEquipe" novalidate>
                <div class="modal-header">
                    <h2 class="modal-title h5 d-flex align-items-center gap-2" id="modalNovaEquipeLabel">
                        <i class="bi bi-people-fill text-warning"></i>
                        <span>Criar Nova Equipe</span>
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="novaEquipeNome" class="form-label">
                            Nome da Equipe <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="novaEquipeNome" name="nome" required maxlength="100"
                            placeholder="Ex: Produto & Growth, Mobile, DevOps..." autocomplete="off">
                        <div class="invalid-feedback">O nome da equipe é obrigatório.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label d-block">Cor da Equipe</label>
                        <div class="planner-color-picker-group">
                            <div class="planner-color-swatches" id="plannerColorSwatches">
                                <?php
                                $coresPadrao = ['#10B981', '#FACC15', '#3B82F6', '#EC4899', '#8B5CF6', '#F97316', '#06B6D4', '#14B8A6'];
                                foreach ($coresPadrao as $idx => $corHex): ?>
                                    <label class="planner-color-swatch-label">
                                        <input type="radio" name="cor_preset" value="<?= $corHex ?>" <?= $idx === 0 ? 'checked' : '' ?> class="visually-hidden">
                                        <span class="planner-color-swatch" style="--swatch-color: <?= $corHex ?>;"></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <input type="color" id="novaEquipeCor" name="cor" value="#10B981"
                                class="form-control form-control-color planner-custom-color-input"
                                title="Escolher cor personalizada">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="novaEquipePai" class="form-label">Equipe Superior / Subdivisão (Opcional)</label>
                        <select class="form-select" id="novaEquipePai" name="pai_id">
                            <option value="">Nenhuma (Equipe Raiz)</option>
                            <?php foreach ($equipes as $eq): ?>
                                <option value="<?= (int) $eq['id'] ?>">
                                    <?= htmlspecialchars($eq['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- CAMPO DE SELEÇÃO DO LÍDER DA EQUIPE -->
                    <div class="mb-3">
                        <label for="novaEquipeLider" class="form-label">Líder da Equipe (Opcional)</label>
                        <select class="form-select" id="novaEquipeLider" name="lider_id">
                            <option value="">Nenhum líder atribuído</option>
                            <?php foreach ($usuarios as $u): ?>
                                <option value="<?= htmlspecialchars($u['id']) ?>">
                                    <?= htmlspecialchars($u['nome']) ?> (<?= htmlspecialchars($u['matricula'] ?? $u['chave']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-1">
                        <span class="form-label d-block mb-1">Membros Iniciais (Opcional)</span>
                        <div class="planner-assignee-picker" role="group" aria-label="Membros da nova equipe">
                            <?php foreach ($usuarios as $u): ?>
                                <label class="planner-assignee-option">
                                    <input type="checkbox" name="membros[]" value="<?= htmlspecialchars($u['id']) ?>"
                                        class="visually-hidden">
                                    <?= plannerRenderAvatar($u, 'sm') ?>
                                    <span class="planner-assignee-option__nome"><?= htmlspecialchars($u['nome']) ?></span>
                                    <span class="planner-assignee-option__cargo"><?= htmlspecialchars($u['cargo']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="plannerBtnCriarEquipe">Criar equipe</button>
                </div>
            </form>
        </div>
    </div>


    <!-- MODAL CENTRALIZADO: DETALHES DAS TAREFAS DO DIA NO CALENDÁRIO -->
    <div class="modal fade" id="plannerModalDia" tabindex="-1" aria-labelledby="modalDiaLabel" aria-hidden="true" data-bs-backdrop="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content planner-modal">
                <div class="modal-header planner-modal__header">
                    <h3 class="modal-title h5 d-flex align-items-center gap-2 mb-0" id="modalDiaLabel">
                        <i class="bi bi-calendar-event text-primary"></i>
                        <span>Tarefas do dia</span>
                    </h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body planner-modal__body" id="plannerModalDiaBody">
                    <p class="text-muted small">Selecione um dia para ver as tarefas.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL DE CONFIRMAÇÃO VISUAL ESTILIZADO (SUBSTITUI ALERTS E CONFIRMS NATIVOS) -->
    <div class="modal fade" id="plannerModalConfirmacao" tabindex="-1" aria-labelledby="plannerModalConfirmacaoLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content planner-modal text-center p-3">
                <div class="modal-body pb-2">
                    <div class="planner-confirm-icon-wrapper mb-3" id="plannerConfirmIconWrap">
                        <i class="bi bi-exclamation-triangle-fill text-danger fs-1" id="plannerConfirmIcon"></i>
                    </div>
                    <h3 class="h5 fw-bold mb-2 text-dark" id="plannerModalConfirmacaoLabel">Confirmar exclusão</h3>
                    <p class="text-muted small mb-0" id="plannerConfirmMensagem">Tem certeza que deseja executar esta ação?</p>
                </div>
                <div class="modal-footer border-0 justify-content-center gap-2 pt-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal" id="plannerBtnCancelarConfirmacao">Cancelar</button>
                    <button type="button" class="btn btn-sm btn-danger px-3" id="plannerBtnExecutarConfirmacao">Confirmar</button>
                </div>
            </div>
        </div>
    </div>

    <script type="application/json" id="planner-data">
<?= $plannerData ?>
</script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/planner.js?v=<?= time() ?>" defer></script>
</body>

</html>