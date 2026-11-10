<?php
// Funciones que usan casi todas las páginas
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function escapar(string $valor): string {
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

// ---------- CSRF ----------
function generarTokenCsrf(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validarTokenCsrf($token): bool {
    return isset($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

// ---------- Mensajes que se muestran una sola vez ----------
function guardarMensaje(string $tipo, string $texto): void {
    $_SESSION['mensaje'] = ['tipo' => $tipo, 'texto' => $texto];
}

function obtenerMensaje(): ?array {
    if (!isset($_SESSION['mensaje'])) {
        return null;
    }
    $mensaje = $_SESSION['mensaje'];
    unset($_SESSION['mensaje']);
    return $mensaje;
}

// ---------- Control de acceso ----------
// Devuelve los datos del usuario logueado (los lee de la base, no de la sesión)
function requerirLogin(PDO $conexion): array {
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: login.php');
        exit;
    }

    try {
        $stmt = $conexion->prepare('SELECT id, nombre, apellido, dni, email, rol, creado_en
            FROM usuarios WHERE id = :id AND archivado_en IS NULL LIMIT 1');
        $stmt->execute(['id' => $_SESSION['usuario_id']]);
        $usuario = $stmt->fetch();
    } catch (PDOException $e) {
        error_log($e->getMessage());
        http_response_code(500);
        exit('No se pudo verificar la sesión.');
    }

    // si lo archivaron mientras tenía la sesión abierta, lo sacamos
    if ($usuario === false) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }

    $_SESSION['usuario_rol'] = $usuario['rol'];
    return $usuario;
}

function requerirAdmin(PDO $conexion): array {
    $usuario = requerirLogin($conexion);
    if ($usuario['rol'] !== 'admin') {
        http_response_code(403);
        exit('Acceso denegado.');
    }
    return $usuario;
}

// ---------- Dirección de entrega ----------
function obtenerDireccion(PDO $conexion, int $usuarioId) {
    $stmt = $conexion->prepare('SELECT calle, numero, piso, ciudad, referencia
        FROM direcciones WHERE usuario_id = :usuario_id LIMIT 1');
    $stmt->execute(['usuario_id' => $usuarioId]);
    return $stmt->fetch();
}

// Arma el texto de la dirección que se guarda en cada pedido
function textoDireccion(array $d): string {
    $texto = $d['calle'] . ' ' . $d['numero'];
    if (!empty($d['piso'])) {
        $texto .= ', ' . $d['piso'];
    }
    $texto .= ', ' . $d['ciudad'];
    if (!empty($d['referencia'])) {
        $texto .= ' (' . $d['referencia'] . ')';
    }
    return $texto;
}

// ---------- Parte de arriba de cada página (menú + mensajes) ----------
function abrirPagina(string $titulo): void {
    $mensaje = obtenerMensaje();
    $logueado = isset($_SESSION['usuario_id']);
    $rol = $_SESSION['usuario_rol'] ?? '';
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escapar($titulo) ?> - Restaurante La Casona</title>
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body>
<header class="barra">
    <a class="logo" href="panel.php">Restaurante La Casona</a>
    <nav>
        <?php if ($logueado): ?>
            <a href="panel.php">Mi panel</a>
            <a href="pedidos.php">Pedidos</a>
            <?php if ($rol === 'admin'): ?>
                <a href="admin_usuarios.php">Usuarios</a>
                <a href="usuarios_archivados.php">Archivados</a>
            <?php endif; ?>
            <a href="cerrar_sesion.php">Cerrar sesión</a>
        <?php else: ?>
            <a href="login.php">Iniciar sesión</a>
            <a href="registro.php">Crear cuenta</a>
        <?php endif; ?>
    </nav>
</header>
<main class="contenedor">
    <?php if ($mensaje !== null): ?>
        <p class="aviso <?= $mensaje['tipo'] === 'ok' ? 'aviso-ok' : 'aviso-error' ?>">
            <?= escapar($mensaje['texto']) ?>
        </p>
    <?php endif; ?>
<?php
}

// ---------- Parte de abajo de cada página ----------
function cerrarPagina(): void {
    ?>
</main>
<footer class="pie">
    Restaurante La Casona · Trabajo práctico 4.º año
</footer>
</body>
</html>
<?php
}
?>