# Orbit — Kanban com Equipes e Calendário

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





Preciso que esses links de navegação fiquem na lateral esquerda. os filtros fiquem centralizados, eu posso ter uma equipe opu varias para uma tarefa e um ou varios responsaveis. E quero um filtro unicos para os responsaveis. o filtro de select devem ser multiselecionaveis. o filtro de data deve ser data inicio ou data fim. quero o layout mais clean. quero ele verde escuro com detalhes em amarelo. Quero algo moderno, o comportamento está bom, mas pode melhorar