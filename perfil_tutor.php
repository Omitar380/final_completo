<?php
session_start();
require 'conexcion.php';

if (!isset($_SESSION['id_usuario'], $_SESSION['rol']) || $_SESSION['rol'] !== 'Estudiante') {
    header('Location: dashboard.php');
    exit();
}

$idTutor = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$tutor = null;
$materias = [];
$horarios = [];
$resenas = [];
$errorPagina = '';

if ($idTutor) {
    try {
        $stmt = $pdo->prepare("SELECT u.id_usuario, u.nombre, u.apellido, u.ciudad, u.foto_perfil, pt.biografia, pt.tarifa_por_hora, pt.calificacion_promedio, pt.modalidad_preferida
            FROM usuarios u INNER JOIN perfiles_tutores pt ON pt.id_tutor = u.id_usuario
            WHERE u.id_usuario = ? AND u.rol = 'Tutor'");
        $stmt->execute([$idTutor]);
        $tutor = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($tutor) {
            $stmt = $pdo->prepare('SELECT m.nombre_materia, m.categoria FROM tutores_materias tm INNER JOIN materias m ON m.id_materia = tm.id_materia WHERE tm.id_tutor = ? ORDER BY m.nombre_materia');
            $stmt->execute([$idTutor]);
            $materias = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare('SELECT dia_semana, hora_inicio, hora_fin FROM horarios_disponibles WHERE id_tutor = ? ORDER BY FIELD(dia_semana, \'Lunes\', \'Martes\', \'Miércoles\', \'Jueves\', \'Viernes\', \'Sábado\', \'Domingo\')');
            $stmt->execute([$idTutor]);
            $horarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare('SELECT rc.puntuacion, rc.comentario, rc.fecha_creacion, u.nombre, u.apellido
                FROM resenas_calificaciones rc
                INNER JOIN reservas_sesiones rs ON rs.id_reserva = rc.id_reserva
                INNER JOIN usuarios u ON u.id_usuario = rs.id_estudiante
                WHERE rs.id_tutor = ?
                ORDER BY rc.fecha_creacion DESC LIMIT 10');
            $stmt->execute([$idTutor]);
            $resenas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $errorPagina = 'No se pudo cargar este perfil. Inténtalo de nuevo.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $tutor ? 'Perfil de ' . htmlspecialchars($tutor['nombre'], ENT_QUOTES, 'UTF-8') : 'Perfil de tutor'; ?> - TutoresOnLine</title>
    <link rel="stylesheet" href="CSS/estilos.css?v=<?php echo filemtime(__DIR__ . '/CSS/estilos.css'); ?>">
</head>
<body class="app-page">
    <main class="public-profile">
        <a class="profile-back-link" href="buscar.php">← Volver a buscar tutores</a>
        <?php if ($errorPagina !== ''): ?>
            <div class="notice notice--error" role="alert"><?php echo htmlspecialchars($errorPagina, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php elseif ($tutor): ?>
            <section class="public-profile-header">
                <div class="public-profile-avatar"><?php if (!empty($tutor['foto_perfil'])): ?><img src="<?php echo htmlspecialchars($tutor['foto_perfil'], ENT_QUOTES, 'UTF-8'); ?>" alt="Foto de <?php echo htmlspecialchars($tutor['nombre'], ENT_QUOTES, 'UTF-8'); ?>"><?php else: ?><?php echo htmlspecialchars(strtoupper(substr($tutor['nombre'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></div>
                <div class="public-profile-main">
                    <p class="dashboard-kicker">PERFIL DEL TUTOR</p>
                    <h1><?php echo htmlspecialchars($tutor['nombre'] . ' ' . $tutor['apellido'], ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p><?php echo htmlspecialchars($tutor['ciudad'] ?: 'Ubicación no indicada', ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($tutor['modalidad_preferida'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <div class="tutor-rating">★ <?php echo number_format((float) $tutor['calificacion_promedio'], 1); ?> <span>(<?php echo count($resenas); ?> reseñas recientes)</span></div>
                </div>
                <div class="public-profile-price">$<?php echo number_format((float) $tutor['tarifa_por_hora'], 2); ?><small> / hora</small></div>
            </section>

            <section class="public-profile-section">
                <h2>Sobre mí</h2>
                <p><?php echo nl2br(htmlspecialchars($tutor['biografia'] ?: 'Este tutor todavía no ha agregado una biografía.', ENT_QUOTES, 'UTF-8')); ?></p>
            </section>

            <section class="public-profile-section">
                <h2>materias que enseño</h2>
                <?php if ($materias): ?>
                    <div class="subject-pills"><?php foreach ($materias as $materia): ?><span><?php echo htmlspecialchars($materia['nombre_materia'], ENT_QUOTES, 'UTF-8'); ?></span><?php endforeach; ?></div>
                <?php else: ?>
                    <p>El tutor aún no ha agregado materias a su perfil.</p>
                <?php endif; ?>
            </section>

            <section class="public-profile-section">
                <h2>Disponibilidad semanal</h2>
                <?php if ($horarios): ?>
                    <ul class="availability-list"><?php foreach ($horarios as $horario): ?><li><strong><?php echo htmlspecialchars($horario['dia_semana'], ENT_QUOTES, 'UTF-8'); ?></strong><span><?php echo htmlspecialchars(substr($horario['hora_inicio'], 0, 5) . ' - ' . substr($horario['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8'); ?></span></li><?php endforeach; ?></ul>
                <?php else: ?>
                    <p>El tutor aún no ha publicado su disponibilidad. Vuelve más tarde.</p>
                <?php endif; ?>
            </section>

            <section class="public-profile-section">
                <h2>Reseñas de estudiantes</h2>
                <?php if ($resenas): ?>
                    <div class="review-list"><?php foreach ($resenas as $resena): ?><article class="review-item"><div class="review-item-heading"><strong><?php echo htmlspecialchars($resena['nombre'] . ' ' . $resena['apellido'], ENT_QUOTES, 'UTF-8'); ?></strong><span>★ <?php echo (int) $resena['puntuacion']; ?>/5</span></div><p><?php echo htmlspecialchars($resena['comentario'] ?: 'Sin comentario.', ENT_QUOTES, 'UTF-8'); ?></p></article><?php endforeach; ?></div>
                <?php else: ?>
                    <p>Este tutor todavía no tiene reseñas. Después de una tutoría completada, podrás dejar la tuya.</p>
                <?php endif; ?>
            </section>

            <?php if ($horarios && $materias): ?>
                <a class="button button--primary public-profile-book" href="reservar.php?id_tutor=<?php echo (int) $tutor['id_usuario']; ?>">Solicitar una tutoría</a>
            <?php else: ?>
                <p class="booking-disabled">No se puede solicitar una sesión hasta que el tutor publique materias y horarios.</p>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state"><h1>No encontramos este tutor</h1><p>El perfil puede haber sido retirado o el enlace no es válido.</p><a class="button button--primary" href="buscar.php">Buscar tutores</a></div>
        <?php endif; ?>
    </main>
</body>
</html>
