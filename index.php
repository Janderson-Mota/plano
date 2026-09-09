<?php
/**
 * index.php — Orbit · Hub principal do Módulo de Gestão de Equipes e Tarefas
 *
 * Convenções aplicadas (item 0 do spec):
 *  - PHP 8+, declare(strict_types=1)
 *  - CSRF via $_SESSION['csrf_token'] + <meta name="csrf-token">
 *  - Mock com assinatura mysqli-ready (plug-in drop-in)
 *  - status_prazo calculado em runtime (nunca armazenado)
 *  - Fuso: America/Sao_Paulo — timestamps sem TZ embutido
 *  - IDs fixos: #orbit-board, #orbit-filtros, #orbit-modal-tarefa,
 *               #orbit-busca-global, [data-view]
 *  - Sanitização: htmlspecialchars() em todo texto externo
 */
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

// ═══════════════════════════════════════════════════════════
// § 0 · SESSÃO & CSRF
// ═══════════════════════════════════════════════════════════
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ═══════════════════════════════════════════════════════════
// § 1 · USUÁRIO ATUAL
// Em produção: $usuarioAtual é injetado pelo projeto-pai.
// ═══════════════════════════════════════════════════════════
$usuarioAtual = $usuarioAtual ?? [
    'id' => 1, 'nome' => 'Ana Ferreira', 'cargo' => 'Front-end',
    'iniciais' => 'AF', 'cor' => '#4C3FE0', 'email' => 'ana@orbit.dev',
];

// ═══════════════════════════════════════════════════════════
// § 2 · CAMADA DE DADOS — Mock com assinatura mysqli-ready
//
// Para conectar ao banco real, substitua o corpo de cada
// função pelo bloco comentado acima do return.
// O retorno deve manter o mesmo shape de array.
// ═══════════════════════════════════════════════════════════

/**
 * @param ?object $db Conexão mysqli real; null = mock.
 * @return list<array{id:int,nome:string,cargo:string,iniciais:string,cor:string,email:string}>
 */
function orbitGetUsuarios(?object $db = null): array
{
    /*
    // Produção:
    $stmt = $db->prepare(
        'SELECT id, nome, cargo, iniciais, cor, email FROM orbit_usuario ORDER BY nome'
    );
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    */
    return [
        ['id'=>1,'nome'=>'Ana Ferreira',  'cargo'=>'Front-end',     'iniciais'=>'AF','cor'=>'#4C3FE0','email'=>'ana@orbit.dev'],
        ['id'=>2,'nome'=>'Bruno Lima',    'cargo'=>'Back-end',      'iniciais'=>'BL','cor'=>'#0EA5A0','email'=>'bruno@orbit.dev'],
        ['id'=>3,'nome'=>'Carla Nunes',   'cargo'=>'UI/UX',         'iniciais'=>'CN','cor'=>'#F59E0B','email'=>'carla@orbit.dev'],
        ['id'=>4,'nome'=>'Diego Santos',  'cargo'=>'QA',            'iniciais'=>'DS','cor'=>'#EF4444','email'=>'diego@orbit.dev'],
        ['id'=>5,'nome'=>'Elisa Prado',   'cargo'=>'Back-end',      'iniciais'=>'EP','cor'=>'#10B981','email'=>'elisa@orbit.dev'],
        ['id'=>6,'nome'=>'Felipe Costa',  'cargo'=>'DevOps',        'iniciais'=>'FC','cor'=>'#8B5CF6','email'=>'felipe@orbit.dev'],
        ['id'=>7,'nome'=>'Gabriela Melo', 'cargo'=>'Product Owner', 'iniciais'=>'GM','cor'=>'#F97316','email'=>'gabi@orbit.dev'],
    ];
}

/**
 * @param ?object $db
 * @return list<array{id:int,nome:string,descricao:string,pai_id:int|null,membros:list<int>}>
 */
function orbitGetEquipes(?object $db = null): array
{
    /*
    // Produção:
    $stmt = $db->prepare(
        'SELECT e.id, e.nome, e.descricao, e.pai_id,
                GROUP_CONCAT(me.usuario_id ORDER BY me.usuario_id) AS membro_ids
         FROM orbit_equipe e
         LEFT JOIN orbit_membro_equipe me ON me.equipe_id = e.id
         GROUP BY e.id ORDER BY e.pai_id IS NULL DESC, e.id'
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return array_map(function($r) {
        $r['membros'] = $r['membro_ids']
            ? array_map('intval', explode(',', $r['membro_ids']))
            : [];
        unset($r['membro_ids']);
        return $r;
    }, $rows);
    */
    return [
        ['id'=>1,'nome'=>'Produto Digital','descricao'=>'Estratégia de produto e UX.','pai_id'=>null,'membros'=>[7,3]],
        ['id'=>2,'nome'=>'Engenharia',     'descricao'=>'Desenvolvimento e qualidade.','pai_id'=>null,'membros'=>[2,6]],
        ['id'=>3,'nome'=>'Front-end',      'descricao'=>'Interfaces e UX.',           'pai_id'=>2,  'membros'=>[1,3]],
        ['id'=>4,'nome'=>'Back-end',       'descricao'=>'APIs e banco de dados.',     'pai_id'=>2,  'membros'=>[2,5]],
        ['id'=>5,'nome'=>'QA',             'descricao'=>'Garantia de qualidade.',     'pai_id'=>2,  'membros'=>[4]],
        ['id'=>6,'nome'=>'Mobile',         'descricao'=>'Versões nativas iOS/Android.','pai_id'=>3, 'membros'=>[1,4]],
        ['id'=>7,'nome'=>'iOS',            'descricao'=>'Desenvolvimento iOS.',       'pai_id'=>6,  'membros'=>[4]],
    ];
}

/**
 * @param ?object $db
 * @return list<array{id:string,titulo:string,cor:string,ordem:int}>
 */
function orbitGetColunas(?object $db = null): array
{
    /*
    // Produção:
    $stmt = $db->prepare('SELECT id, titulo, cor, ordem FROM orbit_coluna ORDER BY ordem');
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    */
    return [
        ['id'=>'backlog',      'titulo'=>'Backlog',      'cor'=>'#94A3B8','ordem'=>0],
        ['id'=>'em-progresso', 'titulo'=>'Em Progresso', 'cor'=>'#4C3FE0','ordem'=>1],
        ['id'=>'em-revisao',   'titulo'=>'Em Revisão',   'cor'=>'#F59E0B','ordem'=>2],
        ['id'=>'concluido',    'titulo'=>'Concluído',    'cor'=>'#10B981','ordem'=>3],
    ];
}

/**
 * Calcula status_prazo em runtime — nunca armazenado no banco.
 * Retorna: 'atrasada' | 'hoje' | 'futura' | 'sem-prazo' | 'concluido'
 */
function orbitCalcularStatusPrazo(?string $prazo, string $colunaId): string
{
    if ($colunaId === 'concluido') return 'concluido';
    if ($prazo === null || $prazo === '') return 'sem-prazo';
    $hoje = date('Y-m-d');
    if ($prazo < $hoje) return 'atrasada';
    if ($prazo === $hoje) return 'hoje';
    return 'futura';
}

/**
 * @param ?object $db
 * @param array{prioridade?:string,usuario?:int,texto?:string} $filtros Filtros opcionais.
 * @return list<array{id:int,titulo:string,descricao:string,coluna_id:string,prioridade:string,
 *                    prazo:string|null,criado_por:int,responsaveis:list<int>,status_prazo:string}>
 */
