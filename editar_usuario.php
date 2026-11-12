<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/funciones.php';

requerirAdmin($conexion);

$id = filter_var($_POST['id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Identificador inválido.');
}

try {
    $stmt = $conexion->prepare('SELECT id, nombre, apellido, dni, email
        FROM usuarios WHERE id = :id AND archivado_en IS NULL LIMIT 1');
    $stmt->execute(['id' => $id]);
    $registro = $stmt->fetch();
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('No se pudo consultar el usuario.');
}

if ($registro === false) {
    http_response_code(404);
    exit('El usuario no existe o está archivado.');
}

$errores = [];
$nombre = $registro['nombre'];
$apellido = $registro['apellido'];
$dni = $registro['dni'];
$email = $registro['email'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCsrf($_POST['csrf_token'] ?? null)) {
        $errores[] = 'La solicitud no es válida. Recargá la página e intentá de nuevo.';
    }

    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $dni = trim($_POST['dni'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($nombre === '' || mb_strlen($nombre) > 80) {
        $errores[] = 'El nombre es obligatorio (máximo 80 caracteres).';
    }
    if ($apellido === '' || mb_strlen($apellido) > 80) {
        $errores[] = 'El apellido es obligatorio (máximo 80 caracteres).';
    }
    if (!preg_match('/^[0-9]{7,8}$/', $dni)) {
        $errores[] = 'El DNI debe tener 7 u 8 números.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $errores[] = 'Ingresá un correo válido.';
    }

    if (empty($errores)) {
        try {
            $stmt = $conexion->prepare('SELECT dni, email FROM usuarios
                WHERE (dni = :dni OR email = :email) AND id <> :id');
            $stmt->execute(['dni' => $dni, 'email' => $email, 'id' => $id]);
            foreach ($stmt->fetchAll() as $fila) {
                if ($fila['dni'] === $dni) {
                    $errores[] = 'Ese DNI ya pertenece a otro usuario.';
                }
                if (strtolower($fila['email']) === strtolower($email)) {
                    $errores[] = 'Ese correo ya pertenece a otro usuario.';
                }
            }

            if (empty($errores)) {
                // El rol y la contraseña NO se tocan acá
                $stmt = $conexion->prepare('UPDATE usuarios
                    SET nombre = :nombre, apellido = :apellido, dni = :dni, email = :email
                    WHERE id = :id AND archivado_en IS NULL');
                $stmt->execute([
                    'nombre' => $nombre,
                    'apellido' => $apellido,
                    'dni' => $dni,
                    'email' => $email,
                    'id' => $id
                ]);

                guardarMensaje('ok', 'Datos actualizados correctamente.');
                header('Location: admin_usuarios.php');
                exit;
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errores[] = 'El DNI o el correo ya están registrados.';
            } else {
                error_log($e->getMessage());
                $errores[] = 'Ocurrió un error al actualizar los datos.';
            }
        }
    }
}

abrirPagina('Editar usuario');
?>
<h1>Editar usuario #<?= (int)$id ?></h1>

<?php if (!empty($errores)): ?>
    <ul class="errores">
        <?php foreach ($errores as $error): ?>
            <li><?= escapar($error) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form action="editar_usuario.php" method="POST" class="formulario">
    <input type="hidden" name="csrf_token" value="<?= escapar(generarTokenCsrf()) ?>">
    <input type="hidden" name="id" value="<?= (int)$id ?>">

    <label for="nombre">Nombre:</label>
    <input type="text" id="nombre" name="nombre" maxlength="80" value="<?= escapar($nombre) ?>" required>

    <label for="apellido">Apellido:</label>
    <input type="text" id="apellido" name="apellido" maxlength="80" value="<?= escapar($apellido) ?>" required>

    <label for="dni">DNI:</label>
    <input type="text" id="dni" name="dni" inputmode="numeric" pattern="[0-9]{7,8}" maxlength="8" value="<?= escapar($dni) ?>" required>

    <label for="email">Correo electrónico:</label>
    <input type="email" id="email" name="email" maxlength="254" value="<?= escapar($email) ?>" required>

    <button type="submit">Guardar cambios</button>
    <a href="admin_usuarios.php">Cancelar</a>
</form>
<?php cerrarPagina(); ?>