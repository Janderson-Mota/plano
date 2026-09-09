<?php
/**
 * api.php — Orbit · Endpoint único da API REST (Monolithic-lite)
 *
 * Convenções do item 0 aplicadas:
 *  - Aceita apenas POST + Content-Type: application/json
 *  - CSRF: valida X-CSRF-Token contra $_SESSION['csrf_token']
 *  - Payload de entrada:  { "action": string, ...campos }
 *  - Payload de saída:    { "success": bool, "data": {...}|null, "error": string|null }
 *  - HTTP status codes:   200 OK | 400 Bad Request | 403 Forbidden | 404 Not Found | 500 Internal
 *  - Sanitização:         todo texto recebido passa por htmlspecialchars() antes de uso
 *  - Fuso horário:        America/Sao_Paulo (DATETIME sem TZ embutido)
 *  - Assinatura mysqli:   cada função de acesso a dados aceita ?mysqli $db = null;
 *                         substitua o corpo para ativar a persistência real.
 */
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

// ═══════════════════════════════════════════════════════════
// § 0 · HEADERS & SESSÃO
// ═══════════════════════════════════════════════════════════
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ═══════════════════════════════════════════════════════════
// § 1 · HELPERS DE RESPOSTA
// ═══════════════════════════════════════════════════════════

/**
 * Envia resposta JSON e encerra.
 * @param mixed $data
 */
