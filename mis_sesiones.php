<?php
session_start();
require 'conexcion.php';

if (!isset($_SESSION['id_usuario'], $_SESSION['rol']) || $_SESSION['rol'] !== 'Estudiante') {
    header('Location: dashboard.php');
    exit();
}

$idEstudiante = (int) $_SESSION['id_usuario'];
$mensaje = '';
$tipoMensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idReserva = filter_input(INPUT_POST, 'id_reserva', FILTER_VALIDATE_INT);
    $puntuacion = filter_input(INPUT_POST, 'puntuacion', FILTER_VALIDATE_INT);
    $comentario = trim($_POST['comentario'] ?? '');

    if (!$idReserva || !$puntuacion || $puntuacion < 1 || $puntuacion > 5 || strlen($comentario) > 2000) {
        $mensaje = 'Selecciona una calificación de 1 a 5 y escribe un comentario de hasta 2000 caracteres.';
        $tipoMensaje = 'error';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id_tutor FROM reservas_sesiones WHERE id_reserva = ? AND id_estudiante = ? AND estado = 'Completada'");
            $stmt->execute([$idReserva, $idEstudiante]);
            $idTutor = $stmt->fetchColumn();
            if (!$idTutor) {
                $mensaje = 'Solo puedes calificar una sesión completada de tu cuenta.';
                $tipoMensaje = 'error';
            } else {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO resenas_calificaciones (id_reserva, puntuacion, comentario) VALUES (?, ?, ?)');
                $stmt->execute([$idReserva, $puntuacion, $comentario]);
                $stmt = $pdo->prepare('UPDATE perfiles_tutores SET calificacion_promedio = (SELECT AVG(rc.puntuacion) FROM resenas_calificaciones rc INNER JOIN reservas_sesiones rs ON rs.id_reserva = rc.id_reserva WHERE rs.id_tutor = ?) WHERE id_tutor = ?');
                $stmt->execute([$idTutor, $idTutor]);
                $pdo->commit();
                $mensaje = 'Gracias por compartir tu opinión.';
                $tipoMensaje = 'success';
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            $mensaje = $exception->getCode() === '23000'
                ? 'Ya enviaste una reseña para esta sesión.'
                : 'No se pudo guardar tu reseña. Inténtalo de nuevo.';
            $tipoMensaje = 'error';
        }
    }
}

