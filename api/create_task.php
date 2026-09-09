<?php
/**
 * api/create_task.php
 * Endpoint simulado (mock) chamado via fetch() ao enviar o formulário
 * "Nova tarefa". Gera um id novo em cima do dataset mock e devolve a
 * tarefa pronta para o JS inserir no quadro, sem persistir de fato.
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

$titulo = trim((string) ($payload['titulo'] ?? ''));
$coluna = (string) ($payload['coluna'] ?? 'backlog');
$prioridade = (string) ($payload['prioridade'] ?? 'media');
$prazo = (string) ($payload['prazo'] ?? date('Y-m-d'));
$descricao = trim((string) ($payload['descricao'] ?? ''));
$responsaveis = array_map('intval', (array) ($payload['responsaveis'] ?? []));

if ($titulo === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'O título é obrigatório.']);
    exit;
}

if (findColumn(getColumns(), $coluna) === null) {
    $coluna = 'backlog';
}

if (!in_array($prioridade, ['baixa', 'media', 'alta'], true)) {
    $prioridade = 'media';
}

$tasksExistentes = getTasks();
$proximoId = 1;
foreach ($tasksExistentes as $t) {
    $proximoId = max($proximoId, $t['id'] + 1);
}

$novaTarefa = [
    'id' => $proximoId,
    'titulo' => $titulo,
    'descricao' => $descricao,
    'coluna' => $coluna,
    'prioridade' => $prioridade,
    'responsaveis' => $responsaveis,
    'prazo' => $prazo !== '' ? $prazo : date('Y-m-d'),
    'comentarios' => 0,
];

echo json_encode(['success' => true, 'task' => $novaTarefa], JSON_UNESCAPED_UNICODE);
