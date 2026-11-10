<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/funciones.php';

$usuario = requerirLogin($conexion);
$errores = [];

// ---------- Cancelar un pedido ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cancelar') {
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
        // Solo se cancela un pedido propio y que siga pendiente
        $stmt = $conexion->prepare("UPDATE pedidos
            SET estado = 'cancelado'
            WHERE id = :id AND usuario_id = :usuario_id AND estado = 'pendiente'");
        $stmt->execute(['id' => $id, 'usuario_id' => $usuario['id']]);

        if ($stmt->rowCount() === 1) {
            guardarMensaje('ok', 'Pedido #' . $id . ' cancelado.');
        } else {
            guardarMensaje('error', 'No se pudo cancelar el pedido.');
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        guardarMensaje('error', 'Ocurrió un error al cancelar el pedido.');
    }

    header('Location: pedidos.php');
    exit;
}

// ---------- Dirección (sin dirección no se puede pedir) ----------
try {
    $direccion = obtenerDireccion($conexion, (int)$usuario['id']);
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('No se pudo consultar la dirección.');
}

if ($direccion === false) {
    guardarMensaje('error', 'Primero tenés que cargar tu dirección de entrega.');
    header('Location: panel.php');
    exit;
}

// ---------- ¿Estamos modificando un pedido existente? ----------
$editandoId = 0;
$idParam = $_GET['id'] ?? $_POST['id'] ?? '';

