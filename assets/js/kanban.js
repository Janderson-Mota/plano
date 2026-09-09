/**
 * kanban.js
 * Gerencia toda a interatividade do quadro: drag and drop entre colunas,
 * filtro por responsável e criação de novas tarefas. Nenhuma dependência
 * além da Drag and Drop API nativa e fetch().
 */
(function () {
    'use strict';

    const board = document.getElementById('board');
    if (!board) return;

    const dataEl = document.getElementById('kanban-data');
    const { team = [], columns = [] } = dataEl ? JSON.parse(dataEl.textContent) : {};

    const activeFilters = new Set();

    /* ----------------------------------------------------------------
     * Drag and drop
     * ------------------------------------------------------------- */

    board.addEventListener('dragstart', (event) => {
        const card = event.target.closest('.task-card');
        if (!card) return;
        card.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', card.dataset.taskId);
    });

    board.addEventListener('dragend', (event) => {
        const card = event.target.closest('.task-card');
        if (card) card.classList.remove('is-dragging');
    });

    board.querySelectorAll('.board-column__body').forEach((columnBody) => {
        columnBody.addEventListener('dragover', (event) => {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            columnBody.classList.add('drag-over');
        });

        columnBody.addEventListener('dragleave', (event) => {
            if (!columnBody.contains(event.relatedTarget)) {
                columnBody.classList.remove('drag-over');
            }
        });

        columnBody.addEventListener('drop', (event) => {
            event.preventDefault();
            columnBody.classList.remove('drag-over');

            const taskId = event.dataTransfer.getData('text/plain');
            const card = board.querySelector(`.task-card[data-task-id="${taskId}"]`);
            if (!card) return;

            const originColumn = card.dataset.column;
            const targetColumn = columnBody.dataset.columnBody;
            if (originColumn === targetColumn) return;

            const emptyState = columnBody.querySelector('.board-column__empty');
            if (emptyState) emptyState.remove();

            columnBody.appendChild(card);
            card.dataset.column = targetColumn;

            updateColumnCounts();
            syncTaskColumn(taskId, targetColumn, originColumn, columnBody, card);
        });
    });

    /**
     * Envia a nova coluna ao endpoint simulado. Em caso de falha,
     * a movimentação é desfeita para manter a UI consistente com o servidor.
     */
    function syncTaskColumn(taskId, targetColumn, originColumn, targetBody, card) {
        fetch('api/update_task.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ task_id: Number(taskId), coluna: targetColumn }),
        })
            .then((res) => res.json())
            .then((data) => {
                if (!data.success) throw new Error(data.error || 'Falha ao atualizar a tarefa.');
            })
            .catch(() => {
                const originBody = board.querySelector(`[data-column-body="${originColumn}"]`);
                if (originBody) {
                    originBody.appendChild(card);
                    card.dataset.column = originColumn;
                    updateColumnCounts();
                }
            });
    }

    function updateColumnCounts() {
        board.querySelectorAll('.board-column').forEach((column) => {
            const columnId = column.dataset.columnId;
            const count = column.querySelectorAll('.task-card').length;
            const badge = column.querySelector(`[data-count-for="${columnId}"]`);
            if (badge) badge.textContent = String(count);

            const body = column.querySelector('.board-column__body');
            if (body && count === 0 && !body.querySelector('.board-column__empty')) {
                const empty = document.createElement('p');
                empty.className = 'board-column__empty';
                empty.textContent = 'Nenhuma tarefa aqui ainda.';
                body.appendChild(empty);
            }
        });
    }

    /* ----------------------------------------------------------------
     * Filtro por responsável
     * ------------------------------------------------------------- */

    document.querySelectorAll('.team-filter__member').forEach((button) => {
        button.addEventListener('click', () => {
            const memberId = button.dataset.filterMember;
            const isActive = activeFilters.has(memberId);

            if (isActive) {
                activeFilters.delete(memberId);
                button.setAttribute('aria-pressed', 'false');
            } else {
                activeFilters.add(memberId);
                button.setAttribute('aria-pressed', 'true');
            }

            applyFilters();
        });
    });

    function applyFilters() {
        const cards = board.querySelectorAll('.task-card');
        cards.forEach((card) => {
            if (activeFilters.size === 0) {
                card.style.display = '';
                return;
            }
            const assignees = (card.dataset.assignees || '').split(',');
            const matches = assignees.some((id) => activeFilters.has(id));
            card.style.display = matches ? '' : 'none';
        });
    }

    /* ----------------------------------------------------------------
     * Criação de tarefas (modal + fetch)
     * ------------------------------------------------------------- */

    const form = document.getElementById('formNovaTarefa');
    if (form) {
        form.addEventListener('submit', (event) => {
            event.preventDefault();

            const formData = new FormData(form);
            const payload = {
                titulo: formData.get('titulo'),
                descricao: formData.get('descricao'),
                coluna: formData.get('coluna'),
                prioridade: formData.get('prioridade'),
                prazo: formData.get('prazo'),
                responsaveis: formData.getAll('responsaveis[]').map(Number),
            };

            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;

            fetch('api/create_task.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            })
                .then((res) => res.json())
                .then((data) => {
                    if (!data.success) throw new Error(data.error || 'Não foi possível criar a tarefa.');
                    addCardToBoard(data.task);
                    form.reset();
                    const modalEl = document.getElementById('modalNovaTarefa');
                    bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                })
                .catch((err) => {
                    alert(err.message);
                })
                .finally(() => {
                    submitBtn.disabled = false;
                });
        });
    }

    function addCardToBoard(task) {
        const columnBody = board.querySelector(`[data-column-body="${task.coluna}"]`);
        if (!columnBody) return;

        const emptyState = columnBody.querySelector('.board-column__empty');
        if (emptyState) emptyState.remove();

        const card = buildCardElement(task);
        columnBody.prepend(card);
        updateColumnCounts();
    }

    function buildCardElement(task) {
        const priorityLabels = { alta: 'Alta', media: 'Média', baixa: 'Baixa' };
        const priorityClass = `priority-${task.prioridade}`;

        const card = document.createElement('article');
        card.className = `task-card ${priorityClass}`;
        card.draggable = true;
        card.tabIndex = 0;
        card.dataset.taskId = String(task.id);
        card.dataset.column = task.coluna;
        card.dataset.assignees = (task.responsaveis || []).join(',');

        const badge = document.createElement('span');
        badge.className = `task-card__badge badge-${priorityClass}`;
        badge.textContent = priorityLabels[task.prioridade] || task.prioridade;
        card.appendChild(badge);

        const title = document.createElement('h3');
        title.className = 'task-card__title';
        title.textContent = task.titulo;
        card.appendChild(title);

        if (task.descricao) {
            const desc = document.createElement('p');
            desc.className = 'task-card__desc';
            desc.textContent = task.descricao;
            card.appendChild(desc);
        }

        const footer = document.createElement('footer');
        footer.className = 'task-card__footer';

        const dateEl = document.createElement('span');
        dateEl.className = 'task-card__date';
        dateEl.innerHTML = `<i class="bi bi-calendar-event" aria-hidden="true"></i> ${formatDateShort(task.prazo)}`;
        footer.appendChild(dateEl);

        const meta = document.createElement('span');
        meta.className = 'task-card__meta';
        meta.appendChild(buildAvatarStack(task.responsaveis || []));
        footer.appendChild(meta);

        card.appendChild(footer);
        return card;
    }

    function buildAvatarStack(ids) {
        const stack = document.createElement('span');
        stack.className = 'avatar-stack';
        ids.forEach((id) => {
            const member = team.find((m) => m.id === Number(id));
            if (!member) return;
            const avatar = document.createElement('span');
            avatar.className = 'avatar avatar-sm rounded-circle';
            avatar.style.backgroundColor = member.cor;
            avatar.title = member.nome;
            avatar.textContent = member.iniciais;
            stack.appendChild(avatar);
        });
        return stack;
    }

    function formatDateShort(isoDate) {
        const meses = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
        const [ano, mes, dia] = isoDate.split('-').map(Number);
        return `${dia} ${meses[mes - 1]}`;
    }
})();
