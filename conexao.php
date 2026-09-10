<?php
declare(strict_types=1);

/**
 * Conexão com o Banco de Dados MySQL
 * Disponibiliza a variável $conn para uso global.
 */

const DB_HOST_PADRAO = '127.0.0.1';
const DB_PORT_PADRAO = 3306;
const DB_NAME_PADRAO = 'planner';
const DB_USER_PADRAO = 'root';
const DB_PASS_PADRAO = '';

$dbHost = DB_HOST_PADRAO;
$dbPort = DB_PORT_PADRAO;
$dbName = DB_NAME_PADRAO;
$dbUser = DB_USER_PADRAO;
$dbPass = DB_PASS_PADRAO;

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $linhas = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($linhas as $linha) {
        $linha = trim($linha);
        if ($linha === '' || str_starts_with($linha, '#')) {
            continue;
        }
        $partes = explode('=', $linha, 2);
        if (count($partes) === 2) {
            $chave = trim($partes[0]);
            $valor = trim($partes[1], " \t\n\r\0\x0B\"'");
            match ($chave) {
                'DB_HOST' => $dbHost = $valor,
                'DB_PORT' => $dbPort = (int)$valor,
                'DB_NAME' => $dbName = $valor,
                'DB_USER' => $dbUser = $valor,
                'DB_PASS' => $dbPass = $valor,
                default   => null,
            };
        }
    }
}

mysqli_report(MYSQLI_REPORT_OFF);

try {
    $conn = @new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int)$dbPort);
    if ($conn->connect_error) {
        $conn = null;
    } else {
        $conn->set_charset('utf8mb4');
    }
} catch (Throwable) {
    $conn = null;
}
