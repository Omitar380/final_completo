<?php
session_start();
require 'conexcion.php';

if (!isset($_SESSION['id_usuario'], $_SESSION['rol']) || $_SESSION['rol'] !== 'Estudiante') {
    header('Location: dashboard.php');
    exit();
}

$busqueda = trim($_GET['busqueda'] ?? '');
$modalidad = $_GET['modalidad'] ?? '';
$tarifaMaxima = trim($_GET['tarifa_max'] ?? '');
$ciudad = trim($_GET['ciudad'] ?? '');
$categoria = trim($_GET['categoria'] ?? '');
$tutores = [];
$materias = [];
$errorPagina = '';

try {
    $stmt = $pdo->query('SELECT DISTINCT categoria FROM materias WHERE categoria IS NOT NULL AND categoria <> \'\' ORDER BY categoria');
    $materias = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $sql = "
        SELECT u.id_usuario, u.nombre, u.apellido, u.ciudad, u.foto_perfil,
               pt.biografia, pt.tarifa_por_hora, pt.calificacion_promedio, pt.modalidad_preferida,
               GROUP_CONCAT(DISTINCT m.nombre_materia ORDER BY m.nombre_materia SEPARATOR ', ') AS materias,
               (SELECT COUNT(*) FROM resenas_calificaciones rc
                INNER JOIN reservas_sesiones rs ON rs.id_reserva = rc.id_reserva
                WHERE rs.id_tutor = u.id_usuario) AS total_resenas
        FROM usuarios u
        INNER JOIN perfiles_tutores pt ON pt.id_tutor = u.id_usuario
        LEFT JOIN tutores_materias tm ON tm.id_tutor = u.id_usuario
        LEFT JOIN materias m ON m.id_materia = tm.id_materia
        WHERE u.rol = 'Tutor'
    ";
    $parametros = [];

    if ($busqueda !== '') {
        $sql .= ' AND (u.nombre LIKE ? OR u.apellido LIKE ? OR pt.biografia LIKE ? OR m.nombre_materia LIKE ?)';
        $coincidencia = '%' . $busqueda . '%';
        array_push($parametros, $coincidencia, $coincidencia, $coincidencia, $coincidencia);
    }
    if (in_array($modalidad, ['Virtual', 'Presencial'], true)) {
        $sql .= " AND (pt.modalidad_preferida = ? OR pt.modalidad_preferida = 'Ambas')";
        $parametros[] = $modalidad;
    }
    if ($tarifaMaxima !== '' && is_numeric($tarifaMaxima) && (float) $tarifaMaxima >= 0) {
        $sql .= ' AND pt.tarifa_por_hora <= ?';
        $parametros[] = (float) $tarifaMaxima;
    }
    if ($ciudad !== '') {
        $sql .= ' AND u.ciudad LIKE ?';
        $parametros[] = '%' . $ciudad . '%';
    }
    if ($categoria !== '') {
        $sql .= ' AND m.categoria = ?';
        $parametros[] = $categoria;
    }

    $sql .= ' GROUP BY u.id_usuario, u.nombre, u.apellido, u.ciudad, u.foto_perfil, pt.biografia, pt.tarifa_por_hora, pt.calificacion_promedio, pt.modalidad_preferida ORDER BY pt.calificacion_promedio DESC, u.nombre';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);
    $tutores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $errorPagina = 'No se pudieron cargar los tutores. Inténtalo de nuevo.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buscar tutores - TutoresOnLine</title>
    <link rel="stylesheet" href="CSS/estilos.css?v=<?php echo filemtime(__DIR__ . '/CSS/estilos.css'); ?>">
