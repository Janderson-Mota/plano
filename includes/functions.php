<?php
/**
 * functions.php
 * Helpers de apresentação. Mantêm index.php e calendario.php enxutos
 * e evitam repetição de marcação (HTML) entre as views.
 */

declare(strict_types=1);

/**
 * Renderiza um avatar circular com as iniciais de um membro da equipe.
 */
function renderAvatar(array $membro, string $tamanho = 'sm'): string
{
    $classeTamanho = $tamanho === 'sm' ? 'avatar-sm' : 'avatar-md';
    $iniciais = htmlspecialchars($membro['iniciais'], ENT_QUOTES);
    $nome = htmlspecialchars($membro['nome'], ENT_QUOTES);
    $cor = htmlspecialchars($membro['cor'], ENT_QUOTES);

    return sprintf(
        '<span class="avatar %s rounded-circle" style="background-color:%s" title="%s" data-member-id="%d">%s</span>',
        $classeTamanho,
        $cor,
        $nome,
        (int) $membro['id'],
        $iniciais
    );
}

/**
 * Renderiza uma pilha de avatares sobrepostos (usada nos cards e na toolbar).
 */
function renderAvatarStack(array $team, array $ids, string $tamanho = 'sm'): string
{
    $html = '<div class="avatar-stack">';
    foreach ($ids as $id) {
        $membro = findTeamMember($team, (int) $id);
        if ($membro !== null) {
            $html .= renderAvatar($membro, $tamanho);
        }
    }
    $html .= '</div>';
    return $html;
}

/**
 * Retorna o rótulo em português e a classe CSS de uma prioridade.
 */
function priorityMeta(string $prioridade): array
{
    $mapa = [
        'alta'  => ['rotulo' => 'Alta',  'classe' => 'priority-alta'],
        'media' => ['rotulo' => 'Média', 'classe' => 'priority-media'],
        'baixa' => ['rotulo' => 'Baixa', 'classe' => 'priority-baixa'],
    ];

    return $mapa[$prioridade] ?? ['rotulo' => ucfirst($prioridade), 'classe' => 'priority-media'];
}

/**
 * Formata uma data ISO (Y-m-d) para o padrão brasileiro abreviado (ex.: "14 set").
 */
function formatDateShort(string $isoDate): string
{
    $meses = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    $timestamp = strtotime($isoDate);
    if ($timestamp === false) {
        return $isoDate;
    }
    $dia = (int) date('j', $timestamp);
    $mes = $meses[(int) date('n', $timestamp) - 1];
    return sprintf('%d %s', $dia, $mes);
}

/**
 * Indica se uma data (Y-m-d) já passou em relação a "hoje".
 */
function isOverdue(string $isoDate): bool
{
    return strtotime($isoDate) < strtotime(date('Y-m-d'));
}

/**
 * Monta a grade de dias de um mês para a view de calendário, já
 * preenchida com as tarefas cujo prazo cai em cada dia.
 * Células nulas no início representam o preenchimento antes do dia 1.
 */
function buildMonthDays(int $ano, int $mes, array $tasks): array
{
    $primeiroDiaTimestamp = mktime(0, 0, 0, $mes, 1, $ano);
    $diasNoMes = (int) date('t', $primeiroDiaTimestamp);
    $offsetSemana = (int) date('w', $primeiroDiaTimestamp); // 0 (dom) a 6 (sáb)
    $hojeIso = date('Y-m-d');

    $dias = [];

    for ($i = 0; $i < $offsetSemana; $i++) {
        $dias[] = null;
    }

    for ($dia = 1; $dia <= $diasNoMes; $dia++) {
        $iso = sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
        $tarefasDoDia = array_values(array_filter($tasks, fn ($t) => $t['prazo'] === $iso));
        $dias[] = [
            'iso' => $iso,
            'numero' => $dia,
            'hoje' => $iso === $hojeIso,
            'tarefas' => $tarefasDoDia,
        ];
    }

    return $dias;
}
