<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/conexao.php';

const PLANNER_PRIORIDADES = ['baixa', 'media', 'alta', 'urgente'];
const PLANNER_MESES_ABREV = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

function plannerConectarBanco(): ?mysqli
{
    global $conn;
    return $conn;
}

function plannerGetUsuarioAtual(?int $id = null): array
{
    global $conn;
    if (!$conn) {
        return [];
    }

    if ($id === null || $id <= 0) {
        $id = (int) ($_SESSION['usuario_id'] ?? ($_GET['usuario_atual'] ?? ($_COOKIE['planner_usuario_id'] ?? 0)));
    }

    if ($id > 0) {
        $stmt = $conn->prepare('SELECT id, nome, cargo, iniciais, cor, email FROM planner_usuario WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                return [
                    'id'       => (int) $row['id'],
                    'nome'     => (string) $row['nome'],
                    'cargo'    => (string) $row['cargo'],
                    'iniciais' => (string) $row['iniciais'],
                    'cor'      => (string) $row['cor'],
                    'email'    => (string) $row['email'],
                ];
            }
        }
    }

    $res = $conn->query('SELECT id, nome, cargo, iniciais, cor, email FROM planner_usuario ORDER BY id ASC LIMIT 1');
    if ($res && $row = $res->fetch_assoc()) {
        return [
            'id'       => (int) $row['id'],
            'nome'     => (string) $row['nome'],
            'cargo'    => (string) $row['cargo'],
            'iniciais' => (string) $row['iniciais'],
            'cor'      => (string) $row['cor'],
            'email'    => (string) $row['email'],
        ];
    }

    return [];
}

function plannerGetUsuarios(): array
{
    global $conn;
    if (!$conn) {
        return [];
    }
    $res = $conn->query('SELECT id, nome, cargo, iniciais, cor, email FROM planner_usuario ORDER BY nome');
    if (!$res) {
        return [];
    }
    return array_map(fn($u) => [
        'id'       => (int) $u['id'],
        'nome'     => (string) $u['nome'],
        'cargo'    => (string) $u['cargo'],
        'iniciais' => (string) $u['iniciais'],
        'cor'      => (string) $u['cor'],
        'email'    => (string) $u['email'],
    ], $res->fetch_all(MYSQLI_ASSOC));
}

function plannerGetEquipes(): array
{
    global $conn;
    if (!$conn) {
        return [];
    }
    $sql = 'SELECT e.id, e.nome, e.descricao, e.pai_id, e.cor,
                   GROUP_CONCAT(me.usuario_id ORDER BY me.usuario_id) AS membro_ids
            FROM planner_equipe e
            LEFT JOIN planner_membro_equipe me ON me.equipe_id = e.id
            GROUP BY e.id ORDER BY e.pai_id IS NULL DESC, e.id';
    $res = $conn->query($sql);
    if (!$res) {
        return [];
    }
    return array_map(function ($r) {
        return [
            'id'        => (int) $r['id'],
            'nome'      => (string) $r['nome'],
            'descricao' => (string) ($r['descricao'] ?? ''),
            'pai_id'    => $r['pai_id'] !== null ? (int) $r['pai_id'] : null,
            'cor'       => (string) ($r['cor'] ?: '#16A34A'),
            'membros'   => $r['membro_ids'] ? array_map('intval', explode(',', $r['membro_ids'])) : [],
        ];
    }, $res->fetch_all(MYSQLI_ASSOC));
}

function plannerGetColunas(): array
{
    global $conn;
    if (!$conn) {
        return [];
    }
    $res = $conn->query('SELECT id, titulo, cor, ordem FROM planner_coluna ORDER BY ordem');
    if (!$res) {
        return [];
    }
    return array_map(fn($c) => [
        'id'     => (string) $c['id'],
        'titulo' => (string) $c['titulo'],
        'cor'    => (string) $c['cor'],
        'ordem'  => (int) $c['ordem'],
    ], $res->fetch_all(MYSQLI_ASSOC));
}

function plannerCalcularStatusPrazo(?string $prazo, string $colunaId): string
{
    if ($colunaId === 'concluido' || str_contains(mb_strtolower($colunaId), 'concluid')) {
        return 'concluido';
    }
    if ($prazo === null || $prazo === '') {
        return 'sem-prazo';
    }
    $hoje = date('Y-m-d');
    if ($prazo < $hoje) {
        return 'atrasada';
    }
    if ($prazo === $hoje) {
        return 'hoje';
    }
    return 'futura';
}