if ($idParam !== '') {
    $editandoId = filter_var($idParam, FILTER_VALIDATE_INT);
    if ($editandoId === false || $editandoId < 1) {
        http_response_code(400);
        exit('Identificador inválido.');
    }

    try {
        $stmt = $conexion->prepare("SELECT id FROM pedidos
            WHERE id = :id AND usuario_id = :usuario_id AND estado = 'pendiente' LIMIT 1");
        $stmt->execute(['id' => $editandoId, 'usuario_id' => $usuario['id']]);
        if ($stmt->fetch() === false) {
            guardarMensaje('error', 'El pedido no existe o ya no se puede modificar.');
            header('Location: pedidos.php');
            exit;
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        http_response_code(500);
        exit('No se pudo consultar el pedido.');
    }
}

// Cantidades que se muestran en el formulario: [plato_id => cantidad]
$actuales = [];
if ($editandoId > 0) {
    try {
        $stmt = $conexion->prepare('SELECT plato_id, cantidad FROM pedido_items WHERE pedido_id = :pedido_id');
        $stmt->execute(['pedido_id' => $editandoId]);
        $actuales = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        http_response_code(500);
        exit('No se pudo cargar el pedido.');
    }
}

// ---------- Guardar (pedido nuevo o modificado) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    if (!validarTokenCsrf($_POST['csrf_token'] ?? null)) {
        $errores[] = 'La solicitud no es válida. Recargá la página e intentá de nuevo.';
    }

    $cantidades = $_POST['cantidad'] ?? [];
    $elegidos = [];
    $actuales = [];

    if (!is_array($cantidades)) {
        $errores[] = 'Los datos del pedido no son válidos.';
    } else {
        foreach ($cantidades as $platoId => $cantidad) {
            $platoId = filter_var($platoId, FILTER_VALIDATE_INT);
            $cantidad = filter_var($cantidad, FILTER_VALIDATE_INT);

            if ($platoId === false || $platoId < 1 || $cantidad === false || $cantidad < 0 || $cantidad > 20) {
                $errores[] = 'Hay una cantidad inválida (máximo 20 por plato).';
                break;
            }
            $actuales[$platoId] = $cantidad;
            if ($cantidad > 0) {
                $elegidos[$platoId] = $cantidad;
            }
        }
    }

    if (empty($errores) && empty($elegidos)) {
        $errores[] = 'Elegí al menos un plato.';
    }

    if (empty($errores)) {
        try {
            // El precio se toma de la base de datos, nunca del formulario
            $ids = array_keys($elegidos);
            $marcadores = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $conexion->prepare("SELECT id, precio FROM platos WHERE disponible = 1 AND id IN ($marcadores)");
            $stmt->execute($ids);
            $platosElegidos = $stmt->fetchAll();

            if (count($platosElegidos) !== count($ids)) {
                $errores[] = 'Alguno de los platos elegidos no está disponible.';
            } else {
                $total = 0;
                foreach ($platosElegidos as $plato) {
                    $total += $plato['precio'] * $elegidos[(int)$plato['id']];
                }

                // Pedido e items se guardan juntos (si algo falla, no se guarda nada)
                $conexion->beginTransaction();

                if ($editandoId > 0) {
                    $pedidoId = $editandoId;

                    $stmt = $conexion->prepare("UPDATE pedidos
                        SET total = :total, direccion_entrega = :direccion
                        WHERE id = :id AND usuario_id = :usuario_id AND estado = 'pendiente'");
                    $stmt->execute([
                        'total' => $total,
                        'direccion' => textoDireccion($direccion),
                        'id' => $pedidoId,
                        'usuario_id' => $usuario['id']
                    ]);

                    $stmt = $conexion->prepare('DELETE FROM pedido_items WHERE pedido_id = :pedido_id');
                    $stmt->execute(['pedido_id' => $pedidoId]);
                } else {
                    $stmt = $conexion->prepare('INSERT INTO pedidos (usuario_id, direccion_entrega, total)
                        VALUES (:usuario_id, :direccion, :total)');
                    $stmt->execute([
                        'usuario_id' => $usuario['id'],
                        'direccion' => textoDireccion($direccion),
                        'total' => $total
                    ]);
                    $pedidoId = (int)$conexion->lastInsertId();
                }

                $stmtItem = $conexion->prepare('INSERT INTO pedido_items (pedido_id, plato_id, cantidad, precio_unitario)
                    VALUES (:pedido_id, :plato_id, :cantidad, :precio)');
                foreach ($platosElegidos as $plato) {
                    $stmtItem->execute([
                        'pedido_id' => $pedidoId,
                        'plato_id' => $plato['id'],
                        'cantidad' => $elegidos[(int)$plato['id']],
                        'precio' => $plato['precio']
                    ]);
                }

                $conexion->commit();

                if ($editandoId > 0) {
                    guardarMensaje('ok', 'Pedido #' . $pedidoId . ' modificado correctamente.');
                } else {
                    guardarMensaje('ok', 'Pedido #' . $pedidoId . ' realizado correctamente.');
                }
                header('Location: pedidos.php');
                exit;
            }
        } catch (PDOException $e) {
            if ($conexion->inTransaction()) {
                $conexion->rollBack();
            }
            error_log($e->getMessage());
            $errores[] = 'Ocurrió un error al guardar el pedido.';
        }
    }
}

// ---------- Datos para mostrar ----------
try {
    $consulta = $conexion->query('SELECT id, nombre, descripcion, precio FROM platos WHERE disponible = 1 ORDER BY id');
    $platos = $consulta->fetchAll();

    $sql = 'SELECT p.id, p.estado, p.total, p.direccion_entrega, p.creado_en,
                   i.cantidad, i.precio_unitario, pl.nombre
            FROM pedidos p
            JOIN pedido_items i ON i.pedido_id = p.id
            JOIN platos pl ON pl.id = i.plato_id
            WHERE p.usuario_id = :usuario_id
            ORDER BY p.id DESC, pl.nombre';
    $stmt = $conexion->prepare($sql);
    $stmt->execute(['usuario_id' => $usuario['id']]);
    $filas = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('No se pudo cargar la información de los pedidos.');
}

// Agrupamos las filas por pedido
$pedidos = [];
foreach ($filas as $fila) {
    $id = $fila['id'];
    if (!isset($pedidos[$id])) {
        $pedidos[$id] = [
            'id' => $id,
            'estado' => $fila['estado'],
            'total' => $fila['total'],
            'direccion' => $fila['direccion_entrega'],
            'fecha' => $fila['creado_en'],
            'items' => []
        ];
    }
    $pedidos[$id]['items'][] = [
        'nombre' => $fila['nombre'],
        'cantidad' => $fila['cantidad'],
        'precio' => $fila['precio_unitario']
    ];
}

abrirPagina('Pedidos');
?>
<h1><?= $editandoId > 0 ? 'Modificar pedido #' . (int)$editandoId : 'Hacer un pedido' ?></h1>

<p><strong>Entregamos en:</strong> <?= escapar(textoDireccion($direccion)) ?>
    · <a href="panel.php">Cambiar dirección</a></p>

<?php if (!empty($errores)): ?>
    <ul class="errores">
        <?php foreach ($errores as $error): ?>
            <li><?= escapar($error) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if (empty($platos)): ?>
    <p>No hay platos disponibles por el momento.</p>
<?php else: ?>
    <form action="pedidos.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?= escapar(generarTokenCsrf()) ?>">
        <input type="hidden" name="accion" value="guardar">
        <?php if ($editandoId > 0): ?>
            <input type="hidden" name="id" value="<?= (int)$editandoId ?>">
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>Plato</th>
                    <th>Descripción</th>
                    <th>Precio</th>
                    <th>Cantidad</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($platos as $plato): ?>
                    <tr>
                        <td><?= escapar($plato['nombre']) ?></td>
                        <td><?= escapar($plato['descripcion'] ?? '') ?></td>
                        <td>$<?= escapar(number_format((float)$plato['precio'], 2, ',', '.')) ?></td>
                        <td>
                            <input type="number" class="cantidad"
                                   name="cantidad[<?= (int)$plato['id'] ?>]"
                                   data-precio="<?= escapar((string)$plato['precio']) ?>"
                                   min="0" max="20"
                                   value="<?= (int)($actuales[$plato['id']] ?? 0) ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="total">Total: $<span id="total">0,00</span></p>

        <button type="submit"><?= $editandoId > 0 ? 'Guardar cambios' : 'Confirmar pedido' ?></button>
        <?php if ($editandoId > 0): ?>
            <a href="pedidos.php">Cancelar modificación</a>
        <?php endif; ?>
    </form>

    <script>
        // Solo muestra el total mientras elegís. El total real lo calcula el servidor.
        var campos = document.querySelectorAll('.cantidad');
        var totalSpan = document.getElementById('total');

        function calcularTotal() {
            var total = 0;
            campos.forEach(function (campo) {
                var cantidad = parseInt(campo.value) || 0;
                total += cantidad * parseFloat(campo.dataset.precio);
            });
            totalSpan.textContent = total.toLocaleString('es-AR', { minimumFractionDigits: 2 });
        }

        campos.forEach(function (campo) {
            campo.addEventListener('input', calcularTotal);
        });
        calcularTotal();
    </script>
<?php endif; ?>

<h2>Mis pedidos</h2>

<?php if (empty($pedidos)): ?>
    <p>Todavía no hiciste ningún pedido.</p>
<?php else: ?>
    <?php foreach ($pedidos as $pedido): ?>
        <section class="tarjeta">
            <h3>
                Pedido #<?= (int)$pedido['id'] ?>
                <span class="estado estado-<?= escapar($pedido['estado']) ?>"><?= escapar($pedido['estado']) ?></span>
            </h3>
            <p><small><?= escapar($pedido['fecha']) ?></small></p>
            <ul>
                <?php foreach ($pedido['items'] as $item): ?>
                    <li>
                        <?= (int)$item['cantidad'] ?> x <?= escapar($item['nombre']) ?>
                        ($<?= escapar(number_format((float)$item['precio'], 2, ',', '.')) ?> c/u)
                    </li>
                <?php endforeach; ?>
            </ul>
            <p><strong>Entrega en:</strong> <?= escapar($pedido['direccion']) ?></p>
            <p><strong>Total:</strong> $<?= escapar(number_format((float)$pedido['total'], 2, ',', '.')) ?></p>

            <?php if ($pedido['estado'] === 'pendiente'): ?>
                <a class="boton" href="pedidos.php?id=<?= (int)$pedido['id'] ?>">Modificar pedido</a>

                <form action="pedidos.php" method="POST" class="en-linea"
                      onsubmit="return confirm('¿Seguro que querés cancelar este pedido?');">
                    <input type="hidden" name="csrf_token" value="<?= escapar(generarTokenCsrf()) ?>">
                    <input type="hidden" name="accion" value="cancelar">
                    <input type="hidden" name="id" value="<?= (int)$pedido['id'] ?>">
                    <button type="submit" class="boton-peligro">Cancelar pedido</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<?php cerrarPagina(); ?>