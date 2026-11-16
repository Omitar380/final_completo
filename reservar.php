<?php
session_start();
require 'conexcion.php';

if (!isset($_SESSION['id_usuario'], $_SESSION['rol']) || $_SESSION['rol'] !== 'Estudiante') {
    header('Location: dashboard.php');
    exit();
}

$idEstudiante = (int) $_SESSION['id_usuario'];
$idTutor = filter_input(INPUT_GET, 'id_tutor', FILTER_VALIDATE_INT);
$tutor = null;
$materias = [];
$horarios = [];
$mensaje = '';
$tipoMensaje = '';
$errorPagina = '';

if ($idTutor) {
    try {
        $stmt = $pdo->prepare("SELECT u.nombre, u.apellido, pt.tarifa_por_hora, pt.modalidad_preferida
            FROM usuarios u INNER JOIN perfiles_tutores pt ON pt.id_tutor = u.id_usuario
            WHERE u.id_usuario = ? AND u.rol = 'Tutor'");
        $stmt->execute([$idTutor]);
        $tutor = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($tutor) {
            $stmt = $pdo->prepare('SELECT m.id_materia, m.nombre_materia FROM tutores_materias tm INNER JOIN materias m ON m.id_materia = tm.id_materia WHERE tm.id_tutor = ? ORDER BY m.nombre_materia');
            $stmt->execute([$idTutor]);
            $materias = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare('SELECT dia_semana, hora_inicio, hora_fin FROM horarios_disponibles WHERE id_tutor = ?');
            $stmt->execute([$idTutor]);
            $horarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $errorPagina = 'No se pudo cargar la disponibilidad del tutor.';
    }
} else {
    $errorPagina = 'Selecciona un tutor desde su perfil para solicitar una sesión.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorPagina === '' && $tutor) {
    $idMateria = filter_input(INPUT_POST, 'id_materia', FILTER_VALIDATE_INT);
    $fecha = $_POST['fecha_sesion'] ?? '';
    $horaInicio = $_POST['hora_inicio'] ?? '';
    $modalidad = $_POST['modalidad'] ?? '';
    $fechaValida = DateTime::createFromFormat('!Y-m-d', $fecha);
    $diasSemanaNumero = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
    $fechaCorrecta = $fechaValida && $fechaValida->format('Y-m-d') === $fecha && $fecha >= date('Y-m-d');
    $materiaValida = false;
    foreach ($materias as $materia) {
        if ((int) $materia['id_materia'] === $idMateria) {
            $materiaValida = true;
            break;
        }
    }
    $modalidadValida = in_array($modalidad, ['Virtual', 'Presencial'], true)
        && ($tutor['modalidad_preferida'] === 'Ambas' || $tutor['modalidad_preferida'] === $modalidad);
    $horaValida = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horaInicio) === 1;

    if (!$materiaValida || !$fechaCorrecta || !$modalidadValida || !$horaValida) {
        $mensaje = 'Revisa la materia, fecha, hora y modalidad seleccionadas.';
        $tipoMensaje = 'error';
    } else {
        $dia = $diasSemanaNumero[(int) $fechaValida->format('N')];
        $horaFin = date('H:i:s', strtotime($horaInicio . ':00 +1 hour'));
        $disponible = false;

        foreach ($horarios as $horario) {
            if ($horario['dia_semana'] === $dia && $horaInicio . ':00' >= $horario['hora_inicio'] && $horaFin <= $horario['hora_fin']) {
                $disponible = true;
                break;
            }
        }

        if (!$disponible) {
            $mensaje = 'El tutor no está disponible en ese día u horario. Revisa su perfil y selecciona una hora dentro de su disponibilidad.';
            $tipoMensaje = 'error';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservas_sesiones
                    WHERE id_tutor = ? AND fecha_sesion = ?
                    AND estado NOT IN ('Rechazada', 'Cancelada')
                    AND hora_inicio < ? AND hora_fin > ?");
                $stmt->execute([$idTutor, $fecha, $horaFin, $horaInicio . ':00']);
                if ((int) $stmt->fetchColumn() > 0) {
                    $mensaje = 'Ese horario ya tiene una solicitud o reserva. Selecciona otra hora.';
                    $tipoMensaje = 'error';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO reservas_sesiones (id_estudiante, id_tutor, id_materia, fecha_sesion, hora_inicio, hora_fin, modalidad, estado)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'Pendiente')");
                    $stmt->execute([$idEstudiante, $idTutor, $idMateria, $fecha, $horaInicio . ':00', $horaFin, $modalidad]);
                    $mensaje = 'Solicitud enviada. Podrás ver el estado en Mis sesiones.';
                    $tipoMensaje = 'success';
                }
            } catch (PDOException $exception) {
                error_log($exception->getMessage());
                $mensaje = 'No se pudo enviar la solicitud. Inténtalo de nuevo.';
                $tipoMensaje = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Solicitar tutoría - TutoresOnLine</title>
    <link rel="stylesheet" href="CSS/estilos.css?v=<?php echo filemtime(__DIR__ . '/CSS/estilos.css'); ?>">
</head>
<body class="app-page">
    <main class="editor-card booking-card">
        <a class="profile-back-link" href="<?php echo $idTutor ? 'perfil_tutor.php?id=' . (int) $idTutor : 'buscar.php'; ?>">← Volver al perfil</a>
        <p class="dashboard-kicker">RESERVA DE SESIÓN</p>
        <h1>Solicitar una tutoría</h1>
        <?php if ($errorPagina !== ''): ?>
            <div class="notice notice--error" role="alert"><?php echo htmlspecialchars($errorPagina, ENT_QUOTES, 'UTF-8'); ?></div>
            <a class="button button--primary" href="buscar.php">Buscar tutores</a>
        <?php elseif (!$tutor): ?>
            <div class="empty-state"><h2>No encontramos este tutor</h2><p>Selecciona un tutor disponible desde la búsqueda.</p><a class="button button--primary" href="buscar.php">Buscar tutores</a></div>
        <?php elseif (!$materias || !$horarios): ?>
            <div class="empty-state"><h2><?php echo htmlspecialchars($tutor['nombre'] . ' ' . $tutor['apellido'], ENT_QUOTES, 'UTF-8'); ?></h2><p>Este tutor todavía no ha configurado materias y horarios. Podrás solicitar una sesión cuando actualice su perfil.</p><a class="button button--light" href="perfil_tutor.php?id=<?php echo (int) $idTutor; ?>">Volver a su perfil</a></div>
        <?php else: ?>
            <p class="section-description">Tutor: <strong><?php echo htmlspecialchars($tutor['nombre'] . ' ' . $tutor['apellido'], ENT_QUOTES, 'UTF-8'); ?></strong> · $<?php echo number_format((float) $tutor['tarifa_por_hora'], 2); ?> por hora</p>
            <?php if ($mensaje !== ''): ?><div class="notice notice--<?php echo $tipoMensaje; ?>" role="alert"><?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <form class="editor-form" method="POST" action="reservar.php?id_tutor=<?php echo (int) $idTutor; ?>">
                <label for="id_materia">Materia</label>
                <select id="id_materia" name="id_materia" required><option value="">Selecciona una materia</option><?php foreach ($materias as $materia): ?><option value="<?php echo (int) $materia['id_materia']; ?>" <?php echo isset($idMateria) && (int) $idMateria === (int) $materia['id_materia'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($materia['nombre_materia'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>

                <label for="fecha_sesion">Fecha de la sesión</label>
                <input id="fecha_sesion" name="fecha_sesion" type="date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($_POST['fecha_sesion'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>

                <label for="hora_inicio">Hora de inicio</label>
                <input id="hora_inicio" name="hora_inicio" type="time" step="60" value="<?php echo htmlspecialchars($_POST['hora_inicio'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                <small>Horarios: <?php foreach ($horarios as $indice => $horario): ?><?php echo $indice > 0 ? ' · ' : ''; ?><?php echo htmlspecialchars($horario['dia_semana'] . ' ' . substr($horario['hora_inicio'], 0, 5) . '-' . substr($horario['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8'); ?><?php endforeach; ?>. Cada sesión dura una hora.</small>

                <label for="modalidad">Modalidad</label>
                <select id="modalidad" name="modalidad" required>
                    <?php if (in_array($tutor['modalidad_preferida'], ['Virtual', 'Ambas'], true)): ?><option value="Virtual">Virtual</option><?php endif; ?>
                    <?php if (in_array($tutor['modalidad_preferida'], ['Presencial', 'Ambas'], true)): ?><option value="Presencial">Presencial</option><?php endif; ?>
                </select>
                <button class="button button--primary editor-submit" type="submit">Enviar solicitud</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
