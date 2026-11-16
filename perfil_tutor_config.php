<?php
session_start();
require 'conexcion.php';

if (!isset($_SESSION['id_usuario'], $_SESSION['rol']) || $_SESSION['rol'] !== 'Tutor') {
    header('Location: dashboard.php');
    exit();
}

$idTutor = (int) $_SESSION['id_usuario'];
$diasSemana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
$categoriasPermitidas = ['Matemáticas y ciencias', 'Idiomas', 'Tecnología', 'Humanidades', 'Artes y otros', 'General'];
$perfil = ['biografia' => '', 'tarifa_por_hora' => '25.00', 'modalidad_preferida' => 'Ambas'];
$materiasTexto = '';
$categoriaMateria = 'General';
$diasSeleccionados = [];
$horaInicio = '09:00';
$horaFin = '17:00';
$mensaje = '';
$tipoMensaje = '';

try {
    $stmt = $pdo->prepare('SELECT biografia, tarifa_por_hora, modalidad_preferida FROM perfiles_tutores WHERE id_tutor = ?');
    $stmt->execute([$idTutor]);
    $perfil = $stmt->fetch(PDO::FETCH_ASSOC) ?: $perfil;

    $stmt = $pdo->prepare('SELECT m.nombre_materia FROM tutores_materias tm INNER JOIN materias m ON m.id_materia = tm.id_materia WHERE tm.id_tutor = ? ORDER BY m.nombre_materia');
    $stmt->execute([$idTutor]);
    $materiasTexto = implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $pdo->prepare('SELECT m.categoria FROM tutores_materias tm INNER JOIN materias m ON m.id_materia = tm.id_materia WHERE tm.id_tutor = ? AND m.categoria IS NOT NULL AND m.categoria <> \'\' ORDER BY m.nombre_materia LIMIT 1');
    $stmt->execute([$idTutor]);
    $categoriaMateria = $stmt->fetchColumn() ?: $categoriaMateria;

    $stmt = $pdo->prepare('SELECT dia_semana, hora_inicio, hora_fin FROM horarios_disponibles WHERE id_tutor = ? ORDER BY id_horario');
    $stmt->execute([$idTutor]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $horario) {
        $diasSeleccionados[] = $horario['dia_semana'];
        $horaInicio = substr($horario['hora_inicio'], 0, 5);
        $horaFin = substr($horario['hora_fin'], 0, 5);
    }
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $mensaje = 'No se pudo cargar el perfil. Inténtalo de nuevo.';
    $tipoMensaje = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $perfil['biografia'] = trim($_POST['biografia'] ?? '');
    $perfil['tarifa_por_hora'] = trim($_POST['tarifa_por_hora'] ?? '');
    $perfil['modalidad_preferida'] = $_POST['modalidad_preferida'] ?? '';
    $materiasTexto = trim($_POST['materias'] ?? '');
    $categoriaMateria = $_POST['categoria'] ?? '';
    $diasSeleccionados = array_values(array_intersect($diasSemana, $_POST['dias'] ?? []));
    $horaInicio = $_POST['hora_inicio'] ?? '';
    $horaFin = $_POST['hora_fin'] ?? '';
    $materias = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\r\n]+/', $materiasTexto)))));

    if ($perfil['biografia'] === '' || strlen($perfil['biografia']) > 2000) {
        $mensaje = 'Escribe una biografía de hasta 2000 caracteres.';
        $tipoMensaje = 'error';
    } elseif (!is_numeric($perfil['tarifa_por_hora']) || (float) $perfil['tarifa_por_hora'] <= 0 || (float) $perfil['tarifa_por_hora'] > 99999) {
        $mensaje = 'Indica una tarifa por hora válida.';
        $tipoMensaje = 'error';
    } elseif (!in_array($perfil['modalidad_preferida'], ['Virtual', 'Presencial', 'Ambas'], true)) {
        $mensaje = 'Selecciona una modalidad válida.';
        $tipoMensaje = 'error';
    } elseif (!in_array($categoriaMateria, $categoriasPermitidas, true)) {
        $mensaje = 'Selecciona una categoría válida para tus materias.';
        $tipoMensaje = 'error';
    } elseif (!$materias || count($materias) > 12 || array_filter($materias, static fn ($materia) => strlen($materia) > 100)) {
        $mensaje = 'Indica entre 1 y 12 materias, separadas por coma.';
        $tipoMensaje = 'error';
    } elseif (!$diasSeleccionados || !preg_match('/^\d{2}:\d{2}$/', $horaInicio) || !preg_match('/^\d{2}:\d{2}$/', $horaFin) || $horaInicio >= $horaFin) {
        $mensaje = 'Selecciona al menos un día y un rango de horas válido.';
        $tipoMensaje = 'error';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO perfiles_tutores (id_tutor, biografia, tarifa_por_hora, modalidad_preferida) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE biografia = VALUES(biografia), tarifa_por_hora = VALUES(tarifa_por_hora), modalidad_preferida = VALUES(modalidad_preferida)');
            $stmt->execute([$idTutor, $perfil['biografia'], $perfil['tarifa_por_hora'], $perfil['modalidad_preferida']]);

            $stmt = $pdo->prepare('DELETE FROM tutores_materias WHERE id_tutor = ?');
            $stmt->execute([$idTutor]);
            $stmt = $pdo->prepare("INSERT INTO materias (nombre_materia, categoria) VALUES (?, ?) ON DUPLICATE KEY UPDATE id_materia = LAST_INSERT_ID(id_materia), categoria = IF(categoria = 'General', VALUES(categoria), categoria)");
            $stmtRelacion = $pdo->prepare('INSERT INTO tutores_materias (id_tutor, id_materia) VALUES (?, ?)');
            foreach ($materias as $materia) {
                $stmt->execute([$materia, $categoriaMateria]);
                $idMateria = (int) $pdo->lastInsertId();
                $stmtRelacion->execute([$idTutor, $idMateria]);
            }

            $stmt = $pdo->prepare('DELETE FROM horarios_disponibles WHERE id_tutor = ?');
            $stmt->execute([$idTutor]);
            $stmtHorario = $pdo->prepare('INSERT INTO horarios_disponibles (id_tutor, dia_semana, hora_inicio, hora_fin) VALUES (?, ?, ?, ?)');
            foreach ($diasSeleccionados as $dia) {
                $stmtHorario->execute([$idTutor, $dia, $horaInicio . ':00', $horaFin . ':00']);
            }

            $pdo->commit();
            $mensaje = 'Tu perfil de tutor y disponibilidad quedaron actualizados.';
            $tipoMensaje = 'success';
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            $mensaje = 'No se pudieron guardar los cambios. Verifica los datos e inténtalo de nuevo.';
            $tipoMensaje = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Perfil profesional - TutoresOnLine</title>
    <link rel="stylesheet" href="CSS/estilos.css?v=<?php echo filemtime(__DIR__ . '/CSS/estilos.css'); ?>">
</head>
<body class="app-page">
    <main class="editor-card">
        <a class="profile-back-link" href="dashboard.php">← Volver al panel</a>
        <p class="dashboard-kicker">PANEL DEL TUTOR</p>
        <h1>Tu perfil profesional</h1>
        <p class="section-description">Completa esta información para que los estudiantes puedan encontrarte y reservar una tutoría.</p>

        <?php if ($mensaje !== ''): ?>
            <div class="notice notice--<?php echo $tipoMensaje; ?>" role="alert"><?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form class="editor-form" method="POST" action="perfil_tutor_config.php">
            <label for="biografia">Biografía y experiencia</label>
            <textarea id="biografia" name="biografia" rows="5" maxlength="2000" required><?php echo htmlspecialchars($perfil['biografia'], ENT_QUOTES, 'UTF-8'); ?></textarea>

            <div class="editor-grid">
                <div><label for="tarifa_por_hora">Tarifa por hora ($)</label><input id="tarifa_por_hora" name="tarifa_por_hora" type="number" min="1" max="99999" step="0.01" value="<?php echo htmlspecialchars((string) $perfil['tarifa_por_hora'], ENT_QUOTES, 'UTF-8'); ?>" required></div>
                <div><label for="modalidad_preferida">Modalidad</label><select id="modalidad_preferida" name="modalidad_preferida"><option value="Ambas" <?php echo $perfil['modalidad_preferida'] === 'Ambas' ? 'selected' : ''; ?>>Virtual o presencial</option><option value="Virtual" <?php echo $perfil['modalidad_preferida'] === 'Virtual' ? 'selected' : ''; ?>>Virtual</option><option value="Presencial" <?php echo $perfil['modalidad_preferida'] === 'Presencial' ? 'selected' : ''; ?>>Presencial</option></select></div>
            </div>

            <label for="materias">materias que enseñas</label>
            <input id="materias" name="materias" type="text" value="<?php echo htmlspecialchars($materiasTexto, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Matemáticas, Inglés, Programación" required>
            <small>Escribe hasta 12 materias separadas por comas.</small>
            <label for="categoria">Categoría de esas materias</label>
            <select id="categoria" name="categoria" required>
                <?php foreach ($categoriasPermitidas as $categoria): ?>
                    <option value="<?php echo htmlspecialchars($categoria, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $categoriaMateria === $categoria ? 'selected' : ''; ?>><?php echo htmlspecialchars($categoria, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>

            <fieldset class="availability-fieldset">
                <legend>Disponibilidad semanal</legend>
                <div class="weekday-grid">
                    <?php foreach ($diasSemana as $dia): ?>
                        <label><input type="checkbox" name="dias[]" value="<?php echo htmlspecialchars($dia, ENT_QUOTES, 'UTF-8'); ?>" <?php echo in_array($dia, $diasSeleccionados, true) ? 'checked' : ''; ?>> <?php echo htmlspecialchars($dia, ENT_QUOTES, 'UTF-8'); ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="editor-grid">
                    <div><label for="hora_inicio">Desde</label><input id="hora_inicio" type="time" name="hora_inicio" value="<?php echo htmlspecialchars($horaInicio, ENT_QUOTES, 'UTF-8'); ?>" required></div>
                    <div><label for="hora_fin">Hasta</label><input id="hora_fin" type="time" name="hora_fin" value="<?php echo htmlspecialchars($horaFin, ENT_QUOTES, 'UTF-8'); ?>" required></div>
                </div>
                <small>El rango horario se aplicará a todos los días seleccionados.</small>
            </fieldset>

            <button class="button button--primary editor-submit" type="submit">Guardar perfil</button>
        </form>
    </main>
</body>
</html>
