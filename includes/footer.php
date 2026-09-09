<?php
/**
 * footer.php
 * Espera a variável opcional:
 *   array $pageScripts - lista de arquivos JS (relativos a assets/js/) a carregar
 */
declare(strict_types=1);

require_once __DIR__ . '/data.php';
require_once __DIR__ . '/functions.php';

$footerTeam = getTeamMembers();
$footerColumns = getColumns();
$pageScripts = $pageScripts ?? [];
?>
</main>

<!-- Modal: Nova Tarefa (compartilhado entre Kanban e Calendário) -->
<div class="modal fade" id="modalNovaTarefa" tabindex="-1" aria-labelledby="modalNovaTarefaLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="formNovaTarefa" novalidate>
            <div class="modal-header">
                <h2 class="modal-title h5" id="modalNovaTarefaLabel">Nova tarefa</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="campoTitulo" class="form-label">Título</label>
                    <input type="text" class="form-control" id="campoTitulo" name="titulo" required maxlength="80">
                </div>
                <div class="mb-3">
                    <label for="campoDescricao" class="form-label">Descrição</label>
                    <textarea class="form-control" id="campoDescricao" name="descricao" rows="2" maxlength="200"></textarea>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label for="campoColuna" class="form-label">Coluna</label>
                        <select class="form-select" id="campoColuna" name="coluna">
                            <?php foreach ($footerColumns as $coluna): ?>
                                <option value="<?= htmlspecialchars($coluna['id']) ?>"><?= htmlspecialchars($coluna['titulo']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label for="campoPrioridade" class="form-label">Prioridade</label>
                        <select class="form-select" id="campoPrioridade" name="prioridade">
                            <option value="baixa">Baixa</option>
                            <option value="media" selected>Média</option>
                            <option value="alta">Alta</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="campoPrazo" class="form-label">Prazo</label>
                    <input type="date" class="form-control" id="campoPrazo" name="prazo">
                </div>
                <div class="mb-1">
                    <span class="form-label d-block">Responsáveis</span>
                    <div class="assignee-picker" role="group" aria-label="Selecionar responsáveis">
                        <?php foreach ($footerTeam as $membro): ?>
                            <label class="assignee-option">
                                <input type="checkbox" name="responsaveis[]" value="<?= (int) $membro['id'] ?>" class="visually-hidden">
                                <?= renderAvatar($membro, 'sm') ?>
                                <span class="assignee-option__name"><?= htmlspecialchars($membro['nome']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Criar tarefa</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php foreach ($pageScripts as $script): ?>
    <script src="assets/js/<?= htmlspecialchars($script) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