function orbitGetTarefas(?object $db = null, array $filtros = []): array
{
    /*
    // Produção (exemplo com filtros dinâmicos):
    $where = ['1=1']; $params = []; $types = '';
    if (!empty($filtros['prioridade'])) { $where[]='t.prioridade=?'; $params[]=$filtros['prioridade']; $types.='s'; }
    if (!empty($filtros['usuario']))    { $where[]='EXISTS(SELECT 1 FROM orbit_grupo_tarefa g WHERE g.tarefa_id=t.id AND g.usuario_id=?)'; $params[]=(int)$filtros['usuario']; $types.='i'; }
    $sql = 'SELECT t.*, GROUP_CONCAT(g.usuario_id ORDER BY g.usuario_id) AS resp_ids
            FROM orbit_tarefa t LEFT JOIN orbit_grupo_tarefa g ON g.tarefa_id=t.id
            WHERE '.implode(' AND ',$where).' GROUP BY t.id ORDER BY t.coluna_id, t.id';
    $stmt = $db->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    // ... mapear resp_ids + calcular status_prazo ...
    */
    $tarefas = [
        ['id'=>1, 'titulo'=>'Modelar esquema do banco de dados',       'descricao'=>'Definir tabelas de usuários, tarefas, colunas e equipes.',   'coluna_id'=>'backlog',      'prioridade'=>'alta',    'prazo'=>'2026-09-14','criado_por'=>2,'responsaveis'=>[2,5],'equipes'=>[2,4]],
        ['id'=>2, 'titulo'=>'Levantar requisitos do módulo de relatórios','descricao'=>'Reunião com o time de produto para mapear indicadores.',    'coluna_id'=>'backlog',      'prioridade'=>'media',   'prazo'=>'2026-09-18','criado_por'=>7,'responsaveis'=>[3],'equipes'=>[1]],
        ['id'=>3, 'titulo'=>'Criar wireframes da tela de configurações','descricao'=>'Baixa fidelidade, cobrindo os três perfis de usuário.',       'coluna_id'=>'backlog',      'prioridade'=>'baixa',   'prazo'=>'2026-09-25','criado_por'=>3,'responsaveis'=>[3,1],'equipes'=>[1,3]],
        ['id'=>4, 'titulo'=>'Implementar autenticação de usuários',     'descricao'=>'Login, recuperação de senha e sessão persistente.',           'coluna_id'=>'em-progresso', 'prioridade'=>'urgente', 'prazo'=>'2026-09-10','criado_por'=>2,'responsaveis'=>[2,4,6],'equipes'=>[2,4]],
        ['id'=>5, 'titulo'=>'Construir componente de drag and drop',    'descricao'=>'Cards arrastáveis entre colunas com feedback visual.',        'coluna_id'=>'em-progresso', 'prioridade'=>'alta',    'prazo'=>'2026-09-09','criado_por'=>1,'responsaveis'=>[1,4],'equipes'=>[2,3]],
        ['id'=>6, 'titulo'=>'Ajustar responsividade do quadro',         'descricao'=>'Rolagem horizontal suave e colunas com largura mínima.',     'coluna_id'=>'em-progresso', 'prioridade'=>'media',   'prazo'=>'2026-09-16','criado_por'=>1,'responsaveis'=>[1],'equipes'=>[2,3]],
        ['id'=>7, 'titulo'=>'Revisar contraste de cores do design system','descricao'=>'Checar acessibilidade (WCAG AA) da paleta escolhida.',      'coluna_id'=>'em-revisao',   'prioridade'=>'media',   'prazo'=>'2026-09-11','criado_por'=>3,'responsaveis'=>[3,4],'equipes'=>[1,3]],
        ['id'=>8, 'titulo'=>'Testar fluxo de criação de tarefas',       'descricao'=>'Validar formulário, mensagens de erro e retorno da API.',    'coluna_id'=>'em-revisao',   'prioridade'=>'alta',    'prazo'=>'2026-09-12','criado_por'=>4,'responsaveis'=>[4],'equipes'=>[2,5]],
        ['id'=>9, 'titulo'=>'Documentar endpoints simulados da API',    'descricao'=>'Descrever payloads de entrada e saída para o time.',         'coluna_id'=>'concluido',    'prioridade'=>'baixa',   'prazo'=>'2026-09-05','criado_por'=>2,'responsaveis'=>[2],'equipes'=>[2,4]],
        ['id'=>10,'titulo'=>'Configurar estrutura inicial do projeto PHP','descricao'=>'Pastas, includes e convenção de nomes definidas.',          'coluna_id'=>'concluido',    'prioridade'=>'media',   'prazo'=>'2026-09-02','criado_por'=>2,'responsaveis'=>[2,5],'equipes'=>[2,4]],
        ['id'=>11,'titulo'=>'Definir paleta de cores e tipografia',     'descricao'=>'Fonte Inter e paleta baseada em azul-tecnológico.',          'coluna_id'=>'concluido',    'prioridade'=>'baixa',   'prazo'=>'2026-08-29','criado_por'=>3,'responsaveis'=>[3],'equipes'=>[1,3]],
        ['id'=>12,'titulo'=>'Planejar sprint de calendário integrado',  'descricao'=>'Alinhar escopo da visão de calendário com o time.',           'coluna_id'=>'backlog',      'prioridade'=>'media',   'prazo'=>'2026-09-22','criado_por'=>7,'responsaveis'=>[5,1],'equipes'=>[1]],
    ];

    // Calcular status_prazo em runtime
    foreach ($tarefas as &$t) {
        $t['status_prazo'] = orbitCalcularStatusPrazo($t['prazo'], $t['coluna_id']);
    }
    unset($t);

    // Aplicar filtros
    if (!empty($filtros['prioridade'])) {
        $tarefas = array_filter($tarefas, fn($t) => $t['prioridade'] === $filtros['prioridade']);
    }
    if (!empty($filtros['usuario'])) {
        $uid = (int)$filtros['usuario'];
        $tarefas = array_filter($tarefas, fn($t) => in_array($uid, $t['responsaveis'], true));
    }
    if (!empty($filtros['equipe'])) {
        $eqId = (int)$filtros['equipe'];
        $tarefas = array_filter($tarefas, fn($t) => in_array($eqId, $t['equipes'] ?? [], true));
    }
    if (!empty($filtros['data_inicio'])) {
        $tarefas = array_filter($tarefas, fn($t) => !empty($t['prazo']) && $t['prazo'] >= $filtros['data_inicio']);
    }
    if (!empty($filtros['data_fim'])) {
        $tarefas = array_filter($tarefas, fn($t) => !empty($t['prazo']) && $t['prazo'] <= $filtros['data_fim']);
    }
    if (!empty($filtros['texto'])) {
        $q = mb_strtolower($filtros['texto']);
        $tarefas = array_filter($tarefas, fn($t) =>
            str_contains(mb_strtolower($t['titulo']), $q) ||
            str_contains(mb_strtolower($t['descricao'] ?? ''), $q)
        );
    }

    return array_values($tarefas);
}

/**
 * @param ?object $db
 * @param int $tarefaId 0 = retorna todos (para o JSON blob do client).
 * @return list<array{id:int,tarefa_id:int,usuario_id:int,texto:string,criado_em:string,editado_em:string|null}>
 */
function orbitGetComentarios(?object $db = null, int $tarefaId = 0): array
{
    /*
    // Produção:
    $sql = $tarefaId
        ? 'SELECT * FROM orbit_comentario WHERE tarefa_id=? ORDER BY criado_em'
        : 'SELECT * FROM orbit_comentario ORDER BY criado_em';
    $stmt = $db->prepare($sql);
    if ($tarefaId) { $stmt->bind_param('i', $tarefaId); }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    */
    $todos = [
        ['id'=>1, 'tarefa_id'=>1,'usuario_id'=>2,'texto'=>'Precisamos alinhar o schema com os requisitos de relatório antes de finalizar.','criado_em'=>'2026-09-01 10:00:00','editado_em'=>null],
        ['id'=>2, 'tarefa_id'=>1,'usuario_id'=>5,'texto'=>'Concordo. Vou preparar um rascunho do ERD até amanhã.','criado_em'=>'2026-09-01 10:30:00','editado_em'=>null],
        ['id'=>3, 'tarefa_id'=>1,'usuario_id'=>2,'texto'=>'ERD revisado — adicionei a tabela orbit_atividade conforme discutido.','criado_em'=>'2026-09-02 09:00:00','editado_em'=>'2026-09-02 09:45:00'],
        ['id'=>4, 'tarefa_id'=>4,'usuario_id'=>2,'texto'=>'Sessão JWT ou cookie httpOnly? Precisamos decidir hoje.','criado_em'=>'2026-09-05 14:00:00','editado_em'=>null],
        ['id'=>5, 'tarefa_id'=>4,'usuario_id'=>6,'texto'=>'Vote em httpOnly. JWT no cliente vira dor de cabeça com refresh token.','criado_em'=>'2026-09-05 14:20:00','editado_em'=>null],
        ['id'=>6, 'tarefa_id'=>4,'usuario_id'=>4,'texto'=>'Testei o fluxo de recuperação de senha — há um bug no token de reset.','criado_em'=>'2026-09-06 10:00:00','editado_em'=>'2026-09-06 10:30:00'],
        ['id'=>7, 'tarefa_id'=>5,'usuario_id'=>1,'texto'=>'DnD API funcionando no Chrome e Firefox. Safari precisa de workaround.','criado_em'=>'2026-09-07 11:00:00','editado_em'=>null],
        ['id'=>8, 'tarefa_id'=>5,'usuario_id'=>4,'texto'=>'Reproduzi o bug no Safari 17. Vou abrir uma issue separada.','criado_em'=>'2026-09-08 09:00:00','editado_em'=>null],
        ['id'=>9, 'tarefa_id'=>7,'usuario_id'=>3,'texto'=>'Paleta revisada. Contraste de texto principal agora passa WCAG AA.','criado_em'=>'2026-09-07 14:00:00','editado_em'=>null],
        ['id'=>10,'tarefa_id'=>7,'usuario_id'=>4,'texto'=>'Preciso verificar os componentes de badge também.','criado_em'=>'2026-09-07 15:00:00','editado_em'=>null],
        ['id'=>11,'tarefa_id'=>7,'usuario_id'=>3,'texto'=>'Badges atualizados. Aguardando aprovação final.','criado_em'=>'2026-09-08 10:00:00','editado_em'=>'2026-09-08 10:20:00'],
        ['id'=>12,'tarefa_id'=>8,'usuario_id'=>4,'texto'=>'Fluxo ok no happy path. Preciso cobrir os edge cases de validação.','criado_em'=>'2026-09-06 09:00:00','editado_em'=>null],
    ];
    return $tarefaId > 0
        ? array_values(array_filter($todos, fn($c) => $c['tarefa_id'] === $tarefaId))
        : $todos;
}

