-- =============================================================================
-- Planner · Módulo de Gestão de Equipes e Tarefas
-- database_chave.sql — Versão 2: Campo de Identificação como `chave`
-- =============================================================================
-- 📌 NOTA DE CONFIGURAÇÃO DO IDENTIFICADOR DO USUÁRIO:
-- Este arquivo utiliza o campo `chave` como identificador de usuário na tabela
-- `acesso_permitido`. Caso em seu banco de produção a coluna se chame `matricula`,
-- utilize o arquivo alternativo `database.sql` ou simplesmente altere o nome
-- do campo `chave` para `matricula` nas tabelas abaixo.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `planner`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `planner`;

SET NAMES utf8mb4;
SET time_zone = '-03:00';
SET foreign_key_checks = 0;

-- -----------------------------------------------------------------------------
-- LIMPEZA DE TABELAS OBSOLETAS E ATUAIS
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS planner_atividade;
DROP TABLE IF EXISTS planner_comentario;
DROP TABLE IF EXISTS planner_tarefa_pessoas_soltas;
DROP TABLE IF EXISTS planner_tarefa_equipe;
DROP TABLE IF EXISTS planner_grupo_tarefa;
DROP TABLE IF EXISTS planner_tarefa;
DROP TABLE IF EXISTS planner_coluna;
DROP TABLE IF EXISTS planner_membro_equipe;
DROP TABLE IF EXISTS planner_equipe;
DROP TABLE IF EXISTS planner_usuario;     -- Removido conforme nova arquitetura
DROP TABLE IF EXISTS planner_cargo;       -- Removido conforme nova arquitetura
DROP TABLE IF EXISTS acesso_permitido;

SET foreign_key_checks = 1;

