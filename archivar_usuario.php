<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/funciones.php';

$admin = requerirAdmin($conexion);

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

if ($id === (int)$admin['id']) {
    guardarMensaje('error', 'No podés archivar tu propia cuenta.');
    header('Location: admin_usuarios.php');
    exit;
}

try {
    $stmt = $conexion->prepare('UPDATE usuarios
        SET archivado_en = CURRENT_TIMESTAMP
        WHERE id = :id
        AND archivado_en IS NULL');
    $stmt->execute(['id' => $id]);

    if ($stmt->rowCount() === 1) {
        guardarMensaje('ok', 'Usuario archivado correctamente.');
    } else {
        guardarMensaje('error', 'No se pudo archivar el usuario.');
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    guardarMensaje('error', 'Ocurrió un error al archivar el usuario.');
}

header('Location: admin_usuarios.php');
exit;