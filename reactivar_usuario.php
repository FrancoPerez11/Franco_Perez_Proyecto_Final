<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/funciones.php';

requerirAdmin($conexion);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

if (!validarTokenCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Solicitud no válida.');
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Identificador inválido.');
}

try {
    $stmt = $conexion->prepare('UPDATE usuarios
        SET archivado_en = NULL
        WHERE id = :id
        AND archivado_en IS NOT NULL');
    $stmt->execute(['id' => $id]);

    if ($stmt->rowCount() === 1) {
        guardarMensaje('ok', 'Usuario reactivado correctamente.');
    } else {
        guardarMensaje('error', 'No se pudo reactivar el usuario.');
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    guardarMensaje('error', 'Ocurrió un error al reactivar el usuario.');
}

header('Location: usuarios_archivados.php');
exit;