-- =============================================================================
-- 1. acesso_permitido (Tabela de Usuários / Acessos)
-- =============================================================================
-- 📌 CAMPO DE IDENTIFICAÇÃO: `chave`
-- Se precisar mudar para `matricula`, troque `chave` por `matricula` aqui e nas FKs.
CREATE TABLE acesso_permitido (
    chave       VARCHAR(50)     NOT NULL, -- <<< CAMPO IDENTIFICADOR (chave)
    nome        VARCHAR(120)    NOT NULL,
    criado_em   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 2. planner_coluna (Chaves Primárias Numéricas INT AUTO_INCREMENT)
-- =============================================================================
CREATE TABLE planner_coluna (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT, -- <<< CHAVE INT OTIMIZADA
    slug        VARCHAR(40)      NOT NULL,
    titulo      VARCHAR(60)      NOT NULL,
    ordem       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    cor         CHAR(7)          NOT NULL DEFAULT '#94A3B8',

    PRIMARY KEY (id),
    UNIQUE KEY uq_coluna_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 3. planner_equipe
-- =============================================================================
CREATE TABLE planner_equipe (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    nome        VARCHAR(120)     NOT NULL,
    descricao   TEXT,
    pai_id      INT UNSIGNED     NULL DEFAULT NULL,
    lider_id    VARCHAR(50)      NULL DEFAULT NULL, -- <<< LÍDER DA EQUIPE (FK acesso_permitido)
    cor         VARCHAR(30)      NOT NULL DEFAULT '#059669',
    criado_em   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    CONSTRAINT fk_equipe_pai
        FOREIGN KEY (pai_id) REFERENCES planner_equipe (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_equipe_lider
        FOREIGN KEY (lider_id) REFERENCES acesso_permitido (chave)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 4. planner_membro_equipe
-- =============================================================================
CREATE TABLE planner_membro_equipe (
    equipe_id     INT UNSIGNED    NOT NULL,
    chave         VARCHAR(50)     NOT NULL, -- <<< FK para acesso_permitido(chave)
    papel         ENUM('lider','membro') NOT NULL DEFAULT 'membro',
    adicionado_em DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (equipe_id, chave),
    CONSTRAINT fk_membro_equipe
        FOREIGN KEY (equipe_id) REFERENCES planner_equipe (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_membro_acesso
        FOREIGN KEY (chave) REFERENCES acesso_permitido (chave)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 5. planner_tarefa
-- =============================================================================
CREATE TABLE planner_tarefa (
    id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    titulo        VARCHAR(200)     NOT NULL,
    descricao     TEXT,
    coluna_id     INT UNSIGNED     NOT NULL DEFAULT 1, -- <<< FK INT para planner_coluna(id)
    prioridade    ENUM('baixa','media','alta','urgente') NOT NULL DEFAULT 'media',
    prazo         DATE             NULL DEFAULT NULL,
    criado_por    VARCHAR(50)      NOT NULL,           -- <<< FK para acesso_permitido(chave)
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
        FOREIGN KEY (criado_por) REFERENCES acesso_permitido (chave)
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 6. planner_grupo_tarefa (Responsáveis N:N)
-- =============================================================================
CREATE TABLE planner_grupo_tarefa (
    tarefa_id     INT UNSIGNED    NOT NULL,
    chave         VARCHAR(50)     NOT NULL, -- <<< FK para acesso_permitido(chave)
    atribuido_em  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (tarefa_id, chave),
    CONSTRAINT fk_gt_tarefa
        FOREIGN KEY (tarefa_id) REFERENCES planner_tarefa (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_gt_acesso
        FOREIGN KEY (chave) REFERENCES acesso_permitido (chave)
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
-- 8. planner_tarefa_pessoas_soltas (Pessoas avulsas selecionadas N:N)
-- =============================================================================
CREATE TABLE planner_tarefa_pessoas_soltas (
    tarefa_id     INT UNSIGNED    NOT NULL,
    chave         VARCHAR(50)     NOT NULL, -- <<< FK para acesso_permitido(chave)
    atribuido_em  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (tarefa_id, chave),
    CONSTRAINT fk_tps_tarefa
        FOREIGN KEY (tarefa_id) REFERENCES planner_tarefa (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_tps_acesso
        FOREIGN KEY (chave) REFERENCES acesso_permitido (chave)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 9. planner_comentario
-- =============================================================================
CREATE TABLE planner_comentario (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    tarefa_id   INT UNSIGNED     NOT NULL,
    chave       VARCHAR(50)      NOT NULL, -- <<< FK para acesso_permitido(chave)
    texto       TEXT             NOT NULL,
    criado_em   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    editado_em  DATETIME         NULL DEFAULT NULL,

    PRIMARY KEY (id),
    INDEX idx_comentario_tarefa (tarefa_id),
    CONSTRAINT fk_comentario_tarefa
        FOREIGN KEY (tarefa_id) REFERENCES planner_tarefa (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_comentario_acesso
        FOREIGN KEY (chave) REFERENCES acesso_permitido (chave)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 10. planner_atividade (Audit Log / Timeline)
-- =============================================================================
CREATE TABLE planner_atividade (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    tarefa_id   INT UNSIGNED     NOT NULL,
    chave       VARCHAR(50)      NOT NULL, -- <<< FK para acesso_permitido(chave)
    tipo        VARCHAR(40)      NOT NULL,
    meta        JSON             NULL DEFAULT NULL,
    criado_em   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_atividade_tarefa (tarefa_id),
    CONSTRAINT fk_atividade_tarefa
        FOREIGN KEY (tarefa_id) REFERENCES planner_tarefa (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_atividade_acesso
        FOREIGN KEY (chave) REFERENCES acesso_permitido (chave)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- DADOS INICIAIS (SEED)
-- =============================================================================

-- Usuários em acesso_permitido
INSERT INTO acesso_permitido (chave, nome) VALUES
('1001', 'Ana Ferreira'),
('1002', 'Bruno Costa'),
('1003', 'Carla Dias'),
('1004', 'Diego Lima'),
('1005', 'Eduardo Souza'),
('1006', 'Fernanda Ribeiro'),
('1007', 'Gabriel Martins');

-- Colunas (com IDs numéricos INT AUTO_INCREMENT)
INSERT INTO planner_coluna (id, slug, titulo, ordem, cor) VALUES
(1, 'backlog',      'Backlog',      1, '#64748B'),
(2, 'em-progresso', 'Em Progresso', 2, '#059669'),
(3, 'em-revisao',   'Em Revisão',   3, '#EAB308'),
(4, 'concluido',    'Concluído',    4, '#10B981');

-- Equipes
INSERT INTO planner_equipe (id, nome, descricao, pai_id, cor) VALUES
-- Equipes (com líder atribuído)
INSERT INTO planner_equipe (id, nome, descricao, pai_id, lider_id, cor) VALUES
(1, 'Produto Digital', 'Estratégia de produto e UX.',       NULL, '1007', '#EAB308'),
(2, 'Engenharia',       'Desenvolvimento e qualidade.',     NULL, '1002', '#059669'),
(3, 'Front-end',        'Interfaces e UX.',                 2,    '1001', '#10B981'),
(4, 'Back-end',         'APIs e banco de dados.',           2,    '1004', '#0D9488'),
(5, 'QA',               'Garantia de qualidade.',           2,    '1005', '#DC2626'),
(6, 'Mobile',           'Versões nativas iOS/Android.',     3,    '1006', '#CA8A04'),
(7, 'iOS',              'Desenvolvimento iOS nativo.',      6,    '1001', '#16A34A');

-- Membros de Equipe (equipe_id, chave, papel)
INSERT INTO planner_membro_equipe (equipe_id, chave, papel) VALUES
(1, '1007', 'lider'),
(1, '1003', 'membro'),
(2, '1002', 'lider'),
(2, '1006', 'membro'),
(3, '1001', 'lider'),
(3, '1003', 'membro'),
(4, '1002', 'lider'),
(4, '1005', 'membro'),
(5, '1004', 'lider'),
(6, '1001', 'lider'),
(6, '1004', 'membro'),
(7, '1004', 'lider');

-- Tarefas
INSERT INTO planner_tarefa (id, titulo, descricao, coluna_id, prioridade, prazo, criado_por, criado_em) VALUES
(1,  'Modelar esquema do banco de dados',         'Definir tabelas do planner, chaves estrangeiras e índices otimizados.',        1, 'alta',    '2026-09-14', '1002', '2026-09-01 09:00:00'),
(2,  'Levantar requisitos do módulo de relatórios','Mapear KPIs essenciais para os gestores acompanharem o progresso das tarefas.', 1, 'media',   '2026-09-18', '1007', '2026-09-01 11:30:00'),
(3,  'Criar wireframes da tela de configurações', 'Desenhar protótipos de baixa fidelidade para gestão de permissões e preferências.', 1, 'baixa',   '2026-09-25', '1003', '2026-09-02 14:00:00'),
(4,  'Implementar autenticação de usuários',      'Integrar fluxo de login via sessão existente.',                                    2, 'urgente', '2026-09-10', '1002', '2026-09-03 08:30:00'),
(5,  'Construir componente de drag and drop',     'Criar movimentação de cards no Kanban usando HTML5 Drag and Drop API nativa.',     2, 'alta',    '2026-09-09', '1001', '2026-09-04 10:15:00'),
(6,  'Ajustar responsividade do quadro',          'Garantir boa visualização das 4 colunas em telas menores que 1024px.',            2, 'media',   '2026-09-04', '1001', '2026-09-04 16:45:00'),
(7,  'Revisar contraste de cores do design system','Validar se todos os textos e badges atendem ao critério AA de acessibilidade WCAG.', 3, 'media',   '2026-09-11', '1003', '2026-09-05 13:20:00'),
(8,  'Testar fluxo de criação de tarefas',        'Executar testes manuais e automatizados para validação de campos obrigatórios.',  3, 'alta',    '2026-09-12', '1004', '2026-09-06 09:00:00'),
(9,  'Documentar endpoints simulados da API',     'Escrever documentação em Markdown com exemplos de payloads e respostas esperadas.', 4, 'baixa',   '2026-09-05', '1002', '2026-08-28 15:00:00'),
(10, 'Configurar estrutura inicial do projeto PHP','Criar arquivos organizados, functions.php e ajax/index.php.',                     4, 'media',   '2026-09-02', '1002', '2026-08-27 10:00:00'),
(11, 'Definir paleta de cores e tipografia',      'Estabelecer variáveis CSS claras com destaques em verde e amarelo.',               4, 'baixa',   '2026-08-29', '1003', '2026-08-26 14:30:00'),
(12, 'Planejar sprint de calendário integrado',   'Alinhar escopo da visão de calendário com o time.',                                1, 'media',   '2026-09-22', '1007', '2026-09-07 17:00:00');

-- Responsáveis das Tarefas (tarefa_id, chave)
INSERT INTO planner_grupo_tarefa (tarefa_id, chave) VALUES
(1, '1002'), (1, '1005'),
(2, '1003'),
(3, '1003'), (3, '1001'),
(4, '1002'), (4, '1004'), (4, '1006'),
(5, '1001'), (5, '1004'),
(6, '1001'),
(7, '1003'), (7, '1004'),
(8, '1004'),
(9, '1002'),
(10, '1002'), (10, '1005'),
(11, '1003'),
(12, '1005'), (12, '1001');

-- Equipes das Tarefas (tarefa_id, equipe_id)
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

-- Pessoas Avulsas (Exemplo Seed)
INSERT INTO planner_tarefa_pessoas_soltas (tarefa_id, chave) VALUES
(6, '1005'),
(8, '1006');

-- Comentários (tarefa_id, chave, texto, criado_em)
INSERT INTO planner_comentario (id, tarefa_id, chave, texto, criado_em) VALUES
(1, 1, '1002', 'Alinhar o schema com os requisitos de relatório.',          '2026-09-01 10:00:00'),
(2, 1, '1005', 'Rascunho do ERD preparado.',                               '2026-09-01 10:30:00'),
(3, 4, '1002', 'Configurada sessão existente.',                            '2026-09-05 14:00:00'),
(4, 5, '1001', 'DnD API funcionando perfeitamente em navegadores modernos.', '2026-09-07 11:00:00');

-- Atividades (tarefa_id, chave, tipo, meta, criado_em)
INSERT INTO planner_atividade (tarefa_id, chave, tipo, meta, criado_em) VALUES
(5, '1001', 'criacao',       NULL,                               '2026-09-04 10:15:00'),
(5, '1001', 'movimentacao',  '{\"de\":1,\"para\":2}',             '2026-09-07 09:30:00'),
(1, '1002', 'criacao',       NULL,                               '2026-09-01 09:00:00'),
(10, '1002', 'conclusao',    '{\"coluna_id\":4}',                 '2026-09-02 18:00:00');