/**
 * @param ?object $db
 * @return list<array{id:int,tarefa_id:int,usuario_id:int,tipo:string,meta:array|null,criado_em:string}>
 */
function orbitGetAtividades(?object $db = null): array
{
    /*
    // Produção:
    $stmt = $db->prepare('SELECT * FROM orbit_atividade ORDER BY criado_em DESC');
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return array_map(function($r) {
        $r['meta'] = $r['meta'] ? json_decode($r['meta'], true) : null;
        return $r;
    }, $rows);
    */
    return [
        ['id'=>1, 'tarefa_id'=>1, 'usuario_id'=>2,'tipo'=>'criacao',     'meta'=>null,                                      'criado_em'=>'2026-08-20 10:00:00'],
        ['id'=>6, 'tarefa_id'=>4, 'usuario_id'=>2,'tipo'=>'movimentacao','meta'=>['de'=>'backlog','para'=>'em-progresso'],   'criado_em'=>'2026-09-03 09:00:00'],
        ['id'=>7, 'tarefa_id'=>5, 'usuario_id'=>1,'tipo'=>'movimentacao','meta'=>['de'=>'backlog','para'=>'em-progresso'],   'criado_em'=>'2026-09-04 10:00:00'],
        ['id'=>8, 'tarefa_id'=>7, 'usuario_id'=>3,'tipo'=>'movimentacao','meta'=>['de'=>'em-progresso','para'=>'em-revisao'],'criado_em'=>'2026-09-05 14:00:00'],
        ['id'=>9, 'tarefa_id'=>8, 'usuario_id'=>4,'tipo'=>'movimentacao','meta'=>['de'=>'backlog','para'=>'em-revisao'],     'criado_em'=>'2026-09-06 08:00:00'],
        ['id'=>10,'tarefa_id'=>9, 'usuario_id'=>2,'tipo'=>'movimentacao','meta'=>['de'=>'em-revisao','para'=>'concluido'],   'criado_em'=>'2026-09-05 15:00:00'],
        ['id'=>11,'tarefa_id'=>10,'usuario_id'=>2,'tipo'=>'movimentacao','meta'=>['de'=>'em-revisao','para'=>'concluido'],   'criado_em'=>'2026-09-02 12:00:00'],
        ['id'=>12,'tarefa_id'=>1, 'usuario_id'=>2,'tipo'=>'comentario',  'meta'=>['comentario_id'=>1],                       'criado_em'=>'2026-09-01 10:00:00'],
        ['id'=>14,'tarefa_id'=>4, 'usuario_id'=>2,'tipo'=>'comentario',  'meta'=>['comentario_id'=>4],                       'criado_em'=>'2026-09-05 14:00:00'],
        ['id'=>15,'tarefa_id'=>5, 'usuario_id'=>1,'tipo'=>'comentario',  'meta'=>['comentario_id'=>7],                       'criado_em'=>'2026-09-07 11:00:00'],
        ['id'=>16,'tarefa_id'=>4, 'usuario_id'=>2,'tipo'=>'atribuicao',  'meta'=>['usuario_id'=>6,'acao'=>'add'],             'criado_em'=>'2026-09-03 09:05:00'],
        ['id'=>17,'tarefa_id'=>12,'usuario_id'=>7,'tipo'=>'atribuicao',  'meta'=>['usuario_id'=>1,'acao'=>'add'],             'criado_em'=>'2026-09-01 10:10:00'],
        ['id'=>18,'tarefa_id'=>4, 'usuario_id'=>7,'tipo'=>'edicao_prazo','meta'=>['de'=>'2026-09-08','para'=>'2026-09-10'],  'criado_em'=>'2026-09-04 16:00:00'],
        ['id'=>19,'tarefa_id'=>5, 'usuario_id'=>4,'tipo'=>'comentario',  'meta'=>['comentario_id'=>8],                       'criado_em'=>'2026-09-08 09:00:00'],
        ['id'=>20,'tarefa_id'=>7, 'usuario_id'=>3,'tipo'=>'comentario',  'meta'=>['comentario_id'=>9],                       'criado_em'=>'2026-09-07 14:00:00'],
        ['id'=>21,'tarefa_id'=>7, 'usuario_id'=>4,'tipo'=>'comentario',  'meta'=>['comentario_id'=>10],                      'criado_em'=>'2026-09-07 15:00:00'],
        ['id'=>22,'tarefa_id'=>8, 'usuario_id'=>4,'tipo'=>'comentario',  'meta'=>['comentario_id'=>12],                      'criado_em'=>'2026-09-06 09:00:00'],
        ['id'=>23,'tarefa_id'=>6, 'usuario_id'=>1,'tipo'=>'movimentacao','meta'=>['de'=>'backlog','para'=>'em-progresso'],   'criado_em'=>'2026-09-07 08:30:00'],
        ['id'=>24,'tarefa_id'=>3, 'usuario_id'=>3,'tipo'=>'atribuicao',  'meta'=>['usuario_id'=>1,'acao'=>'add'],             'criado_em'=>'2026-09-08 11:00:00'],
    ];
}

// ═══════════════════════════════════════════════════════════
// § 3 · HELPERS DE APRESENTAÇÃO
// ═══════════════════════════════════════════════════════════

function orbitRenderAvatar(array $u, string $size = 'sm'): string
{
    $cls = match($size) { 'lg' => 'avatar-lg', 'md' => 'avatar-md', default => 'avatar-sm' };
    return sprintf(
        '<span class="avatar %s" style="background-color:%s" title="%s" data-member-id="%d">%s</span>',
        $cls,
        htmlspecialchars($u['cor'], ENT_QUOTES),
        htmlspecialchars($u['nome'], ENT_QUOTES),
        (int)$u['id'],
        htmlspecialchars($u['iniciais'], ENT_QUOTES)
    );
}

function orbitRenderAvatarStack(array $usuarios, array $ids, string $size = 'sm'): string
{
    $html = '<div class="avatar-stack">';
    $mapa = array_column($usuarios, null, 'id');
    foreach ($ids as $id) {
        if (isset($mapa[$id])) {
            $html .= orbitRenderAvatar($mapa[$id], $size);
        }
    }
    return $html . '</div>';
}

function orbitPriorityMeta(string $p): array
{
    return match($p) {
        'baixa'   => ['rotulo' => 'Baixa',   'classe' => 'priority-baixa'],
        'media'   => ['rotulo' => 'Média',   'classe' => 'priority-media'],
        'alta'    => ['rotulo' => 'Alta',    'classe' => 'priority-alta'],
        'urgente' => ['rotulo' => 'Urgente', 'classe' => 'priority-urgente'],
        default   => ['rotulo' => ucfirst($p), 'classe' => 'priority-media'],
    };
}

function orbitFormatDateShort(?string $iso): string
{
    if (!$iso) return '—';
    $meses = ['jan','fev','mar','abr','mai','jun','jul','ago','set','out','nov','dez'];
    $ts = strtotime($iso);
    return $ts ? sprintf('%d %s', (int)date('j', $ts), $meses[(int)date('n', $ts) - 1]) : $iso;
}

/** Calcula profundidade de uma equipe na árvore (para indentação do select). */
function orbitGetEquipeDepth(array $equipes, int $id, int $depth = 0): int
{
    $mapa = array_column($equipes, null, 'id');
    $eq   = $mapa[$id] ?? null;
    if (!$eq || $eq['pai_id'] === null) return $depth;
    return orbitGetEquipeDepth($equipes, (int)$eq['pai_id'], $depth + 1);
}

// ═══════════════════════════════════════════════════════════
// § 4 · ESTADO DOS FILTROS (querystring)
// ═══════════════════════════════════════════════════════════
$viewsValidas = ['kanban', 'calendario', 'dashboard', 'lista', 'minhas-tarefas'];
$viewAtual    = in_array($_GET['view'] ?? 'kanban', $viewsValidas, true)
                ? ($_GET['view'] ?? 'kanban')
                : 'kanban';

$filtros = [
    'view'        => $viewAtual,
    'equipe'      => (int)($_GET['equipe'] ?? 0),
    'usuario'     => (int)($_GET['usuario'] ?? 0),
    'prioridade'  => in_array($_GET['prioridade'] ?? '', ['','baixa','media','alta','urgente'], true)
                    ? ($_GET['prioridade'] ?? '') : '',
    'data_inicio' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data_inicio'] ?? '') ? $_GET['data_inicio'] : '',
    'data_fim'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data_fim'] ?? '') ? $_GET['data_fim'] : '',
    'prazo'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['prazo'] ?? '') ? $_GET['prazo'] : '',
    'texto'       => htmlspecialchars(mb_substr($_GET['q'] ?? '', 0, 200), ENT_QUOTES),
];

