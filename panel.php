<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/funciones.php';

$usuario = requerirLogin($conexion);
$errores = [];

$calle = '';
$numero = '';
$piso = '';
$ciudad = '';
$referencia = '';

try {
    $direccion = obtenerDireccion($conexion, (int)$usuario['id']);
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('No se pudieron cargar tus datos.');
}

if ($direccion !== false) {
    $calle = $direccion['calle'];
    $numero = $direccion['numero'];
    $piso = $direccion['piso'] ?? '';
    $ciudad = $direccion['ciudad'];
    $referencia = $direccion['referencia'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCsrf($_POST['csrf_token'] ?? null)) {
        $errores[] = 'La solicitud no es válida. Recargá la página e intentá de nuevo.';
    }

    $calle = trim($_POST['calle'] ?? '');
    $numero = trim($_POST['numero'] ?? '');
    $piso = trim($_POST['piso'] ?? '');
    $ciudad = trim($_POST['ciudad'] ?? '');
    $referencia = trim($_POST['referencia'] ?? '');

    if ($calle === '' || mb_strlen($calle) > 100) {
        $errores[] = 'La calle es obligatoria (máximo 100 caracteres).';
    }
    if ($numero === '' || mb_strlen($numero) > 10) {
        $errores[] = 'El número es obligatorio (máximo 10 caracteres).';
    }
    if (mb_strlen($piso) > 20) {
        $errores[] = 'El piso/depto no puede superar los 20 caracteres.';
    }
    if ($ciudad === '' || mb_strlen($ciudad) > 80) {
        $errores[] = 'La ciudad es obligatoria (máximo 80 caracteres).';
    }
    if (mb_strlen($referencia) > 150) {
        $errores[] = 'La referencia no puede superar los 150 caracteres.';
    }

    if (empty($errores)) {
        try {
            $datos = [
                'usuario_id' => $usuario['id'],
                'calle' => $calle,
                'numero' => $numero,
                'piso' => $piso === '' ? null : $piso,
                'ciudad' => $ciudad,
                'referencia' => $referencia === '' ? null : $referencia
            ];

            if ($direccion === false) {
                $sql = 'INSERT INTO direcciones (usuario_id, calle, numero, piso, ciudad, referencia)
                        VALUES (:usuario_id, :calle, :numero, :piso, :ciudad, :referencia)';
            } else {
                $sql = 'UPDATE direcciones
                        SET calle = :calle, numero = :numero, piso = :piso,
                            ciudad = :ciudad, referencia = :referencia
                        WHERE usuario_id = :usuario_id';
            }
            $stmt = $conexion->prepare($sql);
            $stmt->execute($datos);

            guardarMensaje('ok', 'Dirección guardada correctamente.');
            header('Location: panel.php');
            exit;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errores[] = 'Ocurrió un error al guardar la dirección.';
        }
    }
}

abrirPagina('Mi panel');
?>
<h1>Hola, <?= escapar($usuario['nombre']) ?></h1>

<h2>Mis datos</h2>
<table>
    <tr><th>Nombre</th><td><?= escapar($usuario['nombre']) ?></td></tr>
    <tr><th>Apellido</th><td><?= escapar($usuario['apellido']) ?></td></tr>
    <tr><th>DNI</th><td><?= escapar($usuario['dni']) ?></td></tr>
    <tr><th>Correo</th><td><?= escapar($usuario['email']) ?></td></tr>
    <tr><th>Rol</th><td><?= escapar($usuario['rol']) ?></td></tr>
    <tr><th>Cuenta creada</th><td><?= escapar($usuario['creado_en']) ?></td></tr>
    <tr>
        <th>Dirección de entrega</th>
        <td>
            <?php if ($direccion === false): ?>
                Todavía no cargaste una dirección.
            <?php else: ?>
                <?= escapar(textoDireccion($direccion)) ?>
            <?php endif; ?>
        </td>
    </tr>
</table>

<p><a class="boton" href="pedidos.php">Hacer o ver mis pedidos</a></p>

<h2><?= $direccion === false ? 'Cargar dirección de entrega' : 'Cambiar dirección de entrega' ?></h2>

<?php if (!empty($errores)): ?>
    <ul class="errores">
        <?php foreach ($errores as $error): ?>
            <li><?= escapar($error) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form action="panel.php" method="POST" class="formulario">
    <input type="hidden" name="csrf_token" value="<?= escapar(generarTokenCsrf()) ?>">

    <label for="calle">Calle:</label>
    <input type="text" id="calle" name="calle" maxlength="100" value="<?= escapar($calle) ?>" required>

    <label for="numero">Número:</label>
    <input type="text" id="numero" name="numero" maxlength="10" value="<?= escapar($numero) ?>" required>

    <label for="piso">Piso / Depto (opcional):</label>
    <input type="text" id="piso" name="piso" maxlength="20" value="<?= escapar($piso) ?>">

    <label for="ciudad">Ciudad / Localidad:</label>
    <input type="text" id="ciudad" name="ciudad" maxlength="80" value="<?= escapar($ciudad) ?>" required>

    <label for="referencia">Referencia (opcional):</label>
    <input type="text" id="referencia" name="referencia" maxlength="150" placeholder="Ej: timbre B, portón negro" value="<?= escapar($referencia) ?>">

    <button type="submit"><?= $direccion === false ? 'Guardar dirección' : 'Actualizar dirección' ?></button>
</form>
<?php cerrarPagina(); ?>