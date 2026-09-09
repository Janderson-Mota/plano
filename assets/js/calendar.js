/**
 * calendar.js
 * O PHP renderiza o mês atual no primeiro carregamento. A partir daqui,
 * a navegação entre meses e o painel de detalhes do dia são resolvidos
 * inteiramente no cliente, reaproveitando os mesmos dados (tarefas,
 * equipe e colunas) embutidos em <script id="calendar-data">.
 */
(function () {
    'use strict';

    const grid = document.getElementById('calendarGrid');
    const label = document.getElementById('calendarLabel');
    if (!grid || !label) return;

    const dataEl = document.getElementById('calendar-data');
    const { tasks = [], team = [], columns = [] } = dataEl ? JSON.parse(dataEl.textContent) : {};

    const MESES = [
        'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
        'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
    ];
    const PRIORITY_LABELS = { alta: 'Alta', media: 'Média', baixa: 'Baixa' };

    let ano = Number(label.dataset.year);
    let mes = Number(label.dataset.month); // 1-12

    const offcanvasEl = document.getElementById('offcanvasDia');
    const offcanvasBody = document.getElementById('offcanvasDiaBody');
    const offcanvasTitle = document.getElementById('offcanvasDiaLabel');
    const offcanvas = offcanvasEl ? bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl) : null;

    document.getElementById('btnMesAnterior')?.addEventListener('click', () => {
        mes -= 1;
        if (mes < 1) { mes = 12; ano -= 1; }
        renderMonth();
    });

    document.getElementById('btnMesProximo')?.addEventListener('click', () => {
        mes += 1;
        if (mes > 12) { mes = 1; ano += 1; }
        renderMonth();
    });

    document.getElementById('btnHoje')?.addEventListener('click', () => {
        const hoje = new Date();
        ano = hoje.getFullYear();
        mes = hoje.getMonth() + 1;
        renderMonth();
    });

    // Delegação de clique nos dias, funciona para os dias renderizados
    // tanto pelo PHP quanto pelos re-renderizados via JS.
    grid.addEventListener('click', (event) => {
        const dayButton = event.target.closest('.calendar-day:not(.is-empty)');
        if (dayButton) openDayDetails(dayButton.dataset.date);
    });

    function renderMonth() {
        const hojeIso = toIso(new Date());
        const primeiroDiaSemana = new Date(ano, mes - 1, 1).getDay(); // 0 dom - 6 sáb
        const diasNoMes = new Date(ano, mes, 0).getDate();

        // Remove apenas as células de dia, mantendo o cabeçalho de dias da semana.
        grid.querySelectorAll('.calendar-day').forEach((el) => el.remove());

        const fragment = document.createDocumentFragment();

        for (let i = 0; i < primeiroDiaSemana; i++) {
            const blank = document.createElement('div');
            blank.className = 'calendar-day is-empty';
            blank.setAttribute('aria-hidden', 'true');
            fragment.appendChild(blank);
        }

        for (let dia = 1; dia <= diasNoMes; dia++) {
            const iso = `${ano}-${String(mes).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
            const tarefasDoDia = tasks.filter((t) => t.prazo === iso);

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'calendar-day' + (iso === hojeIso ? ' is-today' : '');
            btn.dataset.date = iso;

            const number = document.createElement('span');
            number.className = 'calendar-day__number';
            number.textContent = String(dia);
            btn.appendChild(number);

            const dots = document.createElement('span');
            dots.className = 'calendar-day__dots';
            tarefasDoDia.slice(0, 3).forEach((tarefa) => {
                const dot = document.createElement('span');
                dot.className = `calendar-dot priority-${tarefa.prioridade}`;
                dot.title = tarefa.titulo;
                dots.appendChild(dot);
            });
            if (tarefasDoDia.length > 3) {
                const more = document.createElement('span');
                more.className = 'calendar-day__more';
                more.textContent = `+${tarefasDoDia.length - 3}`;
                dots.appendChild(more);
            }
            btn.appendChild(dots);

            fragment.appendChild(btn);
        }

        grid.appendChild(fragment);
        label.textContent = `${MESES[mes - 1]} de ${ano}`;
        label.dataset.year = String(ano);
        label.dataset.month = String(mes);
    }

    function openDayDetails(iso) {
        if (!offcanvas || !offcanvasBody) return;

        const tarefasDoDia = tasks.filter((t) => t.prazo === iso);
        offcanvasTitle.textContent = formatLongDate(iso);
        offcanvasBody.innerHTML = '';

        if (tarefasDoDia.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'text-muted small';
            empty.textContent = 'Nenhuma tarefa com prazo neste dia.';
            offcanvasBody.appendChild(empty);
        } else {
            tarefasDoDia.forEach((tarefa) => offcanvasBody.appendChild(buildDayTaskItem(tarefa)));
        }

        offcanvas.show();
    }

    function buildDayTaskItem(tarefa) {
        const coluna = columns.find((c) => c.id === tarefa.coluna);

        const item = document.createElement('div');
        item.className = 'day-task';

        const bar = document.createElement('span');
        bar.className = `day-task__bar priority-${tarefa.prioridade}`;
        item.appendChild(bar);

        const content = document.createElement('div');
        content.className = 'flex-fill';

        const title = document.createElement('p');
        title.className = 'day-task__title';
        title.textContent = tarefa.titulo;
        content.appendChild(title);

        if (tarefa.descricao) {
            const desc = document.createElement('p');
            desc.className = 'day-task__desc';
            desc.textContent = tarefa.descricao;
            content.appendChild(desc);
        }

        const metaRow = document.createElement('div');
        metaRow.className = 'd-flex align-items-center justify-content-between';

        const statusBadge = document.createElement('span');
        statusBadge.className = 'task-card__badge';
        statusBadge.style.color = coluna ? coluna.cor : '#94A3B8';
        statusBadge.style.background = 'rgba(15, 23, 42, .05)';
        statusBadge.textContent = coluna ? coluna.titulo : tarefa.coluna;
        metaRow.appendChild(statusBadge);

        const avatars = document.createElement('span');
        avatars.className = 'avatar-stack';
        (tarefa.responsaveis || []).forEach((id) => {
            const membro = team.find((m) => m.id === id);
            if (!membro) return;
            const avatar = document.createElement('span');
            avatar.className = 'avatar avatar-sm rounded-circle';
            avatar.style.backgroundColor = membro.cor;
            avatar.title = membro.nome;
            avatar.textContent = membro.iniciais;
            avatars.appendChild(avatar);
        });
        metaRow.appendChild(avatars);

        content.appendChild(metaRow);
        item.appendChild(content);
        return item;
    }

    function toIso(date) {
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    }

    function formatLongDate(iso) {
        const [y, m, d] = iso.split('-').map(Number);
        return `${d} de ${MESES[m - 1]}`;
    }
})();