</head>
<body class="app-page">
    <div class="app-layout">
        <aside class="app-sidebar">
            <a class="app-logo" href="dashboard.php"><img class="brand-logo-image" src="assets/logo-marca.png" alt=""><span>Tutores<span>OnLine</span></span></a>
            <p class="sidebar-role">Estudiante</p>
            <nav class="app-nav" aria-label="Navegación principal">
                <a href="dashboard.php"><span>⌂</span> Inicio</a>
                <a class="active" href="buscar.php"><span>⌕</span> Buscar tutores</a>
                <a href="mis_sesiones.php"><span>▦</span> Mis sesiones</a>
                <a href="asistente_ia.php"><span>✦</span> Asistente IA</a>
                <a href="perfil.php"><span>◌</span> Mi perfil y archivos</a>
                <a class="nav-logout" href="logout.php"><span>↪</span> Cerrar sesión</a>
            </nav>
        </aside>
        <main class="app-main">
            <header class="app-topbar"><div><p class="dashboard-kicker">EXPLORA LA COMUNIDAD</p><h1>Encuentra tu tutor</h1></div><a class="button button--light" href="dashboard.php">Volver al panel</a></header>
            <p class="dashboard-intro">Filtra por materia, modalidad, precio o ciudad para encontrar una tutoría a tu medida.</p>

            <?php if ($errorPagina !== ''): ?><div class="notice notice--error" role="alert"><?php echo htmlspecialchars($errorPagina, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

            <form class="search-panel" method="GET" action="buscar.php">
                <div><label for="busqueda">Nombre, experiencia o materia</label><input id="busqueda" name="busqueda" type="search" value="<?php echo htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ej. Matemáticas, programación..."></div>
                <div><label for="categoria">Categoría</label><select id="categoria" name="categoria"><option value="">Todas</option><?php foreach ($materias as $nombreCategoria): ?><option value="<?php echo htmlspecialchars($nombreCategoria, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $categoria === $nombreCategoria ? 'selected' : ''; ?>><?php echo htmlspecialchars($nombreCategoria, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                <div><label for="modalidad">Modalidad</label><select id="modalidad" name="modalidad"><option value="">Todas</option><option value="Virtual" <?php echo $modalidad === 'Virtual' ? 'selected' : ''; ?>>Virtual</option><option value="Presencial" <?php echo $modalidad === 'Presencial' ? 'selected' : ''; ?>>Presencial</option></select></div>
                <div><label for="tarifa_max">Precio máximo por hora</label><input id="tarifa_max" name="tarifa_max" type="number" min="0" step="0.01" value="<?php echo htmlspecialchars($tarifaMaxima, ENT_QUOTES, 'UTF-8'); ?>" placeholder="$"></div>
                <div><label for="ciudad">Ciudad</label><input id="ciudad" name="ciudad" type="search" value="<?php echo htmlspecialchars($ciudad, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Tu ciudad"></div>
                <button class="button button--primary" type="submit">Aplicar filtros</button>
            </form>

            <section class="dashboard-section">
                <div class="section-heading"><div><p class="dashboard-kicker">RESULTADOS</p><h2><?php echo count($tutores); ?> tutores encontrados</h2></div><a class="text-link" href="buscar.php">Limpiar filtros</a></div>
                <?php if ($tutores): ?>
                    <div class="tutor-grid">
                        <?php foreach ($tutores as $tutor): ?>
                            <article class="tutor-card-new">
                                <div class="tutor-card-avatar"><?php if (!empty($tutor['foto_perfil'])): ?><img src="<?php echo htmlspecialchars($tutor['foto_perfil'], ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php else: ?><?php echo htmlspecialchars(strtoupper(substr($tutor['nombre'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></div>
                                <div class="tutor-rating">★ <?php echo number_format((float) $tutor['calificacion_promedio'], 1); ?> <span>(<?php echo (int) $tutor['total_resenas']; ?> reseñas)</span></div>
                                <h3><?php echo htmlspecialchars($tutor['nombre'] . ' ' . $tutor['apellido'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                <p class="tutor-city"><?php echo htmlspecialchars($tutor['ciudad'] ?: 'Ubicación no indicada', ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($tutor['modalidad_preferida'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <p class="tutor-bio"><?php echo htmlspecialchars($tutor['biografia'] ?: 'Este tutor aún no ha agregado una biografía.', ENT_QUOTES, 'UTF-8'); ?></p>
                                <p class="tutor-city">materias: <?php echo htmlspecialchars($tutor['materias'] ?: 'Por definir', ENT_QUOTES, 'UTF-8'); ?></p>
                                <div class="tutor-card-footer"><strong>$<?php echo number_format((float) $tutor['tarifa_por_hora'], 2); ?><small> / hora</small></strong><a class="button button--primary" href="perfil_tutor.php?id=<?php echo (int) $tutor['id_usuario']; ?>">Ver perfil</a></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><h3>No encontramos tutores con esos filtros</h3><p>Prueba quitando algún filtro o vuelve más tarde. Los tutores aparecerán cuando completen su perfil profesional.</p></div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