function plannerGetTarefas(mixed $arg1 = [], array $arg2 = []): array
{
    global $conn;
    if (!$conn) {
        return [];
    }

    $filtros = is_array($arg1) ? $arg1 : (is_array($arg2) ? $arg2 : []);

    $where = [];
    $params = [];
    $types = '';

    if (!empty($filtros['prioridade'])) {
        $where[] = 't.prioridade = ?';
        $params[] = (string) $filtros['prioridade'];
        $types .= 's';
    }

    if (!empty($filtros['coluna_id'])) {
        $where[] = 't.coluna_id = ?';
        $params[] = (string) $filtros['coluna_id'];
        $types .= 's';
    }

    if (!empty($filtros['data_inicio'])) {
        $where[] = 't.prazo >= ?';
        $params[] = (string) $filtros['data_inicio'];
        $types .= 's';
    }

    if (!empty($filtros['data_fim'])) {
        $where[] = 't.prazo <= ?';
        $params[] = (string) $filtros['data_fim'];
        $types .= 's';
    }

    if (!empty($filtros['prazo'])) {
        $where[] = 't.prazo = ?';
        $params[] = (string) $filtros['prazo'];
        $types .= 's';
    }

    if (!empty($filtros['texto'])) {
        $where[] = '(t.titulo LIKE ? OR t.descricao LIKE ?)';
        $busca = '%' . trim((string) $filtros['texto']) . '%';
        $params[] = $busca;
        $params[] = $busca;
        $types .= 'ss';
    }

    if (!empty($filtros['usuario'])) {
        $uid = (int) $filtros['usuario'];
        $where[] = 'EXISTS (SELECT 1 FROM planner_grupo_tarefa gt_f WHERE gt_f.tarefa_id = t.id AND gt_f.usuario_id = ' . $uid . ')';
    }

    if (!empty($filtros['equipe'])) {
        $eqId = (int) $filtros['equipe'];
        $where[] = 'EXISTS (SELECT 1 FROM planner_tarefa_equipe te_f WHERE te_f.tarefa_id = t.id AND te_f.equipe_id = ' . $eqId . ')';
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT t.id, t.titulo, t.descricao, t.coluna_id, t.prioridade, t.prazo, t.criado_por,
                   GROUP_CONCAT(DISTINCT gt.usuario_id) AS resp_ids,
                   GROUP_CONCAT(DISTINCT te.equipe_id) AS eq_ids
            FROM planner_tarefa t
            LEFT JOIN planner_grupo_tarefa gt ON gt.tarefa_id = t.id
            LEFT JOIN planner_tarefa_equipe te ON te.tarefa_id = t.id
            {$whereSql}
            GROUP BY t.id
            ORDER BY t.coluna_id, t.id";

    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query($sql);
    }

    if (!$res) {
        return [];
    }

    $tarefas = [];
    while ($r = $res->fetch_assoc()) {
        $tarefas[] = [
            'id'           => (int) $r['id'],
            'titulo'       => (string) $r['titulo'],
            'descricao'    => (string) ($r['descricao'] ?? ''),
            'coluna_id'    => (string) $r['coluna_id'],
            'prioridade'   => (string) $r['prioridade'],
            'prazo'        => $r['prazo'] ? (string) $r['prazo'] : null,
            'criado_por'   => (int) $r['criado_por'],
            'responsaveis' => $r['resp_ids'] ? array_map('intval', explode(',', $r['resp_ids'])) : [],
            'equipes'      => $r['eq_ids'] ? array_map('intval', explode(',', $r['eq_ids'])) : [],
            'status_prazo' => plannerCalcularStatusPrazo($r['prazo'], $r['coluna_id']),
        ];
    }

    return $tarefas;
}

