<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/funciones.php';

if (isset($_SESSION['usuario_id'])) {
    header('Location: panel.php');
    exit;
}

$errores = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $errores[] = 'Completá el correo y la contraseña.';
    } else {
        try {
            $sql = 'SELECT id, nombre, email, password_hash, rol FROM usuarios
                    WHERE email = :email
                    AND archivado_en IS NULL
                    LIMIT 1';
            $stmt = $conexion->prepare($sql);
            $stmt->execute(['email' => $email]);
            $usuario = $stmt->fetch();

            if ($usuario !== false && password_verify($password, $usuario['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['usuario_id'] = (int)$usuario['id'];
                $_SESSION['usuario_rol'] = $usuario['rol'];

                if ($usuario['rol'] === 'admin') {
                    header('Location: admin_usuarios.php');
                } else {
                    header('Location: panel.php');
                }
                exit;
            } else {
                $errores[] = 'El correo o la contraseña son incorrectos.';
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errores[] = 'Ocurrió un error. Intentá de nuevo más tarde.';
        }
    }
}

abrirPagina('Iniciar sesión');
?>
<h1>Iniciar sesión</h1>

<?php if (!empty($errores)): ?>
    <ul class="errores">
        <?php foreach ($errores as $error): ?>
            <li><?= escapar($error) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form action="index.php" method="POST" class="formulario">
    <label for="email">Correo electrónico:</label>
    <input type="email" id="email" name="email" maxlength="254" value="<?= escapar($email) ?>" required>

    <label for="password">Contraseña:</label>
    <input type="password" id="password" name="password" required>

    <button type="submit">Ingresar</button>
</form>
<p>¿No tenés cuenta? <a href="registro.php">Registrate acá</a></p>
<?php cerrarPagina(); ?>