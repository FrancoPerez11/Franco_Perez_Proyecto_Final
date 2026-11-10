<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/funciones.php';

$admin = requerirAdmin($conexion);

$errores = [];
$nombre = '';
$apellido = '';
$dni = '';
$email = '';
$rol = 'usuario';
$rolesPermitidos = ['usuario', 'admin'];

// ---------- Registrar un usuario nuevo ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCsrf($_POST['csrf_token'] ?? null)) {
        $errores[] = 'La solicitud no es válida. Recargá la página e intentá de nuevo.';
    }

    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $dni = trim($_POST['dni'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $rol = $_POST['rol'] ?? 'usuario';
    $password = $_POST['password'] ?? '';

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
    if (!in_array($rol, $rolesPermitidos, true)) {
        $errores[] = 'El rol elegido no es válido.';
    }
    if (strlen($password) < 8) {
        $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
    }

    if (empty($errores)) {
        try {
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
                $stmt = $conexion->prepare('INSERT INTO usuarios (nombre, apellido, dni, email, password_hash, rol)
                    VALUES (:nombre, :apellido, :dni, :email, :password_hash, :rol)');
                $stmt->execute([
                    'nombre' => $nombre,
                    'apellido' => $apellido,
                    'dni' => $dni,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'rol' => $rol
                ]);

                guardarMensaje('ok', 'Usuario registrado correctamente.');
                header('Location: admin_usuarios.php');
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

// ---------- Listado con búsqueda ----------
$buscar = trim($_GET['buscar'] ?? '');

try {
    $sql = 'SELECT id, nombre, apellido, dni, email, rol, creado_en, archivado_en
            FROM usuarios
            WHERE archivado_en IS NULL';
    $parametros = [];

    if ($buscar !== '') {
        $sql .= ' AND (nombre LIKE :nombre OR apellido LIKE :apellido OR dni LIKE :dni OR email LIKE :email)';
        $termino = '%' . $buscar . '%';
        $parametros = [
            'nombre' => $termino,
            'apellido' => $termino,
            'dni' => $termino,
            'email' => $termino
        ];
    }
    $sql .= ' ORDER BY apellido, nombre';

    $stmt = $conexion->prepare($sql);
    $stmt->execute($parametros);
    $usuarios = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('No se pudo consultar la lista de usuarios.');
}

abrirPagina('Administrar usuarios');
?>
<h1>Administración de usuarios</h1>

<form method="GET" action="admin_usuarios.php" class="buscador">
    <input type="search" name="buscar" maxlength="100"
           placeholder="Nombre, apellido, DNI o correo" value="<?= escapar($buscar) ?>">
    <button type="submit">Buscar</button>
</form>

<?php if (empty($usuarios)): ?>
    <p>No se encontraron usuarios.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>ID</th><th>Nombre</th><th>Apellido</th><th>DNI</th>
                <th>Correo</th><th>Rol</th><th>Estado</th><th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><?= escapar((string)$u['id']) ?></td>
                    <td><?= escapar($u['nombre']) ?></td>
                    <td><?= escapar($u['apellido']) ?></td>
                    <td><?= escapar($u['dni']) ?></td>
                    <td><?= escapar($u['email']) ?></td>
                    <td><?= escapar($u['rol']) ?></td>
                    <td>Activo</td>
                    <td>
                        <a href="editar_usuario.php?id=<?= (int)$u['id'] ?>">Editar</a>
                        <?php if ((int)$u['id'] !== (int)$admin['id']): ?>
                            <form action="archivar_usuario.php" method="POST" class="en-linea"
                                  onsubmit="return confirm('¿Archivar a este usuario?');">
                                <input type="hidden" name="csrf_token" value="<?= escapar(generarTokenCsrf()) ?>">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" class="boton-peligro">Archivar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Registrar nuevo usuario</h2>

<?php if (!empty($errores)): ?>
    <ul class="errores">
        <?php foreach ($errores as $error): ?>
            <li><?= escapar($error) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form action="admin_usuarios.php" method="POST" class="formulario">
    <input type="hidden" name="csrf_token" value="<?= escapar(generarTokenCsrf()) ?>">

    <label for="nombre">Nombre:</label>
    <input type="text" id="nombre" name="nombre" maxlength="80" value="<?= escapar($nombre) ?>" required>

    <label for="apellido">Apellido:</label>
    <input type="text" id="apellido" name="apellido" maxlength="80" value="<?= escapar($apellido) ?>" required>

    <label for="dni">DNI:</label>
    <input type="text" id="dni" name="dni" inputmode="numeric" pattern="[0-9]{7,8}" maxlength="8" value="<?= escapar($dni) ?>" required>

    <label for="email">Correo electrónico:</label>
    <input type="email" id="email" name="email" maxlength="254" value="<?= escapar($email) ?>" required>

    <label for="rol">Rol:</label>
    <select id="rol" name="rol">
        <option value="usuario" <?= $rol === 'usuario' ? 'selected' : '' ?>>Usuario</option>
        <option value="admin" <?= $rol === 'admin' ? 'selected' : '' ?>>Administrador</option>
    </select>

    <label for="password">Contraseña inicial (mínimo 8 caracteres):</label>
    <input type="password" id="password" name="password" required>

    <button type="submit">Registrar usuario</button>
</form>
<?php cerrarPagina(); ?>