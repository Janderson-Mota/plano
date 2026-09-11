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

    document.addEventListener('DOMContentLoaded', inicializarPlanner);

    function inicializarPlanner() {
        const elementoDados = document.getElementById('planner-data');
        if (!elementoDados) return;

        let dadosIniciais;
        try {
            dadosIniciais = JSON.parse(elementoDados.textContent);
        } catch (e) {
            console.error('Planner: Falha ao interpretar JSON inicial:', e);
            return;
        }

        // Estado reativo da aplicação
        const estado = {
            usuarioAtual: dadosIniciais.usuarioAtual || {},
            usuarios: dadosIniciais.usuarios || [],
            equipes: dadosIniciais.equipes || [],
            colunas: dadosIniciais.colunas || [],
            tarefas: dadosIniciais.tarefas || [],
            comentarios: dadosIniciais.comentarios || [],
            atividades: dadosIniciais.atividades || [],
            filtros: {
                texto: dadosIniciais.filtros?.texto || '',
                equipes: dadosIniciais.filtros?.equipe ? [Number(dadosIniciais.filtros.equipe)] : [],
                responsaveis: dadosIniciais.filtros?.usuario ? [String(dadosIniciais.filtros.usuario)] : [],
                prioridades: dadosIniciais.filtros?.prioridade ? [dadosIniciais.filtros.prioridade] : [],
                dataInicio: dadosIniciais.filtros?.data_inicio || '',
                dataFim: dadosIniciais.filtros?.data_fim || '',
                prazo: dadosIniciais.filtros?.prazo || '',
            },
            visaoAtual: document.documentElement.dataset.plannerView || 'kanban',
            graficosAtivos: {}
        };

        // =============================================================================
        // § 1.1 · CONTROLE DE SPINNER DISCRETO INTERNO AO MODAL (NÃO GLOBAL)
        // =============================================================================
        let spinnerCounter = 0;

        function mostrarSpinner(texto = 'Salvando...') {
            spinnerCounter++;
            // Identifica modal atualmente aberto
            const activeModal = document.querySelector('.modal.show');
            if (activeModal) {
                let spinnerEl = activeModal.querySelector('.planner-modal-inline-spinner');
                if (!spinnerEl) {
                    spinnerEl = document.createElement('div');
                    spinnerEl.className = 'planner-modal-inline-spinner ms-auto';
                    spinnerEl.innerHTML = `
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                        <span class="planner-modal-spinner-label">${escaparHtml(texto)}</span>
                    `;
                    // Insere preferencialmente no modal-footer ou modal-header
                    const footer = activeModal.querySelector('.modal-footer');
                    const header = activeModal.querySelector('.modal-header');
                    if (footer) {
                        footer.prepend(spinnerEl);
                    } else if (header) {
                        header.appendChild(spinnerEl);
                    } else {
                        activeModal.querySelector('.modal-content')?.prepend(spinnerEl);
                    }
                } else {
                    const lbl = spinnerEl.querySelector('.planner-modal-spinner-label');
                    if (lbl) lbl.textContent = texto;
                    spinnerEl.style.display = 'inline-flex';
                }

                // Desabilita botões de ação do modal enquanto processa para evitar cliques duplicados
                activeModal.querySelectorAll('button[type="submit"], #plannerBtnExecutarConfirmacao, [data-action="salvar"]').forEach(btn => {
                    btn.disabled = true;
                });
            }
        }

        function ocultarSpinner() {
            spinnerCounter = Math.max(0, spinnerCounter - 1);
            if (spinnerCounter === 0) {
                document.querySelectorAll('.planner-modal-inline-spinner').forEach(el => el.remove());
                document.querySelectorAll('.modal button:disabled').forEach(btn => {
                    btn.disabled = false;
                });
            }
        }

        // HELPER GLOBAL DE CONFIRMAÇÃO EM MODAL (ELIMINA CONFIRM E ALERT NATIVOS)
        function confirmarAcao({ titulo = 'Confirmar exclusão', mensagem = 'Tem certeza que deseja executar esta ação?', textoBotao = 'Confirmar', variante = 'danger' } = {}) {
            return new Promise(resolve => {
                const modalEl = document.getElementById('plannerModalConfirmacao');
                if (!modalEl || !window.bootstrap?.Modal) {
                    // Fallback de segurança se Bootstrap Modal não estiver carregado
                    return resolve(false);
                }

                const tituloEl = document.getElementById('plannerModalConfirmacaoLabel');
                const msgEl = document.getElementById('plannerConfirmMensagem');
                const btnOk = document.getElementById('plannerBtnExecutarConfirmacao');
                const btnCancel = document.getElementById('plannerBtnCancelarConfirmacao');
                const iconWrap = document.getElementById('plannerConfirmIconWrap');
                const icon = document.getElementById('plannerConfirmIcon');

                if (tituloEl) tituloEl.textContent = titulo;
                if (msgEl) msgEl.textContent = mensagem;

                if (btnOk) {
                    btnOk.textContent = textoBotao;
                    btnOk.className = `btn btn-sm btn-${variante} px-3`;
                }

                if (iconWrap && icon) {
                    if (variante === 'danger') {
                        iconWrap.style.background = 'rgba(239, 68, 68, 0.1)';
                        icon.className = 'bi bi-exclamation-triangle-fill text-danger fs-1';
                    } else {
                        iconWrap.style.background = 'rgba(16, 185, 129, 0.1)';
                        icon.className = 'bi bi-info-circle-fill text-success fs-1';
                    }
                }

                const bsModal = window.bootstrap.Modal.getOrCreateInstance(modalEl);

                let responded = false;
                function onConfirm() {
                    responded = true;
                    bsModal.hide();
                    resolve(true);
                }

                function onCancel() {
                    if (!responded) {
                        responded = true;
                        resolve(false);
                    }
                }

                // Remove listeners anteriores
                const newBtnOk = btnOk.cloneNode(true);
                btnOk.parentNode.replaceChild(newBtnOk, btnOk);
                newBtnOk.addEventListener('click', onConfirm);

                modalEl.addEventListener('hidden.bs.modal', onCancel, { once: true });
                bsModal.show();
            });
        }

        // Comunicação com API (POST JSON com Spinner Visível)
        async function chamarApi(action, payload = {}) {
            mostrarSpinner();
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
                exibirToast(err.message || 'Ocorreu um erro na requisição.', 'danger');
                throw err;
            } finally {
                ocultarSpinner();
            }
        }

        // Helper de busca de usuário por id / matricula / chave
        function buscarUsuario(id) {
            if (id === null || id === undefined || id === '') return null;
            const strId = String(id).trim();
            return estado.usuarios.find(u =>
                String(u.id).trim() === strId ||
                String(u.matricula || '').trim() === strId ||
                String(u.chave || '').trim() === strId
            ) || null;
        }

        // Helper de busca de coluna por id ou slug
        function buscarColuna(id) {
            if (id === null || id === undefined || id === '') return null;
            const strId = String(id).trim();
            return estado.colunas.find(c =>
                String(c.id).trim() === strId ||
                (c.slug && String(c.slug).trim() === strId)
            ) || null;
        }

        function buscarEquipe(id) {
            return estado.equipes.find(e => e.id === Number(id)) || null;
        }

        function buscarMembrosEquipeEFilhas(equipeId) {
            const eqId = Number(equipeId);
            if (!eqId) return [];
            const resultIds = new Set();

            function coletar(id) {
                const eq = buscarEquipe(id);
                if (!eq) return;
                (eq.membros || []).forEach(m => resultIds.add(m));
                estado.equipes.filter(filha => filha.pai_id === id).forEach(filha => coletar(filha.id));
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

        function formatarTempoRelativo(dateStr) {
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

        function escaparHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // =============================================================================
        // § 2.1 · MÓDULO GLOBAL DE NOTIFICAÇÕES (TOAST SYSTEM EM VANILLA JS)
        // =============================================================================
        window.showToast = function(mensagem, tipo = 'sucesso', duracao = 4000) {
            let container = document.getElementById('plannerToastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'plannerToastContainer';
                container.className = 'planner-toast-container';
                document.body.appendChild(container);
            }

            const tipoNorm = (tipo === 'success' || tipo === 'sucesso') ? 'sucesso'
                           : (tipo === 'danger' || tipo === 'error' || tipo === 'erro') ? 'erro'
                           : (tipo === 'warning' || tipo === 'aviso') ? 'aviso'
                           : 'info';

            const iconMap = {
                'sucesso': 'bi-check-circle-fill',
                'erro': 'bi-x-circle-fill',
                'aviso': 'bi-exclamation-triangle-fill',
                'info': 'bi-info-circle-fill',
            };

            const titleMap = {
                'sucesso': 'Sucesso',
                'erro': 'Erro',
                'aviso': 'Atenção',
                'info': 'Informação',
            };

            const toast = document.createElement('div');
            toast.className = `planner-toast planner-toast--${tipoNorm}`;
            toast.setAttribute('role', 'alert');
            toast.setAttribute('aria-live', 'assertive');

            toast.innerHTML = `
                <div class="planner-toast__icon">
                    <i class="bi ${iconMap[tipoNorm]}"></i>
                </div>
                <div class="planner-toast__content">
                    <div class="planner-toast__title">${titleMap[tipoNorm]}</div>
                    <div class="planner-toast__message">${escaparHtml(mensagem)}</div>
                </div>
                <button type="button" class="planner-toast__close" aria-label="Fechar notificação">
                    <i class="bi bi-x"></i>
                </button>
            `;

            container.appendChild(toast);

            const dismiss = () => {
                toast.classList.add('is-leaving');
                setTimeout(() => {
                    if (toast.parentNode) toast.remove();
                }, 300);
            };

            const closeBtn = toast.querySelector('.planner-toast__close');
            if (closeBtn) closeBtn.addEventListener('click', dismiss);

            if (duracao > 0) {
                setTimeout(dismiss, duracao);
            }
        };
        window.plannerToast = window.showToast;

        function exibirToast(mensagem, tipo = 'sucesso', duracao = 4000) {
            window.showToast(mensagem, tipo, duracao);
        }

        // Helper global de renderização de foto do usuário (substitui bolinhas de iniciais)
        function renderizarAvatar(u, tamanho = 'sm') {
            if (!u) return '';
            const ident = String(u.matricula || u.chave || u.id || 'default').trim();
            const foto = u.foto || `public/img/${ident}.png`;
            const nome = escaparHtml(u.nome || 'Usuário');
            const classeTamanho = `avatar-${tamanho}`;
            return `<img class="avatar ${classeTamanho} rounded-circle" src="${foto}" alt="${nome}" title="${nome}" data-user-id="${ident}" onerror="this.onerror=null;this.src='public/img/default.png';">`;
        }

        // 
        function trocarVisao(viewName, updateUrl = true) {
            const validViews = ['kanban', 'calendario', 'dashboard', 'lista', 'minhas-tarefas'];
            if (!validViews.includes(viewName)) viewName = 'kanban';

            estado.visaoAtual = viewName;
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

            // AÇÕES ESPECÍFICAS DE CADA VIEW
            if (viewName === 'dashboard') {
                // BREVE TIMEOUT PARA CONCLUIR TRANSIÇÃO DE DISPLAY NO DOM
                setTimeout(renderizarGraficosDashboard, 50);
            } else if (viewName === 'calendario') {
                renderizarCalendario();
            }

            // APLICA OS FILTROS ATIVOS NA NOVA VISÃO
            sincronizarFiltros();

            if (updateUrl) {
                const url = new URL(window.location.href);
                url.searchParams.set('view', viewName);
                window.history.pushState({ view: viewName }, '', url);
            }
        }

        // REDIMENSIONAMENTO RESPONSIVO DOS GRÁFICOS QUANDO A JANELA MUDA DE TAMANHO
        window.addEventListener('resize', () => {
            if (estado.visaoAtual === 'dashboard') {
                Object.values(estado.graficosAtivos).forEach(chart => {
                    if (chart && typeof chart.resize === 'function') {
                        chart.resize();
                    }
                });
            }
        });

        // Intercepta cliques nos links de view
        document.querySelectorAll('[data-view-link]').forEach(link => {
            link.addEventListener('click', e => {
                e.preventDefault();
                trocarVisao(link.dataset.viewLink);
            });
        });

        window.addEventListener('popstate', () => {
            const url = new URL(window.location.href);
            const viewFromUrl = url.searchParams.get('view') || 'kanban';
            trocarVisao(viewFromUrl, false);
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
                isNumber: false
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
        function fecharTodosDropdowns() {
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
                fecharTodosDropdowns();
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
                estado.filtros[cfg.key] = values;

                if (badge) {
                    if (values.length > 0) {
                        badge.textContent = String(values.length);
                        badge.classList.remove('d-none');
                    } else {
                        badge.classList.add('d-none');
                    }
                }
                sincronizarFiltros();
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
            if (Array.isArray(estado.filtros[cfg.key]) && estado.filtros[cfg.key].length > 0) {
                dropdown.querySelectorAll(`input[name="${cfg.inputName}"]`).forEach(cb => {
                    const val = cfg.isNumber ? Number(cb.value) : cb.value;
                    if (estado.filtros[cfg.key].includes(val)) {
                        cb.checked = true;
                    }
                });
                if (badge) {
                    badge.textContent = String(estado.filtros[cfg.key].length);
                    badge.classList.remove('d-none');
                }
            }
        });

        // Fechar dropdowns ao clicar em qualquer outra parte da página
        document.addEventListener('click', () => {
            fecharTodosDropdowns();
        });

        // Fechar com a tecla Escape
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                fecharTodosDropdowns();
            }
        });

        // Campo de busca textual com debounce
        let debounceTimer;
        if (filtroTexto) {
            filtroTexto.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    estado.filtros.texto = filtroTexto.value.trim().toLowerCase();
                    sincronizarFiltros();
                }, 200);
            });
        }

        // Filtro de Data Início e Fim
        if (filtroDataInicio) {
            filtroDataInicio.addEventListener('change', () => {
                estado.filtros.dataInicio = filtroDataInicio.value;
                sincronizarFiltros();
            });
        }

        if (filtroDataFim) {
            filtroDataFim.addEventListener('change', () => {
                estado.filtros.dataFim = filtroDataFim.value;
                sincronizarFiltros();
            });
        }

        // Botão Limpar Filtros Global
        if (btnLimparFiltros) {
            btnLimparFiltros.addEventListener('click', () => {
                estado.filtros.texto = '';
                estado.filtros.equipes = [];
                estado.filtros.responsaveis = [];
                estado.filtros.prioridades = [];
                estado.filtros.dataInicio = '';
                estado.filtros.dataFim = '';
                estado.filtros.prazo = '';

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

                fecharTodosDropdowns();
                sincronizarFiltros();
            });
        }

        function tarefaPassaNosFiltos(tarefa) {
            const f = estado.filtros;

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
                const resps = (tarefa.responsaveis || []).map(r => String(r).trim());
                const ps = (tarefa.pessoas_soltas || []).map(p => String(p).trim());
                const todosEnvolvidos = [...resps, ...ps];
                const hasAssignee = f.responsaveis.some(uId => todosEnvolvidos.includes(String(uId).trim()));
                if (!hasAssignee) return false;
            }

            // 4. Filtro de Equipes (múltiplas permitidas — tarefa pode ter uma ou várias equipes)
            if (Array.isArray(f.equipes) && f.equipes.length > 0) {
                const taskEqs = (tarefa.equipes || []).map(Number);
                const matchDirect = f.equipes.some(eqId => taskEqs.includes(eqId));
                if (!matchDirect) {
                    // Fallback: verificar se membros da equipe estão nos responsáveis da tarefa
                    const allowedMembers = f.equipes.flatMap(eqId => buscarMembrosEquipeEFilhas(eqId)).map(m => String(m).trim());
                    const taskResps = (tarefa.responsaveis || []).map(r => String(r).trim());
                    const matchMember = taskResps.some(r => allowedMembers.includes(r));
                    if (!matchMember) return false;
                }
            }

            // 5. Filtro de Intervalo de Data (Data Início e Data Fim)
            if (f.dataInicio) {
                if (!tarefa.prazo || tarefa.prazo < f.dataInicio) return false;
            }
            if (f.dataFim) {
                if (!tarefa.prazo || tarefa.prazo > f.dataFim) return false;
            }

            // 6. Legado: filtro de prazo único se existir
            if (f.prazo && tarefa.prazo) {
                if (tarefa.prazo > f.prazo) return false;
            }

            return true;
        }

        function sincronizarFiltros() {
            // Sincroniza querystring
            const url = new URL(window.location.href);
            if (estado.filtros.texto) url.searchParams.set('q', estado.filtros.texto);
            else url.searchParams.delete('q');

            if (estado.filtros.dataInicio) url.searchParams.set('data_inicio', estado.filtros.dataInicio);
            else url.searchParams.delete('data_inicio');

            if (estado.filtros.dataFim) url.searchParams.set('data_fim', estado.filtros.dataFim);
            else url.searchParams.delete('data_fim');

            if (estado.filtros.equipes.length === 1) url.searchParams.set('equipe', String(estado.filtros.equipes[0]));
            else url.searchParams.delete('equipe');

            if (estado.filtros.responsaveis.length === 1) url.searchParams.set('usuario', String(estado.filtros.responsaveis[0]));
            else url.searchParams.delete('usuario');

            if (estado.filtros.prioridades.length === 1) url.searchParams.set('prioridade', estado.filtros.prioridades[0]);
            else url.searchParams.delete('prioridade');

            window.history.replaceState({ view: estado.visaoAtual }, '', url);

            // Aplica na View Kanban
            const cards = document.querySelectorAll('#planner-board .task-card');
            cards.forEach(card => {
                const id = Number(card.dataset.taskId);
                const tarefa = estado.tarefas.find(t => t.id === id);
                if (!tarefa) return;
                const match = tarefaPassaNosFiltos(tarefa);
                card.style.display = match ? '' : 'none';
            });
            atualizarContagemColunas();

            // APLICA NA VIEW LISTA
            const rows = document.querySelectorAll('#plannerTabelaBody .planner-table__row');
            let visiveisLista = 0;
            rows.forEach(row => {
                const id = Number(row.dataset.taskId);
                const tarefa = estado.tarefas.find(t => t.id === id);
                if (!tarefa) return;
                let match = tarefaPassaNosFiltos(tarefa);

                // FILTRAGEM DINÂMICA POR ABA DA LISTA (TODAS / PENDENTES / ATRASADAS / CONCLUÍDAS)
                if (match && estado.filtroListaTab && estado.filtroListaTab !== 'todas') {
                    if (estado.filtroListaTab === 'pendentes') {
                        match = (String(tarefa.coluna_id) !== 'concluido' && Number(tarefa.coluna_id) !== 4);
                    } else if (estado.filtroListaTab === 'atrasadas') {
                        match = (tarefa.status_prazo === 'atrasada');
                    } else if (estado.filtroListaTab === 'concluidas') {
                        match = (String(tarefa.coluna_id) === 'concluido' || Number(tarefa.coluna_id) === 4);
                    }
                }

                row.style.display = match ? '' : 'none';
                if (match) visiveisLista++;
            });
            const listaCount = document.getElementById('listaCount');
            if (listaCount) listaCount.textContent = `${visiveisLista} tarefa(s)`;

            // ATUALIZA AS CONTAGENS DAS ABAS DA LISTA COM BASE NOS FILTROS ATIVOS
            const tarefasFiltradas = estado.tarefas.filter(t => tarefaPassaNosFiltos(t));
            const cTodas = tarefasFiltradas.length;
            const cPendentes = tarefasFiltradas.filter(t => String(t.coluna_id) !== 'concluido' && Number(t.coluna_id) !== 4).length;
            const cAtrasadas = tarefasFiltradas.filter(t => t.status_prazo === 'atrasada').length;
            const cConcluidas = tarefasFiltradas.filter(t => String(t.coluna_id) === 'concluido' || Number(t.coluna_id) === 4).length;

            const tabTodas = document.querySelector('.planner-lista__tab[data-lista-tab="todas"]');
            const tabPendentes = document.querySelector('.planner-lista__tab[data-lista-tab="pendentes"]');
            const tabAtrasadas = document.querySelector('.planner-lista__tab[data-lista-tab="atrasadas"]');
            const tabConcluidas = document.querySelector('.planner-lista__tab[data-lista-tab="concluidas"]');

            if (tabTodas) tabTodas.textContent = `Todas (${cTodas})`;
            if (tabPendentes) tabPendentes.textContent = `Pendentes (${cPendentes})`;
            if (tabAtrasadas) tabAtrasadas.textContent = `Atrasadas (${cAtrasadas})`;
            if (tabConcluidas) tabConcluidas.textContent = `Concluídas (${cConcluidas})`;

            // CONTROLE DO ESTADO VAZIO NA TABELA DA LISTA
            const tabelaEmptyRow = document.getElementById('plannerTabelaEmptyRow');
            if (tabelaEmptyRow) {
                tabelaEmptyRow.style.display = (visiveisLista === 0) ? '' : 'none';
            }

            // APLICA NA VIEW MINHAS TAREFAS
            const minhasCards = document.querySelectorAll('#minhasGrid .planner-minhas__card');
            let visiveisMinhas = 0;
            minhasCards.forEach(card => {
                const id = Number(card.dataset.taskId);
                const tarefa = estado.tarefas.find(t => t.id === id);
                if (!tarefa) return;
                const match = tarefaPassaNosFiltos(tarefa);
                card.style.display = match ? '' : 'none';
                if (match) visiveisMinhas++;
            });
            const subtituloMinhas = document.getElementById('minhasSubtitle');
            if (subtituloMinhas) subtituloMinhas.textContent = `${visiveisMinhas} tarefa(s) atribuída(s) a você`;

            // CONTROLE DO ESTADO VAZIO DE MINHAS TAREFAS
            const minhasEmpty = document.getElementById('minhasEmpty') || document.getElementById('minhasEmptyState');
            if (minhasEmpty) minhasEmpty.style.display = (visiveisMinhas === 0) ? 'flex' : 'none';

            // RE-RENDERIZA DASHBOARD OU CALENDÁRIO SE ESTIVER ATIVO
            if (estado.visaoAtual === 'dashboard') {
                renderizarGraficosDashboard();
            } else if (estado.visaoAtual === 'calendario') {
                renderizarCalendario();
            }
        }

        function atualizarContagemColunas() {
            document.querySelectorAll('#planner-board .board-col').forEach(col => {
                const colId = col.dataset.columnId;
                const visibleCount = col.querySelectorAll(`.task-card:not([style*="display: none"])`).length;
                const countBadge = col.querySelector(`[data-count-for="${colId}"]`);
                if (countBadge) countBadge.textContent = String(visibleCount);

                // CONTROLE DO ESTADO VAZIO NA COLUNA DO KANBAN
                const emptyCol = col.querySelector('.planner-empty-column');
                if (emptyCol) {
                    emptyCol.style.display = (visibleCount === 0) ? 'flex' : 'none';
                }
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
                    atualizarContagemColunas();

                    try {
                        await chamarApi('mover_tarefa', { task_id: taskId, coluna_id: targetCol });
                        const tarefa = estado.tarefas.find(t => t.id === taskId);
                        if (tarefa) tarefa.coluna_id = targetCol;
                        exibirToast('Tarefa movida com sucesso!', 'success');
                    } catch (err) {
                        // Reverte em caso de falha
                        const originBody = board.querySelector(`[data-column-body="${originCol}"]`);
                        if (originBody) {
                            originBody.appendChild(card);
                            card.dataset.column = originCol;
                            atualizarContagemColunas();
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
                abrirMenuMover(moverBtn);
                return;
            }

            if (moverMenu && !moverMenu.contains(e.target)) {
                fecharMenuMover();
            }
        });

        function abrirMenuMover(btn) {
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

        function fecharMenuMover() {
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
                fecharMenuMover();

                if (!taskId || !targetCol) return;

                const card = document.querySelector(`.task-card[data-task-id="${taskId}"]`);
                const originCol = card ? card.dataset.column : null;
                const targetBody = document.querySelector(`[data-column-body="${targetCol}"]`);

                if (card && targetBody && originCol !== targetCol) {
                    targetBody.appendChild(card);
                    card.dataset.column = targetCol;
                    atualizarContagemColunas();
                }

                try {
                    await chamarApi('mover_tarefa', { task_id: taskId, coluna_id: targetCol });
                    const tarefa = estado.tarefas.find(t => t.id === taskId);
                    if (tarefa) tarefa.coluna_id = targetCol;
                    exibirToast('Tarefa movida com sucesso!', 'success');
                } catch (err) {
                    if (card && originCol) {
                        const originBody = document.querySelector(`[data-column-body="${originCol}"]`);
                        if (originBody) {
                            originBody.appendChild(card);
                            card.dataset.column = originCol;
                            atualizarContagemColunas();
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
                    fecharMenuMover();
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
                if (taskId) abrirDetalhesTarefa(taskId);
            }
        });

        function abrirDetalhesTarefa(taskId) {
            const tarefa = estado.tarefas.find(t => t.id === taskId);
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

            const col = buscarColuna(tarefa.coluna_id);
            if (colunaSpan) colunaSpan.textContent = col ? col.titulo : tarefa.coluna_id;

            if (prazoSpan) {
                prazoSpan.textContent = tarefa.prazo ? `Prazo: ${tarefa.prazo}` : 'Sem prazo';
            }

            const criador = buscarUsuario(tarefa.criado_por);
            if (criadorSpan) {
                criadorSpan.textContent = criador ? `Criado por ${criador.nome}` : 'Planner';
            }

            if (descP) {
                descP.textContent = tarefa.descricao || 'Nenhuma descrição fornecida.';
                descP.classList.toggle('text-muted', !tarefa.descricao);
            }

            if (commentTarefaId) commentTarefaId.value = String(tarefa.id);
            if (btnMover) btnMover.dataset.taskId = String(tarefa.id);

            // 1. Renderiza equipes vinculadas atuais
            const equipesDiv = document.getElementById('plannerModalEquipes');
            if (equipesDiv) {
                equipesDiv.innerHTML = '';
                const eqList = (tarefa.equipes || []).map(Number);
                if (eqList.length === 0) {
                    equipesDiv.innerHTML = '<span class="text-muted small">Nenhuma equipe vinculada.</span>';
                } else {
                    eqList.forEach(eqId => {
                        const eq = buscarEquipe(eqId);
                        if (!eq) return;
                        const badge = document.createElement('span');
                        badge.className = 'planner-removable-badge planner-removable-badge--team';
                        badge.innerHTML = `
                            <i class="bi bi-briefcase-fill" aria-hidden="true"></i>
                            <span>${escaparHtml(eq.nome)}</span>
                            <button type="button" class="planner-removable-badge__del" data-remove-team="${eq.id}" title="Remover equipe ${escaparHtml(eq.nome)}" aria-label="Remover">
                                <i class="bi bi-x"></i>
                            </button>
                        `;
                        equipesDiv.appendChild(badge);
                    });
                }
            }

            // Atualiza contadores dos acordeons no modal de detalhes
            const bEq = document.getElementById('badgeModalEquipes');
            if (bEq) {
                const tot = (tarefa.equipes || []).length;
                bEq.textContent = String(tot);
                bEq.classList.toggle('d-none', tot === 0);
            }
            const bResp = document.getElementById('badgeModalResponsaveis');
            if (bResp) {
                const tot = (tarefa.responsaveis || []).length;
                bResp.textContent = String(tot);
                bResp.classList.toggle('d-none', tot === 0);
            }
            const bPs = document.getElementById('badgeModalPessoasSoltas');
            if (bPs) {
                const tot = (tarefa.pessoas_soltas || []).length;
                bPs.textContent = String(tot);
                bPs.classList.toggle('d-none', tot === 0);
            }

            // 2. Renderiza avatares dos responsáveis atuais
            if (assigneesDiv) {
                assigneesDiv.innerHTML = '';
                const resps = (tarefa.responsaveis || []).map(r => String(r).trim());
                if (resps.length === 0) {
                    assigneesDiv.innerHTML = '<span class="text-muted small">Nenhum responsável atribuído.</span>';
                } else {
                    resps.forEach(uid => {
                        const u = buscarUsuario(uid);
                        if (!u) return;
                        const badge = document.createElement('span');
                        badge.className = 'planner-removable-badge planner-removable-badge--resp';
                        badge.innerHTML = `
                            ${renderizarAvatar(u, 'xs')}
                            <span>${escaparHtml(u.nome)}</span>
                            <button type="button" class="planner-removable-badge__del" data-remove-resp="${u.id}" title="Remover responsável ${escaparHtml(u.nome)}" aria-label="Remover">
                                <i class="bi bi-x"></i>
                            </button>
                        `;
                        assigneesDiv.appendChild(badge);
                    });
                }
            }

            // 3. Renderiza avatares de pessoas soltas no modal de detalhes
            const pessoasSoltasDiv = document.getElementById('plannerModalPessoasSoltas');
            if (pessoasSoltasDiv) {
                pessoasSoltasDiv.innerHTML = '';
                const psList = (tarefa.pessoas_soltas || []).map(p => String(p).trim());
                if (psList.length === 0) {
                    pessoasSoltasDiv.innerHTML = '<span class="text-muted small">Nenhuma pessoa avulsa associada.</span>';
                } else {
                    psList.forEach(uid => {
                        const u = buscarUsuario(uid);
                        if (!u) return;
                        const badge = document.createElement('span');
                        badge.className = 'planner-removable-badge planner-removable-badge--ps';
                        badge.innerHTML = `
                            ${renderizarAvatar(u, 'xs')}
                            <span>${escaparHtml(u.nome)}</span>
                            <button type="button" class="planner-removable-badge__del" data-remove-ps="${u.id}" title="Remover pessoa avulsa ${escaparHtml(u.nome)}" aria-label="Remover">
                                <i class="bi bi-x"></i>
                            </button>
                        `;
                        pessoasSoltasDiv.appendChild(badge);
                    });
                }
            }

            // Preenche picker de equipes
            const eqPicker = document.getElementById('plannerEquipesPicker');
            if (eqPicker) {
                eqPicker.setAttribute('hidden', '');
                const checkboxes = eqPicker.querySelectorAll('input[name="eq_picker[]"]');
                const eqList = (tarefa.equipes || []).map(Number);
                checkboxes.forEach(cb => {
                    cb.checked = eqList.includes(Number(cb.value));
                    cb.closest('.planner-team-option')?.classList.toggle('is-selected', cb.checked);
                });
            }

            // Preenche picker de responsáveis
            const picker = document.getElementById('plannerAssigneePicker');
            if (picker) {
                picker.setAttribute('hidden', '');
                const checkboxes = picker.querySelectorAll('input[name="resp_picker[]"]');
                const resps = (tarefa.responsaveis || []).map(r => String(r).trim());
                checkboxes.forEach(cb => {
                    cb.checked = resps.includes(String(cb.value).trim());
                    cb.closest('.planner-assignee-option')?.classList.toggle('is-selected', cb.checked);
                });
            }

            // Preenche picker de pessoas soltas
            const psPicker = document.getElementById('plannerPessoasSoltasPicker');
            if (psPicker) {
                psPicker.setAttribute('hidden', '');
                const checkboxes = psPicker.querySelectorAll('input[name="ps_picker[]"]');
                const psList = (tarefa.pessoas_soltas || []).map(p => String(p).trim());
                checkboxes.forEach(cb => {
                    cb.checked = psList.includes(String(cb.value).trim());
                    cb.closest('.planner-assignee-option')?.classList.toggle('is-selected', cb.checked);
                });
            }

            carregarComentariosTarefa(tarefa.id);
            carregarAtividadesTarefa(tarefa.id);

            // MANTER ACORDEONS FECHADOS POR PADRÃO NO MODAL DE DETALHES
            const modalTarefaEl = document.getElementById('planner-modal-tarefa');
            if (modalTarefaEl) {
                modalTarefaEl.querySelectorAll('.planner-accordion').forEach(acc => {
                    acc.classList.remove('is-open');
                    const btn = acc.querySelector('.planner-accordion__header');
                    if (btn) btn.setAttribute('aria-expanded', 'false');
                    const body = acc.querySelector('.planner-accordion__body');
                    if (body) body.setAttribute('hidden', '');
                });
            }

            if (modalTarefaInstance) modalTarefaInstance.show();
        }


        // Edição de equipes no modal de detalhe
        const btnEditarEq = document.getElementById('btnEditarEquipes');
        const eqPicker = document.getElementById('plannerEquipesPicker');
        const btnSalvarEq = document.getElementById('btnSalvarEquipes');
        const btnCancelarEq = document.getElementById('btnCancelarEquipes');

        if (btnEditarEq && eqPicker) {
            btnEditarEq.addEventListener('click', () => {
                eqPicker.removeAttribute('hidden');
                btnEditarEq.style.display = 'none';
            });
        }

        if (btnCancelarEq && eqPicker && btnEditarEq) {
            btnCancelarEq.addEventListener('click', () => {
                eqPicker.setAttribute('hidden', '');
                btnEditarEq.style.display = '';
            });
        }

        if (btnSalvarEq && eqPicker) {
            btnSalvarEq.addEventListener('click', async () => {
                const taskId = Number(document.getElementById('plannerCommentTarefaId')?.value);
                if (!taskId) return;

                const selecionados = Array.from(eqPicker.querySelectorAll('input[name="eq_picker[]"]:checked'))
                    .map(cb => Number(cb.value));

                try {
                    await chamarApi('atualizar_equipes', { task_id: taskId, equipes: selecionados });
                    const tarefa = estado.tarefas.find(t => t.id === taskId);
                    if (tarefa) {
                        tarefa.equipes = selecionados;
                    }

                    // Atualiza no DOM do card se existir
                    const card = document.querySelector(`.task-card[data-task-id="${taskId}"]`);
                    if (card) {
                        card.dataset.teams = selecionados.join(',');
                        let teamsWrap = card.querySelector('.task-card__teams');
                        if (selecionados.length > 0) {
                            const badgesHtml = selecionados.map(eqId => {
                                const eq = buscarEquipe(eqId);
                                return eq ? `<span class="task-card__team-badge">${escaparHtml(eq.nome)}</span>` : '';
                            }).join('');
                            if (!teamsWrap) {
                                teamsWrap = document.createElement('div');
                                teamsWrap.className = 'task-card__teams';
                                card.querySelector('.task-card__title')?.after(teamsWrap);
                            }
                            teamsWrap.innerHTML = badgesHtml;
                        } else if (teamsWrap) {
                            teamsWrap.remove();
                        }
                    }

                    exibirToast('Equipes atualizadas!', 'success');
                    eqPicker.setAttribute('hidden', '');
                    if (btnEditarEq) btnEditarEq.style.display = '';
                    abrirDetalhesTarefa(taskId);
                } catch (err) {
                    // Erro tratado por plannerApi
                }
            });
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
                    .map(cb => String(cb.value).trim());

                try {
                    await chamarApi('atualizar_responsaveis', { task_id: taskId, responsaveis: selecionados });
                    const tarefa = estado.tarefas.find(t => t.id === taskId);
                    if (tarefa) {
                        tarefa.responsaveis = selecionados;
                    }

                    // Atualiza no DOM do card
                    const card = document.querySelector(`.task-card[data-task-id="${taskId}"]`);
                    if (card) {
                        card.dataset.assignees = selecionados.join(',');
                        const avatarStack = card.querySelector('.avatar-stack');
                        if (avatarStack) {
                            avatarStack.innerHTML = selecionados.map(uid => {
                                const u = buscarUsuario(uid);
                                return u ? renderizarAvatar(u, 'xs') : '';
                            }).join('');
                        }
                    }

                    exibirToast('Responsáveis atualizados!', 'success');
                    picker.setAttribute('hidden', '');
                    if (btnEditarResp) btnEditarResp.style.display = '';
                    abrirDetalhesTarefa(taskId);
                } catch (err) {
                    // Erro tratado por plannerApi
                }
            });
        }

        // Edição de pessoas soltas (grupo avulso) no modal de detalhe
        const btnEditarPs = document.getElementById('btnEditarPessoasSoltas');
        const psPicker = document.getElementById('plannerPessoasSoltasPicker');
        const btnSalvarPs = document.getElementById('btnSalvarPessoasSoltas');
        const btnCancelarPs = document.getElementById('btnCancelarPessoasSoltas');

        if (btnEditarPs && psPicker) {
            btnEditarPs.addEventListener('click', () => {
                psPicker.removeAttribute('hidden');
                btnEditarPs.style.display = 'none';
            });
        }

        if (btnCancelarPs && psPicker && btnEditarPs) {
            btnCancelarPs.addEventListener('click', () => {
                psPicker.setAttribute('hidden', '');
                btnEditarPs.style.display = '';
            });
        }

        if (btnSalvarPs && psPicker) {
            btnSalvarPs.addEventListener('click', async () => {
                const taskId = Number(document.getElementById('plannerCommentTarefaId')?.value);
                if (!taskId) return;

                const selecionados = Array.from(psPicker.querySelectorAll('input[name="ps_picker[]"]:checked'))
                    .map(cb => String(cb.value).trim());

                try {
                    await chamarApi('atualizar_pessoas_soltas', { task_id: taskId, pessoas_soltas: selecionados });
                    const tarefa = estado.tarefas.find(t => t.id === taskId);
                    if (tarefa) {
                        tarefa.pessoas_soltas = selecionados;
                    }

                    // Atualiza no DOM do card se existir
                    const card = document.querySelector(`.task-card[data-task-id="${taskId}"]`);
                    if (card) {
                        let psBadge = card.querySelector('.task-card__ps-badge');
                        if (selecionados.length > 0) {
                            if (!psBadge) {
                                const wrap = document.createElement('div');
                                wrap.className = 'task-card__pessoas-soltas';
                                wrap.innerHTML = `<span class="task-card__ps-badge"><i class="bi bi-people"></i> Grupo avulso (${selecionados.length})</span>`;
                                card.querySelector('.task-card__title')?.after(wrap);
                            } else {
                                psBadge.innerHTML = `<i class="bi bi-people"></i> Grupo avulso (${selecionados.length})`;
                            }
                        } else if (psBadge) {
                            psBadge.closest('.task-card__pessoas-soltas')?.remove();
                        }
                    }

                    exibirToast('Pessoas soltas (grupo avulso) atualizadas!', 'success');
                    psPicker.setAttribute('hidden', '');
                    if (btnEditarPs) btnEditarPs.style.display = '';
                    abrirDetalhesTarefa(taskId);
                } catch (err) {
                    // Erro tratado por plannerApi
                }
            });
        }

        // Remoção direta/rápida clicando no botão 'x' das badges do modal de detalhes
        document.getElementById('plannerModalEquipes')?.addEventListener('click', async e => {
            const delBtn = e.target.closest('[data-remove-team]');
            if (!delBtn) return;
            const eqId = Number(delBtn.dataset.removeTeam);
            const taskId = Number(document.getElementById('plannerCommentTarefaId')?.value);
            const tarefa = estado.tarefas.find(t => t.id === taskId);
            if (!tarefa || !eqId) return;

            const novos = (tarefa.equipes || []).filter(id => id !== eqId);
            try {
                await chamarApi('atualizar_equipes', { task_id: taskId, equipes: novos });
                tarefa.equipes = novos;
                const card = document.querySelector(`.task-card[data-task-id="${taskId}"]`);
                if (card) {
                    card.dataset.teams = novos.join(',');
                    const teamsWrap = card.querySelector('.task-card__teams');
                    if (novos.length > 0 && teamsWrap) {
                        teamsWrap.innerHTML = novos.map(id => {
                            const eq = buscarEquipe(id);
                            return eq ? `<span class="task-card__team-badge">${escaparHtml(eq.nome)}</span>` : '';
                        }).join('');
                    } else if (teamsWrap) {
                        teamsWrap.remove();
                    }
                }
                exibirToast('Equipe desvinculada!', 'info');
                abrirDetalhesTarefa(taskId);
            } catch (err) {}
        });

        document.getElementById('plannerModalAssignees')?.addEventListener('click', async e => {
            const delBtn = e.target.closest('[data-remove-resp]');
            if (!delBtn) return;
            const respId = String(delBtn.dataset.removeResp).trim();
            const taskId = Number(document.getElementById('plannerCommentTarefaId')?.value);
            const tarefa = estado.tarefas.find(t => t.id === taskId);
            if (!tarefa || !respId) return;

            const novos = (tarefa.responsaveis || []).filter(id => String(id).trim() !== respId);
            try {
                await chamarApi('atualizar_responsaveis', { task_id: taskId, responsaveis: novos });
                tarefa.responsaveis = novos;
                const card = document.querySelector(`.task-card[data-task-id="${taskId}"]`);
                if (card) {
                    card.dataset.assignees = novos.join(',');
                    const avatarStack = card.querySelector('.avatar-stack');
                    if (avatarStack) {
                        avatarStack.innerHTML = novos.map(uid => {
                            const u = buscarUsuario(uid);
                            return u ? renderizarAvatar(u, 'xs') : '';
                        }).join('');
                    }
                }
                exibirToast('Responsável removido!', 'info');
                abrirDetalhesTarefa(taskId);
            } catch (err) {}
        });

        document.getElementById('plannerModalPessoasSoltas')?.addEventListener('click', async e => {
            const delBtn = e.target.closest('[data-remove-ps]');
            if (!delBtn) return;
            const psId = String(delBtn.dataset.removePs).trim();
            const taskId = Number(document.getElementById('plannerCommentTarefaId')?.value);
            const tarefa = estado.tarefas.find(t => t.id === taskId);
            if (!tarefa || !psId) return;

            const novos = (tarefa.pessoas_soltas || []).filter(id => String(id).trim() !== psId);
            try {
                await chamarApi('atualizar_pessoas_soltas', { task_id: taskId, pessoas_soltas: novos });
                tarefa.pessoas_soltas = novos;
                const card = document.querySelector(`.task-card[data-task-id="${taskId}"]`);
                if (card) {
                    const psBadge = card.querySelector('.task-card__ps-badge');
                    if (novos.length > 0) {
                        if (psBadge) psBadge.innerHTML = `<i class="bi bi-people"></i> Grupo avulso (${novos.length})`;
                    } else if (psBadge) {
                        psBadge.closest('.task-card__pessoas-soltas')?.remove();
                    }
                }
                exibirToast('Pessoa avulsa removida!', 'info');
                abrirDetalhesTarefa(taskId);
            } catch (err) {}
        });

        // =============================================================================
        // § 7.1 · EXCLUSÃO DE TAREFA COM MODAL DE CONFIRMAÇÃO ESTILIZADO
        // =============================================================================
        async function executarExclusaoTarefa(taskId) {
            const id = Number(taskId);
            if (!id) return;
            const tarefa = estado.tarefas.find(t => t.id === id);
            const titulo = tarefa ? tarefa.titulo : 'esta tarefa';

            const confirmado = await confirmarAcao({
                titulo: 'Excluir Tarefa',
                mensagem: `Tem certeza que deseja excluir a tarefa «${titulo}» permanentemente? Todas as atividades e comentários serão removidos.`,
                textoBotao: 'Excluir Tarefa',
                variante: 'danger'
            });

            if (!confirmado) return;

            try {
                await chamarApi('excluir_tarefa', { task_id: id });
                // Remove do estado local
                estado.tarefas = estado.tarefas.filter(t => t.id !== id);
                estado.comentarios = estado.comentarios.filter(c => Number(c.tarefa_id) !== id);
                estado.atividades = estado.atividades.filter(a => Number(a.tarefa_id) !== id);

                // Remove cards do DOM (Kanban, Minhas Tarefas e Lista)
                document.querySelectorAll(`.task-card[data-task-id="${id}"]`).forEach(el => el.remove());
                document.querySelectorAll(`.planner-table__row[data-task-id="${id}"]`).forEach(el => el.remove());
                document.querySelectorAll(`.planner-minhas__card[data-task-id="${id}"]`).forEach(el => el.remove());

                atualizarContagemColunas();
                sincronizarFiltros();

                // Fecha o modal de detalhes se estiver aberto
                if (modalTarefaEl && window.bootstrap?.Modal) {
                    window.bootstrap.Modal.getInstance(modalTarefaEl)?.hide();
                }

                exibirToast(`Tarefa «${titulo}» excluída com sucesso!`, 'success');
            } catch (err) {
                // Erro tratado por chamarApi
            }
        }

        // Botão de excluir dentro do modal de detalhes da tarefa
        document.getElementById('plannerModalBtnExcluir')?.addEventListener('click', () => {
            const taskId = Number(document.getElementById('plannerCommentTarefaId')?.value);
            if (taskId) {
                executarExclusaoTarefa(taskId);
            }
        });

        // Delegação global para botões data-action="excluir-tarefa" (Kanban e Lista)
        document.addEventListener('click', e => {
            const btnExcluir = e.target.closest('[data-action="excluir-tarefa"]');
            if (btnExcluir) {
                e.preventDefault();
                e.stopPropagation();
                const taskId = Number(btnExcluir.dataset.taskId || btnExcluir.closest('[data-task-id]')?.dataset.taskId);
                if (taskId) {
                    executarExclusaoTarefa(taskId);
                }
            }
        });

        // Renderização e sincronização de comentários
        async function carregarComentariosTarefa(taskId) {
            renderizarComentariosTarefa(taskId); // Renderiza imediatamente com os dados locais em cache

            try {
                const res = await chamarApi('obter_comentarios', { tarefa_id: taskId });
                if (res?.comentarios && Array.isArray(res.comentarios)) {
                    // Remove os comentários antigos desta tarefa e insere os novos atualizados do servidor
                    estado.comentarios = estado.comentarios.filter(c => Number(c.tarefa_id) !== Number(taskId)).concat(res.comentarios);
                    renderizarComentariosTarefa(taskId);

                    // Atualiza contador de comentários no card da tarefa correspondente
                    const cardComBadge = document.querySelector(`.task-card[data-task-id="${taskId}"] [data-comment-count]`);
                    if (cardComBadge) {
                        cardComBadge.textContent = res.comentarios.length;
                    }
                }
            } catch (err) {
                console.error('Erro ao sincronizar comentários da tarefa:', err);
            }
        }

        function renderizarComentariosTarefa(taskId) {
            const container = document.getElementById('plannerCommentThread');
            if (!container) return;

            const coms = estado.comentarios.filter(c => Number(c.tarefa_id) === Number(taskId));
            container.innerHTML = '';

            if (coms.length === 0) {
                container.innerHTML = '<p class="text-muted small">Nenhum comentário ainda. Seja o primeiro a comentar!</p>';
                return;
            }

            coms.forEach(c => {
                const autor = buscarUsuario(c.usuario_id);
                const isAuthor = String(c.usuario_id).trim() === String(estado.usuarioAtual.id || estado.usuarioAtual.matricula || '').trim();

                const item = document.createElement('div');
                item.className = 'planner-comment';
                item.dataset.comentarioId = String(c.id);

                item.innerHTML = `
                    <div class="planner-comment__avatar">
                        ${renderizarAvatar(autor, 'sm')}
                    </div>
                    <div class="planner-comment__content">
                        <div class="planner-comment__header">
                            <span class="planner-comment__author">${escaparHtml(autor?.nome || 'Usuário')}</span>
                            <span class="planner-comment__time">${formatarTempoRelativo(c.criado_em)}${c.editado_em ? ' <em class="text-muted">(editado)</em>' : ''}</span>
                            ${isAuthor ? `
                            <div class="planner-comment__actions ms-auto d-flex align-items-center gap-1">
                                <button type="button" class="planner-btn-icon-sm" data-comment-action="edit" data-bs-toggle="tooltip" data-bs-placement="top" title="Editar comentário" aria-label="Editar comentário">
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="planner-btn-icon-sm text-danger" data-comment-action="delete" data-bs-toggle="tooltip" data-bs-placement="top" title="Excluir comentário" aria-label="Excluir comentário">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                            </div>` : ''}
                        </div>
                        <div class="planner-comment__body">${escaparHtml(c.texto)}</div>
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
                    const res = await chamarApi('criar_comentario', { tarefa_id: taskId, texto });
                    if (res?.comentario) {
                        estado.comentarios.push(res.comentario);
                        renderizarComentariosTarefa(taskId);
                        if (textInput) textInput.value = '';
                        exibirToast('Comentário enviado!', 'success');
                        // ATUALIZA O LOG DE ATIVIDADES EM TEMPO REAL
                        carregarAtividadesTarefa(taskId);
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
            const comentario = estado.comentarios.find(c => c.id === comId);
            if (!comentario) return;

            if (delBtn) {
                const confirmado = await confirmarAcao({
                    titulo: 'Excluir comentário',
                    mensagem: 'Deseja realmente remover este comentário permanentemente?',
                    textoBotao: 'Excluir',
                    variante: 'danger'
                });
                if (!confirmado) return;

                try {
                    await chamarApi('excluir_comentario', { comentario_id: comId });
                    estado.comentarios = estado.comentarios.filter(c => c.id !== comId);
                    renderizarComentariosTarefa(comentario.tarefa_id);
                    exibirToast('Comentário excluído com sucesso!', 'info');
                } catch (err) {}
            } else if (editBtn) {
                const bodyEl = commentEl.querySelector('.planner-comment__body');
                if (!bodyEl) return;

                const textoOriginal = comentario.texto;
                bodyEl.innerHTML = `
                    <div class="mt-2">
                        <textarea class="form-control form-control-sm mb-2" rows="2">${escaparHtml(textoOriginal)}</textarea>
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
                        const res = await chamarApi('editar_comentario', { comentario_id: comId, texto: novoTexto });
                        comentario.texto = novoTexto;
                        comentario.editado_em = res?.editado_em || new Date().toISOString();
                        renderizarComentariosTarefa(comentario.tarefa_id);
                        exibirToast('Comentário atualizado!', 'success');
                    } catch (err) {}
                });
            }
        });

        // SINCRONIZAÇÃO E CARREGAMENTO DE ATIVIDADES / AUDITORIA DA TAREFA
        async function carregarAtividadesTarefa(taskId) {
            renderizarAtividadesTarefa(taskId); // RENDERIZAÇÃO IMEDIATA COM DADOS EM CACHE

            try {
                const res = await chamarApi('obter_atividades', { tarefa_id: taskId });
                if (res?.atividades && Array.isArray(res.atividades)) {
                    // ATUALIZA O ESTADO GLOBAL DE ATIVIDADES PARA ESTA TAREFA
                    estado.atividades = estado.atividades
                        .filter(a => Number(a.tarefa_id) !== Number(taskId))
                        .concat(res.atividades);
                    renderizarAtividadesTarefa(taskId);
                }
            } catch (err) {
                console.error('Erro ao sincronizar atividades da tarefa:', err);
            }
        }

        function renderizarAtividadesTarefa(taskId) {
            const container = document.getElementById('plannerActivityLog');
            if (!container) return;

            const acts = estado.atividades.filter(a => Number(a.tarefa_id) === Number(taskId));
            container.innerHTML = '';

            if (acts.length === 0) {
                container.innerHTML = '<li class="text-muted small">Nenhum evento registrado.</li>';
                return;
            }

            acts.forEach(a => {
                const user = buscarUsuario(a.usuario_id);
                const li = document.createElement('li');
                li.className = 'planner-activity-log__item';

                let meta = a.meta;
                if (typeof meta === 'string') {
                    try {
                        meta = JSON.parse(meta);
                    } catch (e) {
                        meta = null;
                    }
                }

                let desc = 'realizou uma alteração';
                if (a.tipo === 'criacao') {
                    desc = 'criou a tarefa';
                } else if (a.tipo === 'movimentacao') {
                    const de = meta?.de ? (buscarColuna(meta.de)?.titulo || `Coluna ${meta.de}`) : 'Coluna inicial';
                    const para = meta?.para ? (buscarColuna(meta.para)?.titulo || `Coluna ${meta.para}`) : 'Nova coluna';
                    desc = `moveu de «${de}» para «${para}»`;
                } else if (a.tipo === 'atribuicao') {
                    desc = 'atualizou os responsáveis';
                } else if (a.tipo === 'equipes') {
                    const qtd = meta?.total !== undefined ? ` (${meta.total})` : '';
                    desc = `atualizou as equipes vinculadas${qtd}`;
                } else if (a.tipo === 'pessoas_soltas') {
                    const qtd = meta?.total !== undefined ? ` (${meta.total})` : '';
                    desc = `atualizou o grupo avulso${qtd}`;
                } else if (a.tipo === 'comentario') {
                    desc = 'adicionou um comentário';
                }

                li.innerHTML = `
                    <span class="planner-activity-log__dot"></span>
                    <div class="planner-activity-log__content">
                        <strong>${escaparHtml(user?.nome || 'Usuário')}</strong> ${escaparHtml(desc)}
                        <span class="planner-activity-log__date">${formatarTempoRelativo(a.criado_em)}</span>
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
                const psCheckboxes   = formNova.querySelectorAll('input[name="pessoas_soltas[]"]:checked');

                const titulo = tituloInput?.value.trim();
                if (!titulo) {
                    tituloInput?.classList.add('is-invalid');
                    return;
                }
                tituloInput?.classList.remove('is-invalid');

                const payload = {
                    titulo,
                    descricao: descInput?.value.trim() || null,
                    coluna_id: colInput?.value ? (Number(colInput.value) || colInput.value) : 1,
                    prioridade: prioInput?.value || 'media',
                    prazo: prazoInput?.value || null,
                    responsaveis: Array.from(respCheckboxes).map(cb => String(cb.value).trim()),
                    equipes: Array.from(teamCheckboxes).map(cb => Number(cb.value)),
                    pessoas_soltas: Array.from(psCheckboxes).map(cb => String(cb.value).trim())
                };

                const submitBtn = document.getElementById('plannerBtnCriarTarefa');
                if (submitBtn) submitBtn.disabled = true;

                try {
                    const res = await chamarApi('criar_tarefa', payload);
                    if (res?.tarefa) {
                        res.tarefa.status_prazo = calcularStatusPrazo(res.tarefa.prazo);
                        estado.tarefas.push(res.tarefa);

                        // Adiciona card no Kanban
                        const targetBody = document.querySelector(`[data-column-body="${res.tarefa.coluna_id}"]`);
                        if (targetBody) {
                            const newCard = construirCardKanban(res.tarefa);
                            targetBody.prepend(newCard);
                            atualizarContagemColunas();
                        }

                        // Fecha modal
                        const modalEl = document.getElementById('planner-modal-nova-tarefa');
                        if (modalEl && window.bootstrap?.Modal) {
                            window.bootstrap.Modal.getInstance(modalEl)?.hide();
                        }
                        formNova.reset();
                        formNova.querySelectorAll('.planner-assignee-option.is-selected').forEach(el => el.classList.remove('is-selected'));
                        atualizarResumosAcordeons();
                        exibirToast('Nova tarefa criada com sucesso!', 'success');
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
                const liderSelect = document.getElementById('novaEquipeLider');
                const membrosCheckboxes = formNovaEquipe.querySelectorAll('input[name="membros[]"]:checked');

                const nome = nomeInput?.value.trim();
                if (!nome) {
                    nomeInput?.classList.add('is-invalid');
                    return;
                }
                nomeInput?.classList.remove('is-invalid');

                const paiId = paiSelect?.value ? Number(paiSelect.value) : null;
                const cor = corInput?.value || '#10B981';
                // CAPTURA DO LÍDER SELECIONADO NA CRIAÇÃO DA EQUIPE
                const liderId = liderSelect?.value ? String(liderSelect.value).trim() : null;
                const membros = Array.from(membrosCheckboxes).map(cb => String(cb.value).trim());

                const submitBtn = document.getElementById('plannerBtnCriarEquipe');
                if (submitBtn) submitBtn.disabled = true;

                try {
                    const res = await chamarApi('criar_equipe', { nome, cor, pai_id: paiId, lider_id: liderId, membros });
                    if (res?.equipe) {
                        const novaEquipe = res.equipe;
                        estado.equipes.push(novaEquipe);

                        // 1. Injeta no dropdown multi-select de Equipes
                        const msList = document.querySelector('#dropdownFiltroEquipes .planner-multiselect__list');
                        if (msList) {
                            const label = document.createElement('label');
                            label.className = 'planner-multiselect__item';
                            label.dataset.teamId = String(novaEquipe.id);
                            label.innerHTML = `
                                <input type="checkbox" name="filtro_equipes[]" value="${novaEquipe.id}">
                                <span class="planner-multiselect__check-custom"></span>
                                <span class="planner-multiselect__text">${escaparHtml(novaEquipe.nome)}</span>
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
                                <span>${escaparHtml(novaEquipe.nome)}</span>
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
                        exibirToast(`Equipe "${novaEquipe.nome}" criada com sucesso!`, 'success');
                    }
                } finally {
                    if (submitBtn) submitBtn.disabled = false;
                }
            });
        }

        function construirCardKanban(t) {
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
                const eq = buscarEquipe(eqId);
                return eq ? `<span class="task-card__team-badge">${escaparHtml(eq.nome)}</span>` : '';
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
                <h3 class="task-card__title">${escaparHtml(t.titulo)}</h3>
                ${equipesHtml ? `<div class="task-card__teams">${equipesHtml}</div>` : ''}
                ${t.pessoas_soltas && t.pessoas_soltas.length > 0 ? `
                    <div class="task-card__pessoas-soltas">
                        <span class="task-card__ps-badge" title="Grupo avulso (pessoas soltas)">
                            <i class="bi bi-people" aria-hidden="true"></i> Grupo avulso (${t.pessoas_soltas.length})
                        </span>
                    </div>` : ''}
                ${t.descricao ? `<p class="task-card__desc">${escaparHtml(t.descricao)}</p>` : ''}
                <footer class="task-card__footer">
                    <span class="task-card__date">
                        <i class="bi bi-calendar-event" aria-hidden="true"></i> ${t.prazo ? escaparHtml(t.prazo) : 'Sem prazo'}
                    </span>
                    <span class="task-card__meta">
                        <span class="avatar-stack">
                            ${(t.responsaveis || []).map(uid => {
                                const u = buscarUsuario(uid);
                                return u ? renderizarAvatar(u, 'xs') : '';
                            }).join('')}
                        </span>
                    </span>
                </footer>
            `;
            return article;
        }

        // § 13 · SPOTLIGHT — desativado (removido da interface)
        // O painel de busca global (Ctrl+K) foi removido a pedido do usuário.
        function abrirSpotlight()  { /* desativado */ }
        function fecharSpotlight() { /* desativado */ }
        function renderizarResultadosSpotlight() { /* desativado */ }

        // =============================================================================
        // § 12 · DASHBOARD ANALÍTICO: GRÁFICOS INTERATIVOS (CHART.JS)
        // =============================================================================
        // =============================================================================
        // § 12 · DASHBOARD ANALÍTICO: GRÁFICOS INTERATIVOS E KPIS DINÂMICOS (CHART.JS)
        // =============================================================================
        function renderizarGraficosDashboard() {
            // VERIFICA SE A CLASSE CHART ESTÁ CARREGADA NO ESCOPO GLOBAL
            const ChartConstructor = window.Chart || (typeof Chart !== 'undefined' ? Chart : null);
            if (!ChartConstructor) {
                setTimeout(renderizarGraficosDashboard, 100);
                return;
            }

            const containerDashboard = document.querySelector('.planner-view[data-view="dashboard"]');
            if (containerDashboard && !containerDashboard.classList.contains('is-active')) {
                // SE O DASHBOARD NÃO ESTIVER VISÍVEL NO MOMENTO, NÃO TENTA CALCULAR DIMENSÕES DE CANVAS
                return;
            }

            const canvasColuna = document.getElementById('chartPorColuna');
            const canvasPrio = document.getElementById('chartPorPrioridade');
            const canvasSaude = document.getElementById('chartSaudePrazos');
            const canvasCarga = document.getElementById('chartCargaEquipes');

            const tarefasFiltradas = estado.tarefas.filter(tarefaPassaNosFiltos);

            // ATUALIZA KPIS DO TOPO DO DASHBOARD DINAMICAMENTE COM BASE NOS FILTROS ATIVOS
            const kpiTotalEl = document.getElementById('kpiTotalVal');
            const kpiAtrasadasEl = document.getElementById('kpiAtrasadasVal');
            const kpiFechadasEl = document.getElementById('kpiFechadasVal');
            const kpiUrgentesEl = document.getElementById('kpiUrgentesVal');

            const totalTarefas = tarefasFiltradas.length;
            const concluidasTarefas = tarefasFiltradas.filter(t => String(t.coluna_id) === 'concluido' || Number(t.coluna_id) === 4).length;
            const atrasadasTarefas = tarefasFiltradas.filter(t => t.status_prazo === 'atrasada' && String(t.coluna_id) !== 'concluido' && Number(t.coluna_id) !== 4).length;
            const urgentesTarefas = tarefasFiltradas.filter(t => t.prioridade === 'urgente' && String(t.coluna_id) !== 'concluido' && Number(t.coluna_id) !== 4).length;

            if (kpiTotalEl) kpiTotalEl.textContent = String(totalTarefas);
            if (kpiAtrasadasEl) kpiAtrasadasEl.textContent = String(atrasadasTarefas);
            if (kpiFechadasEl) kpiFechadasEl.textContent = String(concluidasTarefas);
            if (kpiUrgentesEl) kpiUrgentesEl.textContent = String(urgentesTarefas);

            // AGUARDA O PRÓXIMO FRAME DE PINTURA PARA GARANTIR QUE OS CONTAINERS JÁ POSSUEM LARGURA CALCULADA
            requestAnimationFrame(() => {
                // 1. GRÁFICO DE DISTRIBUIÇÃO POR COLUNA (FUNIL KANBAN)
                if (canvasColuna) {
                    if (estado.graficosAtivos.coluna) estado.graficosAtivos.coluna.destroy();

                    const labels = estado.colunas.map(c => c.titulo);
                    const data = estado.colunas.map(c => tarefasFiltradas.filter(t => String(t.coluna_id) === String(c.id)).length);
                    const colors = estado.colunas.map(c => c.cor || '#6366F1');

                    estado.graficosAtivos.coluna = new ChartConstructor(canvasColuna, {
                        type: 'bar',
                        data: {
                            labels,
                            datasets: [{
                                label: 'Tarefas',
                                data,
                                backgroundColor: colors,
                                borderRadius: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            animation: false,
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    callbacks: {
                                        label: (ctx) => ` ${ctx.parsed.y} tarefa(s)`
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: { stepSize: 1, precision: 0, color: '#94A3B8' },
                                    grid: { color: 'rgba(255,255,255,0.06)' }
                                },
                                x: {
                                    ticks: { color: '#94A3B8' },
                                    grid: { display: false }
                                }
                            }
                        }
                    });
                }

                // 2. GRÁFICO DE DISTRIBUIÇÃO POR PRIORIDADE
                if (canvasPrio) {
                    if (estado.graficosAtivos.prio) estado.graficosAtivos.prio.destroy();

                    const prios = ['urgente', 'alta', 'media', 'baixa'];
                    const labelsPrio = ['Urgente', 'Alta', 'Média', 'Baixa'];
                    const dataPrio = prios.map(p => tarefasFiltradas.filter(t => t.prioridade === p).length);
                    const colorsPrio = ['#EF4444', '#F97316', '#FACC15', '#10B981'];

                    estado.graficosAtivos.prio = new ChartConstructor(canvasPrio, {
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
                            maintainAspectRatio: false,
                            animation: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: { color: '#94A3B8', boxWidth: 12, padding: 16 }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: (ctx) => ` ${ctx.label}: ${ctx.parsed} tarefa(s)`
                                    }
                                }
                            },
                            cutout: '65%'
                        }
                    });
                }

                // 3. GRÁFICO DE SAÚDE DOS PRAZOS OPERACIONAIS
                if (canvasSaude) {
                    if (estado.graficosAtivos.saude) estado.graficosAtivos.saude.destroy();

                    let concluidas = 0;
                    let noPrazo = 0;
                    let hoje = 0;
                    let atrasada = 0;
                    let semPrazo = 0;

                    tarefasFiltradas.forEach(t => {
                        const isConcluida = String(t.coluna_id) === 'concluido' || Number(t.coluna_id) === 4;
                        if (isConcluida) {
                            concluidas++;
                        } else if (!t.prazo) {
                            semPrazo++;
                        } else {
                            const status = t.status_prazo || calcularStatusPrazo(t.prazo);
                            if (status === 'atrasada') atrasada++;
                            else if (status === 'hoje') hoje++;
                            else noPrazo++;
                        }
                    });

                    estado.graficosAtivos.saude = new ChartConstructor(canvasSaude, {
                        type: 'doughnut',
                        data: {
                            labels: ['Concluídas', 'No Prazo', 'Vence Hoje', 'Atrasadas', 'Sem Prazo'],
                            datasets: [{
                                data: [concluidas, noPrazo, hoje, atrasada, semPrazo],
                                backgroundColor: ['#10B981', '#3B82F6', '#F59E0B', '#EF4444', '#64748B'],
                                borderWidth: 0
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            animation: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: { color: '#94A3B8', boxWidth: 12, padding: 14 }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: (ctx) => ` ${ctx.label}: ${ctx.parsed} tarefa(s)`
                                    }
                                }
                            },
                            cutout: '65%'
                        }
                    });
                }

                // 4. GRÁFICO DE CARGA DE TRABALHO POR EQUIPE
                if (canvasCarga) {
                    if (estado.graficosAtivos.carga) estado.graficosAtivos.carga.destroy();

                    const labelsEquipes = estado.equipes.map(e => e.nome);
                    const dataCarga = estado.equipes.map(e => {
                        return tarefasFiltradas.filter(t => (t.equipes || []).map(Number).includes(Number(e.id))).length;
                    });
                    const colorsEquipes = estado.equipes.map(e => e.cor || '#3B82F6');

                    estado.graficosAtivos.carga = new ChartConstructor(canvasCarga, {
                        type: 'bar',
                        data: {
                            labels: labelsEquipes,
                            datasets: [{
                                label: 'Tarefas Vinculadas',
                                data: dataCarga,
                                backgroundColor: colorsEquipes,
                                borderRadius: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            animation: false,
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    callbacks: {
                                        label: (ctx) => ` ${ctx.parsed.y} tarefa(s)`
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: { stepSize: 1, precision: 0, color: '#94A3B8' },
                                    grid: { color: 'rgba(255,255,255,0.06)' }
                                },
                                x: {
                                    ticks: { color: '#94A3B8' },
                                    grid: { display: false }
                                }
                            }
                        }
                    });
                }
            });
        }

        // 
        const btnExportCSV = document.getElementById('btnExportCSV');
        if (btnExportCSV) {
            btnExportCSV.addEventListener('click', () => {
                const colunas = ['ID', 'Título', 'Descrição', 'Coluna', 'Prioridade', 'Prazo', 'Status do Prazo', 'Responsáveis'];
                const linhas = [colunas.join(';')];

                estado.tarefas.forEach(t => {
                    const col = buscarColuna(t.coluna_id)?.titulo || t.coluna_id;
                    const resps = (t.responsaveis || []).map(uid => buscarUsuario(uid)?.nome || uid).join(', ');
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
                exibirToast('Arquivo CSV gerado com sucesso!', 'success');
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
        // MODAL CENTRALIZADO DE DETALHES DO DIA NO CALENDÁRIO
        const modalDiaEl = document.getElementById('plannerModalDia') || document.getElementById('plannerOffcanvasDia');
        const modalDiaTitle = document.getElementById('plannerModalDiaLabel') || document.getElementById('offcanvasDiaLabel');
        const modalDiaBody = document.getElementById('plannerModalDiaBody') || document.getElementById('plannerOffcanvasDiaBody');

        if (btnCalAnterior) {
            btnCalAnterior.addEventListener('click', () => {
                calMes--;
                if (calMes < 1) {
                    calMes = 12;
                    calAno--;
                }
                renderizarCalendario();
            });
        }

        if (btnCalProximo) {
            btnCalProximo.addEventListener('click', () => {
                calMes++;
                if (calMes > 12) {
                    calMes = 1;
                    calAno++;
                }
                renderizarCalendario();
            });
        }

        if (btnCalHoje) {
            btnCalHoje.addEventListener('click', () => {
                const now = new Date();
                calAno = now.getFullYear();
                calMes = now.getMonth() + 1;
                renderizarCalendario();
            });
        }

        function formatarDataIso(ano, mes, dia) {
            return `${ano}-${String(mes).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
        }

        function formatarDataLonga(iso) {
            const parts = iso.split('-');
            if (parts.length !== 3) return iso;
            const d = parseInt(parts[2], 10);
            const m = parseInt(parts[1], 10);
            const y = parts[0];
            return `${d} de ${MESES_PT[m - 1]} de ${y}`;
        }

        function renderizarCalendario() {
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
                const iso = formatarDataIso(calAno, calMes, d);
                const isHoje = (iso === hojeIso);

                // Tarefas do dia que passam pelos filtros ativos
                const tarefasDoDia = estado.tarefas.filter(t => t.prazo === iso && tarefaPassaNosFiltos(t));
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
                    abrirDetalhesDia(iso);
                });

                fragment.appendChild(btn);
            }

            grid.appendChild(fragment);
        }

        function abrirDetalhesDia(iso) {
            if (!modalDiaEl || !modalDiaBody) return;

            if (modalDiaTitle) {
                modalDiaTitle.textContent = `Tarefas de ${formatarDataLonga(iso)}`;
            }

            // TAREFAS DESTE DIA (APLICANDO FILTROS ATUAIS)
            const tarefasDoDia = estado.tarefas.filter(t => t.prazo === iso && tarefaPassaNosFiltos(t));
            modalDiaBody.innerHTML = '';

            if (tarefasDoDia.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'planner-cal-empty-day text-center py-5';
                empty.innerHTML = `
                    <i class="bi bi-calendar-check fs-1 text-muted d-block mb-3" style="opacity: 0.5;"></i>
                    <p class="text-muted small mb-0">Nenhuma tarefa com prazo para esta data.</p>
                `;
                modalDiaBody.appendChild(empty);
            } else {
                const list = document.createElement('div');
                list.className = 'planner-cal-task-list d-flex flex-column gap-2';

                tarefasDoDia.forEach(t => {
                    const coluna = buscarColuna(t.coluna_id);
                    const prioMeta = {
                        urgente: { rotulo: 'Urgente', classe: 'prio-urgente' },
                        alta: { rotulo: 'Alta', classe: 'prio-alta' },
                        media: { rotulo: 'Média', classe: 'prio-media' },
                        baixa: { rotulo: 'Baixa', classe: 'prio-baixa' },
                    }[t.prioridade] || { rotulo: t.prioridade, classe: 'prio-media' };

                    const card = document.createElement('div');
                    card.className = 'planner-cal-task-card p-3 rounded-3 mb-2';
                    card.style.cssText = 'background: #FFFFFF; border: 1px solid #E2E8F0; cursor: pointer; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.05);';
                    
                    card.addEventListener('mouseenter', () => {
                        card.style.borderColor = '#10B981';
                        card.style.transform = 'translateY(-2px)';
                        card.style.boxShadow = '0 4px 6px -1px rgba(0,0,0,0.1)';
                    });
                    card.addEventListener('mouseleave', () => {
                        card.style.borderColor = '#E2E8F0';
                        card.style.transform = 'none';
                        card.style.boxShadow = '0 1px 3px rgba(0,0,0,0.05)';
                    });

                    // CLICAR NA TAREFA DO DIA FECHA O MODAL DO DIA E ABRE O MODAL DE DETALHES
                    card.addEventListener('click', () => {
                        if (window.bootstrap?.Modal && document.getElementById('plannerModalDia')) {
                            const bsModal = window.bootstrap.Modal.getInstance(document.getElementById('plannerModalDia'));
                            if (bsModal) bsModal.hide();
                        } else if (window.bootstrap?.Offcanvas && modalDiaEl) {
                            const bsOffcanvas = window.bootstrap.Offcanvas.getInstance(modalDiaEl);
                            if (bsOffcanvas) bsOffcanvas.hide();
                        }
                        abrirDetalhesTarefa(t.id);
                    });

                    // RENDERIZA AVATARES DOS RESPONSÁVEIS
                    let respsHtml = '';
                    if (Array.isArray(t.responsaveis) && t.responsaveis.length > 0) {
                        respsHtml = `<div class="avatar-stack">`;
                        t.responsaveis.slice(0, 3).forEach(uid => {
                            const u = buscarUsuario(uid);
                            if (u) {
                                respsHtml += renderizarAvatar(u, 'xs');
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
                            <span class="badge border" style="background: #F8FAFC; color: ${coluna?.cor || '#475569'}; border-color: #E2E8F0 !important; font-size: 0.72rem; font-weight: 600;">${escaparHtml(coluna?.titulo || t.coluna_id)}</span>
                        </div>
                        <h6 class="mb-1 fw-bold text-dark" style="font-size: 0.95rem; line-height: 1.35; color: #0F172A !important;">${escaparHtml(t.titulo)}</h6>
                        ${t.descricao ? `<p class="text-secondary small mb-2" style="font-size: 0.8rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; color: #475569 !important;">${escaparHtml(t.descricao)}</p>` : ''}
                        <div class="d-flex align-items-center justify-content-between mt-2 pt-2" style="border-top: 1px solid #F1F5F9;">
                            <span class="text-muted small" style="font-size: 0.75rem;"><i class="bi bi-clock me-1 text-primary"></i>${escaparHtml(iso)}</span>
                            ${respsHtml}
                        </div>
                    `;

                    modalDiaBody.appendChild(card);
                });
            }

            // ABERTURA VIA MODAL CENTRALIZADO (OU FALLBACK OFFCANVAS)
            if (window.bootstrap?.Modal && document.getElementById('plannerModalDia')) {
                const instance = window.bootstrap.Modal.getOrCreateInstance(document.getElementById('plannerModalDia'));
                instance.show();
            } else if (window.bootstrap?.Offcanvas && modalDiaEl) {
                const instance = window.bootstrap.Offcanvas.getOrCreateInstance(modalDiaEl);
                instance.show();
            }
        }

        // =============================================================================
        // § 14 · ACORDEONS DE ATRIBUIÇÃO (EQUIPES / RESPONSÁVEIS / PESSOAS SOLTAS)
        // =============================================================================
        function atualizarResumosAcordeons() {
            // 1. Resumo Equipes
            const eqCheckboxes = document.querySelectorAll('#accEquipesContent input[name="equipes[]"]:checked');
            const eqBadge = document.getElementById('badgeAccEquipes');
            const eqSummary = document.getElementById('summaryAccEquipes');
            if (eqBadge && eqSummary) {
                const totalEq = eqCheckboxes.length;
                if (totalEq > 0) {
                    eqBadge.textContent = String(totalEq);
                    eqBadge.classList.remove('d-none');
                    const nomes = Array.from(eqCheckboxes).map(cb => {
                        const opt = cb.closest('.planner-team-option');
                        return opt ? opt.querySelector('span')?.textContent.trim() : '';
                    }).filter(Boolean);
                    if (nomes.length <= 2) {
                        eqSummary.innerHTML = nomes.map(n => `<span class="badge-preview">${escaparHtml(n)}</span>`).join('');
                    } else {
                        eqSummary.innerHTML = `<span class="badge-preview">${escaparHtml(nomes[0])}</span> <span class="badge-preview">+${nomes.length - 1} equipe(s)</span>`;
                    }
                } else {
                    eqBadge.classList.add('d-none');
                    eqSummary.innerHTML = '<span class="text-muted small">Nenhuma selecionada</span>';
                }
            }

            // 2. Resumo Responsáveis
            const respCheckboxes = document.querySelectorAll('#accResponsaveisContent input[name="responsaveis[]"]:checked');
            const respBadge = document.getElementById('badgeAccResponsaveis');
            const respSummary = document.getElementById('summaryAccResponsaveis');
            if (respBadge && respSummary) {
                const totalResp = respCheckboxes.length;
                if (totalResp > 0) {
                    respBadge.textContent = String(totalResp);
                    respBadge.classList.remove('d-none');
                    const nomes = Array.from(respCheckboxes).map(cb => {
                        const opt = cb.closest('.planner-assignee-option');
                        return opt ? opt.querySelector('.planner-assignee-option__nome')?.textContent.trim() : '';
                    }).filter(Boolean);
                    if (nomes.length <= 2) {
                        eqSummary; // no-op
                        respSummary.innerHTML = nomes.map(n => `<span class="badge-preview">${escaparHtml(n)}</span>`).join('');
                    } else {
                        respSummary.innerHTML = `<span class="badge-preview">${escaparHtml(nomes[0])}</span> <span class="badge-preview">+${nomes.length - 1} pessoa(s)</span>`;
                    }
                } else {
                    respBadge.classList.add('d-none');
                    respSummary.innerHTML = '<span class="text-muted small">Nenhum selecionado</span>';
                }
            }

            // 3. Resumo Pessoas Soltas
            const psCheckboxes = document.querySelectorAll('#accPessoasSoltasContent input[name="pessoas_soltas[]"]:checked');
            const psBadge = document.getElementById('badgeAccPessoasSoltas');
            const psSummary = document.getElementById('summaryAccPessoasSoltas');
            if (psBadge && psSummary) {
                const totalPs = psCheckboxes.length;
                if (totalPs > 0) {
                    psBadge.textContent = String(totalPs);
                    psBadge.classList.remove('d-none');
                    const nomes = Array.from(psCheckboxes).map(cb => {
                        const opt = cb.closest('.planner-assignee-option');
                        return opt ? opt.querySelector('.planner-assignee-option__nome')?.textContent.trim() : '';
                    }).filter(Boolean);
                    if (nomes.length <= 2) {
                        psSummary.innerHTML = nomes.map(n => `<span class="badge-preview text-success-emphasis">${escaparHtml(n)}</span>`).join('');
                    } else {
                        psSummary.innerHTML = `<span class="badge-preview text-success-emphasis">${escaparHtml(nomes[0])}</span> <span class="badge-preview">+${nomes.length - 1} avulsa(s)</span>`;
                    }
                } else {
                    psBadge.classList.add('d-none');
                    psSummary.innerHTML = '<span class="text-muted small">Nenhuma selecionada</span>';
                }
            }
        }

        function inicializarAcordeonsAtribuicao() {
            const accordionHeaders = document.querySelectorAll('.planner-accordion__header');
            accordionHeaders.forEach(btn => {
                btn.addEventListener('click', () => {
                    const accordion = btn.closest('.planner-accordion');
                    if (!accordion) return;
                    const contentId = btn.getAttribute('aria-controls');
                    const content = document.getElementById(contentId);
                    if (!content) return;

                    const isOpen = accordion.classList.contains('is-open');
                    if (isOpen) {
                        accordion.classList.remove('is-open');
                        btn.setAttribute('aria-expanded', 'false');
                        content.setAttribute('hidden', '');
                    } else {
                        accordion.classList.add('is-open');
                        btn.setAttribute('aria-expanded', 'true');
                        content.removeAttribute('hidden');
                    }
                });
            });

            // Escuta mudanças nos checkboxes para atualizar o resumo dinâmico e classe is-selected
            const accordionsContainer = document.getElementById('novaTarefaAccordionGroup');
            if (accordionsContainer) {
                accordionsContainer.addEventListener('change', e => {
                    const cb = e.target.closest('input[type="checkbox"]');
                    if (!cb) return;

                    const opt = cb.closest('.planner-assignee-option');
                    if (opt) {
                        opt.classList.toggle('is-selected', cb.checked);
                    }
                    atualizarResumosAcordeons();
                });
            }

            atualizarResumosAcordeons();
        }

        inicializarAcordeonsAtribuicao();

        // =============================================================================
        // § 15 · CONTROLE DE ACORDEONS FECHADOS POR PADRÃO NOS MODAIS
        // =============================================================================
        const modalNovaTarefaEl = document.getElementById('planner-modal-nova-tarefa');
        if (modalNovaTarefaEl) {
            modalNovaTarefaEl.addEventListener('show.bs.modal', () => {
                modalNovaTarefaEl.querySelectorAll('.planner-accordion').forEach(acc => {
                    acc.classList.remove('is-open');
                    const btn = acc.querySelector('.planner-accordion__header');
                    if (btn) btn.setAttribute('aria-expanded', 'false');
                    const body = acc.querySelector('.planner-accordion__body');
                    if (body) body.setAttribute('hidden', '');
                });
                atualizarResumosAcordeons();
            });
        }

        // =============================================================================
        // § 16 · INTERAÇÕES DA VIEW LISTA: FILTRAGEM POR ABAS, ORDENAÇÃO E DETALHES
        // =============================================================================
        // 1. FILTRAGEM POR ABAS RÁPIDAS NA LISTA
        document.querySelectorAll('.planner-lista__tab[data-lista-tab]').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.planner-lista__tab').forEach(t => t.classList.remove('is-active'));
                tab.classList.add('is-active');
                estado.filtroListaTab = tab.dataset.listaTab;
                sincronizarFiltros();
            });
        });

        // 2. ORDENAÇÃO INTERATIVA DE COLUNAS DA TABELA
        let listaSortColuna = null;
        let listaSortAsc = true;
        document.querySelectorAll('.planner-table__th[data-sort]').forEach(th => {
            th.style.cursor = 'pointer';
            th.addEventListener('click', () => {
                const campo = th.dataset.sort;
                if (listaSortColuna === campo) {
                    listaSortAsc = !listaSortAsc;
                } else {
                    listaSortColuna = campo;
                    listaSortAsc = true;
                }

                // ATUALIZA ÍCONES NOS CABEÇALHOS
                document.querySelectorAll('.planner-table__th[data-sort]').forEach(otherTh => {
                    const icon = otherTh.querySelector('i');
                    if (otherTh === th) {
                        icon.className = listaSortAsc ? 'bi bi-arrow-up ms-1 text-warning' : 'bi bi-arrow-down ms-1 text-warning';
                    } else if (icon) {
                        icon.className = 'bi bi-arrow-down-up ms-1';
                    }
                });

                // REORDENA LINHAS DO CORPO DA TABELA
                const tbody = document.getElementById('plannerTabelaBody');
                if (!tbody) return;
                const rows = Array.from(tbody.querySelectorAll('.planner-table__row'));
                rows.sort((a, b) => {
                    const tA = estado.tarefas.find(t => t.id === Number(a.dataset.taskId));
                    const tB = estado.tarefas.find(t => t.id === Number(b.dataset.taskId));
                    if (!tA || !tB) return 0;

                    let valA = tA[campo] || '';
                    let valB = tB[campo] || '';

                    if (campo === 'coluna') {
                        valA = buscarColuna(tA.coluna_id)?.titulo || '';
                        valB = buscarColuna(tB.coluna_id)?.titulo || '';
                    }

                    if (typeof valA === 'string') {
                        return listaSortAsc ? valA.localeCompare(valB) : valB.localeCompare(valA);
                    }
                    return listaSortAsc ? (valA - valB) : (valB - valA);
                });

                rows.forEach(r => tbody.appendChild(r));
                const emptyRow = document.getElementById('plannerTabelaEmptyRow');
                if (emptyRow) tbody.appendChild(emptyRow);
            });
        });

        // 3. CLIQUE NA LINHA DA LISTA PARA ABRIR DETALHES
        const tabelaBody = document.getElementById('plannerTabelaBody');
        if (tabelaBody) {
            tabelaBody.addEventListener('click', e => {
                // Não abre detalhes se clicou no botão de mover ou excluir
                if (e.target.closest('[data-action="mover"]') || e.target.closest('[data-action="excluir-tarefa"]')) return;
                const row = e.target.closest('.planner-table__row');
                if (!row) return;
                const taskId = Number(row.dataset.taskId);
                if (taskId) {
                    abrirDetalhesTarefa(taskId);
                }
            });
        }

        // =============================================================================
        // § 17 · SINCRONIZAÇÃO DE FEEDBACK VISUAL EM TODOS OS PICKERS DE RESPONSÁVEIS
        // =============================================================================
        document.addEventListener('change', e => {
            const cb = e.target.closest('.planner-assignee-option input[type="checkbox"]');
            if (!cb) return;
            const opt = cb.closest('.planner-assignee-option');
            if (opt) {
                opt.classList.toggle('is-selected', cb.checked);
            }
        });

        // =============================================================================
        // § 18 · DELEGAÇÃO DE CLIQUES PARA O CALENDÁRIO (DIAS GERADOS VIA PHP OU JS)
        // =============================================================================
        const calendarGrid = document.getElementById('plannerCalendarGrid');
        if (calendarGrid) {
            calendarGrid.addEventListener('click', e => {
                const dayBtn = e.target.closest('.cal-day[data-date]');
                if (dayBtn && !dayBtn.classList.contains('is-empty')) {
                    const iso = dayBtn.dataset.date;
                    if (iso) {
                        abrirDetalhesDia(iso);
                    }
                }
            });
        }

        // =============================================================================
        // § 19 · INICIALIZAÇÃO DE TOOLTIPS DO BOOTSTRAP EM ELEMENTOS COM DATA-BS-TOGGLE
        // =============================================================================
        function inicializarTooltips() {
            if (window.bootstrap?.Tooltip) {
                const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
                tooltipTriggerList.forEach(el => {
                    window.bootstrap.Tooltip.getOrCreateInstance(el);
                });
            }
        }
        inicializarTooltips();

        // INICIALIZAÇÃO COM A VIEW DEFINIDA NA URL OU PADRÃO
        const urlParams = new URLSearchParams(window.location.search);
        const initialView = urlParams.get('view') || estado.visaoAtual || 'kanban';
        trocarVisao(initialView, false);
    }
})();
