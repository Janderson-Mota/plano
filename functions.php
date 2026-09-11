<?php

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/conexao.php';

// Caminho para imagens dos usuários (concatenado com a matrícula / chave)
$caminhoImagens = 'public/img/';
const PLANNER_CAMINHO_IMAGENS = 'public/img/';

// Chaves estáticas de administrador (exemplo para teste / autorização)
const PLANNER_ADMIN_KEYS = ['1001', '1002'];

const PLANNER_PRIORIDADES = ['baixa', 'media', 'alta', 'urgente'];
const PLANNER_MESES_ABREV = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

function plannerConectarBanco(): ?mysqli
{
    global $conn;
    return $conn;
}

/**
 * Detecta se na tabela acesso_permitido a coluna de identificação é 'chave' ou 'matricula'
 */
function plannerGetCampoIdentificador(): string
{
    static $campo = null;
    if ($campo !== null) {
        return $campo;
    }
    global $conn;
    if (!$conn) {
        return 'matricula';
    }

    $res = $conn->query("SHOW COLUMNS FROM acesso_permitido LIKE 'chave'");
    if ($res && $res->num_rows > 0) {
        $campo = 'chave';
    } else {
        $campo = 'matricula';
    }
    return $campo;
}

function plannerGerarIniciais(string $nome): string
{
    $partes = preg_split('/\s+/', trim($nome));
    if (empty($partes) || empty($partes[0])) {
        return '??';
    }
    $ini = mb_substr($partes[0], 0, 1);
    if (count($partes) > 1) {
        $ini .= mb_substr(end($partes), 0, 1);
    }
    return mb_strtoupper($ini);
}

