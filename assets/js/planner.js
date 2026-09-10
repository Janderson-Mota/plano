/**
 * planner.js — Planner · Script Unificado e Centralizado
 *
 * Módulo de Gestão de Equipes e Tarefas (Vanilla JS, DOM API, Fetch com CSRF)
 *
 * ESTRUTURA E SEÇÕES POR VISÃO/PÁGINA:
 *  - § 1 · GERAL: Inicialização, Leitura de Dados (#planner-data) e API Fetch (ajax/index.php)
 *  - § 2 · GERAL: Helpers de Dados, Formatação e Toasts
 *  - § 3 · NAVEGAÇÃO: Gerenciamento de Visões (Kanban, Calendário, Dashboard, Lista, Minhas Tarefas)
 *  - § 4 · BARRA DE FILTROS: Filtros Transversais Flutuantes (Busca, Multi-selects, Datas)
 *  - § 5 · VISÃO 1 - KANBAN: Quadro de Tarefas & Drag and Drop Nativo (HTML5 DnD API)
 *  - § 6 · VISÃO 1 - KANBAN: Menu de Movimentação Acessível por Teclado/Clique
 *  - § 7 · MODAIS GLOBAIS: Detalhes da Tarefa, Grupo de Responsáveis e Atribuição
 *  - § 8 · MODAIS GLOBAIS: Thread de Comentários (Criar, Editar, Excluir com Timestamps)
 *  - § 9 · MODAIS GLOBAIS: Formulário de Nova Tarefa (#plannerFormNovaTarefa)
 *  - § 10 · MODAIS GLOBAIS: Formulário de Nova Equipe (#plannerFormNovaEquipe)
 *  - § 11 · RECURSOS GLOBAIS: Busca Rápida Spotlight (Ctrl+K / Cmd+K)
 *  - § 12 · VISÃO 3 - DASHBOARD: Gráficos Interativos (Chart.js)
 *  - § 13 · RECURSOS GLOBAIS: Exportação de Dados em CSV (UTF-8 com BOM)
 *  - § 14 · VISÃO 2 - CALENDÁRIO: Navegação de Meses, Grid Dinâmico e Painel Offcanvas
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', initPlanner);

    function initPlanner() {
        const dataEl = document.getElementById('planner-data');
        if (!dataEl) return;

        let initialData;
        try {
            initialData = JSON.parse(dataEl.textContent);
        } catch (e) {
            console.error('Planner: Falha ao interpretar JSON inicial:', e);
            return;
        }

        // Estado reativo da aplicação
        const state = {
            usuarioAtual: initialData.usuarioAtual || {},
            usuarios: initialData.usuarios || [],
            equipes: initialData.equipes || [],
            colunas: initialData.colunas || [],
            tarefas: initialData.tarefas || [],
            comentarios: initialData.comentarios || [],
            atividades: initialData.atividades || [],
            filtros: {
                texto: initialData.filtros?.texto || '',
                equipes: initialData.filtros?.equipe ? [Number(initialData.filtros.equipe)] : [],
                responsaveis: initialData.filtros?.usuario ? [Number(initialData.filtros.usuario)] : [],
                prioridades: initialData.filtros?.prioridade ? [initialData.filtros.prioridade] : [],
                dataInicio: initialData.filtros?.data_inicio || '',
                dataFim: initialData.filtros?.data_fim || '',
                prazo: initialData.filtros?.prazo || '',
            },
            currentView: document.documentElement.dataset.plannerView || 'kanban',
            activeCharts: {}
        };

        // Comunicação com API (POST JSON)
        async function plannerApi(action, payload = {}) {
            try {
                const response = await fetch('ajax/index.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ action, ...payload })
                });

                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.error || `Erro HTTP ${response.status}`);
                }
                return result.data;
            } catch (err) {
                console.error(`Planner API [${action}]:`, err);
                showToast(err.message || 'Ocorreu um erro na requisição.', 'danger');
                throw err;
            }
        }

        // 
        function getUsuario(id) {
            return state.usuarios.find(u => u.id === Number(id)) || null;
        }

        function getColuna(id) {
            return state.colunas.find(c => c.id === id) || null;
        }

        function getEquipe(id) {
            return state.equipes.find(e => e.id === Number(id)) || null;
        }

        function getMembrosEquipeEFilhas(equipeId) {
            const eqId = Number(equipeId);
            if (!eqId) return [];
            const resultIds = new Set();

            function coletar(id) {
                const eq = getEquipe(id);
                if (!eq) return;
                (eq.membros || []).forEach(m => resultIds.add(m));
                state.equipes.filter(filha => filha.pai_id === id).forEach(filha => coletar(filha.id));
            }

            coletar(eqId);
            return Array.from(resultIds);
        }

        function calcularStatusPrazo(prazoIso) {
            if (!prazoIso) return 'futura';
            const hoje = new Date();
            hoje.setHours(0, 0, 0, 0);

            const [ano, mes, dia] = prazoIso.split('-').map(Number);
            const prazo = new Date(ano, mes - 1, dia);
            prazo.setHours(0, 0, 0, 0);

            const diff = prazo.getTime() - hoje.getTime();
            if (diff < 0) return 'atrasada';
            if (diff === 0) return 'hoje';
            return 'futura';
        }

        function formatRelativeTime(dateStr) {
            if (!dateStr) return '';
            const data = new Date(dateStr.replace(' ', 'T'));
            const agora = new Date();
            const diffSec = Math.floor((agora.getTime() - data.getTime()) / 1000);

            if (diffSec < 60) return 'agora há pouco';
            const diffMin = Math.floor(diffSec / 60);
            if (diffMin < 60) return `há ${diffMin} min`;
            const diffH = Math.floor(diffMin / 60);
            if (diffH < 24) return `há ${diffH} ${diffH === 1 ? 'hora' : 'horas'}`;
            const diffDias = Math.floor(diffH / 24);
            if (diffDias === 1) return 'ontem';
            if (diffDias < 7) return `há ${diffDias} dias`;

            const dia = String(data.getDate()).padStart(2, '0');
            const mes = String(data.getMonth() + 1).padStart(2, '0');
            const ano = data.getFullYear();
            return `${dia}/${mes}/${ano}`;
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function showToast(mensagem, tipo = 'primary') {
            let container = document.getElementById('plannerToastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'plannerToastContainer';
                container.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem;pointer-events:none;';
                document.body.appendChild(container);
            }

            const toast = document.createElement('div');
            toast.className = `alert alert-${tipo} py-2 px-3 m-0 shadow-lg`;
            toast.style.cssText = 'pointer-events:auto;min-width:240px;border-radius:10px;font-size:.85rem;animation:plannerFadeIn 0.25s ease;';
            toast.textContent = mensagem;
            container.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity .3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3500);
        }

        // 
        function switchView(viewName, updateUrl = true) {
            const validViews = ['kanban', 'calendario', 'dashboard', 'lista', 'minhas-tarefas'];
            if (!validViews.includes(viewName)) viewName = 'kanban';

            state.currentView = viewName;
            document.documentElement.dataset.plannerView = viewName;

            // Atualiza links de navegação
            document.querySelectorAll('[data-view-link]').forEach(link => {
                const isActive = link.dataset.viewLink === viewName;
                link.classList.toggle('is-active', isActive);
                link.setAttribute('aria-current', isActive ? 'page' : 'false');
            });

            // Atualiza containers de view
            document.querySelectorAll('.planner-view').forEach(viewEl => {
                const isActive = viewEl.dataset.view === viewName;
                viewEl.classList.toggle('is-active', isActive);
            });

            // Ações específicas de cada view
            if (viewName === 'dashboard') {
                renderDashboardCharts();
            } else if (viewName === 'calendario') {
                renderPlannerCalendar();
            }

            if (updateUrl) {
                const url = new URL(window.location.href);
                url.searchParams.set('view', viewName);
                window.history.pushState({ view: viewName }, '', url);
            }
        }

        // Intercepta cliques nos links de view
        document.querySelectorAll('[data-view-link]').forEach(link => {
            link.addEventListener('click', e => {
                e.preventDefault();
                switchView(link.dataset.viewLink);
            });
        });

        window.addEventListener('popstate', () => {
            const url = new URL(window.location.href);
            const viewFromUrl = url.searchParams.get('view') || 'kanban';
            switchView(viewFromUrl, false);
        });

        // 
        const filtroTexto = document.getElementById('filtroTexto');
        const filtroDataInicio = document.getElementById('filtroDataInicio');
        const filtroDataFim = document.getElementById('filtroDataFim');
        const btnLimparFiltros = document.getElementById('btnLimparFiltros');

        // Configurações dos 3 dropdowns multi-selecionáveis
        const multiselects = [
            {
                id: 'msEquipes',
                btnId: 'btnFiltroEquipes',
                dropdownId: 'dropdownFiltroEquipes',
                badgeId: 'badgeFiltroEquipes',
                inputName: 'filtro_equipes[]',
                key: 'equipes',
                isNumber: true
            },
            {
                id: 'msResponsaveis',
                btnId: 'btnFiltroResponsaveis',
                dropdownId: 'dropdownFiltroResponsaveis',
                badgeId: 'badgeFiltroResponsaveis',
                inputName: 'filtro_responsaveis[]',
                key: 'responsaveis',
                isNumber: true
            },
            {
                id: 'msPrioridades',
                btnId: 'btnFiltroPrioridades',
                dropdownId: 'dropdownFiltroPrioridades',
                badgeId: 'badgeFiltroPrioridades',
                inputName: 'filtro_prioridades[]',
                key: 'prioridades',
                isNumber: false
            }
        ];

        // Fechar todos os dropdowns abertos
        function closeAllDropdowns() {
            document.querySelectorAll('.planner-multiselect').forEach(ms => {
                ms.classList.remove('is-open');
                const dd = ms.querySelector('.planner-multiselect__dropdown');
                if (dd) {
                    dd.hidden = true;
                    dd.setAttribute('hidden', '');
                }
                const btn = ms.querySelector('.planner-multiselect__btn');
                if (btn) btn.setAttribute('aria-expanded', 'false');
            });
        }

        // Inicializar eventos de cada multiselect
        multiselects.forEach(cfg => {
            const container = document.getElementById(cfg.id);
            const btn = document.getElementById(cfg.btnId);
            const dropdown = document.getElementById(cfg.dropdownId);
            const badge = document.getElementById(cfg.badgeId);

            if (!container || !btn || !dropdown) return;

            // Abrir / fechar toggle
            btn.addEventListener('click', e => {
                e.stopPropagation();
                const isOpen = container.classList.contains('is-open');
                closeAllDropdowns();
                if (!isOpen) {
                    container.classList.add('is-open');
                    dropdown.hidden = false;
                    dropdown.removeAttribute('hidden');
                    btn.setAttribute('aria-expanded', 'true');
                }
            });

            // Clique dentro do dropdown não propaga para fechar
            dropdown.addEventListener('click', e => {
                e.stopPropagation();
            });

            // Checkboxes com delegação para suportar elementos criados dinamicamente
            function updateSelection() {
                const currentBoxes = dropdown.querySelectorAll(`input[name="${cfg.inputName}"]`);
                const checkedBoxes = Array.from(currentBoxes).filter(cb => cb.checked);
                const values = checkedBoxes.map(cb => cfg.isNumber ? Number(cb.value) : cb.value);
                state.filtros[cfg.key] = values;

                if (badge) {
                    if (values.length > 0) {
                        badge.textContent = String(values.length);
                        badge.classList.remove('d-none');
                    } else {
                        badge.classList.add('d-none');
                    }
                }
                syncFilters();
            }

            dropdown.addEventListener('change', e => {
                if (e.target && e.target.matches(`input[name="${cfg.inputName}"]`)) {
                    updateSelection();
                }
            });

            // Botão Limpar específico deste dropdown
            const clearLink = dropdown.querySelector('.planner-multiselect__clear-link');
            if (clearLink) {
                clearLink.addEventListener('click', e => {
                    e.preventDefault();
                    dropdown.querySelectorAll(`input[name="${cfg.inputName}"]`).forEach(cb => { cb.checked = false; });
                    updateSelection();
                });
            }

            // Marca checkboxes caso já haja valores pré-selecionados
            if (Array.isArray(state.filtros[cfg.key]) && state.filtros[cfg.key].length > 0) {
                dropdown.querySelectorAll(`input[name="${cfg.inputName}"]`).forEach(cb => {
                    const val = cfg.isNumber ? Number(cb.value) : cb.value;
                    if (state.filtros[cfg.key].includes(val)) {
                        cb.checked = true;
                    }
                });
                if (badge) {
                    badge.textContent = String(state.filtros[cfg.key].length);
                    badge.classList.remove('d-none');
                }
            }
        });

        // Fechar dropdowns ao clicar em qualquer outra parte da página
        document.addEventListener('click', () => {
            closeAllDropdowns();
        });

        // Fechar com a tecla Escape
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeAllDropdowns();
            }
        });

        // Campo de busca textual com debounce
        let debounceTimer;
        if (filtroTexto) {
            filtroTexto.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    state.filtros.texto = filtroTexto.value.trim().toLowerCase();
                    syncFilters();
                }, 200);
            });
        }

        // Filtro de Data Início e Fim
        if (filtroDataInicio) {
            filtroDataInicio.addEventListener('change', () => {
                state.filtros.dataInicio = filtroDataInicio.value;
                syncFilters();
            });
        }

        if (filtroDataFim) {
            filtroDataFim.addEventListener('change', () => {
                state.filtros.dataFim = filtroDataFim.value;
                syncFilters();
            });
        }

        // Botão Limpar Filtros Global
        if (btnLimparFiltros) {
            btnLimparFiltros.addEventListener('click', () => {
                state.filtros.texto = '';
                state.filtros.equipes = [];
                state.filtros.responsaveis = [];
                state.filtros.prioridades = [];
                state.filtros.dataInicio = '';
                state.filtros.dataFim = '';
                state.filtros.prazo = '';

                if (filtroTexto) filtroTexto.value = '';
                if (filtroDataInicio) filtroDataInicio.value = '';
                if (filtroDataFim) filtroDataFim.value = '';

                // Desmarcar todos os checkboxes dos multiselects
                multiselects.forEach(cfg => {
                    const dropdown = document.getElementById(cfg.dropdownId);
                    const badge = document.getElementById(cfg.badgeId);
                    if (dropdown) {
                        dropdown.querySelectorAll(`input[name="${cfg.inputName}"]`).forEach(cb => {
                            cb.checked = false;
                        });
                    }
                    if (badge) badge.classList.add('d-none');
                });

                closeAllDropdowns();
                syncFilters();
            });
        }

        function taskMatchesFilters(tarefa) {
            const f = state.filtros;

            // 1. Busca textual no título e descrição
            if (f.texto) {
                const titulo = (tarefa.titulo || '').toLowerCase();
                const desc = (tarefa.descricao || '').toLowerCase();
                if (!titulo.includes(f.texto) && !desc.includes(f.texto)) return false;
            }

            // 2. Filtro de Prioridades (múltiplas permitidas)
            if (Array.isArray(f.prioridades) && f.prioridades.length > 0) {
                if (!f.prioridades.includes(tarefa.prioridade)) return false;
            }

            // 3. Filtro de Responsáveis (múltiplos permitidos — usuário marca um ou vários)
            if (Array.isArray(f.responsaveis) && f.responsaveis.length > 0) {
                const resps = (tarefa.responsaveis || []).map(Number);
                const hasAssignee = f.responsaveis.some(uId => resps.includes(uId));
                if (!hasAssignee) return false;
            }

            // 4. Filtro de Equipes (múltiplas permitidas — tarefa pode ter uma ou várias equipes)
            if (Array.isArray(f.equipes) && f.equipes.length > 0) {
                const taskEqs = (tarefa.equipes || []).map(Number);
                const matchDirect = f.equipes.some(eqId => taskEqs.includes(eqId));
                if (!matchDirect) {
                    // Fallback: verificar se membros da equipe estão nos responsáveis da tarefa
                    const allowedMembers = f.equipes.flatMap(eqId => getMembrosEquipeEFilhas(eqId));
                    const taskResps = (tarefa.responsaveis || []).map(Number);
                    const matchMember = taskResps.some(r => allowedMembers.includes(r));
                    if (!matchMember) return false;
                }
            }

            // 5. Filtro de Intervalo de Data (Data Início e Data Fim)
            if (f.dataInicio && tarefa.prazo) {
                if (tarefa.prazo < f.dataInicio) return false;
            }
            if (f.dataFim && tarefa.prazo) {
                if (tarefa.prazo > f.dataFim) return false;
            }

            // 6. Legado: filtro de prazo único se existir
            if (f.prazo && tarefa.prazo) {
                if (tarefa.prazo > f.prazo) return false;
            }

            return true;
        }

        function syncFilters() {
            // Sincroniza querystring
            const url = new URL(window.location.href);
            if (state.filtros.texto) url.searchParams.set('q', state.filtros.texto);
            else url.searchParams.delete('q');

            if (state.filtros.dataInicio) url.searchParams.set('data_inicio', state.filtros.dataInicio);
            else url.searchParams.delete('data_inicio');

            if (state.filtros.dataFim) url.searchParams.set('data_fim', state.filtros.dataFim);
            else url.searchParams.delete('data_fim');

            if (state.filtros.equipes.length === 1) url.searchParams.set('equipe', String(state.filtros.equipes[0]));
            else url.searchParams.delete('equipe');

            if (state.filtros.responsaveis.length === 1) url.searchParams.set('usuario', String(state.filtros.responsaveis[0]));
            else url.searchParams.delete('usuario');

            if (state.filtros.prioridades.length === 1) url.searchParams.set('prioridade', state.filtros.prioridades[0]);
            else url.searchParams.delete('prioridade');

            window.history.replaceState({ view: state.currentView }, '', url);

            // Aplica na View Kanban
            const cards = document.querySelectorAll('#planner-board .task-card');
            cards.forEach(card => {
                const id = Number(card.dataset.taskId);
                const tarefa = state.tarefas.find(t => t.id === id);
                if (!tarefa) return;
                const match = taskMatchesFilters(tarefa);
                card.style.display = match ? '' : 'none';
            });
            updateColumnCounts();

            // Aplica na View Lista
            const rows = document.querySelectorAll('#plannerTabelaBody .planner-table__row');
            let visiveisLista = 0;
            rows.forEach(row => {
                const id = Number(row.dataset.taskId);
                const tarefa = state.tarefas.find(t => t.id === id);
                if (!tarefa) return;
                const match = taskMatchesFilters(tarefa);
                row.style.display = match ? '' : 'none';
                if (match) visiveisLista++;
            });
            const listaCount = document.getElementById('listaCount');
            if (listaCount) listaCount.textContent = `${visiveisLista} tarefa(s)`;

            // Re-renderiza dashboard ou calendário se estiver ativo
            if (state.currentView === 'dashboard') {
                renderDashboardCharts();
            } else if (state.currentView === 'calendario') {
                renderPlannerCalendar();
            }
        }

        function updateColumnCounts() {
            document.querySelectorAll('#planner-board .board-col').forEach(col => {
                const colId = col.dataset.columnId;
                const visibleCount = col.querySelectorAll(`.task-card:not([style*="display: none"])`).length;
                const countBadge = col.querySelector(`[data-count-for="${colId}"]`);
                if (countBadge) countBadge.textContent = String(visibleCount);
            });
        }

        // 
        const board = document.getElementById('planner-board');
        if (board) {
            board.addEventListener('dragstart', e => {
                const card = e.target.closest('.task-card');
                if (!card) return;
                card.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', card.dataset.taskId);
            });

            board.addEventListener('dragend', e => {
                const card = e.target.closest('.task-card');
                if (card) card.classList.remove('is-dragging');
            });

            board.querySelectorAll('.board-col__body').forEach(colBody => {
                colBody.addEventListener('dragover', e => {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    colBody.classList.add('drag-over');
                });

                colBody.addEventListener('dragleave', e => {
                    if (!colBody.contains(e.relatedTarget)) {
                        colBody.classList.remove('drag-over');
                    }
                });

                colBody.addEventListener('drop', async e => {
                    e.preventDefault();
                    colBody.classList.remove('drag-over');

                    const taskId = Number(e.dataTransfer.getData('text/plain'));
                    if (!taskId) return;

                    const card = board.querySelector(`.task-card[data-task-id="${taskId}"]`);
                    if (!card) return;

                    const originCol = card.dataset.column;
                    const targetCol = colBody.dataset.columnBody;
                    if (originCol === targetCol) return;

                    // Movimentação otimista
                    colBody.appendChild(card);
                    card.dataset.column = targetCol;
                    updateColumnCounts();

                    try {
                        await plannerApi('mover_tarefa', { task_id: taskId, coluna_id: targetCol });
                        const tarefa = state.tarefas.find(t => t.id === taskId);
                        if (tarefa) tarefa.coluna_id = targetCol;
                        showToast('Tarefa movida com sucesso!', 'success');
                    } catch (err) {
                        // Reverte em caso de falha
                        const originBody = board.querySelector(`[data-column-body="${originCol}"]`);
                        if (originBody) {
                            originBody.appendChild(card);
                            card.dataset.column = originCol;
                            updateColumnCounts();
                        }
                    }
                });
            });
        }

        // 
        const moverMenu = document.getElementById('planner-mover-menu');
        let currentMoverBtn = null;

        document.addEventListener('click', e => {
            const moverBtn = e.target.closest('[data-action="mover"]');
            if (moverBtn) {
                e.stopPropagation();
                openMoverMenu(moverBtn);
                return;
            }

            if (moverMenu && !moverMenu.contains(e.target)) {
                closeMoverMenu();
            }
        });

        function openMoverMenu(btn) {
            if (!moverMenu) return;
            const taskId = btn.dataset.taskId;
            moverMenu.dataset.taskId = taskId;
            currentMoverBtn = btn;

            const rect = btn.getBoundingClientRect();
            moverMenu.style.position = 'fixed';
            moverMenu.style.top = `${rect.bottom + 6}px`;
            moverMenu.style.left = `${Math.min(rect.left, window.innerWidth - 220)}px`;
            moverMenu.removeAttribute('hidden');

            const firstItem = moverMenu.querySelector('.planner-move-menu__item');
            if (firstItem) firstItem.focus();
        }

        function closeMoverMenu() {
            if (!moverMenu) return;
            moverMenu.setAttribute('hidden', '');
            if (currentMoverBtn) {
                currentMoverBtn.focus();
                currentMoverBtn = null;
            }
        }

        if (moverMenu) {
            moverMenu.addEventListener('click', async e => {
                const item = e.target.closest('.planner-move-menu__item');
                if (!item) return;

                const taskId = Number(moverMenu.dataset.taskId);
                const targetCol = item.dataset.moveTo;
                closeMoverMenu();

                if (!taskId || !targetCol) return;

                const card = document.querySelector(`.task-card[data-task-id="${taskId}"]`);
                const originCol = card ? card.dataset.column : null;
                const targetBody = document.querySelector(`[data-column-body="${targetCol}"]`);

                if (card && targetBody && originCol !== targetCol) {
                    targetBody.appendChild(card);
                    card.dataset.column = targetCol;
                    updateColumnCounts();
                }

                try {
                    await plannerApi('mover_tarefa', { task_id: taskId, coluna_id: targetCol });
                    const tarefa = state.tarefas.find(t => t.id === taskId);
                    if (tarefa) tarefa.coluna_id = targetCol;
                    showToast('Tarefa movida com sucesso!', 'success');
                } catch (err) {
                    if (card && originCol) {
                        const originBody = document.querySelector(`[data-column-body="${originCol}"]`);
                        if (originBody) {
                            originBody.appendChild(card);
                            card.dataset.column = originCol;
                            updateColumnCounts();
                        }
                    }
                }
            });

            moverMenu.addEventListener('keydown', e => {
                const items = Array.from(moverMenu.querySelectorAll('.planner-move-menu__item'));
                const currentIndex = items.indexOf(document.activeElement);

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    const next = items[(currentIndex + 1) % items.length];
                    if (next) next.focus();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    const prev = items[(currentIndex - 1 + items.length) % items.length];
                    if (prev) prev.focus();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    closeMoverMenu();
                }
            });
        }

        // 
        const modalTarefaEl = document.getElementById('planner-modal-tarefa');
        let modalTarefaInstance = null;
        if (modalTarefaEl && window.bootstrap?.Modal) {
            modalTarefaInstance = window.bootstrap.Modal.getOrCreateInstance(modalTarefaEl);
        }

        document.addEventListener('click', e => {
            const detalheBtn = e.target.closest('[data-action="detalhe"]') ||
                               (e.target.closest('.task-card') && !e.target.closest('button'));
            if (detalheBtn) {
                const taskId = Number(detalheBtn.dataset.taskId || detalheBtn.closest('.task-card')?.dataset.taskId);
                if (taskId) openTaskDetail(taskId);
            }
        });

        function openTaskDetail(taskId) {
            const tarefa = state.tarefas.find(t => t.id === taskId);
            if (!tarefa) return;

            const modalTitle = document.getElementById('plannerModalTarefaLabel');
            const prioBadge = document.getElementById('plannerModalPrioBadge');
            const colunaSpan = document.getElementById('plannerModalColuna')?.querySelector('span');
            const prazoSpan = document.getElementById('plannerModalPrazo')?.querySelector('span');
            const criadorSpan = document.getElementById('plannerModalCriador')?.querySelector('span');
            const descP = document.getElementById('plannerModalDesc');
            const assigneesDiv = document.getElementById('plannerModalAssignees');
            const commentTarefaId = document.getElementById('plannerCommentTarefaId');
            const btnMover = document.getElementById('plannerModalBtnMover');

            if (modalTitle) modalTitle.textContent = tarefa.titulo;
            if (prioBadge) {
                prioBadge.className = `task-card__badge badge-priority-${tarefa.prioridade}`;
                prioBadge.textContent = tarefa.prioridade.toUpperCase();
            }

            const col = getColuna(tarefa.coluna_id);
            if (colunaSpan) colunaSpan.textContent = col ? col.titulo : tarefa.coluna_id;

            if (prazoSpan) {
                prazoSpan.textContent = tarefa.prazo ? `Prazo: ${tarefa.prazo}` : 'Sem prazo';
            }

            const criador = getUsuario(tarefa.criado_por);
            if (criadorSpan) {
                criadorSpan.textContent = criador ? `Criado por ${criador.nome}` : 'Planner';
            }

            if (descP) {
                descP.textContent = tarefa.descricao || 'Nenhuma descrição fornecida.';
                descP.classList.toggle('text-muted', !tarefa.descricao);
            }

            if (commentTarefaId) commentTarefaId.value = String(tarefa.id);
            if (btnMover) btnMover.dataset.taskId = String(tarefa.id);

            // Renderiza avatares dos responsáveis atuais
            if (assigneesDiv) {
                assigneesDiv.innerHTML = '';
                const resps = (tarefa.responsaveis || []).map(Number);
                if (resps.length === 0) {
                    assigneesDiv.innerHTML = '<span class="text-muted small">Nenhum responsável atribuído.</span>';
                } else {
                    resps.forEach(uid => {
                        const u = getUsuario(uid);
                        if (!u) return;
                        const badge = document.createElement('span');
                        badge.className = 'badge bg-dark border border-secondary text-light me-1 p-2 align-items-center gap-1 d-inline-flex';
                        badge.innerHTML = `<span class="avatar avatar-xs rounded-circle" style="background:${u.cor}">${escapeHtml(u.iniciais)}</span> ${escapeHtml(u.nome)}`;
                        assigneesDiv.appendChild(badge);
                    });
                }
            }

            // Preenche picker de responsáveis
            const picker = document.getElementById('plannerAssigneePicker');
            if (picker) {
                picker.setAttribute('hidden', '');
                const checkboxes = picker.querySelectorAll('input[name="resp_picker[]"]');
                const resps = (tarefa.responsaveis || []).map(Number);
                checkboxes.forEach(cb => {
                    cb.checked = resps.includes(Number(cb.value));
                    cb.closest('.planner-assignee-option')?.classList.toggle('is-selected', cb.checked);
                });
            }

            renderTaskComments(tarefa.id);
            renderTaskActivities(tarefa.id);

            if (modalTarefaInstance) modalTarefaInstance.show();
        }

        // Edição de responsáveis no modal de detalhe
        const btnEditarResp = document.getElementById('btnEditarResponsaveis');
        const picker = document.getElementById('plannerAssigneePicker');
        const btnSalvarResp = document.getElementById('btnSalvarResponsaveis');
        const btnCancelarResp = document.getElementById('btnCancelarResponsaveis');

        if (btnEditarResp && picker) {
            btnEditarResp.addEventListener('click', () => {
                picker.removeAttribute('hidden');
                btnEditarResp.style.display = 'none';
            });
        }

        if (btnCancelarResp && picker && btnEditarResp) {
            btnCancelarResp.addEventListener('click', () => {
                picker.setAttribute('hidden', '');
                btnEditarResp.style.display = '';
            });
        }

        if (btnSalvarResp && picker) {
            btnSalvarResp.addEventListener('click', async () => {
                const taskId = Number(document.getElementById('plannerCommentTarefaId')?.value);
                if (!taskId) return;

                const selecionados = Array.from(picker.querySelectorAll('input[name="resp_picker[]"]:checked'))
                    .map(cb => Number(cb.value));

                try {
                    await plannerApi('atualizar_responsaveis', { task_id: taskId, responsaveis: selecionados });
                    const tarefa = state.tarefas.find(t => t.id === taskId);
                    if (tarefa) {
                        tarefa.responsaveis = selecionados;
                    }

                    // Atualiza no DOM do card
                    const card = document.querySelector(`.task-card[data-task-id="${taskId}"]`);
                    if (card) {
                        card.dataset.assignees = selecionados.join(',');
                    }

                    showToast('Responsáveis atualizados!', 'success');
                    picker.setAttribute('hidden', '');
                    if (btnEditarResp) btnEditarResp.style.display = '';
                    openTaskDetail(taskId);
                } catch (err) {
                    // Erro tratado por plannerApi
                }
            });
        }

        // 
        function renderTaskComments(taskId) {
            const container = document.getElementById('plannerCommentThread');
            if (!container) return;

            const coms = state.comentarios.filter(c => c.tarefa_id === taskId);
            container.innerHTML = '';

            if (coms.length === 0) {
                container.innerHTML = '<p class="text-muted small">Nenhum comentário ainda. Seja o primeiro a comentar!</p>';
                return;
            }

            coms.forEach(c => {
                const autor = getUsuario(c.usuario_id);
                const isAuthor = Number(c.usuario_id) === Number(state.usuarioAtual.id);

                const item = document.createElement('div');
                item.className = 'planner-comment';
                item.dataset.comentarioId = String(c.id);

                item.innerHTML = `
                    <div class="planner-comment__avatar">
                        <span class="avatar avatar-sm rounded-circle" style="background:${autor?.cor || '#6366f1'}">
                            ${escapeHtml(autor?.iniciais || '?')}
                        </span>
                    </div>
                    <div class="planner-comment__content">
                        <div class="planner-comment__header">
                            <span class="planner-comment__author">${escapeHtml(autor?.nome || 'Usuário')}</span>
                            <span class="planner-comment__time">${formatRelativeTime(c.criado_em)}${c.editado_em ? ' <em class="text-muted">(editado)</em>' : ''}</span>
                            ${isAuthor ? `
                            <div class="planner-comment__actions ms-auto">
                                <button type="button" class="btn btn-link btn-sm p-0 text-muted me-2" data-comment-action="edit">Editar</button>
                                <button type="button" class="btn btn-link btn-sm p-0 text-danger" data-comment-action="delete">Excluir</button>
                            </div>` : ''}
                        </div>
                        <div class="planner-comment__body">${escapeHtml(c.texto)}</div>
                    </div>
                `;

                container.appendChild(item);
            });
        }

        const commentForm = document.getElementById('plannerCommentForm');
        if (commentForm) {
            commentForm.addEventListener('submit', async e => {
                e.preventDefault();
                const taskId = Number(document.getElementById('plannerCommentTarefaId')?.value);
                const textInput = document.getElementById('plannerCommentText');
                const texto = textInput?.value.trim();

                if (!taskId || !texto) return;

                const submitBtn = commentForm.querySelector('button[type="submit"]');
                if (submitBtn) submitBtn.disabled = true;

                try {
                    const res = await plannerApi('criar_comentario', { tarefa_id: taskId, texto });
                    if (res?.comentario) {
                        state.comentarios.push(res.comentario);
                        renderTaskComments(taskId);
                        if (textInput) textInput.value = '';
                        showToast('Comentário enviado!', 'success');
                    }
                } finally {
                    if (submitBtn) submitBtn.disabled = false;
                }
            });
        }

        // Editar/Excluir comentário (delegação)
        document.getElementById('plannerCommentThread')?.addEventListener('click', async e => {
            const editBtn = e.target.closest('[data-comment-action="edit"]');
            const delBtn = e.target.closest('[data-comment-action="delete"]');
            const commentEl = e.target.closest('.planner-comment');
            if (!commentEl) return;

            const comId = Number(commentEl.dataset.comentarioId);
            const comentario = state.comentarios.find(c => c.id === comId);
            if (!comentario) return;

            if (delBtn) {
                if (!confirm('Deseja realmente excluir este comentário?')) return;
                try {
                    await plannerApi('excluir_comentario', { comentario_id: comId });
                    state.comentarios = state.comentarios.filter(c => c.id !== comId);
                    renderTaskComments(comentario.tarefa_id);
                    showToast('Comentário excluído!', 'info');
                } catch (err) {}
            } else if (editBtn) {
                const bodyEl = commentEl.querySelector('.planner-comment__body');
                if (!bodyEl) return;

                const textoOriginal = comentario.texto;
                bodyEl.innerHTML = `
                    <div class="mt-2">
                        <textarea class="form-control form-control-sm mb-2" rows="2">${escapeHtml(textoOriginal)}</textarea>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-primary" id="btnSalvarComEdit">Salvar</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelarComEdit">Cancelar</button>
                        </div>
                    </div>
                `;

                bodyEl.querySelector('#btnCancelarComEdit')?.addEventListener('click', () => {
                    bodyEl.textContent = textoOriginal;
                });

                bodyEl.querySelector('#btnSalvarComEdit')?.addEventListener('click', async () => {
                    const novoTexto = bodyEl.querySelector('textarea')?.value.trim();
                    if (!novoTexto) return;
                    try {
                        const res = await plannerApi('editar_comentario', { comentario_id: comId, texto: novoTexto });
                        comentario.texto = novoTexto;
                        comentario.editado_em = res?.editado_em || new Date().toISOString();
                        renderTaskComments(comentario.tarefa_id);
                        showToast('Comentário atualizado!', 'success');
                    } catch (err) {}
                });
            }
        });

        function renderTaskActivities(taskId) {
            const container = document.getElementById('plannerActivityLog');
            if (!container) return;

            const acts = state.atividades.filter(a => a.tarefa_id === taskId);
            container.innerHTML = '';

            if (acts.length === 0) {
                container.innerHTML = '<li class="text-muted small">Nenhum evento registrado.</li>';
                return;
            }

            acts.forEach(a => {
                const user = getUsuario(a.usuario_id);
                const li = document.createElement('li');
                li.className = 'planner-activity-log__item';

                let desc = 'realizou uma alteração';
                if (a.tipo === 'criacao') desc = 'criou a tarefa';
                else if (a.tipo === 'movimentacao') {
                    const de = a.meta?.de ? (getColuna(a.meta.de)?.titulo || a.meta.de) : '';
                    const para = a.meta?.para ? (getColuna(a.meta.para)?.titulo || a.meta.para) : '';
                    desc = `moveu de «${de}» para «${para}»`;
                } else if (a.tipo === 'atribuicao') {
                    desc = 'atualizou os responsáveis';
                } else if (a.tipo === 'comentario') {
                    desc = 'adicionou um comentário';
                }

                li.innerHTML = `
                    <span class="planner-activity-log__dot"></span>
                    <div class="planner-activity-log__content">
                        <strong>${escapeHtml(user?.nome || 'Usuário')}</strong> ${escapeHtml(desc)}
                        <span class="planner-activity-log__date">${formatRelativeTime(a.criado_em)}</span>
                    </div>
                `;
                container.appendChild(li);
            });
        }

        // 
        const formNova = document.getElementById('plannerFormNovaTarefa');
        if (formNova) {
            formNova.addEventListener('submit', async e => {
                e.preventDefault();

                const tituloInput = document.getElementById('novaTarefaTitulo');
                const descInput = document.getElementById('novaTarefaDescricao');
                const colInput = document.getElementById('novaTarefaColuna');
                const prioInput = document.getElementById('novaTarefaPrioridade');
                const prazoInput = document.getElementById('novaTarefaPrazo');
                const respCheckboxes = formNova.querySelectorAll('input[name="responsaveis[]"]:checked');
                const teamCheckboxes = formNova.querySelectorAll('input[name="equipes[]"]:checked');

                const titulo = tituloInput?.value.trim();
                if (!titulo) {
                    tituloInput?.classList.add('is-invalid');
                    return;
                }
                tituloInput?.classList.remove('is-invalid');

                const payload = {
                    titulo,
                    descricao: descInput?.value.trim() || null,
                    coluna_id: colInput?.value || 'backlog',
                    prioridade: prioInput?.value || 'media',
                    prazo: prazoInput?.value || null,
                    responsaveis: Array.from(respCheckboxes).map(cb => Number(cb.value)),
                    equipes: Array.from(teamCheckboxes).map(cb => Number(cb.value))
                };

                const submitBtn = document.getElementById('plannerBtnCriarTarefa');
                if (submitBtn) submitBtn.disabled = true;

                try {
                    const res = await plannerApi('criar_tarefa', payload);
                    if (res?.tarefa) {
                        res.tarefa.status_prazo = calcularStatusPrazo(res.tarefa.prazo);
                        state.tarefas.push(res.tarefa);

                        // Adiciona card no Kanban
                        const targetBody = document.querySelector(`[data-column-body="${res.tarefa.coluna_id}"]`);
                        if (targetBody) {
                            const newCard = buildKanbanCard(res.tarefa);
                            targetBody.prepend(newCard);
                            updateColumnCounts();
                        }

                        // Fecha modal
                        const modalEl = document.getElementById('planner-modal-nova-tarefa');
                        if (modalEl && window.bootstrap?.Modal) {
                            window.bootstrap.Modal.getInstance(modalEl)?.hide();
                        }
                        formNova.reset();
                        showToast('Nova tarefa criada com sucesso!', 'success');
                    }
                } finally {
                    if (submitBtn) submitBtn.disabled = false;
                }
            });
        }

        // 
        const formNovaEquipe = document.getElementById('plannerFormNovaEquipe');
        if (formNovaEquipe) {
            // Sincronizar swatches com input de cor
            const swatches = formNovaEquipe.querySelectorAll('input[name="cor_preset"]');
            const customColorInput = document.getElementById('novaEquipeCor');

            swatches.forEach(radio => {
                radio.addEventListener('change', () => {
                    if (radio.checked && customColorInput) {
                        customColorInput.value = radio.value;
                    }
                });
            });

            if (customColorInput) {
                customColorInput.addEventListener('input', () => {
                    swatches.forEach(radio => {
                        radio.checked = (radio.value.toLowerCase() === customColorInput.value.toLowerCase());
                    });
                });
            }

            formNovaEquipe.addEventListener('submit', async e => {
                e.preventDefault();

                const nomeInput = document.getElementById('novaEquipeNome');
                const paiSelect = document.getElementById('novaEquipePai');
                const corInput = document.getElementById('novaEquipeCor');
                const membrosCheckboxes = formNovaEquipe.querySelectorAll('input[name="membros[]"]:checked');

                const nome = nomeInput?.value.trim();
                if (!nome) {
                    nomeInput?.classList.add('is-invalid');
                    return;
                }
                nomeInput?.classList.remove('is-invalid');

                const paiId = paiSelect?.value ? Number(paiSelect.value) : null;
                const cor = corInput?.value || '#10B981';
                const membros = Array.from(membrosCheckboxes).map(cb => Number(cb.value));

                const submitBtn = document.getElementById('plannerBtnCriarEquipe');
                if (submitBtn) submitBtn.disabled = true;

                try {
                    const res = await plannerApi('criar_equipe', { nome, cor, pai_id: paiId, membros });
                    if (res?.equipe) {
                        const novaEquipe = res.equipe;
                        state.equipes.push(novaEquipe);

                        // 1. Injeta no dropdown multi-select de Equipes
                        const msList = document.querySelector('#dropdownFiltroEquipes .planner-multiselect__list');
                        if (msList) {
                            const label = document.createElement('label');
                            label.className = 'planner-multiselect__item';
                            label.dataset.teamId = String(novaEquipe.id);
                            label.innerHTML = `
                                <input type="checkbox" name="filtro_equipes[]" value="${novaEquipe.id}">
                                <span class="planner-multiselect__check-custom"></span>
                                <span class="planner-multiselect__text">${escapeHtml(novaEquipe.nome)}</span>
                            `;
                            msList.appendChild(label);
                        }

                        // 2. Injeta no picker de equipes do modal de Nova Tarefa
                        const teamsPicker = document.getElementById('novaTarefaTeamsPicker') || document.querySelector('.planner-teams-picker');
                        if (teamsPicker) {
                            const opt = document.createElement('label');
                            opt.className = 'planner-team-option';
                            opt.dataset.teamId = String(novaEquipe.id);
                            opt.innerHTML = `
                                <input type="checkbox" name="equipes[]" value="${novaEquipe.id}">
                                <span>${escapeHtml(novaEquipe.nome)}</span>
                            `;
                            teamsPicker.appendChild(opt);
                        }

                        // 3. Injeta no select de equipe pai no próprio modal de Nova Equipe
                        if (paiSelect) {
                            const newOption = document.createElement('option');
                            newOption.value = String(novaEquipe.id);
                            newOption.textContent = novaEquipe.nome;
                            paiSelect.appendChild(newOption);
                        }

                        // Fecha o modal
                        const modalEl = document.getElementById('planner-modal-nova-equipe');
                        if (modalEl && window.bootstrap?.Modal) {
                            window.bootstrap.Modal.getInstance(modalEl)?.hide();
                        }
                        formNovaEquipe.reset();
                        showToast(`Equipe "${novaEquipe.nome}" criada com sucesso!`, 'success');
                    }
                } finally {
                    if (submitBtn) submitBtn.disabled = false;
                }
            });
        }

        function buildKanbanCard(t) {
            const prioLabels = { urgente: 'URGENTE', alta: 'ALTA', media: 'MÉDIA', baixa: 'BAIXA' };
            const article = document.createElement('article');
            article.className = `task-card priority-${t.prioridade} sp-${t.status_prazo || 'futura'}`;
            article.draggable = true;
            article.tabIndex = 0;
            article.dataset.taskId = String(t.id);
            article.dataset.column = t.coluna_id;
            article.dataset.priority = t.prioridade;
            article.dataset.assignees = (t.responsaveis || []).join(',');
            article.dataset.teams = (t.equipes || []).join(',');

            const equipesHtml = (t.equipes || []).map(eqId => {
                const eq = getEquipe(eqId);
                return eq ? `<span class="task-card__team-badge">${escapeHtml(eq.nome)}</span>` : '';
            }).join('');

            article.innerHTML = `
                <div class="task-card__top">
                    <span class="task-card__badge badge-priority-${t.prioridade}">
                        ${prioLabels[t.prioridade] || t.prioridade}
                    </span>
                    <div class="task-card__actions" role="group">
                        <button type="button" class="task-card__action-btn" data-action="mover" data-task-id="${t.id}" aria-label="Mover tarefa" tabindex="-1">
                            <i class="bi bi-arrows-move" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="task-card__action-btn" data-action="detalhe" data-task-id="${t.id}" aria-label="Ver detalhes" tabindex="-1">
                            <i class="bi bi-three-dots" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <h3 class="task-card__title">${escapeHtml(t.titulo)}</h3>
                ${equipesHtml ? `<div class="task-card__teams">${equipesHtml}</div>` : ''}
                ${t.descricao ? `<p class="task-card__desc">${escapeHtml(t.descricao)}</p>` : ''}
                <footer class="task-card__footer">
                    <span class="task-card__date">
                        <i class="bi bi-calendar-event" aria-hidden="true"></i> ${t.prazo ? escapeHtml(t.prazo) : 'Sem prazo'}
                    </span>
                    <span class="task-card__meta">
                        <span class="avatar-stack">
                            ${(t.responsaveis || []).map(uid => {
                                const u = getUsuario(uid);
                                return u ? `<span class="avatar avatar-xs rounded-circle" style="background:${u.cor}" title="${escapeHtml(u.nome)}">${escapeHtml(u.iniciais)}</span>` : '';
                            }).join('')}
                        </span>
                    </span>
                </footer>
            `;
            return article;
        }

        // 
        const spotlight = document.getElementById('planner-busca-global');
        const spotlightInput = document.getElementById('plannerSpotlightInput');
        const spotlightResults = document.getElementById('plannerSpotlightResults');
        const btnSpotlight = document.getElementById('btnSpotlight');

        function openSpotlight() {
            if (!spotlight || !spotlightInput) return;
            spotlight.removeAttribute('hidden');
            spotlightInput.value = '';
            renderSpotlightResults('');
            spotlightInput.focus();
        }

        function closeSpotlight() {
            if (!spotlight) return;
            spotlight.setAttribute('hidden', '');
        }

        document.addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                if (spotlight && !spotlight.hasAttribute('hidden')) closeSpotlight();
                else openSpotlight();
            } else if (e.key === 'Escape' && spotlight && !spotlight.hasAttribute('hidden')) {
                closeSpotlight();
            }
        });

        if (btnSpotlight) btnSpotlight.addEventListener('click', openSpotlight);

        document.getElementById('plannerSpotlightBackdrop')?.addEventListener('click', closeSpotlight);

        if (spotlightInput) {
            spotlightInput.addEventListener('input', () => {
                renderSpotlightResults(spotlightInput.value.trim());
            });

            spotlightInput.addEventListener('keydown', e => {
                const items = Array.from(spotlightResults?.querySelectorAll('.planner-spotlight__item') || []);
                const current = spotlightResults?.querySelector('.planner-spotlight__item.is-selected');
                let index = items.indexOf(current);

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (current) current.classList.remove('is-selected');
                    const next = items[(index + 1) % items.length];
                    if (next) {
                        next.classList.add('is-selected');
                        next.scrollIntoView({ block: 'nearest' });
                    }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (current) current.classList.remove('is-selected');
                    const prev = items[(index - 1 + items.length) % items.length];
                    if (prev) {
                        prev.classList.add('is-selected');
                        prev.scrollIntoView({ block: 'nearest' });
                    }
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (current) current.click();
                }
            });
        }

        function renderSpotlightResults(query) {
            if (!spotlightResults) return;
            spotlightResults.innerHTML = '';

            if (!query) {
                spotlightResults.innerHTML = `
                    <li class="planner-spotlight__hint" role="option">
                        <i class="bi bi-lightbulb" aria-hidden="true"></i>
                        Digite para buscar tarefas, pessoas ou equipes...
                    </li>
                `;
                return;
            }

            const q = query.toLowerCase();
            const matchingTasks = state.tarefas.filter(t =>
                (t.titulo || '').toLowerCase().includes(q) || (t.descricao || '').toLowerCase().includes(q)
            ).slice(0, 5);

            const matchingUsers = state.usuarios.filter(u =>
                (u.nome || '').toLowerCase().includes(q) || (u.cargo || '').toLowerCase().includes(q)
            ).slice(0, 3);

            const matchingEquipes = state.equipes.filter(e =>
                (e.nome || '').toLowerCase().includes(q)
            ).slice(0, 3);

            if (matchingTasks.length === 0 && matchingUsers.length === 0 && matchingEquipes.length === 0) {
                spotlightResults.innerHTML = `
                    <li class="planner-spotlight__hint" role="option">
                        <i class="bi bi-emoji-neutral" aria-hidden="true"></i>
                        Nenhum resultado encontrado para «${escapeHtml(query)}».
                    </li>
                `;
                return;
            }

            // Seção Tarefas
            if (matchingTasks.length > 0) {
                const groupHeader = document.createElement('li');
                groupHeader.className = 'planner-spotlight__group-header text-muted small px-3 py-1';
                groupHeader.textContent = 'Tarefas';
                spotlightResults.appendChild(groupHeader);

                matchingTasks.forEach(t => {
                    const li = document.createElement('li');
                    li.className = 'planner-spotlight__item p-2 px-3 d-flex align-items-center gap-2 cursor-pointer';
                    li.innerHTML = `
                        <i class="bi bi-check2-circle text-primary"></i>
                        <span class="flex-fill text-truncate">${escapeHtml(t.titulo)}</span>
                        <span class="badge badge-priority-${t.prioridade} small">${t.prioridade}</span>
                    `;
                    li.addEventListener('click', () => {
                        closeSpotlight();
                        openTaskDetail(t.id);
                    });
                    spotlightResults.appendChild(li);
                });
            }

            // Seção Pessoas
            if (matchingUsers.length > 0) {
                const groupHeader = document.createElement('li');
                groupHeader.className = 'planner-spotlight__group-header text-muted small px-3 py-1 mt-2';
                groupHeader.textContent = 'Pessoas';
                spotlightResults.appendChild(groupHeader);

                matchingUsers.forEach(u => {
                    const li = document.createElement('li');
                    li.className = 'planner-spotlight__item p-2 px-3 d-flex align-items-center gap-2 cursor-pointer';
                    li.innerHTML = `
                        <span class="avatar avatar-xs rounded-circle" style="background:${u.cor}">${escapeHtml(u.iniciais)}</span>
                        <span class="flex-fill text-truncate">${escapeHtml(u.nome)} <small class="text-muted">(${escapeHtml(u.cargo)})</small></span>
                    `;
                    li.addEventListener('click', () => {
                        closeSpotlight();
                        state.filtros.usuario = u.id;
                        avatarBtns.forEach(b => b.setAttribute('aria-pressed', Number(b.dataset.filterUsuario) === u.id ? 'true' : 'false'));
                        syncFilters();
                    });
                    spotlightResults.appendChild(li);
                });
            }

            // Seção Equipes
            if (matchingEquipes.length > 0) {
                const groupHeader = document.createElement('li');
                groupHeader.className = 'planner-spotlight__group-header text-muted small px-3 py-1 mt-2';
                groupHeader.textContent = 'Equipes';
                spotlightResults.appendChild(groupHeader);

                matchingEquipes.forEach(eq => {
                    const li = document.createElement('li');
                    li.className = 'planner-spotlight__item p-2 px-3 d-flex align-items-center gap-2 cursor-pointer';
                    li.innerHTML = `
                        <i class="bi bi-people text-info"></i>
                        <span class="flex-fill text-truncate">${escapeHtml(eq.nome)}</span>
                    `;
                    li.addEventListener('click', () => {
                        closeSpotlight();
                        state.filtros.equipe = eq.id;
                        if (filtroEquipe) filtroEquipe.value = String(eq.id);
                        syncFilters();
                    });
                    spotlightResults.appendChild(li);
                });
            }

            const firstResult = spotlightResults.querySelector('.planner-spotlight__item');
            if (firstResult) firstResult.classList.add('is-selected');
        }

        // 
        function renderDashboardCharts() {
            if (typeof Chart === 'undefined') return;

            const canvasColuna = document.getElementById('chartPorColuna');
            const canvasPrio = document.getElementById('chartPorPrioridade');
            const canvasAtiv = document.getElementById('chartAtividade7dias');

            const tarefasFiltradas = state.tarefas.filter(taskMatchesFilters);

            // Gráfico 1: Tarefas por Coluna
            if (canvasColuna) {
                if (state.activeCharts.coluna) state.activeCharts.coluna.destroy();

                const labels = state.colunas.map(c => c.titulo);
                const data = state.colunas.map(c => tarefasFiltradas.filter(t => t.coluna_id === c.id).length);
                const colors = state.colunas.map(c => c.cor);

                state.activeCharts.coluna = new Chart(canvasColuna, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [{
                            data,
                            backgroundColor: colors,
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1, color: '#94A3B8' }, grid: { color: 'rgba(255,255,255,0.06)' } },
                            x: { ticks: { color: '#94A3B8' }, grid: { display: false } }
                        }
                    }
                });
            }

            // Gráfico 2: Tarefas por Prioridade
            if (canvasPrio) {
                if (state.activeCharts.prio) state.activeCharts.prio.destroy();

                const prios = ['urgente', 'alta', 'media', 'baixa'];
                const labelsPrio = ['Urgente', 'Alta', 'Média', 'Baixa'];
                const dataPrio = prios.map(p => tarefasFiltradas.filter(t => t.prioridade === p).length);
                const colorsPrio = ['#EF4444', '#F97316', '#FACC15', '#10B981'];

                state.activeCharts.prio = new Chart(canvasPrio, {
                    type: 'doughnut',
                    data: {
                        labels: labelsPrio,
                        datasets: [{
                            data: dataPrio,
                            backgroundColor: colorsPrio,
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'bottom', labels: { color: '#94A3B8', boxWidth: 12 } }
                        },
                        cutout: '70%'
                    }
                });
            }

            // Gráfico 3: Atividades últimos 7 dias
            if (canvasAtiv) {
                if (state.activeCharts.ativ) state.activeCharts.ativ.destroy();

                const diasLabels = [];
                const diasContagem = [];
                for (let i = 6; i >= 0; i--) {
                    const d = new Date();
                    d.setDate(d.getDate() - i);
                    const iso = d.toISOString().split('T')[0];
                    const label = `${d.getDate()}/${d.getMonth() + 1}`;
                    diasLabels.push(label);

                    const count = state.atividades.filter(a => a.criado_em && a.criado_em.startsWith(iso)).length;
                    diasContagem.push(count);
                }

                state.activeCharts.ativ = new Chart(canvasAtiv, {
                    type: 'line',
                    data: {
                        labels: diasLabels,
                        datasets: [{
                            label: 'Atividades',
                            data: diasContagem,
                            borderColor: '#FACC15',
                            backgroundColor: 'rgba(250, 204, 21, 0.12)',
                            pointBackgroundColor: '#FACC15',
                            pointBorderColor: '#10B981',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1, color: '#94A3B8' }, grid: { color: 'rgba(255,255,255,0.06)' } },
                            x: { ticks: { color: '#94A3B8' }, grid: { display: false } }
                        }
                    }
                });
            }
        }

        // 
        const btnExportCSV = document.getElementById('btnExportCSV');
        if (btnExportCSV) {
            btnExportCSV.addEventListener('click', () => {
                const colunas = ['ID', 'Título', 'Descrição', 'Coluna', 'Prioridade', 'Prazo', 'Status do Prazo', 'Responsáveis'];
                const linhas = [colunas.join(';')];

                state.tarefas.forEach(t => {
                    const col = getColuna(t.coluna_id)?.titulo || t.coluna_id;
                    const resps = (t.responsaveis || []).map(uid => getUsuario(uid)?.nome || uid).join(', ');
                    const linha = [
                        t.id,
                        `"${(t.titulo || '').replace(/"/g, '""')}"`,
                        `"${(t.descricao || '').replace(/"/g, '""')}"`,
                        `"${col.replace(/"/g, '""')}"`,
                        t.prioridade,
                        t.prazo || '',
                        t.status_prazo || '',
                        `"${resps.replace(/"/g, '""')}"`
                    ];
                    linhas.push(linha.join(';'));
                });

                const csvContent = '\uFEFF' + linhas.join('\r\n');
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `planner_tarefas_${new Date().toISOString().split('T')[0]}.csv`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                showToast('Arquivo CSV gerado com sucesso!', 'success');
            });
        }

        // 
        const MESES_PT = [
            'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
            'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'
        ];

        const calLabel = document.getElementById('calendarLabel');
        let calAno = calLabel?.dataset.year ? parseInt(calLabel.dataset.year, 10) : new Date().getFullYear();
        let calMes = calLabel?.dataset.month ? parseInt(calLabel.dataset.month, 10) : (new Date().getMonth() + 1);

        const btnCalAnterior = document.getElementById('calBtnAnterior');
        const btnCalProximo = document.getElementById('calBtnProximo');
        const btnCalHoje = document.getElementById('calBtnHoje');
        const offcanvasDiaEl = document.getElementById('plannerOffcanvasDia');
        const offcanvasDiaTitle = document.getElementById('offcanvasDiaLabel');
        const offcanvasDiaBody = document.getElementById('plannerOffcanvasDiaBody');

        if (btnCalAnterior) {
            btnCalAnterior.addEventListener('click', () => {
                calMes--;
                if (calMes < 1) {
                    calMes = 12;
                    calAno--;
                }
                renderPlannerCalendar();
            });
        }

        if (btnCalProximo) {
            btnCalProximo.addEventListener('click', () => {
                calMes++;
                if (calMes > 12) {
                    calMes = 1;
                    calAno++;
                }
                renderPlannerCalendar();
            });
        }

        if (btnCalHoje) {
            btnCalHoje.addEventListener('click', () => {
                const now = new Date();
                calAno = now.getFullYear();
                calMes = now.getMonth() + 1;
                renderPlannerCalendar();
            });
        }

        function formatCalDateIso(ano, mes, dia) {
            return `${ano}-${String(mes).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
        }

        function formatCalDateLong(iso) {
            const parts = iso.split('-');
            if (parts.length !== 3) return iso;
            const d = parseInt(parts[2], 10);
            const m = parseInt(parts[1], 10);
            const y = parts[0];
            return `${d} de ${MESES_PT[m - 1]} de ${y}`;
        }

        function renderPlannerCalendar() {
            const grid = document.getElementById('plannerCalendarGrid');
            const label = document.getElementById('calendarLabel');
            if (!grid || !label) return;

            // Atualiza o rótulo do mês/ano
            label.textContent = `${MESES_PT[calMes - 1]} de ${calAno}`;
            label.dataset.year = String(calAno);
            label.dataset.month = String(calMes);

            // Remove células de dias anteriores mantendo os 7 headers de dias da semana (.cal-weekday)
            grid.querySelectorAll('.cal-day').forEach(el => el.remove());

            const hojeIso = new Date().toISOString().split('T')[0];
            const primeiroDiaSemana = new Date(calAno, calMes - 1, 1).getDay(); // 0 = Dom, 6 = Sáb
            const diasNoMes = new Date(calAno, calMes, 0).getDate();

            const fragment = document.createDocumentFragment();

            // Células vazias de preenchimento antes do primeiro dia
            for (let i = 0; i < primeiroDiaSemana; i++) {
                const empty = document.createElement('div');
                empty.className = 'cal-day is-empty';
                empty.setAttribute('aria-hidden', 'true');
                fragment.appendChild(empty);
            }

            // Renderiza cada dia do mês
            for (let d = 1; d <= diasNoMes; d++) {
                const iso = formatCalDateIso(calAno, calMes, d);
                const isHoje = (iso === hojeIso);

                // Tarefas do dia que passam pelos filtros ativos
                const tarefasDoDia = state.tarefas.filter(t => t.prazo === iso && taskMatchesFilters(t));
                const numTs = tarefasDoDia.length;

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `cal-day${isHoje ? ' is-today' : ''}`;
                btn.dataset.date = iso;
                btn.setAttribute('role', 'gridcell');
                btn.setAttribute('aria-label', `${d} de ${MESES_PT[calMes - 1]}${numTs ? `, ${numTs} tarefa(s)` : ''}`);

                const numSpan = document.createElement('span');
                numSpan.className = 'cal-day__num';
                numSpan.textContent = String(d);
                btn.appendChild(numSpan);

                const dotsSpan = document.createElement('span');
                dotsSpan.className = 'cal-day__dots';
                dotsSpan.setAttribute('aria-hidden', 'true');

                // Renderiza até 3 dots de prioridade
                tarefasDoDia.slice(0, 3).forEach(t => {
                    const dot = document.createElement('span');
                    dot.className = `cal-dot priority-${t.prioridade || 'media'}`;
                    dot.title = t.titulo || '';
                    dotsSpan.appendChild(dot);
                });

                // Se houver mais de 3 tarefas, exibe "+N"
                if (numTs > 3) {
                    const more = document.createElement('span');
                    more.className = 'cal-day__more';
                    more.textContent = `+${numTs - 3}`;
                    dotsSpan.appendChild(more);
                }

                btn.appendChild(dotsSpan);

                // Clique no dia abre o Offcanvas de detalhes do dia
                btn.addEventListener('click', () => {
                    openDayDetails(iso);
                });

                fragment.appendChild(btn);
            }

            grid.appendChild(fragment);
        }

        function openDayDetails(iso) {
            if (!offcanvasDiaEl || !offcanvasDiaBody) return;

            if (offcanvasDiaTitle) {
                offcanvasDiaTitle.textContent = `Tarefas de ${formatCalDateLong(iso)}`;
            }

            // Tarefas deste dia (aplicando filtros atuais)
            const tarefasDoDia = state.tarefas.filter(t => t.prazo === iso && taskMatchesFilters(t));
            offcanvasDiaBody.innerHTML = '';

            if (tarefasDoDia.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'planner-cal-empty-day text-center py-5';
                empty.innerHTML = `
                    <i class="bi bi-calendar-check fs-1 text-muted d-block mb-3" style="opacity: 0.5;"></i>
                    <p class="text-muted small mb-0">Nenhuma tarefa com prazo para esta data.</p>
                `;
                offcanvasDiaBody.appendChild(empty);
            } else {
                const list = document.createElement('div');
                list.className = 'planner-cal-task-list d-flex flex-column gap-2';

                tarefasDoDia.forEach(t => {
                    const coluna = getColuna(t.coluna_id);
                    const prioMeta = {
                        urgente: { rotulo: 'Urgente', classe: 'prio-urgente' },
                        alta: { rotulo: 'Alta', classe: 'prio-alta' },
                        media: { rotulo: 'Média', classe: 'prio-media' },
                        baixa: { rotulo: 'Baixa', classe: 'prio-baixa' },
                    }[t.prioridade] || { rotulo: t.prioridade, classe: 'prio-media' };

                    const card = document.createElement('div');
                    card.className = 'planner-cal-task-card p-3 rounded-3';
                    card.style.cssText = 'background: var(--planner-surface-2); border: 1px solid var(--planner-border); cursor: pointer; transition: all 0.2s;';
                    
                    card.addEventListener('mouseenter', () => {
                        card.style.borderColor = 'var(--planner-gold)';
                        card.style.transform = 'translateY(-2px)';
                    });
                    card.addEventListener('mouseleave', () => {
                        card.style.borderColor = 'var(--planner-border)';
                        card.style.transform = 'none';
                    });

                    // Clicar na tarefa do dia abre o modal de detalhes
                    card.addEventListener('click', () => {
                        const bsOffcanvas = window.bootstrap?.Offcanvas?.getInstance(offcanvasDiaEl);
                        if (bsOffcanvas) bsOffcanvas.hide();
                        openTaskDetail(t.id);
                    });

                    // Renderiza avatares dos responsáveis
                    let respsHtml = '';
                    if (Array.isArray(t.responsaveis) && t.responsaveis.length > 0) {
                        respsHtml = `<div class="avatar-stack">`;
                        t.responsaveis.slice(0, 3).forEach(uid => {
                            const u = getUsuario(uid);
                            if (u) {
                                respsHtml += `<span class="avatar avatar-xs" style="background-color: ${escapeHtml(u.cor)}" title="${escapeHtml(u.nome)}">${escapeHtml(u.iniciais)}</span>`;
                            }
                        });
                        if (t.responsaveis.length > 3) {
                            respsHtml += `<span class="avatar avatar-xs avatar--more">+${t.responsaveis.length - 3}</span>`;
                        }
                        respsHtml += `</div>`;
                    }

                    card.innerHTML = `
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="planner-prio-tag ${prioMeta.classe}">${prioMeta.rotulo}</span>
                            <span class="badge" style="background: rgba(255,255,255,0.06); color: ${coluna?.cor || '#94A3B8'}; font-size: 0.72rem;">${escapeHtml(coluna?.titulo || t.coluna_id)}</span>
                        </div>
                        <h6 class="mb-1 text-white fw-bold" style="font-size: 0.92rem;">${escapeHtml(t.titulo)}</h6>
                        ${t.descricao ? `<p class="text-muted small mb-2" style="font-size: 0.78rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">${escapeHtml(t.descricao)}</p>` : ''}
                        <div class="d-flex align-items-center justify-content-between mt-2 pt-2" style="border-top: 1px solid rgba(255,255,255,0.05);">
                            <span class="text-muted small" style="font-size: 0.75rem;"><i class="bi bi-clock me-1"></i>${escapeHtml(iso)}</span>
                            ${respsHtml}
                        </div>
                    `;

                    list.appendChild(card);
                });

                offcanvasDiaBody.appendChild(list);
            }

            if (window.bootstrap?.Offcanvas) {
                const instance = window.bootstrap.Offcanvas.getOrCreateInstance(offcanvasDiaEl);
                instance.show();
            }
        }

        // Inicialização com a view definida na URL
        const urlParams = new URLSearchParams(window.location.search);
        const initialView = urlParams.get('view') || state.currentView || 'kanban';
        switchView(initialView, false);
    }
})();