function plannerGetComentarios(int|array $arg1 = 0, int $arg2 = 0): array
{
    global $conn;
    if (!$conn) {
        return [];
    }

    $tarefaId = is_int($arg1) ? $arg1 : $arg2;

    if ($tarefaId > 0) {
        $stmt = $conn->prepare('SELECT id, tarefa_id, usuario_id, texto, criado_em, editado_em FROM planner_comentario WHERE tarefa_id = ? ORDER BY criado_em');
        if ($stmt) {
            $stmt->bind_param('i', $tarefaId);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = null;
        }
    } else {
        $res = $conn->query('SELECT id, tarefa_id, usuario_id, texto, criado_em, editado_em FROM planner_comentario ORDER BY criado_em');
    }

    if (!$res) {
        return [];
    }

    return array_map(fn($c) => [
        'id'         => (int) $c['id'],
        'tarefa_id'  => (int) $c['tarefa_id'],
        'usuario_id' => (int) $c['usuario_id'],
        'texto'      => (string) $c['texto'],
        'criado_em'  => (string) $c['criado_em'],
        'editado_em' => $c['editado_em'] ? (string) $c['editado_em'] : null,
    ], $res->fetch_all(MYSQLI_ASSOC));
}

function plannerGetAtividades(): array
{
    global $conn;
    if (!$conn) {
        return [];
    }
    $res = $conn->query('SELECT id, tarefa_id, usuario_id, tipo, meta, criado_em FROM planner_atividade ORDER BY criado_em DESC LIMIT 50');
    if (!$res) {
        return [];
    }
    return array_map(function ($r) {
        return [
            'id'         => (int) $r['id'],
            'tarefa_id'  => (int) $r['tarefa_id'],
            'usuario_id' => (int) $r['usuario_id'],
            'tipo'       => (string) $r['tipo'],
            'meta'       => $r['meta'] ? json_decode((string) $r['meta'], true) : null,
            'criado_em'  => (string) $r['criado_em'],
        ];
    }, $res->fetch_all(MYSQLI_ASSOC));
}

function plannerGetMembrosEquipeEFilhas(mixed $arg1, ?int $arg2 = null): array
{
    if (is_int($arg1) && $arg2 === null) {
        $equipeId = $arg1;
        $equipes = plannerGetEquipes();
    } elseif (is_array($arg1) && is_int($arg2)) {
        $equipes = $arg1;
        $equipeId = $arg2;
    } else {
        return [];
    }

    $membros = [];
    $alvo = null;
    foreach ($equipes as $e) {
        if ($e['id'] === $equipeId) {
            $alvo = $e;
            break;
        }
    }
    if ($alvo) {
        $membros = array_merge($membros, $alvo['membros']);
    }
    foreach ($equipes as $e) {
        if ($e['pai_id'] === $equipeId) {
            $membros = array_merge($membros, plannerGetMembrosEquipeEFilhas($equipes, $e['id']));
        }
    }
    return array_values(array_unique($membros));
}

function plannerGetEquipeDepth(mixed $arg1, ?int $arg2 = null): int
{
    if (is_int($arg1) && $arg2 === null) {
        $equipeId = $arg1;
        $equipes = plannerGetEquipes();
    } elseif (is_array($arg1) && is_int($arg2)) {
        $equipes = $arg1;
        $equipeId = $arg2;
    } else {
        return 0;
    }

    $depth = 0;
    $currId = $equipeId;
    while ($currId !== null) {
        $paiId = null;
        foreach ($equipes as $e) {
            if ($e['id'] === $currId) {
                $paiId = $e['pai_id'];
                break;
            }
        }
        if ($paiId !== null) {
            $depth++;
            $currId = $paiId;
        } else {
            break;
        }
    }
    return $depth;
}

function plannerRenderAvatar(array $usuario, string $tamanho = 'sm'): string
{
    $classeTamanho = match ($tamanho) {
        'xs' => 'avatar-xs',
        'md' => 'avatar-md',
        'lg' => 'avatar-lg',
        default => 'avatar-sm',
    };
    $iniciais = htmlspecialchars($usuario['iniciais'] ?? '', ENT_QUOTES, 'UTF-8');
    $nome = htmlspecialchars($usuario['nome'] ?? '', ENT_QUOTES, 'UTF-8');
    $cor = htmlspecialchars($usuario['cor'] ?? '#16A34A', ENT_QUOTES, 'UTF-8');
    $id = (int) ($usuario['id'] ?? 0);

    return sprintf(
        '<span class="avatar %s" style="background-color:%s" title="%s" data-user-id="%d">%s</span>',
        $classeTamanho,
        $cor,
        $nome,
        $id,
        $iniciais
    );
}