// ═══════════════════════════════════════════════════════════
// § 5 · CARREGA OS DADOS
// ═══════════════════════════════════════════════════════════
$usuarios    = orbitGetUsuarios();
$equipes     = orbitGetEquipes();
$colunas     = orbitGetColunas();
$tarefas     = orbitGetTarefas(null, $filtros);
$todasTarefas = orbitGetTarefas();   // sem filtros — para JSON blob e Dashboard
$comentarios = orbitGetComentarios();
$atividades  = orbitGetAtividades();

// Minhas tarefas: filtradas pelo usuário atual, sem outros filtros
$minhasTarefas = array_values(array_filter(
    $todasTarefas,
    fn($t) => in_array((int)$usuarioAtual['id'], $t['responsaveis'], true)
));

// Mapas por ID (acesso rápido)
$usuariosMapa = array_column($usuarios, null, 'id');
$equipesMapa  = array_column($equipes, null, 'id');

// Calendário: mês atual
$calAno  = (int)date('Y');
$calMes  = (int)date('n');
$mesesPt = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho',
            'Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
$hojeIso = date('Y-m-d');

// Mapa coluna por ID (acesso rápido)
$colunasMapa = array_column($colunas, null, 'id');

// KPIs para o Dashboard
$kpiTotal     = count($todasTarefas);
$kpiAtrasadas = count(array_filter($todasTarefas, fn($t) => $t['status_prazo'] === 'atrasada'));
$kpiFechadas  = count(array_filter($todasTarefas, fn($t) => $t['coluna_id'] === 'concluido'));
$kpiUrgentes  = count(array_filter($todasTarefas, fn($t) => $t['prioridade'] === 'urgente'));

