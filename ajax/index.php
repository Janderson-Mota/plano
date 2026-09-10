<?php
declare(strict_types=1);

require_once __DIR__ . '/../functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    plannerError('Método não permitido. Use POST.', 405);
}

$rawBody = file_get_contents('php://input');
$body    = json_decode($rawBody ?: '{}', true);

if (!is_array($body)) {
    plannerError('Corpo da requisição inválido. Esperado JSON.', 400);
}

$action = trim((string)($body['action'] ?? ''));
if ($action === '') {
    plannerError('Campo "action" obrigatório.', 400);
}

$usuarioAtual = $usuarioAtual ?? plannerGetUsuarioAtual();

match($action) {
    'criar_tarefa'           => plannerActionCriarTarefa($body, $usuarioAtual),
    'criar_equipe'           => plannerActionCriarEquipe($body, $usuarioAtual),
    'mover_tarefa'           => plannerActionMoverTarefa($body, $usuarioAtual),
    'atualizar_responsaveis' => plannerActionAtualizarResponsaveis($body, $usuarioAtual),
    'criar_comentario'       => plannerActionCriarComentario($body, $usuarioAtual),
    'editar_comentario'      => plannerActionEditarComentario($body, $usuarioAtual),
    'excluir_comentario'     => plannerActionExcluirComentario($body, $usuarioAtual),
    default                  => plannerError("Ação desconhecida: {$action}", 400),
};