function plannerRenderAvatarStack(array $todosUsuarios, array $usuarioIds, string $tamanho = 'sm', int $limite = 3): string
{
    if (empty($usuarioIds)) {
        return '<span class="text-muted small" style="font-size:0.75rem">Não atribuído</span>';
    }

    $usuariosPorId = [];
    foreach ($todosUsuarios as $u) {
        $usuariosPorId[$u['id']] = $u;
    }

    $exibidos = array_slice($usuarioIds, 0, $limite);
    $sobra = count($usuarioIds) - $limite;

    $html = '<div class="avatar-stack">';
    foreach ($exibidos as $uid) {
        if (isset($usuariosPorId[$uid])) {
            $html .= plannerRenderAvatar($usuariosPorId[$uid], $tamanho);
        }
    }
    if ($sobra > 0) {
        $html .= sprintf(
            '<span class="avatar avatar-%s avatar--more" title="+%d responsável(is)">+%d</span>',
            $tamanho,
            $sobra,
            $sobra
        );
    }
    $html .= '</div>';
    return $html;
}

function plannerFormatDateShort(string $isoDate): string
{
    $timestamp = strtotime($isoDate);
    if ($timestamp === false) {
        return $isoDate;
    }
    $dia = (int) date('j', $timestamp);
    $mes = PLANNER_MESES_ABREV[(int) date('n', $timestamp) - 1] ?? '';
    return sprintf('%d %s', $dia, $mes);
}

function plannerPriorityMeta(string $prioridade): array
{
    return match ($prioridade) {
        'urgente' => ['rotulo' => 'Urgente', 'classe' => 'priority-urgente'],
        'alta'    => ['rotulo' => 'Alta',    'classe' => 'priority-alta'],
        'media'   => ['rotulo' => 'Média',   'classe' => 'priority-media'],
        'baixa'   => ['rotulo' => 'Baixa',   'classe' => 'priority-baixa'],
        default   => ['rotulo' => ucfirst($prioridade), 'classe' => 'priority-media'],
    };
}

