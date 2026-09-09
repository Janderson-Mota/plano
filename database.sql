-- =============================================================================
-- Orbit · Módulo de Gestão de Equipes e Tarefas
-- database.sql — Schema + Seed
-- =============================================================================
-- Convenções:
--   • Prefixo orbit_  → evita colisão com tabelas do projeto-pai
--   • Fuso horário    → America/Sao_Paulo (DATETIMEs sem TZ embutido)
--   • Charset         → utf8mb4 / utf8mb4_unicode_ci
--   • Engine          → InnoDB (suporte a FK)
--   • Enums fixos     → prioridade: baixa|media|alta|urgente
--                        papel:      lider|membro
--                        tipo:       criacao|movimentacao|comentario|atribuicao|edicao_prazo
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '-03:00';   -- America/Sao_Paulo
SET foreign_key_checks = 0;

-- -----------------------------------------------------------------------------
-- LIMPEZA (re-execução segura)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS orbit_atividade;
DROP TABLE IF EXISTS orbit_comentario;
DROP TABLE IF EXISTS orbit_grupo_tarefa;
DROP TABLE IF EXISTS orbit_tarefa;
DROP TABLE IF EXISTS orbit_coluna;
DROP TABLE IF EXISTS orbit_membro_equipe;
DROP TABLE IF EXISTS orbit_equipe;
DROP TABLE IF EXISTS orbit_usuario;

SET foreign_key_checks = 1;