function plannerGetUsuarioAtual(string|int|null $idOuMatricula = null): array
{
    global $conn;
    if (!$conn) {
        return [];
    }

    $campo = plannerGetCampoIdentificador();

    if ($idOuMatricula === null || $idOuMatricula === '' || $idOuMatricula === 0 || $idOuMatricula === '0') {
        $idOuMatricula = (string) ($_SESSION['usuario_id'] ?? ($_GET['usuario_atual'] ?? ($_COOKIE['planner_usuario_id'] ?? '')));
    }

    if (!empty($idOuMatricula)) {
        $idStr = (string) $idOuMatricula;
        $stmt = $conn->prepare("SELECT `{$campo}` AS identificador, nome FROM acesso_permitido WHERE `{$campo}` = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $idStr);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $ident = (string) $row['identificador'];
                return [
                    'id'        => $ident,
                    'matricula' => $ident,
                    'chave'     => $ident,
                    'nome'      => (string) $row['nome'],
                    'cargo'     => 'Colaborador',
                    'iniciais'  => plannerGerarIniciais((string) $row['nome']),
                    'cor'       => '#059669',
                    'foto'      => PLANNER_CAMINHO_IMAGENS . $ident . '.png',
                    'email'     => '',
                ];
            }
        }
    }

    // Primeiro usuário de acesso_permitido por padrão
    $res = $conn->query("SELECT `{$campo}` AS identificador, nome FROM acesso_permitido ORDER BY `{$campo}` ASC LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        $ident = (string) $row['identificador'];
        return [
            'id'        => $ident,
            'matricula' => $ident,
            'chave'     => $ident,
            'nome'      => (string) $row['nome'],
            'cargo'     => 'Colaborador',
            'iniciais'  => plannerGerarIniciais((string) $row['nome']),
            'cor'       => '#059669',
            'foto'      => PLANNER_CAMINHO_IMAGENS . $ident . '.png',
            'email'     => '',
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
    $campo = plannerGetCampoIdentificador();
    $res = $conn->query("SELECT `{$campo}` AS identificador, nome FROM acesso_permitido ORDER BY nome");
    if (!$res) {
        return [];
    }

    $usuarios = [];
    $cores = ['#059669', '#2563EB', '#D97706', '#0D9488', '#7C3AED', '#DB2777', '#16A34A', '#F97316'];
    $idx = 0;
    while ($u = $res->fetch_assoc()) {
        $ident = (string) $u['identificador'];
        $cor = $cores[$idx % count($cores)];
        $usuarios[] = [
            'id'        => $ident,
            'matricula' => $ident,
            'chave'     => $ident,
            'nome'      => (string) $u['nome'],
            'cargo'     => 'Colaborador',
            'iniciais'  => plannerGerarIniciais((string) $u['nome']),
            'cor'       => $cor,
            'foto'      => PLANNER_CAMINHO_IMAGENS . $ident . '.png',
            'email'     => '',
        ];
        $idx++;
    }
    return $usuarios;
}

function plannerGetEquipes(): array
{
    global $conn;
    if (!$conn) {
        return [];
    }
    $campo = plannerGetCampoIdentificador();
    // BUSCA EQUIPES COM INFORMAÇÕES DO LÍDER E LISTA DE MEMBROS
    $sql = "SELECT e.id, e.nome, e.descricao, e.pai_id, e.lider_id, e.cor,
                   ap.nome AS lider_nome,
                   GROUP_CONCAT(me.`{$campo}` ORDER BY me.`{$campo}`) AS membro_ids
            FROM planner_equipe e
            LEFT JOIN acesso_permitido ap ON ap.`{$campo}` = e.lider_id
            LEFT JOIN planner_membro_equipe me ON me.equipe_id = e.id
            GROUP BY e.id ORDER BY e.pai_id IS NULL DESC, e.id";
    $res = $conn->query($sql);
    if (!$res) {
        return [];
    }
    return array_map(function ($r) {
        $ident = (string)($r['lider_id'] ?? '');
        return [
            'id'         => (int) $r['id'],
            'nome'       => (string) $r['nome'],
            'descricao'  => (string) ($r['descricao'] ?? ''),
            'pai_id'     => $r['pai_id'] !== null ? (int) $r['pai_id'] : null,
            'lider_id'   => $r['lider_id'] !== null ? (string) $r['lider_id'] : null,
            'lider_nome' => (string) ($r['lider_nome'] ?? ''),
            'lider_foto' => $ident ? (PLANNER_CAMINHO_IMAGENS . $ident . '.png') : null,
            'cor'        => (string) ($r['cor'] ?: '#16A34A'),
            'membros'    => $r['membro_ids'] ? explode(',', $r['membro_ids']) : [],
        ];
    }, $res->fetch_all(MYSQLI_ASSOC));
}

function plannerGetColunas(): array
{
    global $conn;
    if (!$conn) {
        return [];
    }
    $res = $conn->query('SELECT id, slug, titulo, cor, ordem FROM planner_coluna ORDER BY ordem');
    if (!$res) {
        return [];
    }
    return array_map(fn($c) => [
        'id'     => (int) $c['id'],
        'slug'   => (string) ($c['slug'] ?? ''),
        'titulo' => (string) $c['titulo'],
        'cor'    => (string) $c['cor'],
        'ordem'  => (int) $c['ordem'],
    ], $res->fetch_all(MYSQLI_ASSOC));
}

function plannerCalcularStatusPrazo(?string $prazo, int|string $colunaId): string
{
    if ((int)$colunaId === 4 || (string)$colunaId === '4' || (string)$colunaId === 'concluido' || str_contains(mb_strtolower((string)$colunaId), 'concluid')) {
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

    $campo = plannerGetCampoIdentificador();
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
        $params[] = (int) $filtros['coluna_id'];
        $types .= 'i';
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
        $uid = (string) $filtros['usuario'];
        $where[] = "EXISTS (SELECT 1 FROM planner_grupo_tarefa gt_f WHERE gt_f.tarefa_id = t.id AND gt_f.`{$campo}` = ?)";
        $params[] = $uid;
        $types .= 's';
    }

    if (!empty($filtros['equipe'])) {
        $eqId = (int) $filtros['equipe'];
        $where[] = 'EXISTS (SELECT 1 FROM planner_tarefa_equipe te_f WHERE te_f.tarefa_id = t.id AND te_f.equipe_id = ' . $eqId . ')';
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT t.id, t.titulo, t.descricao, t.coluna_id, t.prioridade, t.prazo, t.criado_por,
                   GROUP_CONCAT(DISTINCT gt.`{$campo}`) AS resp_ids,
                   GROUP_CONCAT(DISTINCT te.equipe_id) AS eq_ids,
                   GROUP_CONCAT(DISTINCT tps.`{$campo}`) AS ps_ids
            FROM planner_tarefa t
            LEFT JOIN planner_grupo_tarefa gt ON gt.tarefa_id = t.id
            LEFT JOIN planner_tarefa_equipe te ON te.tarefa_id = t.id
            LEFT JOIN planner_tarefa_pessoas_soltas tps ON tps.tarefa_id = t.id
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
            'id'             => (int) $r['id'],
            'titulo'         => (string) $r['titulo'],
            'descricao'      => (string) ($r['descricao'] ?? ''),
            'coluna_id'      => (int) $r['coluna_id'],
            'prioridade'     => (string) $r['prioridade'],
            'prazo'          => $r['prazo'] ? (string) $r['prazo'] : null,
            'criado_por'     => (string) $r['criado_por'],
            'responsaveis'   => $r['resp_ids'] ? explode(',', $r['resp_ids']) : [],
            'equipes'        => $r['eq_ids'] ? array_map('intval', explode(',', $r['eq_ids'])) : [],
            'pessoas_soltas' => $r['ps_ids'] ? explode(',', $r['ps_ids']) : [],
            'status_prazo'   => plannerCalcularStatusPrazo($r['prazo'], (int)$r['coluna_id']),
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

    $campo = plannerGetCampoIdentificador();
    $tarefaId = is_int($arg1) ? $arg1 : $arg2;

    if ($tarefaId > 0) {
        $stmt = $conn->prepare("SELECT id, tarefa_id, `{$campo}` AS usuario_id, texto, criado_em, editado_em FROM planner_comentario WHERE tarefa_id = ? ORDER BY criado_em");
        if ($stmt) {
            $stmt->bind_param('i', $tarefaId);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = null;
        }
    } else {
        $res = $conn->query("SELECT id, tarefa_id, `{$campo}` AS usuario_id, texto, criado_em, editado_em FROM planner_comentario ORDER BY criado_em");
    }

    if (!$res) {
        return [];
    }

    return array_map(fn($c) => [
        'id'         => (int) $c['id'],
        'tarefa_id'  => (int) $c['tarefa_id'],
        'usuario_id' => (string) $c['usuario_id'],
        'texto'      => (string) $c['texto'],
        'criado_em'  => (string) $c['criado_em'],
        'editado_em' => $c['editado_em'] ? (string) $c['editado_em'] : null,
    ], $res->fetch_all(MYSQLI_ASSOC));
}

// RECUPERA O HISTÓRICO DE ATIVIDADES / AUDITORIA (OPCIONALMENTE FILTRADO POR TAREFA)
function plannerGetAtividades(int $tarefaId = 0): array
{
    global $conn;
    if (!$conn) {
        return [];
    }
    $campo = plannerGetCampoIdentificador();
    if ($tarefaId > 0) {
        $stmt = $conn->prepare("SELECT id, tarefa_id, `{$campo}` AS usuario_id, tipo, meta, criado_em FROM planner_atividade WHERE tarefa_id = ? ORDER BY criado_em DESC LIMIT 100");
        if ($stmt) {
            $stmt->bind_param('i', $tarefaId);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = null;
        }
    } else {
        $res = $conn->query("SELECT id, tarefa_id, `{$campo}` AS usuario_id, tipo, meta, criado_em FROM planner_atividade ORDER BY criado_em DESC LIMIT 100");
    }

    if (!$res) {
        return [];
    }
    return array_map(function ($r) {
        $meta = $r['meta'];
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        return [
            'id'         => (int) $r['id'],
            'tarefa_id'  => (int) $r['tarefa_id'],
            'usuario_id' => (string) $r['usuario_id'],
            'tipo'       => (string) $r['tipo'],
            'meta'       => is_array($meta) ? $meta : null,
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

/**
 * Renderiza a foto do usuário em vez de bolinhas com iniciais
 */
function plannerRenderAvatar(array $usuario, string $tamanho = 'sm'): string
{
    $classeTamanho = match ($tamanho) {
        'xs' => 'avatar-xs',
        'md' => 'avatar-md',
        'lg' => 'avatar-lg',
        default => 'avatar-sm',
    };
    $nome = htmlspecialchars($usuario['nome'] ?? 'Usuário', ENT_QUOTES, 'UTF-8');
    $ident = htmlspecialchars((string)($usuario['matricula'] ?? $usuario['id'] ?? $usuario['chave'] ?? 'default'), ENT_QUOTES, 'UTF-8');
    $foto = htmlspecialchars($usuario['foto'] ?? (PLANNER_CAMINHO_IMAGENS . $ident . '.png'), ENT_QUOTES, 'UTF-8');
    $fallback = htmlspecialchars(PLANNER_CAMINHO_IMAGENS . 'default.png', ENT_QUOTES, 'UTF-8');

    return sprintf(
        '<img class="avatar %s rounded-circle" src="%s" alt="%s" title="%s" data-user-id="%s" onerror="this.onerror=null;this.src=\'%s\';">',
        $classeTamanho,
        $foto,
        $nome,
        $nome,
        $ident,
        $fallback
    );
}

function plannerRenderAvatarStack(array $todosUsuarios, array $usuarioIds, string $tamanho = 'sm', int $limite = 3): string
{
    if (empty($usuarioIds)) {
        return '<span class="text-muted small" style="font-size:0.75rem">Não atribuído</span>';
    }

    $usuariosPorId = [];
    foreach ($todosUsuarios as $u) {
        $uid = (string)($u['matricula'] ?? $u['id'] ?? $u['chave'] ?? '');
        $usuariosPorId[$uid] = $u;
    }

    $exibidos = array_slice($usuarioIds, 0, $limite);
    $sobra = count($usuarioIds) - $limite;

    $html = '<div class="avatar-stack">';
    foreach ($exibidos as $uid) {
        $uidStr = (string)$uid;
        if (isset($usuariosPorId[$uidStr])) {
            $html .= plannerRenderAvatar($usuariosPorId[$uidStr], $tamanho);
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

function plannerSanitizeUserIds(mixed $val): array
{
    if (!is_array($val)) return [];
    $res = [];
    foreach ($val as $item) {
        $str = trim((string)$item);
        if ($str !== '') $res[] = $str;
    }
    return array_values(array_unique($res));
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

    $campo        = plannerGetCampoIdentificador();
    $titulo       = plannerSanitizeStr($body['titulo'] ?? '', false, 200);
    $descricao    = plannerSanitizeStr($body['descricao'] ?? '', true, 1000);
    $colunaId     = plannerSanitizeInt($body['coluna_id'] ?? 1);
    $prioridade   = plannerSanitizeStr($body['prioridade'] ?? '', false, 10);
    $prazo        = plannerSanitizeStr($body['prazo'] ?? '', true, 10);
    $responsaveis = plannerSanitizeUserIds($body['responsaveis'] ?? []);
    $equipes      = plannerSanitizeIds($body['equipes'] ?? []);
    $pessoasSoltas = plannerSanitizeUserIds($body['pessoas_soltas'] ?? []);

    if (empty($equipes) && !empty($body['equipe_id'])) {
        $eqId = plannerSanitizeInt($body['equipe_id']);
        if ($eqId > 0) $equipes = [$eqId];
    }

    if ($titulo === '') plannerError('O título é obrigatório.', 400);
    if (!in_array($prioridade, PLANNER_PRIORIDADES, true)) plannerError('Prioridade inválida.', 400);
    if ($prazo !== null && $prazo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $prazo)) {
        plannerError('Formato de prazo inválido. Use YYYY-MM-DD.', 400);
    }

    if ($colunaId <= 0) {
        $colRes = $conn->query('SELECT id FROM planner_coluna ORDER BY ordem ASC LIMIT 1');
        if ($colRes && $colRow = $colRes->fetch_assoc()) {
            $colunaId = (int) $colRow['id'];
        } else {
            $colunaId = 1;
        }
    }

    $agora = plannerNow();
    $criadorId = (string) ($usuarioAtual['matricula'] ?? $usuarioAtual['chave'] ?? $usuarioAtual['id'] ?? '');
    if ($criadorId === '') {
        $uAtual = plannerGetUsuarioAtual();
        $criadorId = (string) ($uAtual['matricula'] ?? $uAtual['chave'] ?? $uAtual['id'] ?? '1001');
    }

    $stmt = $conn->prepare('INSERT INTO planner_tarefa (titulo, descricao, coluna_id, prioridade, prazo, criado_por, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        plannerError('Erro ao preparar query: ' . $conn->error, 500);
    }
    $stmt->bind_param('ssisssss', $titulo, $descricao, $colunaId, $prioridade, $prazo, $criadorId, $agora, $agora);
    if (!$stmt->execute()) {
        plannerError('Erro ao salvar tarefa: ' . $stmt->error, 500);
    }
    $novaId = (int) $conn->insert_id;

    foreach ($responsaveis as $uid) {
        $sResp = $conn->prepare("INSERT INTO planner_grupo_tarefa (tarefa_id, `{$campo}`) VALUES (?, ?)");
        if ($sResp) {
            $sResp->bind_param('is', $novaId, $uid);
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

    foreach ($pessoasSoltas as $psId) {
        $sPs = $conn->prepare("INSERT INTO planner_tarefa_pessoas_soltas (tarefa_id, `{$campo}`) VALUES (?, ?)");
        if ($sPs) {
            $sPs->bind_param('is', $novaId, $psId);
            $sPs->execute();
        }
    }

    $sAtiv = $conn->prepare("INSERT INTO planner_atividade (tarefa_id, `{$campo}`, tipo, criado_em) VALUES (?, ?, 'criacao', ?)");
    if ($sAtiv) {
        $sAtiv->bind_param('iss', $novaId, $criadorId, $agora);
        $sAtiv->execute();
    }

    $novaTarefa = [
        'id'             => $novaId,
        'titulo'         => $titulo,
        'descricao'      => $descricao,
        'coluna_id'      => $colunaId,
        'prioridade'     => $prioridade,
        'prazo'          => ($prazo !== '' && $prazo !== null) ? $prazo : null,
        'criado_por'     => $criadorId,
        'responsaveis'   => $responsaveis,
        'equipes'        => $equipes,
        'pessoas_soltas' => $pessoasSoltas,
        'status_prazo'   => plannerCalcularStatusPrazo($prazo, $colunaId),
        'criado_em'      => $agora,
        'atualizado_em'  => $agora,
    ];

    plannerOk(['tarefa' => $novaTarefa]);
}

function plannerActionMoverTarefa(array $body, array $usuarioAtual): never
{
    global $conn;
    if (!$conn) {
        plannerError('Sem conexão com o banco de dados.', 500);
    }

    $campo    = plannerGetCampoIdentificador();
    $taskId   = plannerSanitizeInt($body['task_id'] ?? 0);
    $colunaId = plannerSanitizeInt($body['coluna_id'] ?? 0);

    if ($taskId <= 0)   plannerError('task_id inválido.', 400);
    if ($colunaId <= 0) plannerError('Coluna inválida.', 400);

    $agora = plannerNow();
    $usuarioId = (string) ($usuarioAtual['matricula'] ?? $usuarioAtual['chave'] ?? $usuarioAtual['id'] ?? '');
    if ($usuarioId === '') {
        $uAtual = plannerGetUsuarioAtual();
        $usuarioId = (string) ($uAtual['matricula'] ?? $uAtual['chave'] ?? $uAtual['id'] ?? '1001');
    }

    // RECUPERA A COLUNA ANTERIOR DA TAREFA PARA O REGISTRO DE AUDITORIA
    $colunaAntigaId = null;
    $sOld = $conn->prepare('SELECT coluna_id FROM planner_tarefa WHERE id = ? LIMIT 1');
    if ($sOld) {
        $sOld->bind_param('i', $taskId);
        $sOld->execute();
        $rOld = $sOld->get_result();
        if ($rOld && $rowOld = $rOld->fetch_assoc()) {
            $colunaAntigaId = (int)$rowOld['coluna_id'];
        }
    }

    $stmt = $conn->prepare('UPDATE planner_tarefa SET coluna_id = ?, atualizado_em = ? WHERE id = ?');
    if (!$stmt) {
        plannerError('Erro ao preparar query: ' . $conn->error, 500);
    }
    $stmt->bind_param('isi', $colunaId, $agora, $taskId);
    if (!$stmt->execute()) {
        plannerError('Erro ao atualizar coluna da tarefa: ' . $stmt->error, 500);
    }

    // REGISTRA A ATIVIDADE DE MOVIMENTAÇÃO COM COLUNA DE ORIGEM E DESTINO
    $meta = json_encode([
        'de'   => $colunaAntigaId,
        'para' => $colunaId,
    ]);
    $sAtiv = $conn->prepare("INSERT INTO planner_atividade (tarefa_id, `{$campo}`, tipo, meta, criado_em) VALUES (?, ?, 'movimentacao', ?, ?)");
    if ($sAtiv) {
        $sAtiv->bind_param('isss', $taskId, $usuarioId, $meta, $agora);
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

    $campo        = plannerGetCampoIdentificador();
    $taskId       = plannerSanitizeInt($body['task_id'] ?? 0);
    $responsaveis = plannerSanitizeUserIds($body['responsaveis'] ?? []);

    if ($taskId <= 0) plannerError('task_id inválido.', 400);

    $agora = plannerNow();
    $usuarioId = (string) ($usuarioAtual['matricula'] ?? $usuarioAtual['chave'] ?? $usuarioAtual['id'] ?? '');
    if ($usuarioId === '') {
        $uAtual = plannerGetUsuarioAtual();
        $usuarioId = (string) ($uAtual['matricula'] ?? $uAtual['chave'] ?? $uAtual['id'] ?? '1001');
    }

    $sDel = $conn->prepare('DELETE FROM planner_grupo_tarefa WHERE tarefa_id = ?');
    if ($sDel) {
        $sDel->bind_param('i', $taskId);
        $sDel->execute();
    }

    foreach ($responsaveis as $uid) {
        $sIns = $conn->prepare("INSERT INTO planner_grupo_tarefa (tarefa_id, `{$campo}`) VALUES (?, ?)");
        if ($sIns) {
            $sIns->bind_param('is', $taskId, $uid);
            $sIns->execute();
        }
    }

    $sAtiv = $conn->prepare("INSERT INTO planner_atividade (tarefa_id, `{$campo}`, tipo, criado_em) VALUES (?, ?, 'atribuicao', ?)");
    if ($sAtiv) {
        $sAtiv->bind_param('iss', $taskId, $usuarioId, $agora);
        $sAtiv->execute();
    }

    plannerOk([
        'task_id'       => $taskId,
        'responsaveis'  => $responsaveis,
        'atualizado_em' => $agora,
    ]);
}

function plannerActionAtualizarEquipes(array $body, array $usuarioAtual): never
{
    global $conn;
    if (!$conn) {
        plannerError('Sem conexão com o banco de dados.', 500);
    }

    $campo   = plannerGetCampoIdentificador();
    $taskId  = plannerSanitizeInt($body['task_id'] ?? 0);
    $equipes = plannerSanitizeIds($body['equipes'] ?? []);

    if ($taskId <= 0) plannerError('task_id inválido.', 400);

    $agora = plannerNow();
    $usuarioId = (string) ($usuarioAtual['matricula'] ?? $usuarioAtual['chave'] ?? $usuarioAtual['id'] ?? '');
    if ($usuarioId === '') {
        $uAtual = plannerGetUsuarioAtual();
        $usuarioId = (string) ($uAtual['matricula'] ?? $uAtual['chave'] ?? $uAtual['id'] ?? '1001');
    }

    $sDel = $conn->prepare('DELETE FROM planner_tarefa_equipe WHERE tarefa_id = ?');
    if ($sDel) {
        $sDel->bind_param('i', $taskId);
        $sDel->execute();
    }

    foreach ($equipes as $eid) {
        $sIns = $conn->prepare('INSERT INTO planner_tarefa_equipe (tarefa_id, equipe_id) VALUES (?, ?)');
        if ($sIns) {
            $sIns->bind_param('ii', $taskId, $eid);
            $sIns->execute();
        }
    }

    $sAtiv = $conn->prepare("INSERT INTO planner_atividade (tarefa_id, `{$campo}`, tipo, meta, criado_em) VALUES (?, ?, 'equipes', ?, ?)");
    if ($sAtiv) {
        $meta = json_encode(['total' => count($equipes)]);
        $sAtiv->bind_param('isss', $taskId, $usuarioId, $meta, $agora);
        $sAtiv->execute();
    }

    plannerOk([
        'task_id'       => $taskId,
        'equipes'       => $equipes,
        'atualizado_em' => $agora,
    ]);
}

function plannerActionAtualizarPessoasSoltas(array $body, array $usuarioAtual): never
{
    global $conn;
    if (!$conn) {
        plannerError('Sem conexão com o banco de dados.', 500);
    }

    $campo         = plannerGetCampoIdentificador();
    $taskId        = plannerSanitizeInt($body['task_id'] ?? 0);
    $pessoasSoltas = plannerSanitizeUserIds($body['pessoas_soltas'] ?? []);

    if ($taskId <= 0) plannerError('task_id inválido.', 400);

    $agora = plannerNow();
    $usuarioId = (string) ($usuarioAtual['matricula'] ?? $usuarioAtual['chave'] ?? $usuarioAtual['id'] ?? '');
    if ($usuarioId === '') {
        $uAtual = plannerGetUsuarioAtual();
        $usuarioId = (string) ($uAtual['matricula'] ?? $uAtual['chave'] ?? $uAtual['id'] ?? '1001');
    }

    $sDel = $conn->prepare('DELETE FROM planner_tarefa_pessoas_soltas WHERE tarefa_id = ?');
    if ($sDel) {
        $sDel->bind_param('i', $taskId);
        $sDel->execute();
    }

    foreach ($pessoasSoltas as $uid) {
        $sIns = $conn->prepare("INSERT INTO planner_tarefa_pessoas_soltas (tarefa_id, `{$campo}`) VALUES (?, ?)");
        if ($sIns) {
            $sIns->bind_param('is', $taskId, $uid);
            $sIns->execute();
        }
    }

    $sAtiv = $conn->prepare("INSERT INTO planner_atividade (tarefa_id, `{$campo}`, tipo, meta, criado_em) VALUES (?, ?, 'pessoas_soltas', ?, ?)");
    if ($sAtiv) {
        $meta = json_encode(['total' => count($pessoasSoltas)]);
        $sAtiv->bind_param('isss', $taskId, $usuarioId, $meta, $agora);
        $sAtiv->execute();
    }

    plannerOk([
        'task_id'        => $taskId,
        'pessoas_soltas' => $pessoasSoltas,
        'atualizado_em'  => $agora,
    ]);
}

function plannerActionCriarComentario(array $body, array $usuarioAtual): never
{
    global $conn;
    if (!$conn) {
        plannerError('Sem conexão com o banco de dados.', 500);
    }

    $campo    = plannerGetCampoIdentificador();
    $tarefaId = plannerSanitizeInt($body['tarefa_id'] ?? 0);
    $texto    = plannerSanitizeStr($body['texto'] ?? '', false, 2000);

    if ($tarefaId <= 0) plannerError('tarefa_id inválido.', 400);
    if ($texto === '')  plannerError('O texto do comentário não pode ser vazio.', 400);

    $criado_em = plannerNow();
    $usuarioId = (string) ($usuarioAtual['matricula'] ?? $usuarioAtual['chave'] ?? $usuarioAtual['id'] ?? '');
    if ($usuarioId === '') {
        $uAtual = plannerGetUsuarioAtual();
        $usuarioId = (string) ($uAtual['matricula'] ?? $uAtual['chave'] ?? $uAtual['id'] ?? '1001');
    }

    $stmt = $conn->prepare("INSERT INTO planner_comentario (tarefa_id, `{$campo}`, texto, criado_em) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        plannerError('Erro ao preparar query: ' . $conn->error, 500);
    }
    $stmt->bind_param('isss', $tarefaId, $usuarioId, $texto, $criado_em);
    if (!$stmt->execute()) {
        plannerError('Erro ao inserir comentário: ' . $stmt->error, 500);
    }
    $novoId = (int) $conn->insert_id;

    // REGISTRA A ATIVIDADE DE NOVO COMENTÁRIO
    $sAtiv = $conn->prepare("INSERT INTO planner_atividade (tarefa_id, `{$campo}`, tipo, criado_em) VALUES (?, ?, 'comentario', ?)");
    if ($sAtiv) {
        $sAtiv->bind_param('iss', $tarefaId, $usuarioId, $criado_em);
        $sAtiv->execute();
    }

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

// AÇÃO AJAX: EXCLUIR TAREFA DO PLANNER COM LIMPEZA EM CASCATA
function plannerActionExcluirTarefa(array $body, array $usuarioAtual): never
{
    global $conn;
    if (!$conn) {
        plannerError('Sem conexão com o banco de dados.', 500);
    }

    $taskId = plannerSanitizeInt($body['task_id'] ?? 0);
    if ($taskId <= 0) {
        plannerError('task_id inválido.', 400);
    }

    // REMOVE REGISTROS ASSOCIADOS EM TABELAS RELACIONADAS
    $conn->query("DELETE FROM planner_grupo_tarefa WHERE tarefa_id = {$taskId}");
    $conn->query("DELETE FROM planner_tarefa_equipe WHERE tarefa_id = {$taskId}");
    $conn->query("DELETE FROM planner_tarefa_pessoas_soltas WHERE tarefa_id = {$taskId}");
    $conn->query("DELETE FROM planner_comentario WHERE tarefa_id = {$taskId}");
    $conn->query("DELETE FROM planner_atividade WHERE tarefa_id = {$taskId}");

    // REMOVE A TAREFA PRINCIPAL
    $stmt = $conn->prepare('DELETE FROM planner_tarefa WHERE id = ?');
    if (!$stmt) {
        plannerError('Erro ao preparar query: ' . $conn->error, 500);
    }
    $stmt->bind_param('i', $taskId);
    if (!$stmt->execute()) {
        plannerError('Erro ao excluir tarefa: ' . $stmt->error, 500);
    }

    plannerOk(['task_id' => $taskId, 'excluido_em' => plannerNow()]);
}

function plannerActionCriarEquipe(array $body, array $usuarioAtual): never
{
    global $conn;
    if (!$conn) {
        plannerError('Sem conexão com o banco de dados.', 500);
    }

    $campo   = plannerGetCampoIdentificador();
    $nome    = plannerSanitizeStr($body['nome'] ?? '', false, 100);
    $cor     = plannerSanitizeStr($body['cor'] ?? '#16A34A', false, 20);
    $paiId   = !empty($body['pai_id']) ? plannerSanitizeInt($body['pai_id']) : null;
    $liderId = !empty($body['lider_id']) ? plannerSanitizeStr($body['lider_id'], false, 50) : null;
    $membros = plannerSanitizeUserIds($body['membros'] ?? []);

    // SE UM LÍDER FOI ESPECIFICADO, GARANTE QUE ELE TAMBÉM CONSTE NA LISTA DE MEMBROS
    if ($liderId !== null && !in_array($liderId, $membros, true)) {
        $membros[] = $liderId;
    }

    if ($nome === '') {
        plannerError('O nome da equipe é obrigatório.', 400);
    }
    if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $cor)) {
        $cor = '#16A34A';
    }

    // INSERÇÃO DA EQUIPE COM LÍDER ASSOCIADO
    $stmt = $conn->prepare('INSERT INTO planner_equipe (nome, cor, pai_id, lider_id) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        plannerError('Erro ao preparar query: ' . $conn->error, 500);
    }
    $stmt->bind_param('ssis', $nome, $cor, $paiId, $liderId);
    if (!$stmt->execute()) {
        plannerError('Erro ao inserir equipe: ' . $stmt->error, 500);
    }
    $novaId = (int) $conn->insert_id;

    // VINCULAÇÃO DOS MEMBROS E DEFINIÇÃO DO PAPEL (LIDER OU MEMBRO)
    foreach ($membros as $uid) {
        $papel = ($uid === $liderId) ? 'lider' : 'membro';
        $sMem = $conn->prepare("INSERT INTO planner_membro_equipe (equipe_id, `{$campo}`, papel) VALUES (?, ?, ?)");
        if ($sMem) {
            $sMem->bind_param('iss', $novaId, $uid, $papel);
            $sMem->execute();
        }
    }

    // OBTÉM DADOS DO LÍDER PARA RESPOSTA
    $liderNome = '';
    if ($liderId !== null) {
        $sLid = $conn->prepare("SELECT nome FROM acesso_permitido WHERE `{$campo}` = ? LIMIT 1");
        if ($sLid) {
            $sLid->bind_param('s', $liderId);
            $sLid->execute();
            $rLid = $sLid->get_result();
            if ($rLid && $rowLid = $rLid->fetch_assoc()) {
                $liderNome = (string)$rowLid['nome'];
            }
        }
    }

    $novaEquipe = [
        'id'            => $novaId,
        'nome'          => $nome,
        'cor'           => $cor,
        'pai_id'        => $paiId,
        'lider_id'      => $liderId,
        'lider_nome'    => $liderNome,
        'lider_foto'    => $liderId ? (PLANNER_CAMINHO_IMAGENS . $liderId . '.png') : null,
        'total_membros' => count($membros),
        'membros'       => $membros,
    ];

    plannerOk(['equipe' => $novaEquipe]);
}

function plannerActionObterComentarios(array $body, array $usuarioAtual): never
{
    $tarefaId = plannerSanitizeInt($body['tarefa_id'] ?? 0);
    if ($tarefaId <= 0) {
        plannerError('tarefa_id inválido.', 400);
    }

    $comentarios = plannerGetComentarios($tarefaId);
    plannerOk([
        'tarefa_id'   => $tarefaId,
        'comentarios' => $comentarios,
    ]);
}

// AÇÃO AJAX: RECUPERAR TODAS AS ATIVIDADES / AUDITORIA DE UMA TAREFA
function plannerActionObterAtividades(array $body, array $usuarioAtual): never
{
    $tarefaId = plannerSanitizeInt($body['tarefa_id'] ?? 0);
    if ($tarefaId <= 0) {
        plannerError('tarefa_id inválido.', 400);
    }

    $atividades = plannerGetAtividades($tarefaId);
    plannerOk([
        'tarefa_id'  => $tarefaId,
        'atividades' => $atividades,
    ]);
}

