<?php
/**
 * api/update_task.php
 * Endpoint simulado (mock) chamado via fetch() quando um card é
 * solto em outra coluna. Não há persistência real: apenas valida
 * o payload e responde como uma API de verdade responderia.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/data.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);

$taskId    = isset($payload['task_id']) ? (int) $payload['task_id'] : 0;
$novaColuna = isset($payload['coluna']) ? (string) $payload['coluna'] : '';

$colunaValida = findColumn(getColumns(), $novaColuna);

if ($taskId <= 0 || $colunaValida === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Dados inválidos para atualização.']);
    exit;
}

// Simula a atualização de status em um banco de dados.
echo json_encode([
    'success'    => true,
    'task_id'    => $taskId,
    'coluna'     => $novaColuna,
    'updated_at' => date(DATE_ATOM),
]);