-- =============================================================================
-- 1. orbit_usuario
-- =============================================================================
-- Espelha o usuário já autenticado pelo projeto-pai.
-- O módulo recebe $usuarioAtual (array) e usa o id aqui como FK.
-- Em produção, esta tabela pode ser substituída por uma VIEW sobre a
-- tabela de usuários do projeto-pai.
-- =============================================================================
CREATE TABLE orbit_usuario (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    nome        VARCHAR(120)     NOT NULL,
    cargo       VARCHAR(80)      NOT NULL DEFAULT '',
    iniciais    CHAR(3)          NOT NULL,           -- ex.: "AF"
    cor         CHAR(7)          NOT NULL DEFAULT '#4C3FE0',  -- hex para avatar
    email       VARCHAR(200)     NOT NULL DEFAULT '',
    criado_em   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_orbit_usuario_email (email)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 2. orbit_equipe
-- =============================================================================
-- Árvore de equipes e sub-equipes via adjacência (pai_id).
-- Profundidade máxima: 4 níveis (validada server-side contra ciclos).
-- Nível 1 → equipe raiz (pai_id IS NULL).
-- =============================================================================
CREATE TABLE orbit_equipe (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    nome        VARCHAR(120)     NOT NULL,
    descricao   TEXT,
    pai_id      INT UNSIGNED     NULL DEFAULT NULL,  -- NULL = equipe raiz
    criado_em   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    CONSTRAINT fk_equipe_pai
        FOREIGN KEY (pai_id) REFERENCES orbit_equipe (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 3. orbit_membro_equipe
-- =============================================================================
-- Vínculo N:N entre usuário e equipe, com papel fixo (lider|membro).
-- =============================================================================
CREATE TABLE orbit_membro_equipe (
    equipe_id     INT UNSIGNED    NOT NULL,
    usuario_id    INT UNSIGNED    NOT NULL,
    papel         ENUM('lider','membro') NOT NULL DEFAULT 'membro',
    adicionado_em DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (equipe_id, usuario_id),
    CONSTRAINT fk_membro_equipe
        FOREIGN KEY (equipe_id) REFERENCES orbit_equipe (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_membro_usuario
        FOREIGN KEY (usuario_id) REFERENCES orbit_usuario (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 4. orbit_coluna
-- =============================================================================
-- Colunas do Kanban. 'ordem' define a sequência da esquerda para direita.
-- =============================================================================
CREATE TABLE orbit_coluna (
    id          VARCHAR(40)      NOT NULL,       -- slug: 'backlog', 'em-progresso'
    titulo      VARCHAR(80)      NOT NULL,
    cor         CHAR(7)          NOT NULL DEFAULT '#94A3B8',
    ordem       TINYINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 5. orbit_tarefa
-- =============================================================================
-- Núcleo do módulo. status_prazo é DERIVADO em runtime (PHP/JS):
--   atrasada → prazo < CURDATE() AND coluna_id != 'concluido'
--   hoje     → prazo = CURDATE()
--   futura   → prazo > CURDATE()
-- Não é armazenado no banco.
-- =============================================================================
CREATE TABLE orbit_tarefa (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    titulo        VARCHAR(200)    NOT NULL,
    descricao     TEXT,
    coluna_id     VARCHAR(40)     NOT NULL,
    prioridade    ENUM('baixa','media','alta','urgente') NOT NULL DEFAULT 'media',
    prazo         DATE            NULL DEFAULT NULL,
    criado_por    INT UNSIGNED    NOT NULL,
    criado_em     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    CONSTRAINT fk_tarefa_coluna
        FOREIGN KEY (coluna_id) REFERENCES orbit_coluna (id)
        ON UPDATE CASCADE,
    CONSTRAINT fk_tarefa_criador
        FOREIGN KEY (criado_por) REFERENCES orbit_usuario (id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 6. orbit_grupo_tarefa
-- =============================================================================
-- Grupo ad-hoc: responsáveis livres por tarefa, independente de equipe fixa.
-- Qualquer usuário pode ser adicionado a qualquer tarefa (cross-team).
-- =============================================================================
CREATE TABLE orbit_grupo_tarefa (
    tarefa_id     INT UNSIGNED    NOT NULL,
    usuario_id    INT UNSIGNED    NOT NULL,
    adicionado_em DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (tarefa_id, usuario_id),
    CONSTRAINT fk_grupo_tarefa
        FOREIGN KEY (tarefa_id) REFERENCES orbit_tarefa (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_grupo_usuario
        FOREIGN KEY (usuario_id) REFERENCES orbit_usuario (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 7. orbit_comentario
-- =============================================================================
-- Thread de comentários de uma tarefa.
-- editado_em NULL  → nunca editado.
-- editado_em !NULL → mostra "(editado)" na UI.
-- Somente o autor pode editar/excluir (validado server-side).
-- =============================================================================
CREATE TABLE orbit_comentario (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    tarefa_id   INT UNSIGNED     NOT NULL,
    usuario_id  INT UNSIGNED     NOT NULL,
    texto       TEXT             NOT NULL,
    criado_em   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    editado_em  DATETIME         NULL DEFAULT NULL,

    PRIMARY KEY (id),
    KEY idx_comentario_tarefa (tarefa_id),
    CONSTRAINT fk_comentario_tarefa
        FOREIGN KEY (tarefa_id) REFERENCES orbit_tarefa (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_comentario_usuario
        FOREIGN KEY (usuario_id) REFERENCES orbit_usuario (id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 8. orbit_atividade
-- =============================================================================
-- Log de auditoria por tarefa. 'meta' (JSON) carrega dados por tipo:
--   movimentacao → {"de":"backlog","para":"em-progresso"}
--   atribuicao   → {"usuario_id":3,"acao":"add"|"remove"}
--   edicao_prazo → {"de":"2026-09-10","para":"2026-09-20"}
--   comentario   → {"comentario_id":7}
--   criacao      → null
-- =============================================================================
CREATE TABLE orbit_atividade (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    tarefa_id   INT UNSIGNED     NOT NULL,
    usuario_id  INT UNSIGNED     NOT NULL,
    tipo        ENUM(
                    'criacao',
                    'movimentacao',
                    'comentario',
                    'atribuicao',
                    'edicao_prazo'
                ) NOT NULL,
    meta        JSON             NULL DEFAULT NULL,
    criado_em   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_atividade_tarefa    (tarefa_id),
    KEY idx_atividade_criado_em (criado_em),
    CONSTRAINT fk_atividade_tarefa
        FOREIGN KEY (tarefa_id) REFERENCES orbit_tarefa (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_atividade_usuario
        FOREIGN KEY (usuario_id) REFERENCES orbit_usuario (id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- SEED — Dados de Exemplo
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Usuários
-- -----------------------------------------------------------------------------
INSERT INTO orbit_usuario (id, nome, cargo, iniciais, cor, email, criado_em) VALUES
(1, 'Ana Ferreira',  'Front-end',     'AF', '#4C3FE0', 'ana@orbit.dev',    '2026-08-01 09:00:00'),
(2, 'Bruno Lima',    'Back-end',      'BL', '#0EA5A0', 'bruno@orbit.dev',  '2026-08-01 09:05:00'),
(3, 'Carla Nunes',   'UI/UX',         'CN', '#F59E0B', 'carla@orbit.dev',  '2026-08-01 09:10:00'),
(4, 'Diego Santos',  'QA',            'DS', '#EF4444', 'diego@orbit.dev',  '2026-08-01 09:15:00'),
(5, 'Elisa Prado',   'Back-end',      'EP', '#10B981', 'elisa@orbit.dev',  '2026-08-01 09:20:00'),
(6, 'Felipe Costa',  'DevOps',        'FC', '#8B5CF6', 'felipe@orbit.dev', '2026-08-02 10:00:00'),
(7, 'Gabriela Melo', 'Product Owner', 'GM', '#F97316', 'gabi@orbit.dev',   '2026-08-02 10:05:00');

-- -----------------------------------------------------------------------------
-- Colunas Kanban
-- -----------------------------------------------------------------------------
INSERT INTO orbit_coluna (id, titulo, cor, ordem) VALUES
('backlog',      'Backlog',      '#94A3B8', 0),
('em-progresso', 'Em Progresso', '#4C3FE0', 1),
('em-revisao',   'Em Revisão',   '#F59E0B', 2),
('concluido',    'Concluído',    '#10B981', 3);

-- -----------------------------------------------------------------------------
-- Equipes — Árvore de 4 níveis
--
-- Nível 1 (raiz):   1=Produto Digital   2=Engenharia
-- Nível 2:          3=Front-end(2)      4=Back-end(2)   5=QA(2)
-- Nível 3:          6=Mobile(3)
-- Nível 4:          7=iOS(6)
-- -----------------------------------------------------------------------------
INSERT INTO orbit_equipe (id, nome, descricao, pai_id, criado_em) VALUES
(1, 'Produto Digital', 'Estratégia de produto e UX.',               NULL, '2026-08-01 08:00:00'),
(2, 'Engenharia',      'Desenvolvimento e qualidade de software.',   NULL, '2026-08-01 08:00:00'),
(3, 'Front-end',       'Interfaces e experiência do usuário.',       2,    '2026-08-01 08:05:00'),
(4, 'Back-end',        'APIs, serviços e banco de dados.',           2,    '2026-08-01 08:05:00'),
(5, 'QA',              'Garantia de qualidade e testes.',            2,    '2026-08-01 08:05:00'),
(6, 'Mobile',          'Versões nativas iOS e Android.',             3,    '2026-08-10 09:00:00'),
(7, 'iOS',             'Desenvolvimento exclusivo iOS.',             6,    '2026-08-15 10:00:00');

-- -----------------------------------------------------------------------------
-- Membros das equipes
-- -----------------------------------------------------------------------------
INSERT INTO orbit_membro_equipe (equipe_id, usuario_id, papel, adicionado_em) VALUES
(1, 7, 'lider',  '2026-08-01 08:10:00'),
(1, 3, 'membro', '2026-08-01 08:10:00'),
(2, 2, 'lider',  '2026-08-01 08:10:00'),
(2, 6, 'membro', '2026-08-01 08:10:00'),
(3, 1, 'lider',  '2026-08-01 08:15:00'),
(3, 3, 'membro', '2026-08-01 08:15:00'),
(4, 2, 'lider',  '2026-08-01 08:15:00'),
(4, 5, 'membro', '2026-08-01 08:15:00'),
(5, 4, 'lider',  '2026-08-01 08:15:00'),
(6, 1, 'lider',  '2026-08-10 09:05:00'),
(6, 4, 'membro', '2026-08-10 09:05:00'),
(7, 4, 'lider',  '2026-08-15 10:05:00');

-- -----------------------------------------------------------------------------
-- Tarefas (todos os enums de prioridade + todas as colunas)
-- -----------------------------------------------------------------------------
INSERT INTO orbit_tarefa
    (id, titulo, descricao, coluna_id, prioridade, prazo, criado_por, criado_em, atualizado_em)
VALUES
(1,  'Modelar esquema do banco de dados',
     'Definir tabelas de usuários, tarefas, colunas e equipes.',
     'backlog',      'alta',    '2026-09-14', 2, '2026-08-20 10:00:00', '2026-09-01 14:00:00'),

(2,  'Levantar requisitos do módulo de relatórios',
     'Reunião com o time de produto para mapear indicadores.',
     'backlog',      'media',   '2026-09-18', 7, '2026-08-21 09:30:00', '2026-08-21 09:30:00'),

(3,  'Criar wireframes da tela de configurações',
     'Baixa fidelidade, cobrindo os três perfis de usuário.',
     'backlog',      'baixa',   '2026-09-25', 3, '2026-08-22 11:00:00', '2026-08-22 11:00:00'),

(4,  'Implementar autenticação de usuários',
     'Login, recuperação de senha e sessão persistente.',
     'em-progresso', 'urgente', '2026-09-10', 2, '2026-08-25 08:00:00', '2026-09-05 16:00:00'),

(5,  'Construir componente de drag and drop',
     'Cards arrastáveis entre colunas com feedback visual.',
     'em-progresso', 'alta',    '2026-09-09', 1, '2026-08-25 08:30:00', '2026-09-08 17:00:00'),

(6,  'Ajustar responsividade do quadro em telas pequenas',
     'Rolagem horizontal suave e colunas com largura mínima.',
     'em-progresso', 'media',   '2026-09-16', 1, '2026-08-26 10:00:00', '2026-08-26 10:00:00'),

(7,  'Revisar contraste de cores do design system',
     'Checar acessibilidade (WCAG AA) da paleta escolhida.',
     'em-revisao',   'media',   '2026-09-11', 3, '2026-08-27 13:00:00', '2026-09-07 09:00:00'),

(8,  'Testar fluxo de criação de tarefas',
     'Validar formulário, mensagens de erro e retorno da API.',
     'em-revisao',   'alta',    '2026-09-12', 4, '2026-08-28 09:00:00', '2026-09-06 11:00:00'),

(9,  'Documentar endpoints simulados da API',
     'Descrever payloads de entrada e saída para o time.',
     'concluido',    'baixa',   '2026-09-05', 2, '2026-08-15 10:00:00', '2026-09-05 15:00:00'),

(10, 'Configurar estrutura inicial do projeto PHP',
     'Pastas, includes e convenção de nomes definidas.',
     'concluido',    'media',   '2026-09-02', 2, '2026-08-10 08:00:00', '2026-09-02 12:00:00'),

(11, 'Definir paleta de cores e tipografia',
     'Fonte Inter e paleta baseada em azul-tecnológico.',
     'concluido',    'baixa',   '2026-08-29', 3, '2026-08-05 09:00:00', '2026-08-29 16:00:00'),

(12, 'Planejar sprint de calendário integrado',
     'Alinhar escopo da visão de calendário com o time.',
     'backlog',      'media',   '2026-09-22', 7, '2026-09-01 10:00:00', '2026-09-01 10:00:00');

-- -----------------------------------------------------------------------------
-- Grupos ad-hoc (responsáveis por tarefa — cross-team)
-- Tarefa 4: Back-end + QA + DevOps → grupo urgente cross-team
-- Tarefa 12: Elisa (Back-end) + Ana (Front-end) → ad-hoc
-- -----------------------------------------------------------------------------
INSERT INTO orbit_grupo_tarefa (tarefa_id, usuario_id, adicionado_em) VALUES
(1,  2, '2026-08-20 10:05:00'),
(1,  5, '2026-08-20 10:05:00'),
(2,  3, '2026-08-21 09:35:00'),
(3,  3, '2026-08-22 11:05:00'),
(3,  1, '2026-08-22 11:05:00'),
(4,  2, '2026-08-25 08:05:00'),   -- Back-end
(4,  4, '2026-08-25 08:05:00'),   -- QA (cross-team)
(4,  6, '2026-08-25 08:10:00'),   -- DevOps (cross-team)
(5,  1, '2026-08-25 08:35:00'),
(5,  4, '2026-08-25 08:35:00'),
(6,  1, '2026-08-26 10:05:00'),
(7,  3, '2026-08-27 13:05:00'),
(7,  4, '2026-08-27 13:05:00'),
(8,  4, '2026-08-28 09:05:00'),
(9,  2, '2026-08-15 10:05:00'),
(10, 2, '2026-08-10 08:05:00'),
(10, 5, '2026-08-10 08:05:00'),
(11, 3, '2026-08-05 09:05:00'),
(12, 5, '2026-09-01 10:05:00'),   -- Elisa (Back-end)
(12, 1, '2026-09-01 10:10:00');   -- Ana (Front-end) → cross-team

-- -----------------------------------------------------------------------------
-- Comentários (3 com editado_em preenchido, restantes NULL)
-- -----------------------------------------------------------------------------
INSERT INTO orbit_comentario
    (id, tarefa_id, usuario_id, texto, criado_em, editado_em)
VALUES
(1,  1, 2, 'Precisamos alinhar o schema com os requisitos de relatório antes de finalizar.',
    '2026-09-01 10:00:00', NULL),
(2,  1, 5, 'Concordo. Vou preparar um rascunho do ERD até amanhã.',
    '2026-09-01 10:30:00', NULL),
(3,  1, 2, 'ERD revisado — adicionei a tabela orbit_atividade conforme discutido.',
    '2026-09-02 09:00:00', '2026-09-02 09:45:00'),  -- editado

(4,  4, 2, 'Sessão JWT ou cookie httpOnly? Precisamos decidir hoje.',
    '2026-09-05 14:00:00', NULL),
(5,  4, 6, 'Vote em httpOnly. JWT no cliente vira dor de cabeça com refresh token.',
    '2026-09-05 14:20:00', NULL),
(6,  4, 4, 'Testei o fluxo de recuperação de senha — há um bug no token de reset.',
    '2026-09-06 10:00:00', '2026-09-06 10:30:00'),  -- editado

(7,  5, 1, 'DnD API funcionando no Chrome e Firefox. Safari precisa de workaround.',
    '2026-09-07 11:00:00', NULL),
(8,  5, 4, 'Reproduzi o bug no Safari 17. Vou abrir uma issue separada.',
    '2026-09-08 09:00:00', NULL),

(9,  7, 3, 'Paleta revisada. Contraste de texto principal agora passa WCAG AA.',
    '2026-09-07 14:00:00', NULL),
(10, 7, 4, 'Preciso verificar os componentes de badge também.',
    '2026-09-07 15:00:00', NULL),
(11, 7, 3, 'Badges atualizados. Aguardando aprovação final.',
    '2026-09-08 10:00:00', '2026-09-08 10:20:00'),  -- editado

(12, 8, 4, 'Fluxo ok no happy path. Preciso cobrir os edge cases de validação.',
    '2026-09-06 09:00:00', NULL);

-- -----------------------------------------------------------------------------
-- Atividades (últimos 7 dias para o gráfico do Dashboard + histórico)
-- -----------------------------------------------------------------------------
INSERT INTO orbit_atividade
    (id, tarefa_id, usuario_id, tipo, meta, criado_em)
VALUES
-- Criações
(1,  1,  2, 'criacao',      NULL,                                            '2026-08-20 10:00:00'),
(2,  2,  7, 'criacao',      NULL,                                            '2026-08-21 09:30:00'),
(3,  3,  3, 'criacao',      NULL,                                            '2026-08-22 11:00:00'),
(4,  4,  2, 'criacao',      NULL,                                            '2026-08-25 08:00:00'),
(5,  5,  1, 'criacao',      NULL,                                            '2026-08-25 08:30:00'),

-- Movimentações (dentro dos últimos 7 dias: 2026-09-02 a 2026-09-09)
(6,  4,  2, 'movimentacao', '{"de":"backlog","para":"em-progresso"}',        '2026-09-03 09:00:00'),
(7,  5,  1, 'movimentacao', '{"de":"backlog","para":"em-progresso"}',        '2026-09-04 10:00:00'),
(8,  7,  3, 'movimentacao', '{"de":"em-progresso","para":"em-revisao"}',     '2026-09-05 14:00:00'),
(9,  8,  4, 'movimentacao', '{"de":"backlog","para":"em-revisao"}',          '2026-09-06 08:00:00'),
(10, 9,  2, 'movimentacao', '{"de":"em-revisao","para":"concluido"}',        '2026-09-05 15:00:00'),
(11, 10, 2, 'movimentacao', '{"de":"em-revisao","para":"concluido"}',        '2026-09-02 12:00:00'),
(23, 6,  1, 'movimentacao', '{"de":"backlog","para":"em-progresso"}',        '2026-09-07 08:30:00'),

-- Comentários (atividade gerada ao comentar)
(12, 1,  2, 'comentario',   '{"comentario_id":1}',                           '2026-09-01 10:00:00'),
(13, 1,  5, 'comentario',   '{"comentario_id":2}',                           '2026-09-01 10:30:00'),
(14, 4,  2, 'comentario',   '{"comentario_id":4}',                           '2026-09-05 14:00:00'),
(15, 5,  1, 'comentario',   '{"comentario_id":7}',                           '2026-09-07 11:00:00'),
(19, 5,  4, 'comentario',   '{"comentario_id":8}',                           '2026-09-08 09:00:00'),
(20, 7,  3, 'comentario',   '{"comentario_id":9}',                           '2026-09-07 14:00:00'),
(21, 7,  4, 'comentario',   '{"comentario_id":10}',                          '2026-09-07 15:00:00'),
(22, 8,  4, 'comentario',   '{"comentario_id":12}',                          '2026-09-06 09:00:00'),

-- Atribuições ad-hoc
(16, 4,  2, 'atribuicao',   '{"usuario_id":6,"acao":"add"}',                 '2026-09-03 09:05:00'),
(17, 12, 7, 'atribuicao',   '{"usuario_id":1,"acao":"add"}',                 '2026-09-01 10:10:00'),
(24, 3,  3, 'atribuicao',   '{"usuario_id":1,"acao":"add"}',                 '2026-09-08 11:00:00'),

-- Edição de prazo
(18, 4,  7, 'edicao_prazo', '{"de":"2026-09-08","para":"2026-09-10"}',       '2026-09-04 16:00:00');

-- =============================================================================
-- FIM DO SCRIPT
-- =============================================================================