function plannerRespond(bool $success, mixed $data, ?string $error, int $httpCode = 200): never
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(
        ['success' => $success, 'data' => $data, 'error' => $error],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function plannerOk(mixed $data = null): never
{
    plannerRespond(true, $data, null, 200);
}

function plannerError(string $error, int $httpCode = 400): never
{
    plannerRespond(false, null, $error, $httpCode);
}

function plannerSanitizeStr(mixed $val, bool $nullable = false, int $maxLen = 2000): ?string
{
    if ($val === null || $val === '') {
        return $nullable ? null : '';
    }
    return htmlspecialchars(mb_substr((string) $val, 0, $maxLen), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function plannerSanitizeInt(mixed $val): int
{
    return (int) filter_var($val, FILTER_SANITIZE_NUMBER_INT);
}

function plannerSanitizeIds(mixed $val): array
{
    if (!is_array($val)) return [];
    return array_values(array_filter(array_map('intval', $val), fn($id) => $id > 0));
}

function plannerNow(): string
{
    return date('Y-m-d H:i:s');
}

function plannerActionCriarTarefa(array $body, array $usuarioAtual): never
{
    global $conn;
    if (!$conn) {
        plannerError('Sem conexão com o banco de dados.', 500);
    }

    $titulo       = plannerSanitizeStr($body['titulo'] ?? '', false, 200);
    $descricao    = plannerSanitizeStr($body['descricao'] ?? '', true, 1000);
    $colunaId     = plannerSanitizeStr($body['coluna_id'] ?? '', false, 40);
    $prioridade   = plannerSanitizeStr($body['prioridade'] ?? '', false, 10);
    $prazo        = plannerSanitizeStr($body['prazo'] ?? '', true, 10);
    $responsaveis = plannerSanitizeIds($body['responsaveis'] ?? []);
    $equipes      = plannerSanitizeIds($body['equipes'] ?? []);

    if (empty($equipes) && !empty($body['equipe_id'])) {
        $eqId = plannerSanitizeInt($body['equipe_id']);
        if ($eqId > 0) $equipes = [$eqId];
    }

    if ($titulo === '') plannerError('O título é obrigatório.', 400);
    if (!in_array($prioridade, PLANNER_PRIORIDADES, true)) plannerError('Prioridade inválida.', 400);
    if ($prazo !== null && $prazo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $prazo)) {
        plannerError('Formato de prazo inválido. Use YYYY-MM-DD.', 400);
    }

    if ($colunaId === '') {
        $colRes = $conn->query('SELECT id FROM planner_coluna ORDER BY ordem ASC LIMIT 1');
        if ($colRes && $colRow = $colRes->fetch_assoc()) {
            $colunaId = (string) $colRow['id'];
        } else {
            $colunaId = 'backlog';
        }
    }

    $agora = plannerNow();
    $criadorId = (int) ($usuarioAtual['id'] ?? 0);
    if ($criadorId <= 0) {
        $uAtual = plannerGetUsuarioAtual();
        $criadorId = (int) ($uAtual['id'] ?? 0);
    }
    if ($criadorId <= 0) {
        $uRes = $conn->query('SELECT id FROM planner_usuario ORDER BY id ASC LIMIT 1');
        if ($uRes && $uRow = $uRes->fetch_assoc()) {
            $criadorId = (int) $uRow['id'];
        }
    }

    $stmt = $conn->prepare('INSERT INTO planner_tarefa (titulo, descricao, coluna_id, prioridade, prazo, criado_por, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        plannerError('Erro ao preparar query: ' . $conn->error, 500);
    }
    $stmt->bind_param('sssssiss', $titulo, $descricao, $colunaId, $prioridade, $prazo, $criadorId, $agora, $agora);
    if (!$stmt->execute()) {
        plannerError('Erro ao salvar tarefa: ' . $stmt->error, 500);
    }
    $novaId = (int) $conn->insert_id;

    foreach ($responsaveis as $uid) {
        $sResp = $conn->prepare('INSERT INTO planner_grupo_tarefa (tarefa_id, usuario_id) VALUES (?, ?)');
        if ($sResp) {
            $sResp->bind_param('ii', $novaId, $uid);
            $sResp->execute();
        }
    }

    foreach ($equipes as $eid) {
        $sEq = $conn->prepare('INSERT INTO planner_tarefa_equipe (tarefa_id, equipe_id) VALUES (?, ?)');
        if ($sEq) {
            $sEq->bind_param('ii', $novaId, $eid);
            $sEq->execute();
        }
    }

    $sAtiv = $conn->prepare('INSERT INTO planner_atividade (tarefa_id, usuario_id, tipo, criado_em) VALUES (?, ?, "criacao", ?)');
    if ($sAtiv) {
        $sAtiv->bind_param('iis', $novaId, $criadorId, $agora);
        $sAtiv->execute();
    }

    $novaTarefa = [
        'id'           => $novaId,
        'titulo'       => $titulo,
        'descricao'    => $descricao,
        'coluna_id'    => $colunaId,
        'prioridade'   => $prioridade,
        'prazo'        => ($prazo !== '' && $prazo !== null) ? $prazo : null,
        'criado_por'   => $criadorId,
        'responsaveis' => $responsaveis,
        'equipes'      => $equipes,
        'status_prazo' => plannerCalcularStatusPrazo($prazo, $colunaId),
        'criado_em'    => $agora,
        'atualizado_em'=> $agora,
    ];

    plannerOk(['tarefa' => $novaTarefa]);
}

function plannerActionMoverTarefa(array $body, array $usuarioAtual): never
{
    global $conn;
    if (!$conn) {
        plannerError('Sem conexão com o banco de dados.', 500);
    }

    $taskId   = plannerSanitizeInt($body['task_id'] ?? 0);
    $colunaId = plannerSanitizeStr($body['coluna_id'] ?? '', false, 40);

    if ($taskId <= 0)     plannerError('task_id inválido.', 400);
    if ($colunaId === '') plannerError('Coluna inválida.', 400);

    $agora = plannerNow();
    $usuarioId = (int) ($usuarioAtual['id'] ?? 0);
    if ($usuarioId <= 0) {
        $uAtual = plannerGetUsuarioAtual();
        $usuarioId = (int) ($uAtual['id'] ?? 0);
    }
    if ($usuarioId <= 0) {
        $uRes = $conn->query('SELECT id FROM planner_usuario ORDER BY id ASC LIMIT 1');
        if ($uRes && $uRow = $uRes->fetch_assoc()) {
            $usuarioId = (int) $uRow['id'];
        }
    }

    $stmt = $conn->prepare('UPDATE planner_tarefa SET coluna_id = ?, atualizado_em = ? WHERE id = ?');
    if (!$stmt) {
        plannerError('Erro ao preparar query: ' . $conn->error, 500);
    }
    $stmt->bind_param('ssi', $colunaId, $agora, $taskId);
    if (!$stmt->execute()) {
        plannerError('Erro ao atualizar coluna da tarefa: ' . $stmt->error, 500);
    }

    $meta = json_encode(['para' => $colunaId]);
    $sAtiv = $conn->prepare('INSERT INTO planner_atividade (tarefa_id, usuario_id, tipo, meta, criado_em) VALUES (?, ?, "movimentacao", ?, ?)');
    if ($sAtiv) {
        $sAtiv->bind_param('iiss', $taskId, $usuarioId, $meta, $agora);
        $sAtiv->execute();
    }

    plannerOk([
        'task_id'       => $taskId,
        'coluna_id'     => $colunaId,
        'atualizado_em' => $agora,
    ]);
}

function plannerActionAtualizarResponsaveis(array $body, array $usuarioAtual): never
{
    global $conn;
    if (!$conn) {
        plannerError('Sem conexão com o banco de dados.', 500);
    }

    $taskId       = plannerSanitizeInt($body['task_id'] ?? 0);
    $responsaveis = plannerSanitizeIds($body['responsaveis'] ?? []);

    if ($taskId <= 0) plannerError('task_id inválido.', 400);

    $agora = plannerNow();
    $usuarioId = (int) ($usuarioAtual['id'] ?? 0);
    if ($usuarioId <= 0) {
        $uAtual = plannerGetUsuarioAtual();
        $usuarioId = (int) ($uAtual['id'] ?? 0);
    }
    if ($usuarioId <= 0) {
        $uRes = $conn->query('SELECT id FROM planner_usuario ORDER BY id ASC LIMIT 1');
        if ($uRes && $uRow = $uRes->fetch_assoc()) {
            $usuarioId = (int) $uRow['id'];
        }
    }

    $sDel = $conn->prepare('DELETE FROM planner_grupo_tarefa WHERE tarefa_id = ?');
    if ($sDel) {
        $sDel->bind_param('i', $taskId);
        $sDel->execute();
    }

    foreach ($responsaveis as $uid) {
        $sIns = $conn->prepare('INSERT INTO planner_grupo_tarefa (tarefa_id, usuario_id) VALUES (?, ?)');
        if ($sIns) {
            $sIns->bind_param('ii', $taskId, $uid);
            $sIns->execute();
        }
    }

    $sAtiv = $conn->prepare('INSERT INTO planner_atividade (tarefa_id, usuario_id, tipo, criado_em) VALUES (?, ?, "atribuicao", ?)');
    if ($sAtiv) {
        $sAtiv->bind_param('iis', $taskId, $usuarioId, $agora);
        $sAtiv->execute();
    }

    plannerOk([
        'task_id'       => $taskId,
        'responsaveis'  => $responsaveis,
        'atualizado_em' => $agora,
    ]);
}

function plannerActionCriarComentario(array $body, array $usuarioAtual): never
{
    global $conn;
    if (!$conn) {
        plannerError('Sem conexão com o banco de dados.', 500);
    }

    $tarefaId = plannerSanitizeInt($body['tarefa_id'] ?? 0);
    $texto    = plannerSanitizeStr($body['texto'] ?? '', false, 2000);

    if ($tarefaId <= 0) plannerError('tarefa_id inválido.', 400);
    if ($texto === '')  plannerError('O texto do comentário não pode ser vazio.', 400);

    $criado_em = plannerNow();
    $usuarioId = (int) ($usuarioAtual['id'] ?? 0);
    if ($usuarioId <= 0) {
        $uAtual = plannerGetUsuarioAtual();
        $usuarioId = (int) ($uAtual['id'] ?? 0);
    }
    if ($usuarioId <= 0) {
        $uRes = $conn->query('SELECT id FROM planner_usuario ORDER BY id ASC LIMIT 1');
        if ($uRes && $uRow = $uRes->fetch_assoc()) {
            $usuarioId = (int) $uRow['id'];
        }
    }

    $stmt = $conn->prepare('INSERT INTO planner_comentario (tarefa_id, usuario_id, texto, criado_em) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        plannerError('Erro ao preparar query: ' . $conn->error, 500);
    }
    $stmt->bind_param('iiss', $tarefaId, $usuarioId, $texto, $criado_em);
    if (!$stmt->execute()) {
        plannerError('Erro ao inserir comentário: ' . $stmt->error, 500);
    }
    $novoId = (int) $conn->insert_id;

    plannerOk([
        'comentario' => [
            'id'         => $novoId,
            'tarefa_id'  => $tarefaId,
            'usuario_id' => $usuarioId,
            'texto'      => $texto,
            'criado_em'  => $criado_em,
            'editado_em' => null,
        ],
    ]);
}

function plannerActionEditarComentario(array $body, array $usuarioAtual): never
{
    global $conn;
    if (!$conn) {
        plannerError('Sem conexão com o banco de dados.', 500);
    }

    $comentarioId = plannerSanitizeInt($body['comentario_id'] ?? 0);
    $texto        = plannerSanitizeStr($body['texto'] ?? '', false, 2000);

    if ($comentarioId <= 0) plannerError('comentario_id inválido.', 400);
    if ($texto === '')       plannerError('O texto não pode ser vazio.', 400);

    $editado_em = plannerNow();
    $stmt = $conn->prepare('UPDATE planner_comentario SET texto = ?, editado_em = ? WHERE id = ?');
    if (!$stmt) {
        plannerError('Erro ao preparar query: ' . $conn->error, 500);
    }
    $stmt->bind_param('ssi', $texto, $editado_em, $comentarioId);
    if (!$stmt->execute()) {
        plannerError('Erro ao editar comentário: ' . $stmt->error, 500);
    }

    plannerOk([
        'comentario_id' => $comentarioId,
        'texto'         => $texto,
        'editado_em'    => $editado_em,
    ]);
}

function plannerActionExcluirComentario(array $body, array $usuarioAtual): never
{
    global $conn;
    if (!$conn) {
        plannerError('Sem conexão com o banco de dados.', 500);
    }

    $comentarioId = plannerSanitizeInt($body['comentario_id'] ?? 0);
    if ($comentarioId <= 0) plannerError('comentario_id inválido.', 400);

    $stmt = $conn->prepare('DELETE FROM planner_comentario WHERE id = ?');
    if (!$stmt) {
        plannerError('Erro ao preparar query: ' . $conn->error, 500);
    }
    $stmt->bind_param('i', $comentarioId);
    if (!$stmt->execute()) {
        plannerError('Erro ao excluir comentário: ' . $stmt->error, 500);
    }

    plannerOk(['comentario_id' => $comentarioId, 'excluido_em' => plannerNow()]);
}

function plannerActionCriarEquipe(array $body, array $usuarioAtual): never
{
    global $conn;
    if (!$conn) {
        plannerError('Sem conexão com o banco de dados.', 500);
    }

    $nome    = plannerSanitizeStr($body['nome'] ?? '', false, 100);
    $cor     = plannerSanitizeStr($body['cor'] ?? '#16A34A', false, 20);
    $paiId   = !empty($body['pai_id']) ? plannerSanitizeInt($body['pai_id']) : null;
    $membros = plannerSanitizeIds($body['membros'] ?? []);

    if ($nome === '') {
        plannerError('O nome da equipe é obrigatório.', 400);
    }
    if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $cor)) {
        $cor = '#16A34A';
    }

    $stmt = $conn->prepare('INSERT INTO planner_equipe (nome, cor, pai_id) VALUES (?, ?, ?)');
    if (!$stmt) {
        plannerError('Erro ao preparar query: ' . $conn->error, 500);
    }
    $stmt->bind_param('ssi', $nome, $cor, $paiId);
    if (!$stmt->execute()) {
        plannerError('Erro ao inserir equipe: ' . $stmt->error, 500);
    }
    $novaId = (int) $conn->insert_id;

    foreach ($membros as $uid) {
        $sMem = $conn->prepare('INSERT INTO planner_membro_equipe (equipe_id, usuario_id) VALUES (?, ?)');
        if ($sMem) {
            $sMem->bind_param('ii', $novaId, $uid);
            $sMem->execute();
        }
    }

    $novaEquipe = [
        'id'            => $novaId,
        'nome'          => $nome,
        'cor'           => $cor,
        'pai_id'        => $paiId,
        'total_membros' => count($membros),
        'membros'       => $membros,
    ];

    plannerOk(['equipe' => $novaEquipe]);
}