// JSON blob para o app.js (tarefas SEM filtros para o cliente filtrar)
$orbitData = json_encode([
    'usuarioAtual' => $usuarioAtual,
    'usuarios'     => $usuarios,
    'equipes'      => $equipes,
    'colunas'      => $colunas,
    'tarefas'      => $todasTarefas,
    'comentarios'  => $comentarios,
    'atividades'   => $atividades,
    'filtros'      => $filtros,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="pt-BR" data-orbit-view="<?= htmlspecialchars($viewAtual) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <meta name="description" content="Orbit — Módulo de gestão de equipes e tarefas.">
    <title>Orbit ·
        <?= htmlspecialchars(match($viewAtual) {
            'kanban'          => 'Kanban',
            'calendario'      => 'Calendário',
            'dashboard'       => 'Dashboard',
            'lista'           => 'Lista',
            'minhas-tarefas'  => 'Minhas Tarefas',
            default           => 'Orbit',
        }) ?>
    </title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <!-- Chart.js — carregado antes do body para estar pronto quando app.js inicializar os gráficos -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

    <link href="assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
</head>
<body class="orbit-body">

<div class="orbit-app-layout">

    <!-- ═══════════════════════════════════════════════════════
         SIDEBAR LATERAL ESQUERDA
         ═══════════════════════════════════════════════════════ -->
    <aside class="orbit-sidebar" id="orbit-sidebar" role="navigation" aria-label="Menu principal">
        <div class="orbit-sidebar__brand">
            <a href="index.php" class="orbit-brand" aria-label="Orbit — página inicial">
                <span class="orbit-brand__mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none">
                        <circle cx="12" cy="12" r="3.4" fill="#FACC15"/>
                        <ellipse cx="12" cy="12" rx="10" ry="4.2" stroke="#FACC15" stroke-width="1.6"/>
                        <ellipse cx="12" cy="12" rx="10" ry="4.2" stroke="#10B981" stroke-width="1.6"
                                 transform="rotate(60 12 12)" opacity=".65"/>
                        <ellipse cx="12" cy="12" rx="10" ry="4.2" stroke="#10B981" stroke-width="1.6"
                                 transform="rotate(120 12 12)" opacity=".65"/>
                    </svg>
                </span>
                <div class="orbit-brand__info">
                    <span class="orbit-brand__name">Orbit</span>
                    <span class="orbit-brand__badge">PRO</span>
                </div>
            </a>
        </div>

        <div class="orbit-sidebar__action">
            <button type="button"
                    class="orbit-btn-gold w-100"
                    id="btnNovaTarefa"
                    data-bs-toggle="modal"
                    data-bs-target="#orbit-modal-nova-tarefa">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                <span>Nova tarefa</span>
            </button>
        </div>

        <nav class="orbit-sidebar__nav" aria-label="Navegação principal">
            <?php
            $navItems = [
                ['view'=>'kanban',         'icon'=>'bi-kanban',        'label'=>'Kanban'],
                ['view'=>'calendario',     'icon'=>'bi-calendar3',     'label'=>'Calendário'],
                ['view'=>'dashboard',      'icon'=>'bi-bar-chart-line','label'=>'Dashboard'],
                ['view'=>'lista',          'icon'=>'bi-list-task',     'label'=>'Lista'],
                ['view'=>'minhas-tarefas', 'icon'=>'bi-person-check',  'label'=>'Minhas tarefas', 'badge'=>count($minhasTarefas)],
            ];
            foreach ($navItems as $nav):
                $isActive = ($viewAtual === $nav['view']);
                $qs = http_build_query(array_merge($filtros, ['view' => $nav['view']]));
            ?>
            <a href="?<?= $qs ?>"
               class="orbit-sidebar__link <?= $isActive ? 'is-active' : '' ?>"
               data-view-link="<?= htmlspecialchars($nav['view']) ?>"
               aria-current="<?= $isActive ? 'page' : 'false' ?>">
                <i class="bi <?= $nav['icon'] ?> orbit-sidebar__icon" aria-hidden="true"></i>
                <span class="orbit-sidebar__label"><?= $nav['label'] ?></span>
                <?php if (!empty($nav['badge'])): ?>
                <span class="orbit-sidebar__count"><?= $nav['badge'] ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>

        <div class="orbit-sidebar__footer">
            <div class="orbit-sidebar__user">
                <?= orbitRenderAvatar($usuarioAtual, 'md') ?>
                <div class="orbit-sidebar__user-details">
                    <span class="orbit-sidebar__user-name"><?= htmlspecialchars($usuarioAtual['nome']) ?></span>
                    <span class="orbit-sidebar__user-role"><?= htmlspecialchars($usuarioAtual['cargo']) ?></span>
                </div>
            </div>
        </div>
    </aside>

    <!-- ═══════════════════════════════════════════════════════
         WRAPPER PRINCIPAL (TOPBAR COM FILTROS CENTRALIZADOS + CONTEÚDO)
         ═══════════════════════════════════════════════════════ -->
    <div class="orbit-main-wrapper">
        
        <!-- TOPBAR COM FILTROS CENTRALIZADOS -->
        <header class="orbit-topbar" role="banner">
            
            <div id="orbit-filtros" class="orbit-filtros-centered" role="search" aria-label="Filtros de tarefas">
                
                <!-- 1. Busca textual -->
                <div class="orbit-filtros__search-wrap">
                    <i class="bi bi-search orbit-filtros__search-icon" aria-hidden="true"></i>
                    <label for="filtroTexto" class="visually-hidden">Buscar tarefas</label>
                    <input type="search"
                           id="filtroTexto"
                           class="orbit-filtros__input"
                           placeholder="Buscar tarefas..."
                           value="<?= htmlspecialchars($filtros['texto']) ?>"
                           data-filter="texto"
                           autocomplete="off"
                           maxlength="200">
                </div>

                <!-- 2. Dropdown multi-selecionável: Equipes -->
                <div class="orbit-multiselect" id="msEquipes">
                    <button type="button" class="orbit-multiselect__btn" id="btnFiltroEquipes" aria-expanded="false" aria-haspopup="true">
                        <i class="bi bi-people-fill" aria-hidden="true"></i>
                        <span class="orbit-multiselect__label">Equipes</span>
                        <span class="orbit-multiselect__badge d-none" id="badgeFiltroEquipes">0</span>
                        <i class="bi bi-chevron-down orbit-multiselect__arrow ms-auto" aria-hidden="true"></i>
                    </button>
                    <div class="orbit-multiselect__dropdown" id="dropdownFiltroEquipes" hidden>
                        <div class="orbit-multiselect__header">
                            <span class="orbit-multiselect__title">Filtrar por Equipe</span>
                            <button type="button" class="orbit-multiselect__clear-link" data-clear="equipes">Limpar</button>
                        </div>
                        <div class="orbit-multiselect__list">
                            <?php foreach ($equipes as $eq):
                                $depth = orbitGetEquipeDepth($equipes, (int)$eq['id']);
                            ?>
                            <label class="orbit-multiselect__item">
                                <input type="checkbox" name="filtro_equipes[]" value="<?= (int)$eq['id'] ?>">
                                <span class="orbit-multiselect__check-custom"></span>
                                <span class="orbit-multiselect__text">
                                    <?= str_repeat('&nbsp;&nbsp;', $depth) ?><?= htmlspecialchars($eq['nome']) ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- 3. Filtro Único para Responsáveis (Multi-selecionável com avatar + nome) -->
                <div class="orbit-multiselect" id="msResponsaveis">
                    <button type="button" class="orbit-multiselect__btn" id="btnFiltroResponsaveis" aria-expanded="false" aria-haspopup="true">
                        <i class="bi bi-person-fill" aria-hidden="true"></i>
                        <span class="orbit-multiselect__label">Responsáveis</span>
                        <span class="orbit-multiselect__badge d-none" id="badgeFiltroResponsaveis">0</span>
                        <i class="bi bi-chevron-down orbit-multiselect__arrow ms-auto" aria-hidden="true"></i>
                    </button>
                    <div class="orbit-multiselect__dropdown" id="dropdownFiltroResponsaveis" hidden>
                        <div class="orbit-multiselect__header">
                            <span class="orbit-multiselect__title">Filtrar Responsáveis</span>
                            <button type="button" class="orbit-multiselect__clear-link" data-clear="responsaveis">Limpar</button>
                        </div>
                        <div class="orbit-multiselect__list">
                            <?php foreach ($usuarios as $u): ?>
                            <label class="orbit-multiselect__item orbit-multiselect__item--user">
                                <input type="checkbox" name="filtro_responsaveis[]" value="<?= (int)$u['id'] ?>">
                                <span class="orbit-multiselect__check-custom"></span>
                                <?= orbitRenderAvatar($u, 'sm') ?>
                                <div class="orbit-multiselect__user-info">
                                    <span class="orbit-multiselect__user-name"><?= htmlspecialchars($u['nome']) ?></span>
                                    <span class="orbit-multiselect__user-role"><?= htmlspecialchars($u['cargo']) ?></span>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- 4. Dropdown multi-selecionável: Prioridades -->
                <div class="orbit-multiselect" id="msPrioridades">
                    <button type="button" class="orbit-multiselect__btn" id="btnFiltroPrioridades" aria-expanded="false" aria-haspopup="true">
                        <i class="bi bi-flag-fill" aria-hidden="true"></i>
                        <span class="orbit-multiselect__label">Prioridades</span>
                        <span class="orbit-multiselect__badge d-none" id="badgeFiltroPrioridades">0</span>
                        <i class="bi bi-chevron-down orbit-multiselect__arrow ms-auto" aria-hidden="true"></i>
                    </button>
                    <div class="orbit-multiselect__dropdown" id="dropdownFiltroPrioridades" hidden>
                        <div class="orbit-multiselect__header">
                            <span class="orbit-multiselect__title">Filtrar por Prioridade</span>
                            <button type="button" class="orbit-multiselect__clear-link" data-clear="prioridades">Limpar</button>
                        </div>
                        <div class="orbit-multiselect__list">
                            <label class="orbit-multiselect__item">
                                <input type="checkbox" name="filtro_prioridades[]" value="urgente">
                                <span class="orbit-multiselect__check-custom"></span>
                                <span class="orbit-prio-tag prio-urgente">🔴 Urgente</span>
                            </label>
                            <label class="orbit-multiselect__item">
                                <input type="checkbox" name="filtro_prioridades[]" value="alta">
                                <span class="orbit-multiselect__check-custom"></span>
                                <span class="orbit-prio-tag prio-alta">🟠 Alta</span>
                            </label>
                            <label class="orbit-multiselect__item">
                                <input type="checkbox" name="filtro_prioridades[]" value="media">
                                <span class="orbit-multiselect__check-custom"></span>
                                <span class="orbit-prio-tag prio-media">🟡 Média</span>
                            </label>
                            <label class="orbit-multiselect__item">
                                <input type="checkbox" name="filtro_prioridades[]" value="baixa">
                                <span class="orbit-multiselect__check-custom"></span>
                                <span class="orbit-prio-tag prio-baixa">🟢 Baixa</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- 5. Filtro de Data: Data Início e Data Fim -->
                <div class="orbit-date-range" title="Filtrar por intervalo de prazo">
                    <div class="orbit-date-range__field">
                        <span class="orbit-date-range__tag">Início</span>
                        <input type="date"
                               id="filtroDataInicio"
                               class="orbit-date-range__input"
                               aria-label="Data Início"
                               title="Data Início"
                               value="<?= htmlspecialchars($filtros['data_inicio']) ?>">
                    </div>
                    <span class="orbit-date-range__divider" aria-hidden="true">→</span>
                    <div class="orbit-date-range__field">
                        <span class="orbit-date-range__tag">Fim</span>
                        <input type="date"
                               id="filtroDataFim"
                               class="orbit-date-range__input"
                               aria-label="Data Fim"
                               title="Data Fim"
                               value="<?= htmlspecialchars($filtros['data_fim']) ?>">
                    </div>
                </div>

                <!-- 6. Botão Limpar -->
                <button type="button" class="orbit-filtros__clear" id="btnLimparFiltros"
                        aria-label="Limpar todos os filtros" title="Limpar todos os filtros">
                    <i class="bi bi-x-circle" aria-hidden="true"></i>
                    <span class="d-none d-xl-inline">Limpar</span>
                </button>

            </div>

            <!-- Spotlight quick action na direita da topbar -->
            <div class="orbit-topbar__actions">
                <button type="button" class="orbit-btn-icon" id="btnSpotlight"
                        aria-label="Busca global (Ctrl+K)" title="Busca global (Ctrl+K)">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <kbd class="orbit-kbd d-none d-lg-inline">Ctrl K</kbd>
                </button>
            </div>
        </header>

        <!-- ═══════════════════════════════════════════════════════
             CONTEÚDO PRINCIPAL
             ═══════════════════════════════════════════════════════ -->
        <main class="orbit-main" id="orbit-main">

    <!-- ──────────────────────────────────────────────────────
         VIEW 1: KANBAN
         #orbit-board · data-view="kanban"
         ────────────────────────────────────────────────────── -->
    <section id="orbit-board"
             data-view="kanban"
             class="orbit-view <?= $viewAtual === 'kanban' ? 'is-active' : '' ?>"
             aria-label="Quadro Kanban">

        <div class="orbit-board__inner">
            <?php foreach ($colunas as $coluna):
                $cardsColuna = array_values(
                    array_filter($tarefas, fn($t) => $t['coluna_id'] === $coluna['id'])
                );
            ?>
            <section class="board-col"
                     data-column-id="<?= htmlspecialchars($coluna['id']) ?>"
                     aria-labelledby="col-<?= htmlspecialchars($coluna['id']) ?>-title">

                <header class="board-col__header">
                    <span class="board-col__dot"
                          style="background-color:<?= htmlspecialchars($coluna['cor']) ?>"
                          aria-hidden="true"></span>
                    <h2 class="board-col__title"
                        id="col-<?= htmlspecialchars($coluna['id']) ?>-title">
                        <?= htmlspecialchars($coluna['titulo']) ?>
                    </h2>
                    <span class="board-col__count"
                          data-count-for="<?= htmlspecialchars($coluna['id']) ?>">
                        <?= count($cardsColuna) ?>
                    </span>
                </header>

                <div class="board-col__body"
                     data-column-body="<?= htmlspecialchars($coluna['id']) ?>"
                     role="list"
                     aria-label="Tarefas em <?= htmlspecialchars($coluna['titulo']) ?>">

                    <?php foreach ($cardsColuna as $tarefa):
                        $pm       = orbitPriorityMeta($tarefa['prioridade']);
                        $sp       = $tarefa['status_prazo'];
                        $totalCom = count(array_filter(
                            $comentarios,
                            fn($c) => $c['tarefa_id'] === $tarefa['id']
                        ));
                    ?>
                    <article class="task-card <?= $pm['classe'] ?> sp-<?= $sp ?>"
                             role="listitem"
                             draggable="true"
                             tabindex="0"
                             data-task-id="<?= (int)$tarefa['id'] ?>"
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
                                <button type="button"
                                        class="task-card__action-btn"
                                        data-action="mover"
                                        data-task-id="<?= (int)$tarefa['id'] ?>"
                                        aria-label="Mover tarefa para outra coluna"
                                        aria-haspopup="menu"
                                        aria-expanded="false"
                                        tabindex="-1">
                                    <i class="bi bi-arrows-move" aria-hidden="true"></i>
                                </button>
                                <button type="button"
                                        class="task-card__action-btn"
                                        data-action="detalhe"
                                        data-task-id="<?= (int)$tarefa['id'] ?>"
                                        aria-label="Ver detalhes da tarefa"
                                        tabindex="-1">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <h3 class="task-card__title"><?= htmlspecialchars($tarefa['titulo']) ?></h3>

                        <?php if (!empty($tarefa['equipes'])): ?>
                        <div class="task-card__teams">
                            <?php foreach ($tarefa['equipes'] as $eqId):
                                if (isset($equipesMapa[$eqId])): ?>
                                <span class="task-card__team-badge"><?= htmlspecialchars($equipesMapa[$eqId]['nome']) ?></span>
                            <?php endif; endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($tarefa['descricao'])): ?>
                        <p class="task-card__desc"><?= htmlspecialchars($tarefa['descricao']) ?></p>
                        <?php endif; ?>

                        <footer class="task-card__footer">
                            <span class="task-card__date <?= in_array($sp, ['atrasada','hoje'], true) ? 'is-'.$sp : '' ?>">
                                <i class="bi bi-calendar-event" aria-hidden="true"></i>
                                <?= orbitFormatDateShort($tarefa['prazo']) ?>
                            </span>
                            <span class="task-card__meta">
                                <?= orbitRenderAvatarStack($usuarios, $tarefa['responsaveis']) ?>
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
        </div><!-- /.orbit-board__inner -->
    </section>

    <!-- ──────────────────────────────────────────────────────
         VIEW 2: CALENDÁRIO
         data-view="calendario"
         ────────────────────────────────────────────────────── -->
    <section data-view="calendario"
             class="orbit-view <?= $viewAtual === 'calendario' ? 'is-active' : '' ?>"
             aria-label="Calendário de prazos">

        <div class="orbit-calendar">
            <div class="orbit-calendar__toolbar">
                <button type="button" class="orbit-btn-icon" id="calBtnAnterior" aria-label="Mês anterior">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                </button>
                <h2 class="orbit-calendar__label"
                    id="calendarLabel"
                    data-year="<?= $calAno ?>"
                    data-month="<?= $calMes ?>">
                    <?= htmlspecialchars($mesesPt[$calMes - 1]) ?> de <?= $calAno ?>
                </h2>
                <button type="button" class="orbit-btn-icon" id="calBtnProximo" aria-label="Próximo mês">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </button>
                <button type="button" class="orbit-btn-ghost orbit-btn-ghost--sm" id="calBtnHoje">
                    Hoje
                </button>
            </div>

            <div class="orbit-calendar__grid" id="orbitCalendarGrid"
                 role="grid" aria-labelledby="calendarLabel">
                <?php foreach (['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'] as $ds): ?>
                <div class="cal-weekday" role="columnheader"><?= $ds ?></div>
                <?php endforeach; ?>

                <?php
                $primeiroDia = (int)date('w', mktime(0, 0, 0, $calMes, 1, $calAno));
                $diasNoMes   = (int)date('t', mktime(0, 0, 0, $calMes, 1, $calAno));
                for ($i = 0; $i < $primeiroDia; $i++): ?>
                <div class="cal-day is-empty" aria-hidden="true"></div>
                <?php endfor; ?>

                <?php for ($d = 1; $d <= $diasNoMes; $d++):
                    $iso    = sprintf('%04d-%02d-%02d', $calAno, $calMes, $d);
                    $isHoje = ($iso === $hojeIso);
                    $tsDia  = array_filter($todasTarefas, fn($t) => $t['prazo'] === $iso);
                    $numTs  = count($tsDia);
                ?>
                <button type="button"
                        class="cal-day <?= $isHoje ? 'is-today' : '' ?>"
                        data-date="<?= $iso ?>"
                        role="gridcell"
                        aria-label="<?= $d ?> de <?= $mesesPt[$calMes-1] ?><?= $numTs ? ", $numTs tarefa(s)" : '' ?>">
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
            </div><!-- /.orbit-calendar__grid -->
        </div>

        <!-- Offcanvas: detalhes do dia -->
        <div class="offcanvas offcanvas-end" id="orbitOffcanvasDia"
             tabindex="-1" aria-labelledby="offcanvasDiaLabel">
            <div class="offcanvas-header">
                <h3 class="offcanvas-title" id="offcanvasDiaLabel">Tarefas do dia</h3>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
            </div>
            <div class="offcanvas-body" id="orbitOffcanvasDiaBody">
                <p class="text-muted small">Selecione um dia para ver as tarefas.</p>
            </div>
        </div>
    </section>

    <!-- ──────────────────────────────────────────────────────
         VIEW 3: DASHBOARD
         data-view="dashboard"
         Chart.js inicializado pelo app.js após DOMContentLoaded.
         ────────────────────────────────────────────────────── -->
    <section data-view="dashboard"
             class="orbit-view <?= $viewAtual === 'dashboard' ? 'is-active' : '' ?>"
             aria-label="Dashboard">

        <div class="orbit-dashboard">
            <div class="orbit-dashboard__header">
                <h2 class="orbit-dashboard__title">Visão geral</h2>
                <button type="button" class="orbit-btn-ghost" id="btnExportCSV">
                    <i class="bi bi-download" aria-hidden="true"></i>
                    Exportar CSV
                </button>
            </div>

            <!-- KPIs -->
            <div class="orbit-kpis" role="list">
                <div class="kpi-card" role="listitem">
                    <i class="bi bi-list-check kpi-card__icon" aria-hidden="true"></i>
                    <span class="kpi-card__value"><?= $kpiTotal ?></span>
                    <span class="kpi-card__label">Total de tarefas</span>
                </div>
                <div class="kpi-card kpi-card--danger" role="listitem">
                    <i class="bi bi-clock-history kpi-card__icon" aria-hidden="true"></i>
                    <span class="kpi-card__value"><?= $kpiAtrasadas ?></span>
                    <span class="kpi-card__label">Atrasadas</span>
                </div>
                <div class="kpi-card kpi-card--success" role="listitem">
                    <i class="bi bi-check-circle kpi-card__icon" aria-hidden="true"></i>
                    <span class="kpi-card__value"><?= $kpiFechadas ?></span>
                    <span class="kpi-card__label">Concluídas</span>
                </div>
                <div class="kpi-card kpi-card--warning" role="listitem">
                    <i class="bi bi-exclamation-triangle kpi-card__icon" aria-hidden="true"></i>
                    <span class="kpi-card__value"><?= $kpiUrgentes ?></span>
                    <span class="kpi-card__label">Urgentes</span>
                </div>
            </div>

            <!-- Gráficos (3 mínimos definidos no spec) -->
            <div class="orbit-charts">
                <!-- Gráfico 1: Tarefas por coluna (barra) -->
                <div class="orbit-chart-card">
                    <h3 class="orbit-chart-card__title">
                        <i class="bi bi-columns-gap" aria-hidden="true"></i>
                        Tarefas por coluna
                    </h3>
                    <div class="orbit-chart-card__body">
                        <canvas id="chartPorColuna"
                                role="img"
                                aria-label="Gráfico de barras: distribuição de tarefas por coluna Kanban"></canvas>
                    </div>
                </div>

                <!-- Gráfico 2: Tarefas por prioridade (rosca) -->
                <div class="orbit-chart-card">
                    <h3 class="orbit-chart-card__title">
                        <i class="bi bi-pie-chart" aria-hidden="true"></i>
                        Tarefas por prioridade
                    </h3>
                    <div class="orbit-chart-card__body">
                        <canvas id="chartPorPrioridade"
                                role="img"
                                aria-label="Gráfico de rosca: distribuição de tarefas por prioridade"></canvas>
                    </div>
                </div>

                <!-- Gráfico 3: Atividade dos últimos 7 dias (linha) — ocupa linha inteira -->
                <div class="orbit-chart-card orbit-chart-card--wide">
                    <h3 class="orbit-chart-card__title">
                        <i class="bi bi-activity" aria-hidden="true"></i>
                        Atividade — últimos 7 dias
                    </h3>
                    <div class="orbit-chart-card__body">
                        <canvas id="chartAtividade7dias"
                                role="img"
                                aria-label="Gráfico de linha: atividade registrada nos últimos 7 dias"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ──────────────────────────────────────────────────────
         VIEW 4: LISTA
         data-view="lista"
         ────────────────────────────────────────────────────── -->
    <section data-view="lista"
             class="orbit-view <?= $viewAtual === 'lista' ? 'is-active' : '' ?>"
             aria-label="Lista de tarefas">

        <div class="orbit-lista">
            <div class="orbit-lista__header">
                <h2 class="orbit-lista__title">Todas as tarefas</h2>
                <span class="orbit-lista__count" id="listaCount" aria-live="polite">
                    <?= count($tarefas) ?> tarefa(s)
                </span>
            </div>

            <div class="orbit-lista__table-wrap">
                <table class="orbit-table" id="orbitTabela" aria-label="Tabela de tarefas">
                    <thead>
                        <tr>
                            <th scope="col" class="orbit-table__th" data-sort="titulo"
                                tabindex="0" aria-sort="none">
                                Tarefa <i class="bi bi-chevron-expand" aria-hidden="true"></i>
                            </th>
                            <th scope="col" class="orbit-table__th" data-sort="prioridade"
                                tabindex="0" aria-sort="none">
                                Prioridade <i class="bi bi-chevron-expand" aria-hidden="true"></i>
                            </th>
                            <th scope="col" class="orbit-table__th" data-sort="coluna_id"
                                tabindex="0" aria-sort="none">
                                Status <i class="bi bi-chevron-expand" aria-hidden="true"></i>
                            </th>
                            <th scope="col" class="orbit-table__th" data-sort="prazo"
                                tabindex="0" aria-sort="none">
                                Prazo <i class="bi bi-chevron-expand" aria-hidden="true"></i>
                            </th>
                            <th scope="col" class="orbit-table__th">Responsáveis</th>
                            <th scope="col" class="orbit-table__th orbit-table__th--actions">
                                <span class="visually-hidden">Ações</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="orbitTabelaBody">
                        <?php foreach ($tarefas as $tarefa):
                            $pm  = orbitPriorityMeta($tarefa['prioridade']);
                            $col = $colunasMapa[$tarefa['coluna_id']] ?? null;
                            $sp  = $tarefa['status_prazo'];
                        ?>
                        <tr class="orbit-table__row"
                            data-task-id="<?= (int)$tarefa['id'] ?>"
                            data-priority="<?= htmlspecialchars($tarefa['prioridade']) ?>"
                            data-coluna="<?= htmlspecialchars($tarefa['coluna_id']) ?>"
                            data-prazo="<?= htmlspecialchars($tarefa['prazo'] ?? '') ?>"
                            data-titulo="<?= htmlspecialchars(mb_strtolower($tarefa['titulo'])) ?>"
                            data-assignees="<?= htmlspecialchars(implode(',', $tarefa['responsaveis'])) ?>">

                            <td class="orbit-table__td orbit-table__td--title">
                                <button type="button"
                                        class="orbit-table__title-btn"
                                        data-action="detalhe"
                                        data-task-id="<?= (int)$tarefa['id'] ?>">
                                    <?= htmlspecialchars($tarefa['titulo']) ?>
                                </button>
                            </td>
                            <td class="orbit-table__td">
                                <span class="task-card__badge badge-<?= $pm['classe'] ?>">
                                    <?= $pm['rotulo'] ?>
                                </span>
                            </td>
                            <td class="orbit-table__td">
                                <span class="orbit-status-dot"
                                      style="background:<?= htmlspecialchars($col['cor'] ?? '#94A3B8') ?>"></span>
                                <?= htmlspecialchars($col['titulo'] ?? $tarefa['coluna_id']) ?>
                            </td>
                            <td class="orbit-table__td">
                                <span class="task-card__date <?= in_array($sp, ['atrasada','hoje'], true) ? 'is-'.$sp : '' ?>">
                                    <?= orbitFormatDateShort($tarefa['prazo']) ?>
                                </span>
                            </td>
                            <td class="orbit-table__td">
                                <?= orbitRenderAvatarStack($usuarios, $tarefa['responsaveis']) ?>
                            </td>
                            <td class="orbit-table__td orbit-table__td--actions">
                                <button type="button"
                                        class="orbit-btn-icon-sm"
                                        data-action="detalhe"
                                        data-task-id="<?= (int)$tarefa['id'] ?>"
                                        aria-label="Ver detalhes de «<?= htmlspecialchars($tarefa['titulo']) ?>»">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                                <button type="button"
                                        class="orbit-btn-icon-sm"
                                        data-action="mover"
                                        data-task-id="<?= (int)$tarefa['id'] ?>"
                                        aria-label="Mover tarefa"
                                        aria-haspopup="menu"
                                        aria-expanded="false">
                                    <i class="bi bi-arrows-move" aria-hidden="true"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (count($tarefas) === 0): ?>
                        <tr>
                            <td colspan="6" class="orbit-table__empty">
                                <i class="bi bi-search" aria-hidden="true"></i>
                                Nenhuma tarefa encontrada com os filtros atuais.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- ──────────────────────────────────────────────────────
         VIEW 5: MINHAS TAREFAS
         data-view="minhas-tarefas"
         ────────────────────────────────────────────────────── -->
    <section data-view="minhas-tarefas"
             class="orbit-view <?= $viewAtual === 'minhas-tarefas' ? 'is-active' : '' ?>"
             aria-label="Minhas tarefas">

        <div class="orbit-minhas">
            <div class="orbit-minhas__header">
                <?= orbitRenderAvatar($usuarioAtual, 'lg') ?>
                <div>
                    <h2 class="orbit-minhas__title">
                        Olá, <?= htmlspecialchars(explode(' ', $usuarioAtual['nome'])[0]) ?> 👋
                    </h2>
                    <p class="orbit-minhas__subtitle">
                        <?= count($minhasTarefas) ?> tarefa(s) atribuída(s) a você
                    </p>
                </div>
            </div>

            <?php
            $ordemPrio = ['urgente', 'alta', 'media', 'baixa'];
            $algumGrupo = false;
            foreach ($ordemPrio as $prio):
                $grupo = array_values(array_filter($minhasTarefas, fn($t) => $t['prioridade'] === $prio));
                if (count($grupo) === 0) continue;
                $algumGrupo = true;
                $pm = orbitPriorityMeta($prio);
            ?>
            <div class="orbit-minhas__group">
                <h3 class="orbit-minhas__group-title">
                    <span class="task-card__badge badge-<?= $pm['classe'] ?>"><?= $pm['rotulo'] ?></span>
                    <span class="orbit-minhas__group-count"><?= count($grupo) ?></span>
                </h3>
                <div class="orbit-minhas__cards">
                    <?php foreach ($grupo as $tarefa):
                        $sp  = $tarefa['status_prazo'];
                        $col = $colunasMapa[$tarefa['coluna_id']] ?? null;
                    ?>
                    <article class="task-card task-card--compact <?= $pm['classe'] ?>"
                             data-task-id="<?= (int)$tarefa['id'] ?>">
                        <span class="orbit-status-dot"
                              style="background:<?= htmlspecialchars($col['cor'] ?? '#94A3B8') ?>"
                              title="<?= htmlspecialchars($col['titulo'] ?? '') ?>"></span>
                        <div class="task-card__compact-body">
                            <button type="button"
                                    class="task-card__title-btn"
                                    data-action="detalhe"
                                    data-task-id="<?= (int)$tarefa['id'] ?>">
                                <?= htmlspecialchars($tarefa['titulo']) ?>
                            </button>
                            <span class="task-card__date <?= in_array($sp, ['atrasada','hoje'], true) ? 'is-'.$sp : '' ?>">
                                <i class="bi bi-calendar-event" aria-hidden="true"></i>
                                <?= orbitFormatDateShort($tarefa['prazo']) ?>
                            </span>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (!$algumGrupo): ?>
            <div class="orbit-minhas__empty">
                <i class="bi bi-check2-all orbit-minhas__empty-icon" aria-hidden="true"></i>
                <p>Nenhuma tarefa atribuída a você no momento.</p>
            </div>
            <?php endif; ?>
        </div>
    </section>

        </main><!-- /#orbit-main -->

    </div><!-- /.orbit-main-wrapper -->
</div><!-- /.orbit-app-layout -->

<!-- ═══════════════════════════════════════════════════════
     MENU DE MOVER — Alternativa de teclado/clique ao DnD
     Posicionado via JS próximo ao card/botão ativo.
     Navegação: setas ↑↓ + Enter; fecha com Esc.
     ═══════════════════════════════════════════════════════ -->
<div id="orbit-mover-menu"
     class="orbit-move-menu"
     role="menu"
     aria-labelledby="orbitMoverMenuLabel"
     data-task-id=""
     hidden>
    <p class="orbit-move-menu__label" id="orbitMoverMenuLabel">Mover para:</p>
    <?php foreach ($colunas as $coluna): ?>
    <button type="button"
            class="orbit-move-menu__item"
            role="menuitem"
            data-move-to="<?= htmlspecialchars($coluna['id']) ?>"
            tabindex="-1">
        <span class="orbit-move-menu__dot"
              style="background:<?= htmlspecialchars($coluna['cor']) ?>"></span>
        <?= htmlspecialchars($coluna['titulo']) ?>
    </button>
    <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     MODAL: DETALHE DA TAREFA — #orbit-modal-tarefa
     Aberto via [data-action="detalhe"] em qualquer view.
     Conteúdo populado inteiramente pelo app.js.
     ═══════════════════════════════════════════════════════ -->
<div class="modal fade" id="orbit-modal-tarefa"
     tabindex="-1"
     aria-labelledby="orbitModalTarefaLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content orbit-modal">

            <div class="modal-header orbit-modal__header">
                <span class="task-card__badge" id="orbitModalPrioBadge"></span>
                <h2 class="modal-title" id="orbitModalTarefaLabel">Carregando&hellip;</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body orbit-modal__body">

                <!-- Meta: coluna · prazo · criador -->
                <div class="orbit-modal__meta" id="orbitModalMeta">
                    <span class="orbit-modal__meta-item" id="orbitModalColuna">
                        <i class="bi bi-columns-gap" aria-hidden="true"></i>
                        <span></span>
                    </span>
                    <span class="orbit-modal__meta-item" id="orbitModalPrazo">
                        <i class="bi bi-calendar-event" aria-hidden="true"></i>
                        <span></span>
                    </span>
                    <span class="orbit-modal__meta-item" id="orbitModalCriador">
                        <i class="bi bi-person" aria-hidden="true"></i>
                        <span></span>
                    </span>
                </div>

                <!-- Descrição -->
                <section class="orbit-modal__section">
                    <h3 class="orbit-modal__section-title">Descrição</h3>
                    <p id="orbitModalDesc" class="orbit-modal__desc text-muted">—</p>
                </section>

                <!-- Responsáveis ad-hoc (editável) -->
                <section class="orbit-modal__section">
                    <h3 class="orbit-modal__section-title">Responsáveis do grupo</h3>
                    <div class="orbit-modal__assignees" id="orbitModalAssignees"></div>
                    <button type="button"
                            class="orbit-btn-ghost orbit-btn-ghost--sm mt-2"
                            id="btnEditarResponsaveis">
                        <i class="bi bi-person-plus" aria-hidden="true"></i>
                        Editar grupo
                    </button>
                    <div class="orbit-assignee-picker" id="orbitAssigneePicker" hidden
                         role="group" aria-label="Selecionar responsáveis">
                        <?php foreach ($usuarios as $u): ?>
                        <label class="orbit-assignee-option">
                            <input type="checkbox"
                                   name="resp_picker[]"
                                   value="<?= (int)$u['id'] ?>"
                                   class="visually-hidden">
                            <?= orbitRenderAvatar($u, 'sm') ?>
                            <span class="orbit-assignee-option__nome"><?= htmlspecialchars($u['nome']) ?></span>
                            <span class="orbit-assignee-option__cargo"><?= htmlspecialchars($u['cargo']) ?></span>
                        </label>
                        <?php endforeach; ?>
                        <div class="orbit-assignee-picker__footer">
                            <button type="button" class="btn btn-sm btn-primary" id="btnSalvarResponsaveis">
                                Salvar grupo
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelarResponsaveis">
                                Cancelar
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Thread de comentários -->
                <section class="orbit-modal__section">
                    <h3 class="orbit-modal__section-title">Comentários</h3>
                    <div class="orbit-comments"
                         id="orbitCommentThread"
                         aria-live="polite"
                         aria-label="Thread de comentários">
                        <p class="text-muted small">Nenhum comentário ainda.</p>
                    </div>

                    <form class="orbit-comment-form" id="orbitCommentForm" novalidate>
                        <input type="hidden" name="tarefa_id" id="orbitCommentTarefaId">
                        <div class="orbit-comment-form__row">
                            <?= orbitRenderAvatar($usuarioAtual, 'sm') ?>
                            <label for="orbitCommentText" class="visually-hidden">Novo comentário</label>
                            <textarea class="orbit-textarea"
                                      id="orbitCommentText"
                                      name="texto"
                                      placeholder="Escreva um comentário..."
                                      rows="2"
                                      maxlength="2000"
                                      required
                                      aria-required="true"></textarea>
                        </div>
                        <div class="orbit-comment-form__actions">
                            <button type="submit" class="btn btn-primary btn-sm">Comentar</button>
                        </div>
                    </form>
                </section>

                <!-- Log de atividade -->
                <section class="orbit-modal__section orbit-modal__section--activity">
                    <h3 class="orbit-modal__section-title">Atividade</h3>
                    <ol class="orbit-activity-log" id="orbitActivityLog" reversed aria-label="Log de atividade">
                        <!-- Populado pelo app.js -->
                    </ol>
                </section>

            </div><!-- /.modal-body -->

            <div class="modal-footer orbit-modal__footer">
                <button type="button"
                        class="orbit-btn-ghost"
                        id="orbitModalBtnMover"
                        data-action="mover"
                        aria-haspopup="menu"
                        aria-expanded="false">
                    <i class="bi bi-arrows-move" aria-hidden="true"></i>
                    Mover para&hellip;
                </button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    Fechar
                </button>
            </div>

        </div><!-- /.modal-content -->
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     MODAL: NOVA TAREFA — #orbit-modal-nova-tarefa
     ═══════════════════════════════════════════════════════ -->
<div class="modal fade" id="orbit-modal-nova-tarefa"
     tabindex="-1"
     aria-labelledby="orbitModalNovaTarefaLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content orbit-modal" id="orbitFormNovaTarefa" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="modal-header orbit-modal__header">
                <h2 class="modal-title" id="orbitModalNovaTarefaLabel">Nova tarefa</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <div class="mb-3">
                    <label for="novaTarefaTitulo" class="form-label">
                        Título <span aria-hidden="true" class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control" id="novaTarefaTitulo"
                           name="titulo" required maxlength="200" autocomplete="off">
                    <div class="invalid-feedback">O título é obrigatório.</div>
                </div>

                <div class="mb-3">
                    <label for="novaTarefaDescricao" class="form-label">Descrição</label>
                    <textarea class="form-control" id="novaTarefaDescricao"
                              name="descricao" rows="2" maxlength="1000"></textarea>
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
                            <option value="urgente">🔴 Urgente</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="novaTarefaPrazo" class="form-label">Prazo</label>
                    <input type="date" class="form-control" id="novaTarefaPrazo" name="prazo">
                </div>

                <div class="mb-3">
                    <span class="form-label d-block mb-1">Equipes</span>
                    <div class="orbit-teams-picker" role="group" aria-label="Equipes responsáveis">
                        <?php foreach ($equipes as $eq): ?>
                        <label class="orbit-team-option">
                            <input type="checkbox" name="equipes[]" value="<?= (int)$eq['id'] ?>">
                            <span><?= htmlspecialchars($eq['nome']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mb-1">
                    <span class="form-label d-block" id="novarespLabel">
                        Responsáveis — grupo ad-hoc
                    </span>
                    <div class="orbit-assignee-picker"
                         role="group"
                         aria-labelledby="novarespLabel">
                        <?php foreach ($usuarios as $u): ?>
                        <label class="orbit-assignee-option">
                            <input type="checkbox"
                                   name="responsaveis[]"
                                   value="<?= (int)$u['id'] ?>"
                                   class="visually-hidden">
                            <?= orbitRenderAvatar($u, 'sm') ?>
                            <span class="orbit-assignee-option__nome"><?= htmlspecialchars($u['nome']) ?></span>
                            <span class="orbit-assignee-option__cargo"><?= htmlspecialchars($u['cargo']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="orbitBtnCriarTarefa">Criar tarefa</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     SPOTLIGHT — Busca global (Ctrl+K / Cmd+K)
     #orbit-busca-global
     ═══════════════════════════════════════════════════════ -->
<div id="orbit-busca-global"
     class="orbit-spotlight"
     role="dialog"
     aria-modal="true"
     aria-label="Busca global"
     hidden>
    <div class="orbit-spotlight__backdrop" id="orbitSpotlightBackdrop"></div>
    <div class="orbit-spotlight__panel">
        <div class="orbit-spotlight__input-row">
            <i class="bi bi-search orbit-spotlight__icon" aria-hidden="true"></i>
            <label for="orbitSpotlightInput" class="visually-hidden">Busca global</label>
            <input type="search"
                   id="orbitSpotlightInput"
                   class="orbit-spotlight__input"
                   placeholder="Buscar tarefas, pessoas, equipes..."
                   autocomplete="off"
                   spellcheck="false"
                   aria-autocomplete="list"
                   aria-controls="orbitSpotlightResults"
                   aria-activedescendant="">
            <kbd class="orbit-kbd">Esc</kbd>
        </div>

        <ul class="orbit-spotlight__results"
            id="orbitSpotlightResults"
            role="listbox"
            aria-label="Resultados da busca"
            aria-live="polite">
            <li class="orbit-spotlight__hint" role="option" aria-selected="false">
                <i class="bi bi-lightbulb" aria-hidden="true"></i>
                Digite para buscar tarefas, pessoas ou equipes.
            </li>
        </ul>

        <div class="orbit-spotlight__footer" aria-hidden="true">
            <span><kbd class="orbit-kbd">↑↓</kbd> navegar</span>
            <span><kbd class="orbit-kbd">Enter</kbd> selecionar</span>
            <span><kbd class="orbit-kbd">Esc</kbd> fechar</span>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     JSON DATA BLOB — lido pelo app.js em DOMContentLoaded
     Todas as entidades sem filtros; o JS filtra no cliente.
     ═══════════════════════════════════════════════════════ -->
<script type="application/json" id="orbit-data">
<?= $orbitData ?>
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js" defer></script>
</body>
</html>
