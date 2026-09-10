# Planner — Kanban com Equipes e Calendário

Protótipo funcional de um quadro Kanban com suporte a equipes e uma
visão de calendário, construído em PHP + JavaScript Vanilla + Bootstrap 5.

## Como rodar localmente

Este projeto não precisa de banco de dados — os dados vêm de
`includes/data.php` (mock). Basta o servidor embutido do PHP:

```bash
cd kanban-board
php -S 127.0.0.1:8000
```

Abra `http://127.0.0.1:8000` no navegador. Use `127.0.0.1`, não
`localhost` — evita o problema clássico de resolução de URL relativa
do servidor embutido do PHP.

Requer PHP 8.1+ (usa `declare(strict_types=1)`, tipos de retorno e
arrow functions).

## Estrutura

```
kanban-board/
├── index.php              # Quadro Kanban (view principal)
├── calendario.php         # Visão de calendário
├── includes/
│   ├── data.php           # "Banco de dados" simulado (equipe, colunas, tarefas)
│   ├── functions.php      # Helpers de renderização (avatares, badges, datas)
│   ├── header.php          # <head>, navbar (Kanban / Calendário)
│   └── footer.php          # Modal "Nova tarefa", scripts
├── assets/
│   ├── css/style.css      # Design tokens + todo o CSS customizado
│   └── js/
│       ├── kanban.js      # Drag and drop, filtro de equipe, criação de tarefas
│       └── calendar.js    # Navegação de mês e painel de detalhes do dia
└── api/
    ├── update_task.php    # Endpoint simulado: recebe a coluna nova de um card
    └── create_task.php    # Endpoint simulado: recebe e "cria" uma nova tarefa
```

## Decisões de arquitetura

- **PHP renderiza, JS interage.** Cada página faz o primeiro paint
  completo no servidor (colunas, cards, grade do mês já preenchidos).
  O JS assume a partir daí: nenhuma tela em branco esperando fetch.
- **Sem persistência real.** `api/*.php` valida o payload e responde
  como uma API de verdade responderia, mas não grava em lugar nenhum
  — é só trocar `getTasks()`/`getColumns()` por consultas PDO quando
  houver banco.
- **Um JSON por página, não N fetches.** `index.php` e `calendario.php`
  embutem os dados (`<script type="application/json">`) que o JS
  precisa para montar novos cards ou repintar o mês, evitando round-trips
  desnecessários.
- **Delegação de eventos.** `kanban.js` escuta drag/drop no container
  `#board`, não em cada card — cards criados dinamicamente já funcionam
  sem precisar re-registrar listeners.

## Paleta e tipografia

- Fundo: `#F8F9FA` · Cards: branco com sombra suave
- Marca (azul-tech): `#4C3FE0` / hover `#3D31C4`
- Prioridade: alta `#EF4444` · média `#F59E0B` · baixa `#10B981`
- Fonte: Inter (Google Fonts), pesos 400–700



-- =============================================================================
-- Planner · Módulo de Gestão de Equipes e Tarefas
-- database.sql — Schema Completo e Dados Iniciais (Seed)
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `planner`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `planner`;

SET NAMES utf8mb4;
SET time_zone = '-03:00';
SET foreign_key_checks = 0;

-- -----------------------------------------------------------------------------
-- LIMPEZA
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS planner_atividade;
DROP TABLE IF EXISTS planner_comentario;
DROP TABLE IF EXISTS planner_tarefa_equipe;
DROP TABLE IF EXISTS planner_grupo_tarefa;
DROP TABLE IF EXISTS planner_tarefa;
DROP TABLE IF EXISTS planner_coluna;
DROP TABLE IF EXISTS planner_membro_equipe;
DROP TABLE IF EXISTS planner_equipe;
DROP TABLE IF EXISTS planner_usuario;

SET foreign_key_checks = 1;