$sesiones = [];
try {
    $stmt = $pdo->prepare('SELECT rs.id_reserva, rs.fecha_sesion, rs.hora_inicio, rs.hora_fin, rs.modalidad, rs.enlace_videoconferencia, rs.estado,
            m.nombre_materia, t.id_usuario AS id_tutor, t.nombre AS nombre_tutor, t.apellido AS apellido_tutor,
            rc.puntuacion AS mi_puntuacion
        FROM reservas_sesiones rs
        INNER JOIN materias m ON m.id_materia = rs.id_materia
        INNER JOIN usuarios t ON t.id_usuario = rs.id_tutor
        LEFT JOIN resenas_calificaciones rc ON rc.id_reserva = rs.id_reserva
        WHERE rs.id_estudiante = ?
        ORDER BY rs.fecha_sesion DESC, rs.hora_inicio DESC');
    $stmt->execute([$idEstudiante]);
    $sesiones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $mensaje = 'No se pudieron cargar tus sesiones.';
    $tipoMensaje = 'error';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mis sesiones - TutoresOnLine</title>
    <link rel="stylesheet" href="CSS/estilos.css?v=<?php echo filemtime(__DIR__ . '/CSS/estilos.css'); ?>">
</head>
<body class="app-page">
    <div class="app-layout">
        <aside class="app-sidebar">
            <a class="app-logo" href="dashboard.php"><img class="brand-logo-image" src="assets/logo-marca.png" alt=""><span>Tutores<span>OnLine</span></span></a>
            <p class="sidebar-role">Estudiante</p>
            <nav class="app-nav" aria-label="Navegación principal">
                <a href="dashboard.php"><span>⌂</span> Inicio</a>
                <a href="buscar.php"><span>⌕</span> Buscar tutores</a>
                <a class="active" href="mis_sesiones.php"><span>▦</span> Mis sesiones</a>
                <a href="asistente_ia.php"><span>✦</span> Asistente IA</a>
                <a href="perfil.php"><span>◌</span> Mi perfil y archivos</a>
                <a class="nav-logout" href="logout.php"><span>↪</span> Cerrar sesión</a>
            </nav>
        </aside>
        <main class="app-main">
            <header class="app-topbar"><div><p class="dashboard-kicker">PANEL DEL ESTUDIANTE</p><h1>Mis sesiones</h1></div><a class="button button--primary" href="buscar.php">Buscar tutor</a></header>
            <p class="dashboard-intro">Consulta el estado de tus solicitudes, tus próximas tutorías y el historial de sesiones.</p>
            <?php if ($mensaje !== ''): ?><div class="notice notice--<?php echo $tipoMensaje; ?>" role="alert"><?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($sesiones): ?>
                <div class="session-list">
                    <?php foreach ($sesiones as $sesion): ?>
                        <article class="session-card-new">
                            <div class="session-card-heading"><div><p class="dashboard-kicker"><?php echo htmlspecialchars($sesion['nombre_materia'], ENT_QUOTES, 'UTF-8'); ?></p><h2><?php echo htmlspecialchars($sesion['nombre_tutor'] . ' ' . $sesion['apellido_tutor'], ENT_QUOTES, 'UTF-8'); ?></h2></div><span class="request-status request-status--<?php echo strtolower($sesion['estado']); ?>"><?php echo htmlspecialchars($sesion['estado'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                            <p><?php echo htmlspecialchars($sesion['fecha_sesion'] . ' · ' . substr($sesion['hora_inicio'], 0, 5) . ' - ' . substr($sesion['hora_fin'], 0, 5) . ' · ' . $sesion['modalidad'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php if ($sesion['estado'] === 'Aceptada' && $sesion['modalidad'] === 'Virtual' && $sesion['enlace_videoconferencia']): ?><a class="text-link" href="<?php echo htmlspecialchars($sesion['enlace_videoconferencia'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Entrar a la videoconferencia →</a><?php endif; ?>
                            <?php if ($sesion['estado'] === 'Completada' && $sesion['mi_puntuacion'] === null): ?>
                                <form class="review-form" method="POST">
                                    <input type="hidden" name="id_reserva" value="<?php echo (int) $sesion['id_reserva']; ?>">
                                    <label for="puntuacion-<?php echo (int) $sesion['id_reserva']; ?>">Tu calificación</label>
                                    <select id="puntuacion-<?php echo (int) $sesion['id_reserva']; ?>" name="puntuacion" required><option value="">Elige de 1 a 5 estrellas</option><?php for ($estrella = 5; $estrella >= 1; $estrella--): ?><option value="<?php echo $estrella; ?>"><?php echo $estrella; ?> estrellas</option><?php endfor; ?></select>
                                    <label for="comentario-<?php echo (int) $sesion['id_reserva']; ?>">Comentario (opcional)</label>
                                    <textarea id="comentario-<?php echo (int) $sesion['id_reserva']; ?>" name="comentario" rows="2" maxlength="2000"></textarea>
                                    <button class="button button--primary" type="submit">Enviar reseña</button>
                                </form>
                            <?php elseif ($sesion['mi_puntuacion'] !== null): ?>
                                <p class="review-submitted">Tu calificación: ★ <?php echo (int) $sesion['mi_puntuacion']; ?>/5</p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><h2>Aún no tienes sesiones</h2><p>Cuando solicites una tutoría, podrás seguir su estado y consultar los detalles desde aquí.</p><a class="button button--primary" href="buscar.php">Explorar tutores</a></div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
