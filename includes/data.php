<?php
/**
 * data.php
 * Camada de dados simulados (mock).
 * Em uma aplicação real, estas funções seriam substituídas por
 * consultas PDO a um banco de dados (MySQL/PostgreSQL).
 */

declare(strict_types=1);

/**
 * Retorna os membros da equipe disponíveis para atribuição de tarefas.
 */
function getTeamMembers(): array
{
    return [
        ['id' => 1, 'nome' => 'Ana Ferreira', 'cargo' => 'Front-end',  'iniciais' => 'AF', 'cor' => '#4C3FE0'],
        ['id' => 2, 'nome' => 'Bruno Lima',   'cargo' => 'Back-end',   'iniciais' => 'BL', 'cor' => '#0EA5A0'],
        ['id' => 3, 'nome' => 'Carla Nunes',  'cargo' => 'UI/UX',      'iniciais' => 'CN', 'cor' => '#F59E0B'],
        ['id' => 4, 'nome' => 'Diego Santos', 'cargo' => 'QA',         'iniciais' => 'DS', 'cor' => '#EF4444'],
        ['id' => 5, 'nome' => 'Elisa Prado',  'cargo' => 'Back-end',   'iniciais' => 'EP', 'cor' => '#10B981'],
    ];
}

/**
 * Retorna as colunas do quadro Kanban, na ordem em que devem ser exibidas.
 * A "cor" alimenta tanto o indicador da coluna quanto os pontinhos do calendário.
 */
function getColumns(): array
{
    return [
        ['id' => 'backlog',      'titulo' => 'Backlog',      'cor' => '#94A3B8'],
        ['id' => 'em-progresso', 'titulo' => 'Em Progresso', 'cor' => '#4C3FE0'],
        ['id' => 'em-revisao',   'titulo' => 'Em Revisão',   'cor' => '#F59E0B'],
        ['id' => 'concluido',    'titulo' => 'Concluído',    'cor' => '#10B981'],
    ];
}

/**
 * Retorna as tarefas simuladas do quadro.
 * "coluna" referencia o id definido em getColumns().
 * "responsaveis" referencia os ids definidos em getTeamMembers().
 */
function getTasks(): array
{
    return [
        [
            'id' => 1,
            'titulo' => 'Modelar esquema do banco de dados',
            'descricao' => 'Definir tabelas de usuários, tarefas, colunas e equipes.',
            'coluna' => 'backlog',
            'prioridade' => 'alta',
            'responsaveis' => [2, 5],
            'prazo' => '2026-09-14',
            'comentarios' => 3,
        ],
        [
            'id' => 2,
            'titulo' => 'Levantar requisitos do módulo de relatórios',
            'descricao' => 'Reunião com o time de produto para mapear indicadores.',
            'coluna' => 'backlog',
            'prioridade' => 'media',
            'responsaveis' => [3],
            'prazo' => '2026-09-18',
            'comentarios' => 0,
        ],
        [
            'id' => 3,
            'titulo' => 'Criar wireframes da tela de configurações',
            'descricao' => 'Baixa fidelidade, cobrindo os três perfis de usuário.',
            'coluna' => 'backlog',
            'prioridade' => 'baixa',
            'responsaveis' => [3, 1],
            'prazo' => '2026-09-25',
            'comentarios' => 1,
        ],
        [
            'id' => 4,
            'titulo' => 'Implementar autenticação de usuários',
            'descricao' => 'Login, recuperação de senha e sessão persistente.',
            'coluna' => 'em-progresso',
            'prioridade' => 'alta',
            'responsaveis' => [2],
            'prazo' => '2026-09-10',
            'comentarios' => 5,
        ],
        [
            'id' => 5,
            'titulo' => 'Construir componente de drag and drop',
            'descricao' => 'Cards arrastáveis entre colunas com feedback visual.',
            'coluna' => 'em-progresso',
            'prioridade' => 'alta',
            'responsaveis' => [1, 4],
            'prazo' => '2026-09-09',
            'comentarios' => 2,
        ],
        [
            'id' => 6,
            'titulo' => 'Ajustar responsividade do quadro em telas pequenas',
            'descricao' => 'Rolagem horizontal suave e colunas com largura mínima.',
            'coluna' => 'em-progresso',
            'prioridade' => 'media',
            'responsaveis' => [1],
            'prazo' => '2026-09-16',
            'comentarios' => 0,
        ],
        [
            'id' => 7,
            'titulo' => 'Revisar contraste de cores do design system',
            'descricao' => 'Checar acessibilidade (WCAG AA) da paleta escolhida.',
            'coluna' => 'em-revisao',
            'prioridade' => 'media',
            'responsaveis' => [3, 4],
            'prazo' => '2026-09-11',
            'comentarios' => 4,
        ],
        [
            'id' => 8,
            'titulo' => 'Testar fluxo de criação de tarefas',
            'descricao' => 'Validar formulário, mensagens de erro e retorno da API.',
            'coluna' => 'em-revisao',
            'prioridade' => 'alta',
            'responsaveis' => [4],
            'prazo' => '2026-09-12',
            'comentarios' => 1,
        ],
        [
            'id' => 9,
            'titulo' => 'Documentar endpoints simulados da API',
            'descricao' => 'Descrever payloads de entrada e saída para o time.',
            'coluna' => 'concluido',
            'prioridade' => 'baixa',
            'responsaveis' => [2],
            'prazo' => '2026-09-05',
            'comentarios' => 0,
        ],
        [
            'id' => 10,
            'titulo' => 'Configurar estrutura inicial do projeto PHP',
            'descricao' => 'Pastas, includes e convenção de nomes definidas.',
            'coluna' => 'concluido',
            'prioridade' => 'media',
            'responsaveis' => [2, 5],
            'prazo' => '2026-09-02',
            'comentarios' => 2,
        ],
        [
            'id' => 11,
            'titulo' => 'Definir paleta de cores e tipografia',
            'descricao' => 'Fonte Inter e paleta baseada em azul-tecnológico.',
            'coluna' => 'concluido',
            'prioridade' => 'baixa',
            'responsaveis' => [3],
            'prazo' => '2026-08-29',
            'comentarios' => 3,
        ],
        [
            'id' => 12,
            'titulo' => 'Planejar sprint de calendário integrado',
            'descricao' => 'Alinhar escopo da visão de calendário com o time.',
            'coluna' => 'backlog',
            'prioridade' => 'media',
            'responsaveis' => [5, 1],
            'prazo' => '2026-09-22',
            'comentarios' => 1,
        ],
    ];
}

/**
 * Busca um membro da equipe pelo id. Retorna null se não encontrado.
 */
function findTeamMember(array $team, int $id): ?array
{
    foreach ($team as $member) {
        if ($member['id'] === $id) {
            return $member;
        }
    }
    return null;
}

/**
 * Busca uma coluna pelo id. Retorna null se não encontrada.
 */
function findColumn(array $columns, string $id): ?array
{
    foreach ($columns as $column) {
        if ($column['id'] === $id) {
            return $column;
        }
    }
    return null;
}
