<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/funciones.php';

if (isset($_SESSION['usuario_id'])) {
    header('Location: panel.php');
    exit;
}

$errores = [];
$nombre = '';
$apellido = '';
$dni = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCsrf($_POST['csrf_token'] ?? null)) {
        $errores[] = 'La solicitud no es válida. Recargá la página e intentá de nuevo.';
    }

    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $dni = trim($_POST['dni'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmar = $_POST['confirmar'] ?? '';

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
    if (strlen($password) < 8) {
        $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
    }
    if ($password !== $confirmar) {
        $errores[] = 'Las contraseñas no coinciden.';
    }

    if (empty($errores)) {
        try {
            // Chequeo de duplicados
            $stmt = $conexion->prepare('SELECT dni, email FROM usuarios WHERE dni = :dni OR email = :email');
            $stmt->execute(['dni' => $dni, 'email' => $email]);
            foreach ($stmt->fetchAll() as $fila) {
                if ($fila['dni'] === $dni) {
                    $errores[] = 'Ese DNI ya está registrado.';
                }
                if (strtolower($fila['email']) === strtolower($email)) {
                    $errores[] = 'Ese correo ya está registrado.';
                }
            }

            if (empty($errores)) {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $sql = 'INSERT INTO usuarios (nombre, apellido, dni, email, password_hash, rol)
                        VALUES (:nombre, :apellido, :dni, :email, :password_hash, :rol)';
                $stmt = $conexion->prepare($sql);
                $stmt->execute([
                    'nombre' => $nombre,
                    'apellido' => $apellido,
                    'dni' => $dni,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'rol' => 'usuario'   // siempre usuario, el rol se define en el servidor
                ]);

                guardarMensaje('ok', 'Cuenta creada correctamente. Ya podés iniciar sesión.');
                header('Location: login.php');
                exit;
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errores[] = 'El DNI o el correo ya están registrados.';
            } else {
                error_log($e->getMessage());
                $errores[] = 'Ocurrió un error al guardar los datos.';
            }
        }
    }
}

abrirPagina('Crear cuenta');
?>
<h1>Crear cuenta</h1>

<?php if (!empty($errores)): ?>
    <ul class="errores">
        <?php foreach ($errores as $error): ?>
            <li><?= escapar($error) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form action="registro.php" method="POST" class="formulario">
    <input type="hidden" name="csrf_token" value="<?= escapar(generarTokenCsrf()) ?>">

    <label for="nombre">Nombre:</label>
    <input type="text" id="nombre" name="nombre" maxlength="80" value="<?= escapar($nombre) ?>" required>

    <label for="apellido">Apellido:</label>
    <input type="text" id="apellido" name="apellido" maxlength="80" value="<?= escapar($apellido) ?>" required>

    <label for="dni">DNI:</label>
    <input type="text" id="dni" name="dni" inputmode="numeric" pattern="[0-9]{7,8}" maxlength="8" value="<?= escapar($dni) ?>" required>

    <label for="email">Correo electrónico:</label>
    <input type="email" id="email" name="email" maxlength="254" value="<?= escapar($email) ?>" required>

    <label for="password">Contraseña (mínimo 8 caracteres):</label>
    <input type="password" id="password" name="password" required>

    <label for="confirmar">Repetir contraseña:</label>
    <input type="password" id="confirmar" name="confirmar" required>

    <button type="submit">Crear cuenta</button>
</form>
<p>¿Ya tenés cuenta? <a href="login.php">Iniciá sesión</a></p>
<?php cerrarPagina(); ?>