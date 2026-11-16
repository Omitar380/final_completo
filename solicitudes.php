<?php
session_start();
require 'conexcion.php';

if (!isset($_SESSION['id_usuario'], $_SESSION['rol']) || $_SESSION['rol'] !== 'Tutor') {
    header('Location: dashboard.php');
    exit();
}

$idTutor = (int) $_SESSION['id_usuario'];
$mensaje = '';
$tipoMensaje = '';
$solicitudes = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idReserva = filter_input(INPUT_POST, 'id_reserva', FILTER_VALIDATE_INT);
    $nuevoEstado = $_POST['nuevo_estado'] ?? '';
    $estadoPermitido = in_array($nuevoEstado, ['Aceptada', 'Rechazada', 'Completada'], true);
    if (!$idReserva || !$estadoPermitido) {
        $mensaje = 'La acción solicitada no es válida.';
        $tipoMensaje = 'error';
    } else {
        try {
            if ($nuevoEstado === 'Completada') {
                $stmt = $pdo->prepare("UPDATE reservas_sesiones SET estado = 'Completada'
                    WHERE id_reserva = ? AND id_tutor = ? AND estado = 'Aceptada'
                    AND (fecha_sesion < CURDATE() OR (fecha_sesion = CURDATE() AND hora_fin <= CURTIME()))");
                $stmt->execute([$idReserva, $idTutor]);
            } else {
                $stmt = $pdo->prepare("UPDATE reservas_sesiones SET estado = ? WHERE id_reserva = ? AND id_tutor = ? AND estado = 'Pendiente'");
                $stmt->execute([$nuevoEstado, $idReserva, $idTutor]);
            }
            if ($stmt->rowCount() === 1) {
                $mensaje = $nuevoEstado === 'Aceptada'
                    ? 'Aceptaste la solicitud.'
                    : ($nuevoEstado === 'Rechazada' ? 'Rechazaste la solicitud.' : 'La sesión quedó marcada como completada.');
                $tipoMensaje = 'success';
            } else {
                $mensaje = 'La solicitud ya fue procesada o no te pertenece.';
                $tipoMensaje = 'error';
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $mensaje = 'No se pudo actualizar la solicitud. Inténtalo de nuevo.';
            $tipoMensaje = 'error';
        }
    }
}

try {
    $stmt = $pdo->prepare("SELECT rs.id_reserva, rs.fecha_sesion, rs.hora_inicio, rs.hora_fin, rs.modalidad, rs.estado,
            m.nombre_materia, u.nombre AS nombre_estudiante, u.apellido AS apellido_estudiante
        FROM reservas_sesiones rs
        INNER JOIN materias m ON m.id_materia = rs.id_materia
        INNER JOIN usuarios u ON u.id_usuario = rs.id_estudiante
        WHERE rs.id_tutor = ?
        ORDER BY (rs.estado = 'Pendiente') DESC, rs.fecha_sesion DESC, rs.hora_inicio");
    $stmt->execute([$idTutor]);
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $mensaje = 'No se pudieron cargar las solicitudes.';
    $tipoMensaje = 'error';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Solicitudes - TutoresOnLine</title>
    <link rel="stylesheet" href="CSS/estilos.css?v=<?php echo filemtime(__DIR__ . '/CSS/estilos.css'); ?>">
</head>
<body class="app-page">
    <div class="app-layout">
        <aside class="app-sidebar">
            <a class="app-logo" href="dashboard.php"><img class="brand-logo-image" src="assets/logo-marca.png" alt=""><span>Tutores<span>OnLine</span></span></a>
            <p class="sidebar-role">Tutor</p>
            <nav class="app-nav" aria-label="Navegación principal">
                <a href="dashboard.php"><span>⌂</span> Inicio</a>
                <a href="perfil_tutor_config.php"><span>◉</span> Mi perfil de tutor</a>
                <a class="active" href="solicitudes.php"><span>▤</span> Solicitudes</a>
                <a href="perfil.php"><span>◌</span> Mi perfil y archivos</a>
                <a class="nav-logout" href="logout.php"><span>↪</span> Cerrar sesión</a>
            </nav>
        </aside>
        <main class="app-main">
            <header class="app-topbar"><div><p class="dashboard-kicker">PANEL DEL TUTOR</p><h1>Solicitudes de tutoría</h1></div><a class="button button--light" href="dashboard.php">Volver al panel</a></header>
            <p class="dashboard-intro">Responde a las solicitudes de los estudiantes y consulta las sesiones de tu cuenta.</p>
            <?php if ($mensaje !== ''): ?><div class="notice notice--<?php echo $tipoMensaje; ?>" role="alert"><?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($solicitudes): ?>
                <div class="request-list">
                    <?php foreach ($solicitudes as $solicitud): ?>
                        <article class="request-card">
                            <div class="request-card-main">
                                <div class="request-card-heading"><div><p class="dashboard-kicker"><?php echo htmlspecialchars($solicitud['nombre_materia'], ENT_QUOTES, 'UTF-8'); ?></p><h2><?php echo htmlspecialchars($solicitud['nombre_estudiante'] . ' ' . $solicitud['apellido_estudiante'], ENT_QUOTES, 'UTF-8'); ?></h2></div><span class="request-status request-status--<?php echo strtolower($solicitud['estado']); ?>"><?php echo htmlspecialchars($solicitud['estado'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                                <p><?php echo htmlspecialchars($solicitud['fecha_sesion'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars(substr($solicitud['hora_inicio'], 0, 5) . ' - ' . substr($solicitud['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($solicitud['modalidad'], ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <?php if ($solicitud['estado'] === 'Pendiente'): ?>
                                <div class="request-actions">
                                    <form method="POST"><input type="hidden" name="id_reserva" value="<?php echo (int) $solicitud['id_reserva']; ?>"><input type="hidden" name="nuevo_estado" value="Aceptada"><button class="button button--accept" type="submit">Aceptar</button></form>
                                    <form method="POST"><input type="hidden" name="id_reserva" value="<?php echo (int) $solicitud['id_reserva']; ?>"><input type="hidden" name="nuevo_estado" value="Rechazada"><button class="button button--reject" type="submit">Rechazar</button></form>
                                </div>
                            <?php elseif ($solicitud['estado'] === 'Aceptada' && ($solicitud['fecha_sesion'] < date('Y-m-d') || ($solicitud['fecha_sesion'] === date('Y-m-d') && $solicitud['hora_fin'] <= date('H:i:s')))): ?>
                                <div class="request-actions"><form method="POST"><input type="hidden" name="id_reserva" value="<?php echo (int) $solicitud['id_reserva']; ?>"><input type="hidden" name="nuevo_estado" value="Completada"><button class="button button--accept" type="submit">Marcar completada</button></form></div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><h2>No tienes solicitudes todavía</h2><p>Cuando un estudiante solicite una sesión contigo, aparecerá en esta página.</p></div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
