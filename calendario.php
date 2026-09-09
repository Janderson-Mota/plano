<?php
/**
 * calendario.php - Visão de calendário
 * O PHP renderiza a grade do mês atual com as tarefas já posicionadas
 * por prazo. A navegação entre meses e o painel de detalhes do dia
 * são assumidos pelo JS (assets/js/calendar.js), que reutiliza os
 * mesmos dados via um bloco JSON embutido.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/functions.php';

$team    = getTeamMembers();
$columns = getColumns();
$tasks   = getTasks();

$anoAtual = (int) date('Y');
$mesAtual = (int) date('n');
$dias     = buildMonthDays($anoAtual, $mesAtual, $tasks);

$mesesPt = [
    'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
    'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
];
$labelMes = $mesesPt[$mesAtual - 1] . ' de ' . $anoAtual;

$pageTitle  = 'Calendário · Orbit';
$activePage = 'calendario';
require __DIR__ . '/includes/header.php';
?>

<div class="calendar-toolbar">
    <div class="calendar-toolbar__nav">
        <button type="button" class="btn-icon" id="btnMesAnterior" aria-label="Mês anterior">
            <i class="bi bi-chevron-left" aria-hidden="true"></i>
        </button>
        <h2 class="calendar-toolbar__label" id="calendarLabel" data-year="<?= $anoAtual ?>" data-month="<?= $mesAtual ?>">
            <?= htmlspecialchars($labelMes) ?>
        </h2>
        <button type="button" class="btn-icon" id="btnMesProximo" aria-label="Próximo mês">
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
        </button>
    </div>
    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnHoje">Hoje</button>
</div>

<div class="calendar-grid" id="calendarGrid">
    <?php foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $diaSemana): ?>
        <div class="calendar-grid__weekday"><?= $diaSemana ?></div>
    <?php endforeach; ?>

    <?php foreach ($dias as $dia): ?>
        <?php if ($dia === null): ?>
            <div class="calendar-day is-empty" aria-hidden="true"></div>
        <?php else: ?>
            <button type="button"
                    class="calendar-day <?= $dia['hoje'] ? 'is-today' : '' ?>"
                    data-date="<?= $dia['iso'] ?>">
                <span class="calendar-day__number"><?= $dia['numero'] ?></span>
                <span class="calendar-day__dots">
                    <?php foreach (array_slice($dia['tarefas'], 0, 3) as $tarefaDia): ?>
                        <?php $pm = priorityMeta($tarefaDia['prioridade']); ?>
                        <span class="calendar-dot <?= $pm['classe'] ?>" title="<?= htmlspecialchars($tarefaDia['titulo']) ?>"></span>
                    <?php endforeach; ?>
                    <?php if (count($dia['tarefas']) > 3): ?>
                        <span class="calendar-day__more">+<?= count($dia['tarefas']) - 3 ?></span>
                    <?php endif; ?>
                </span>
            </button>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<!-- Painel de detalhes do dia, preenchido dinamicamente pelo JS -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasDia" aria-labelledby="offcanvasDiaLabel">
    <div class="offcanvas-header">
        <h2 class="offcanvas-title h5" id="offcanvasDiaLabel">Tarefas do dia</h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
    </div>
    <div class="offcanvas-body" id="offcanvasDiaBody">
        <p class="text-muted small">Selecione um dia para ver as tarefas.</p>
    </div>
</div>

<script type="application/json" id="calendar-data">
<?= json_encode(['tasks' => $tasks, 'team' => $team, 'columns' => $columns], JSON_UNESCAPED_UNICODE) ?>
</script>

<?php
$pageScripts = ['calendar.js'];
require __DIR__ . '/includes/footer.php';