-- =============================================================================
-- 1. planner_usuario
-- =============================================================================
CREATE TABLE planner_usuario (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    nome        VARCHAR(120)     NOT NULL,
    cargo       VARCHAR(80)      NOT NULL DEFAULT '',
    iniciais    CHAR(3)          NOT NULL,
    cor         CHAR(7)          NOT NULL DEFAULT '#059669',
    email       VARCHAR(200)     NOT NULL DEFAULT '',
    criado_em   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_planner_usuario_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 2. planner_equipe
-- =============================================================================
CREATE TABLE planner_equipe (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    nome        VARCHAR(120)     NOT NULL,
    descricao   TEXT,
    pai_id      INT UNSIGNED     NULL DEFAULT NULL,
    cor         VARCHAR(30)      NOT NULL DEFAULT '#059669',
    criado_em   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    CONSTRAINT fk_equipe_pai
        FOREIGN KEY (pai_id) REFERENCES planner_equipe (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 3. planner_membro_equipe
-- =============================================================================
CREATE TABLE planner_membro_equipe (
    equipe_id     INT UNSIGNED    NOT NULL,
    usuario_id    INT UNSIGNED    NOT NULL,
    papel         ENUM('lider','membro') NOT NULL DEFAULT 'membro',
    adicionado_em DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (equipe_id, usuario_id),
    CONSTRAINT fk_membro_equipe
        FOREIGN KEY (equipe_id) REFERENCES planner_equipe (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_membro_usuario
        FOREIGN KEY (usuario_id) REFERENCES planner_usuario (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 4. planner_coluna
-- =============================================================================
CREATE TABLE planner_coluna (
    id          VARCHAR(40)      NOT NULL,
    titulo      VARCHAR(60)      NOT NULL,
    ordem       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    cor         CHAR(7)          NOT NULL DEFAULT '#94A3B8',

    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 5. planner_tarefa
-- =============================================================================
CREATE TABLE planner_tarefa (
    id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    titulo        VARCHAR(200)     NOT NULL,
    descricao     TEXT,
    coluna_id     VARCHAR(40)      NOT NULL DEFAULT 'backlog',
    prioridade    ENUM('baixa','media','alta','urgente') NOT NULL DEFAULT 'media',
    prazo         DATE             NULL DEFAULT NULL,
    criado_por    INT UNSIGNED     NOT NULL,
    criado_em     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_tarefa_coluna (coluna_id),
    INDEX idx_tarefa_prioridade (prioridade),
    INDEX idx_tarefa_prazo (prazo),
    CONSTRAINT fk_tarefa_coluna
        FOREIGN KEY (coluna_id) REFERENCES planner_coluna (id)
        ON UPDATE CASCADE,
    CONSTRAINT fk_tarefa_criador
        FOREIGN KEY (criado_por) REFERENCES planner_usuario (id)
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 6. planner_grupo_tarefa (Responsáveis N:N)
-- =============================================================================
CREATE TABLE planner_grupo_tarefa (
    tarefa_id     INT UNSIGNED    NOT NULL,
    usuario_id    INT UNSIGNED    NOT NULL,
    atribuido_em  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (tarefa_id, usuario_id),
    CONSTRAINT fk_gt_tarefa
        FOREIGN KEY (tarefa_id) REFERENCES planner_tarefa (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_gt_usuario
        FOREIGN KEY (usuario_id) REFERENCES planner_usuario (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 7. planner_tarefa_equipe (Equipes associadas à Tarefa N:N)
-- =============================================================================
CREATE TABLE planner_tarefa_equipe (
    tarefa_id     INT UNSIGNED    NOT NULL,
    equipe_id     INT UNSIGNED    NOT NULL,
    adicionado_em DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (tarefa_id, equipe_id),
    CONSTRAINT fk_te_tarefa
        FOREIGN KEY (tarefa_id) REFERENCES planner_tarefa (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_te_equipe
        FOREIGN KEY (equipe_id) REFERENCES planner_equipe (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 8. planner_comentario
-- =============================================================================
CREATE TABLE planner_comentario (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    tarefa_id   INT UNSIGNED     NOT NULL,
    usuario_id  INT UNSIGNED     NOT NULL,
    texto       TEXT             NOT NULL,
    criado_em   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    editado_em  DATETIME         NULL DEFAULT NULL,

    PRIMARY KEY (id),
    INDEX idx_comentario_tarefa (tarefa_id),
    CONSTRAINT fk_comentario_tarefa
        FOREIGN KEY (tarefa_id) REFERENCES planner_tarefa (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_comentario_usuario
        FOREIGN KEY (usuario_id) REFERENCES planner_usuario (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 9. planner_atividade (Audit Log / Timeline)
-- =============================================================================
CREATE TABLE planner_atividade (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    tarefa_id   INT UNSIGNED     NOT NULL,
    usuario_id  INT UNSIGNED     NOT NULL,
    tipo        VARCHAR(40)      NOT NULL,
    meta        JSON             NULL DEFAULT NULL,
    criado_em   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_atividade_tarefa (tarefa_id),
    CONSTRAINT fk_atividade_tarefa
        FOREIGN KEY (tarefa_id) REFERENCES planner_tarefa (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_atividade_usuario
        FOREIGN KEY (usuario_id) REFERENCES planner_usuario (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- DADOS INICIAIS (SEED)
-- =============================================================================

-- Usuários
INSERT INTO planner_usuario (id, nome, cargo, iniciais, cor, email) VALUES
(1, 'Ana Ferreira',     'Front-end',        'AF', '#059669', 'ana@planner.dev'),
(2, 'Bruno Costa',      'Tech Lead',        'BC', '#10B981', 'bruno@planner.dev'),
(3, 'Carla Dias',       'Product Designer', 'CD', '#EAB308', 'carla@planner.dev'),
(4, 'Diego Lima',       'QA Engineer',      'DL', '#0D9488', 'diego@planner.dev'),
(5, 'Eduardo Souza',    'Back-end',         'ES', '#14B8A6', 'eduardo@planner.dev'),
(6, 'Fernanda Ribeiro', 'Mobile Dev',       'FR', '#CA8A04', 'fernanda@planner.dev'),
(7, 'Gabriel Martins',  'Product Manager',  'GM', '#16A34A', 'gabriel@planner.dev');

-- Equipes
INSERT INTO planner_equipe (id, nome, descricao, pai_id, cor) VALUES
(1, 'Produto Digital', 'Estratégia de produto e UX.',       NULL, '#EAB308'),
(2, 'Engenharia',       'Desenvolvimento e qualidade.',     NULL, '#059669'),
(3, 'Front-end',        'Interfaces e UX.',                 2,    '#10B981'),
(4, 'Back-end',         'APIs e banco de dados.',           2,    '#0D9488'),
(5, 'QA',               'Garantia de qualidade.',           2,    '#DC2626'),
(6, 'Mobile',           'Versões nativas iOS/Android.',     3,    '#CA8A04'),
(7, 'iOS',              'Desenvolvimento iOS nativo.',      6,    '#16A34A');

-- Membros de Equipe
INSERT INTO planner_membro_equipe (equipe_id, usuario_id, papel) VALUES
(1, 7, 'lider'),
(1, 3, 'membro'),
(2, 2, 'lider'),
(2, 6, 'membro'),
(3, 1, 'lider'),
(3, 3, 'membro'),
(4, 2, 'lider'),
(4, 5, 'membro'),
(5, 4, 'lider'),
(6, 1, 'lider'),
(6, 4, 'membro'),
(7, 4, 'lider');

-- Colunas
INSERT INTO planner_coluna (id, titulo, ordem, cor) VALUES
('backlog',      'Backlog',      1, '#64748B'),
('em-progresso', 'Em Progresso', 2, '#059669'),
('em-revisao',   'Em Revisão',   3, '#EAB308'),
('concluido',    'Concluído',    4, '#10B981');

-- Tarefas
INSERT INTO planner_tarefa (id, titulo, descricao, coluna_id, prioridade, prazo, criado_por, criado_em) VALUES
(1,  'Modelar esquema do banco de dados',         'Definir tabelas do planner, chaves estrangeiras e índices otimizados.',        'backlog',      'alta',    '2026-09-14', 2, '2026-09-01 09:00:00'),
(2,  'Levantar requisitos do módulo de relatórios','Mapear KPIs essenciais para os gestores acompanharem o progresso das tarefas.', 'backlog',      'media',   '2026-09-18', 7, '2026-09-01 11:30:00'),
(3,  'Criar wireframes da tela de configurações', 'Desenhar protótipos de baixa fidelidade para gestão de permissões e preferências.', 'backlog',    'baixa',   '2026-09-25', 3, '2026-09-02 14:00:00'),
(4,  'Implementar autenticação de usuários',      'Integrar fluxo de login via sessão existente.',                                    'em-progresso', 'urgente', '2026-09-10', 2, '2026-09-03 08:30:00'),
(5,  'Construir componente de drag and drop',     'Criar movimentação de cards no Kanban usando HTML5 Drag and Drop API nativa.',     'em-progresso', 'alta',    '2026-09-09', 1, '2026-09-04 10:15:00'),
(6,  'Ajustar responsividade do quadro',          'Garantir boa visualização das 4 colunas em telas menores que 1024px.',            'em-progresso', 'media',   '2026-09-16', 1, '2026-09-04 16:45:00'),
(7,  'Revisar contraste de cores do design system','Validar se todos os textos e badges atendem ao critério AA de acessibilidade WCAG.', 'em-revisao', 'media',   '2026-09-11', 3, '2026-09-05 13:20:00'),
(8,  'Testar fluxo de criação de tarefas',        'Executar testes manuais e automatizados para validação de campos obrigatórios.',  'em-revisao',   'alta',    '2026-09-12', 4, '2026-09-06 09:00:00'),
(9,  'Documentar endpoints simulados da API',     'Escrever documentação em Markdown com exemplos de payloads e respostas esperadas.', 'concluido',  'baixa',   '2026-09-05', 2, '2026-08-28 15:00:00'),
(10, 'Configurar estrutura inicial do projeto PHP','Criar arquivos organizados, functions.php e ajax/index.php.',                     'concluido',    'media',   '2026-09-02', 2, '2026-08-27 10:00:00'),
(11, 'Definir paleta de cores e tipografia',      'Estabelecer variáveis CSS claras com destaques em verde e amarelo.',               'concluido',    'baixa',   '2026-08-29', 3, '2026-08-26 14:30:00'),
(12, 'Planejar sprint de calendário integrado',   'Alinhar escopo da visão de calendário com o time.',                                'backlog',      'media',   '2026-09-22', 7, '2026-09-07 17:00:00');

-- Responsáveis das Tarefas (N:N)
INSERT INTO planner_grupo_tarefa (tarefa_id, usuario_id) VALUES
(1, 2), (1, 5),
(2, 3),
(3, 3), (3, 1),
(4, 2), (4, 4), (4, 6),
(5, 1), (5, 4),
(6, 1),
(7, 3), (7, 4),
(8, 4),
(9, 2),
(10, 2), (10, 5),
(11, 3),
(12, 5), (12, 1);

-- Equipes das Tarefas (N:N)
INSERT INTO planner_tarefa_equipe (tarefa_id, equipe_id) VALUES
(1, 2), (1, 4),
(2, 1),
(3, 1), (3, 3),
(4, 2), (4, 4),
(5, 2), (5, 3),
(6, 2), (6, 3),
(7, 1), (7, 3),
(8, 2), (8, 5),
(9, 2), (9, 4),
(10, 2), (10, 4),
(11, 1), (11, 3),
(12, 1);

-- Comentários
INSERT INTO planner_comentario (id, tarefa_id, usuario_id, texto, criado_em) VALUES
(1, 1, 2, 'Alinhar o schema com os requisitos de relatório.',          '2026-09-01 10:00:00'),
(2, 1, 5, 'Rascunho do ERD preparado.',                               '2026-09-01 10:30:00'),
(3, 4, 2, 'Configurada sessão existente.',                            '2026-09-05 14:00:00'),
(4, 5, 1, 'DnD API funcionando perfeitamente em navegadores modernos.', '2026-09-07 11:00:00');

-- Atividades
INSERT INTO planner_atividade (tarefa_id, usuario_id, tipo, meta, criado_em) VALUES
(5, 1, 'criacao',       NULL,                               '2026-09-04 10:15:00'),
(5, 1, 'movimentacao',  '{\"de\":\"backlog\",\"para\":\"em-progresso\"}', '2026-09-07 09:30:00'),
(1, 2, 'criacao',       NULL,                               '2026-09-01 09:00:00'),
(10, 2, 'conclusao',    '{\"coluna\":\"concluido\"}',       '2026-09-02 18:00:00');
