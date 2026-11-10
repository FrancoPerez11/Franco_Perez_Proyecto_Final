<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/funciones.php';

requerirAdmin($conexion);

try {
    $stmt = $conexion->query('SELECT id, nombre, apellido, dni, email, archivado_en
        FROM usuarios
        WHERE archivado_en IS NOT NULL
        ORDER BY archivado_en DESC');
    $usuarios = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('No se pudo consultar la lista de archivados.');
}

abrirPagina('Usuarios archivados');
?>
<h1>Usuarios archivados</h1>

<?php if (empty($usuarios)): ?>
    <p>No hay usuarios archivados.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>ID</th><th>Nombre</th><th>Apellido</th><th>DNI</th>
                <th>Correo</th><th>Archivado el</th><th>Acción</th>
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
                    <td><?= escapar($u['archivado_en']) ?></td>
                    <td>
                        <form action="reactivar_usuario.php" method="POST" class="en-linea"
                              onsubmit="return confirm('¿Reactivar a este usuario?');">
                            <input type="hidden" name="csrf_token" value="<?= escapar(generarTokenCsrf()) ?>">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <button type="submit">Reactivar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php cerrarPagina(); ?>