function orbitRespond(bool $success, mixed $data, ?string $error, int $httpCode = 200): never
{
    http_response_code($httpCode);
    echo json_encode(
        ['success' => $success, 'data' => $data, 'error' => $error],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function orbitOk(mixed $data = null): never
{
    orbitRespond(true, $data, null, 200);
}

function orbitError(string $error, int $httpCode = 400): never
{
    orbitRespond(false, null, $error, $httpCode);
}

// ═══════════════════════════════════════════════════════════
// § 2 · VALIDAÇÃO DE MÉTODO E CSRF
// ═══════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    orbitError('Método não permitido. Use POST.', 405);
}

// Validação CSRF
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$csrfSession = $_SESSION['csrf_token'] ?? '';

if (empty($csrfSession) || !hash_equals($csrfSession, $csrfHeader)) {
    orbitError('Token CSRF inválido ou ausente.', 403);
}

// ═══════════════════════════════════════════════════════════
// § 3 · LEITURA DO BODY JSON
// ═══════════════════════════════════════════════════════════

$rawBody = file_get_contents('php://input');
$body    = json_decode($rawBody ?: '{}', true);

if (!is_array($body)) {
    orbitError('Corpo da requisição inválido. Esperado JSON.', 400);
}

$action = trim((string)($body['action'] ?? ''));
if ($action === '') {
    orbitError('Campo "action" obrigatório.', 400);
}

// ═══════════════════════════════════════════════════════════
// § 4 · HELPERS DE SANITIZAÇÃO
// ═══════════════════════════════════════════════════════════

/** Sanitiza string de entrada; retorna null se vazia e $nullable = true. */
function orbitSanitizeStr(mixed $val, bool $nullable = false, int $maxLen = 2000): ?string
{
    if ($val === null || $val === '') {
        return $nullable ? null : '';
    }
    $str = htmlspecialchars(mb_substr((string)$val, 0, $maxLen), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return $str;
}

function orbitSanitizeInt(mixed $val): int
{
    return (int)filter_var($val, FILTER_SANITIZE_NUMBER_INT);
}

function orbitSanitizeIds(mixed $val): array
{
    if (!is_array($val)) return [];
    return array_values(array_filter(array_map('intval', $val), fn($id) => $id > 0));
}

function orbitNow(): string
{
    return date('Y-m-d H:i:s');
}

// ═══════════════════════════════════════════════════════════
// § 5 · ENUMS VÁLIDOS (item 0)
// ═══════════════════════════════════════════════════════════

const ORBIT_PRIORIDADES = ['baixa', 'media', 'alta', 'urgente'];
const ORBIT_COLUNAS     = ['backlog', 'em-progresso', 'em-revisao', 'concluido'];

// ═══════════════════════════════════════════════════════════
// § 6 · MOCK DE DADOS (mesmo shape do index.php)
// Em produção: substituir cada função por queries mysqli.
// ═══════════════════════════════════════════════════════════

function orbitApiGetTarefas(?object $db = null): array
{
    // Produção:
    // $stmt = $db->prepare('SELECT t.*, GROUP_CONCAT(g.usuario_id) AS resp_ids
    //     FROM orbit_tarefa t LEFT JOIN orbit_grupo_tarefa g ON g.tarefa_id=t.id
    //     GROUP BY t.id');
    // $stmt->execute(); ...
    return [
        ['id'=>1, 'titulo'=>'Modelar esquema do banco de dados',       'coluna_id'=>'backlog',      'prioridade'=>'alta',    'prazo'=>'2026-09-14','criado_por'=>2,'responsaveis'=>[2,5],'equipes'=>[2,4]],
        ['id'=>2, 'titulo'=>'Levantar requisitos do módulo de relatórios','coluna_id'=>'backlog',      'prioridade'=>'media',   'prazo'=>'2026-09-18','criado_por'=>7,'responsaveis'=>[3],'equipes'=>[1]],
        ['id'=>3, 'titulo'=>'Criar wireframes da tela de configurações','coluna_id'=>'backlog',      'prioridade'=>'baixa',   'prazo'=>'2026-09-25','criado_por'=>3,'responsaveis'=>[3,1],'equipes'=>[1,3]],
        ['id'=>4, 'titulo'=>'Implementar autenticação de usuários',     'coluna_id'=>'em-progresso', 'prioridade'=>'urgente', 'prazo'=>'2026-09-10','criado_por'=>2,'responsaveis'=>[2,4,6],'equipes'=>[2,4]],
        ['id'=>5, 'titulo'=>'Construir componente de drag and drop',    'coluna_id'=>'em-progresso', 'prioridade'=>'alta',    'prazo'=>'2026-09-09','criado_por'=>1,'responsaveis'=>[1,4],'equipes'=>[2,3]],
        ['id'=>6, 'titulo'=>'Ajustar responsividade do quadro',         'coluna_id'=>'em-progresso', 'prioridade'=>'media',   'prazo'=>'2026-09-16','criado_por'=>1,'responsaveis'=>[1],'equipes'=>[2,3]],
        ['id'=>7, 'titulo'=>'Revisar contraste de cores do design system','coluna_id'=>'em-revisao',   'prioridade'=>'media',   'prazo'=>'2026-09-11','criado_por'=>3,'responsaveis'=>[3,4],'equipes'=>[1,3]],
        ['id'=>8, 'titulo'=>'Testar fluxo de criação de tarefas',       'coluna_id'=>'em-revisao',   'prioridade'=>'alta',    'prazo'=>'2026-09-12','criado_por'=>4,'responsaveis'=>[4],'equipes'=>[2,5]],
        ['id'=>9, 'titulo'=>'Documentar endpoints simulados da API',    'coluna_id'=>'concluido',    'prioridade'=>'baixa',   'prazo'=>'2026-09-05','criado_por'=>2,'responsaveis'=>[2],'equipes'=>[2,4]],
        ['id'=>10,'titulo'=>'Configurar estrutura inicial do projeto PHP','coluna_id'=>'concluido',    'prioridade'=>'media',   'prazo'=>'2026-09-02','criado_por'=>2,'responsaveis'=>[2,5],'equipes'=>[2,4]],
        ['id'=>11,'titulo'=>'Definir paleta de cores e tipografia',     'coluna_id'=>'concluido',    'prioridade'=>'baixa',   'prazo'=>'2026-08-29','criado_por'=>3,'responsaveis'=>[3],'equipes'=>[1,3]],
        ['id'=>12,'titulo'=>'Planejar sprint de calendário integrado',  'coluna_id'=>'backlog',      'prioridade'=>'media',   'prazo'=>'2026-09-22','criado_por'=>7,'responsaveis'=>[5,1],'equipes'=>[1]],
    ];
}

function orbitApiGetComentarios(?object $db = null): array
{
    // Produção: SELECT * FROM orbit_comentario ORDER BY criado_em
    return [
        ['id'=>1,'tarefa_id'=>1,'usuario_id'=>2,'texto'=>'Precisamos alinhar o schema com os requisitos de relatório antes de finalizar.','criado_em'=>'2026-09-01 10:00:00','editado_em'=>null],
        ['id'=>2,'tarefa_id'=>1,'usuario_id'=>5,'texto'=>'Concordo. Vou preparar um rascunho do ERD até amanhã.','criado_em'=>'2026-09-01 10:30:00','editado_em'=>null],
        ['id'=>3,'tarefa_id'=>1,'usuario_id'=>2,'texto'=>'ERD revisado — adicionei a tabela orbit_atividade conforme discutido.','criado_em'=>'2026-09-02 09:00:00','editado_em'=>'2026-09-02 09:45:00'],
        ['id'=>4,'tarefa_id'=>4,'usuario_id'=>2,'texto'=>'Sessão JWT ou cookie httpOnly? Precisamos decidir hoje.','criado_em'=>'2026-09-05 14:00:00','editado_em'=>null],
        ['id'=>5,'tarefa_id'=>4,'usuario_id'=>6,'texto'=>'Vote em httpOnly. JWT no cliente vira dor de cabeça com refresh token.','criado_em'=>'2026-09-05 14:20:00','editado_em'=>null],
        ['id'=>6,'tarefa_id'=>4,'usuario_id'=>4,'texto'=>'Testei o fluxo de recuperação de senha — há um bug no token de reset.','criado_em'=>'2026-09-06 10:00:00','editado_em'=>'2026-09-06 10:30:00'],
        ['id'=>7,'tarefa_id'=>5,'usuario_id'=>1,'texto'=>'DnD API funcionando no Chrome e Firefox. Safari precisa de workaround.','criado_em'=>'2026-09-07 11:00:00','editado_em'=>null],
        ['id'=>8,'tarefa_id'=>5,'usuario_id'=>4,'texto'=>'Reproduzi o bug no Safari 17. Vou abrir uma issue separada.','criado_em'=>'2026-09-08 09:00:00','editado_em'=>null],
        ['id'=>9,'tarefa_id'=>7,'usuario_id'=>3,'texto'=>'Paleta revisada. Contraste de texto principal agora passa WCAG AA.','criado_em'=>'2026-09-07 14:00:00','editado_em'=>null],
        ['id'=>10,'tarefa_id'=>7,'usuario_id'=>4,'texto'=>'Preciso verificar os componentes de badge também.','criado_em'=>'2026-09-07 15:00:00','editado_em'=>null],
        ['id'=>11,'tarefa_id'=>7,'usuario_id'=>3,'texto'=>'Badges atualizados. Aguardando aprovação final.','criado_em'=>'2026-09-08 10:00:00','editado_em'=>'2026-09-08 10:20:00'],
        ['id'=>12,'tarefa_id'=>8,'usuario_id'=>4,'texto'=>'Fluxo ok no happy path. Preciso cobrir os edge cases de validação.','criado_em'=>'2026-09-06 09:00:00','editado_em'=>null],
    ];
}

// ═══════════════════════════════════════════════════════════
// § 7 · USUÁRIO ATUAL (injetado pelo projeto-pai)
// ═══════════════════════════════════════════════════════════

$usuarioAtual = $usuarioAtual ?? [
    'id' => 1, 'nome' => 'Ana Ferreira', 'cargo' => 'Front-end',
    'iniciais' => 'AF', 'cor' => '#4C3FE0', 'email' => 'ana@orbit.dev',
];

// ═══════════════════════════════════════════════════════════
// § 8 · ROTEAMENTO DE AÇÕES
// ═══════════════════════════════════════════════════════════

match($action) {
    'criar_tarefa'           => orbitActionCriarTarefa($body, $usuarioAtual),
    'mover_tarefa'           => orbitActionMoverTarefa($body),
    'atualizar_responsaveis' => orbitActionAtualizarResponsaveis($body),
    'criar_comentario'       => orbitActionCriarComentario($body, $usuarioAtual),
    'editar_comentario'      => orbitActionEditarComentario($body, $usuarioAtual),
    'excluir_comentario'     => orbitActionExcluirComentario($body, $usuarioAtual),
    default                  => orbitError("Ação desconhecida: {$action}", 400),
};

// ═══════════════════════════════════════════════════════════
// § 9 · HANDLERS DE AÇÃO
// ═══════════════════════════════════════════════════════════

/**
 * POST { action:"criar_tarefa", titulo, descricao?, coluna_id, prioridade, prazo?, responsaveis[], equipes[] }
 *
 * Produção: INSERT INTO orbit_tarefa + INSERT INTO orbit_grupo_tarefa (loop)
 *           + INSERT INTO orbit_atividade (tipo='criacao')
 */
function orbitActionCriarTarefa(array $body, array $usuarioAtual): never
{
    $titulo      = orbitSanitizeStr($body['titulo'] ?? '', false, 200);
    $descricao   = orbitSanitizeStr($body['descricao'] ?? '', true, 1000);
    $colunaId    = orbitSanitizeStr($body['coluna_id'] ?? '', false, 40);
    $prioridade  = orbitSanitizeStr($body['prioridade'] ?? '', false, 10);
    $prazo       = orbitSanitizeStr($body['prazo'] ?? '', true, 10);
    $responsaveis = orbitSanitizeIds($body['responsaveis'] ?? []);
    $equipes     = orbitSanitizeIds($body['equipes'] ?? []);

    // Compatibilidade caso venha equipe_id único
    if (empty($equipes) && !empty($body['equipe_id'])) {
        $eqId = orbitSanitizeInt($body['equipe_id']);
        if ($eqId > 0) $equipes = [$eqId];
    }

    // Validação
    if ($titulo === '') orbitError('O título é obrigatório.', 400);
    if (!in_array($colunaId, ORBIT_COLUNAS, true)) orbitError('Coluna inválida.', 400);
    if (!in_array($prioridade, ORBIT_PRIORIDADES, true)) orbitError('Prioridade inválida.', 400);
    if ($prazo !== null && $prazo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $prazo)) {
        orbitError('Formato de prazo inválido. Use YYYY-MM-DD.', 400);
    }

    // Mock: simula ID gerado e retorna a tarefa criada
    $novaId = rand(100, 9999);
    $novaTarefa = [
        'id'           => $novaId,
        'titulo'       => $titulo,
        'descricao'    => $descricao,
        'coluna_id'    => $colunaId,
        'prioridade'   => $prioridade,
        'prazo'        => ($prazo !== '' && $prazo !== null) ? $prazo : null,
        'criado_por'   => $usuarioAtual['id'],
        'responsaveis' => $responsaveis,
        'equipes'      => $equipes,
        'criado_em'    => orbitNow(),
        'atualizado_em'=> orbitNow(),
    ];

    orbitOk(['tarefa' => $novaTarefa]);
}

/**
 * POST { action:"mover_tarefa", task_id, coluna_id }
 *
 * Produção: UPDATE orbit_tarefa SET coluna_id=?, atualizado_em=NOW() WHERE id=?
 *           + INSERT INTO orbit_atividade (tipo='movimentacao', meta='{"de":"..","para":".."}')
 */
function orbitActionMoverTarefa(array $body): never
{
    $taskId  = orbitSanitizeInt($body['task_id'] ?? 0);
    $colunaId = orbitSanitizeStr($body['coluna_id'] ?? '', false, 40);

    if ($taskId <= 0)                                         orbitError('task_id inválido.', 400);
    if (!in_array($colunaId, ORBIT_COLUNAS, true))           orbitError('Coluna inválida.', 400);

    // Verifica existência da tarefa no mock
    $tarefas = orbitApiGetTarefas();
    $tarefa  = null;
    foreach ($tarefas as $t) {
        if ($t['id'] === $taskId) { $tarefa = $t; break; }
    }
    if ($tarefa === null) orbitError("Tarefa #{$taskId} não encontrada.", 404);

    /*
    // Produção:
    $de = $tarefa['coluna_id'];
    $stmt = $db->prepare('UPDATE orbit_tarefa SET coluna_id=?, atualizado_em=NOW() WHERE id=?');
    $stmt->bind_param('si', $colunaId, $taskId);
    $stmt->execute();
    $meta = json_encode(['de' => $de, 'para' => $colunaId]);
    $stmt2 = $db->prepare('INSERT INTO orbit_atividade (tarefa_id, usuario_id, tipo, meta) VALUES (?, ?, "movimentacao", ?)');
    $stmt2->bind_param('iis', $taskId, $usuarioAtual['id'], $meta);
    $stmt2->execute();
    */

    orbitOk([
        'task_id'      => $taskId,
        'coluna_id'    => $colunaId,
        'atualizado_em'=> orbitNow(),
    ]);
}

/**
 * POST { action:"atualizar_responsaveis", task_id, responsaveis[] }
 *
 * Produção: DELETE FROM orbit_grupo_tarefa WHERE tarefa_id=?
 *           + INSERT INTO orbit_grupo_tarefa loop
 *           + INSERT INTO orbit_atividade (tipo='atribuicao')
 */
function orbitActionAtualizarResponsaveis(array $body): never
{
    $taskId       = orbitSanitizeInt($body['task_id'] ?? 0);
    $responsaveis = orbitSanitizeIds($body['responsaveis'] ?? []);

    if ($taskId <= 0) orbitError('task_id inválido.', 400);

    $tarefas = orbitApiGetTarefas();
    $existe  = false;
    foreach ($tarefas as $t) {
        if ($t['id'] === $taskId) { $existe = true; break; }
    }
    if (!$existe) orbitError("Tarefa #{$taskId} não encontrada.", 404);

    /*
    // Produção:
    $stmt = $db->prepare('DELETE FROM orbit_grupo_tarefa WHERE tarefa_id=?');
    $stmt->bind_param('i', $taskId); $stmt->execute();
    foreach ($responsaveis as $uid) {
        $stmt2 = $db->prepare('INSERT INTO orbit_grupo_tarefa (tarefa_id, usuario_id) VALUES (?,?)');
        $stmt2->bind_param('ii', $taskId, $uid); $stmt2->execute();
    }
    */

    orbitOk([
        'task_id'      => $taskId,
        'responsaveis' => $responsaveis,
        'atualizado_em'=> orbitNow(),
    ]);
}

/**
 * POST { action:"criar_comentario", tarefa_id, texto }
 *
 * Produção: INSERT INTO orbit_comentario + INSERT INTO orbit_atividade (tipo='comentario')
 */
function orbitActionCriarComentario(array $body, array $usuarioAtual): never
{
    $tarefaId = orbitSanitizeInt($body['tarefa_id'] ?? 0);
    $texto    = orbitSanitizeStr($body['texto'] ?? '', false, 2000);

    if ($tarefaId <= 0)  orbitError('tarefa_id inválido.', 400);
    if ($texto === '')   orbitError('O texto do comentário não pode ser vazio.', 400);

    // Verifica existência da tarefa
    $tarefas = orbitApiGetTarefas();
    $existe  = false;
    foreach ($tarefas as $t) {
        if ($t['id'] === $tarefaId) { $existe = true; break; }
    }
    if (!$existe) orbitError("Tarefa #{$tarefaId} não encontrada.", 404);

    /*
    // Produção:
    $stmt = $db->prepare('INSERT INTO orbit_comentario (tarefa_id, usuario_id, texto) VALUES (?, ?, ?)');
    $stmt->bind_param('iis', $tarefaId, $usuarioAtual['id'], $texto);
    $stmt->execute();
    $novoId = $db->insert_id;
    $meta = json_encode(['comentario_id' => $novoId]);
    $stmt2 = $db->prepare('INSERT INTO orbit_atividade (tarefa_id, usuario_id, tipo, meta) VALUES (?, ?, "comentario", ?)');
    $stmt2->bind_param('iis', $tarefaId, $usuarioAtual['id'], $meta);
    $stmt2->execute();
    */

    $novoId   = rand(100, 9999);
    $criado_em = orbitNow();

    orbitOk([
        'comentario' => [
            'id'         => $novoId,
            'tarefa_id'  => $tarefaId,
            'usuario_id' => $usuarioAtual['id'],
            'texto'      => $texto,
            'criado_em'  => $criado_em,
            'editado_em' => null,
        ],
    ]);
}

/**
 * POST { action:"editar_comentario", comentario_id, texto }
 *
 * Produção: UPDATE orbit_comentario SET texto=?, editado_em=NOW() WHERE id=? AND usuario_id=?
 * Apenas o autor pode editar.
 */
function orbitActionEditarComentario(array $body, array $usuarioAtual): never
{
    $comentarioId = orbitSanitizeInt($body['comentario_id'] ?? 0);
    $texto        = orbitSanitizeStr($body['texto'] ?? '', false, 2000);

    if ($comentarioId <= 0) orbitError('comentario_id inválido.', 400);
    if ($texto === '')       orbitError('O texto não pode ser vazio.', 400);

    // Verifica autoria no mock
    $comentarios = orbitApiGetComentarios();
    $comentario  = null;
    foreach ($comentarios as $c) {
        if ($c['id'] === $comentarioId) { $comentario = $c; break; }
    }
    if ($comentario === null)                            orbitError('Comentário não encontrado.', 404);
    if ($comentario['usuario_id'] !== $usuarioAtual['id']) orbitError('Apenas o autor pode editar este comentário.', 403);

    /*
    // Produção:
    $stmt = $db->prepare(
        'UPDATE orbit_comentario SET texto=?, editado_em=NOW() WHERE id=? AND usuario_id=?'
    );
    $stmt->bind_param('sii', $texto, $comentarioId, $usuarioAtual['id']);
    $stmt->execute();
    if ($stmt->affected_rows === 0) orbitError('Nenhuma linha atualizada.', 403);
    */

    $editado_em = orbitNow();

    orbitOk([
        'comentario_id' => $comentarioId,
        'texto'         => $texto,
        'editado_em'    => $editado_em,
    ]);
}

/**
 * POST { action:"excluir_comentario", comentario_id }
 *
 * Produção: DELETE FROM orbit_comentario WHERE id=? AND usuario_id=?
 * Apenas o autor pode excluir.
 */
function orbitActionExcluirComentario(array $body, array $usuarioAtual): never
{
    $comentarioId = orbitSanitizeInt($body['comentario_id'] ?? 0);

    if ($comentarioId <= 0) orbitError('comentario_id inválido.', 400);

    // Verifica autoria no mock
    $comentarios = orbitApiGetComentarios();
    $comentario  = null;
    foreach ($comentarios as $c) {
        if ($c['id'] === $comentarioId) { $comentario = $c; break; }
    }
    if ($comentario === null)                            orbitError('Comentário não encontrado.', 404);
    if ($comentario['usuario_id'] !== $usuarioAtual['id']) orbitError('Apenas o autor pode excluir este comentário.', 403);

    /*
    // Produção:
    $stmt = $db->prepare('DELETE FROM orbit_comentario WHERE id=? AND usuario_id=?');
    $stmt->bind_param('ii', $comentarioId, $usuarioAtual['id']);
    $stmt->execute();
    if ($stmt->affected_rows === 0) orbitError('Nenhuma linha excluída.', 403);
    */

    orbitOk(['comentario_id' => $comentarioId, 'excluido_em' => orbitNow()]);
}
