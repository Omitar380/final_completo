<?php
session_start();
require 'conexcion.php';

if (!isset($_SESSION['id_usuario'], $_SESSION['rol']) || !in_array($_SESSION['rol'], ['Estudiante', 'Tutor'], true)) {
    header('Location: index.php');
    exit();
}

$idUsuario = (int) $_SESSION['id_usuario'];
$rol = $_SESSION['rol'];
$nombre = $_SESSION['nombre'] ?? 'Usuario';
$fotoPerfil = null;
$errorPagina = '';
$tutores = [];
$categorias = [];
$perfilTutor = null;
$estadisticas = ['pendientes' => 0, 'proximas' => 0, 'resenas' => 0, 'calificacion' => 0];

try {
    $stmt = $pdo->prepare('SELECT foto_perfil FROM usuarios WHERE id_usuario = ?');
    $stmt->execute([$idUsuario]);
    $fotoPerfil = $stmt->fetchColumn() ?: null;

    if ($rol === 'Tutor') {
        $stmt = $pdo->prepare('SELECT biografia, tarifa_por_hora, calificacion_promedio, modalidad_preferida FROM perfiles_tutores WHERE id_tutor = ?');
        $stmt->execute([$idUsuario]);
        $perfilTutor = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservas_sesiones WHERE id_tutor = ? AND estado = 'Pendiente'");
        $stmt->execute([$idUsuario]);
        $estadisticas['pendientes'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservas_sesiones WHERE id_tutor = ? AND estado = 'Aceptada' AND fecha_sesion >= CURDATE()");
        $stmt->execute([$idUsuario]);
        $estadisticas['proximas'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) AS total, COALESCE(AVG(rc.puntuacion), 0) AS calificacion FROM resenas_calificaciones rc INNER JOIN reservas_sesiones rs ON rs.id_reserva = rc.id_reserva WHERE rs.id_tutor = ?');
        $stmt->execute([$idUsuario]);
        $resenas = $stmt->fetch(PDO::FETCH_ASSOC);
        $estadisticas['resenas'] = (int) ($resenas['total'] ?? 0);
        $estadisticas['calificacion'] = (float) ($resenas['calificacion'] ?? 0);
    } else {
        $stmt = $pdo->query('SELECT categoria, COUNT(*) AS total FROM materias GROUP BY categoria ORDER BY categoria');
        $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT u.id_usuario, u.nombre, u.apellido, u.ciudad, u.foto_perfil,
                   pt.biografia, pt.tarifa_por_hora, pt.calificacion_promedio, pt.modalidad_preferida,
                   (SELECT COUNT(*) FROM resenas_calificaciones rc
                    INNER JOIN reservas_sesiones rs ON rs.id_reserva = rc.id_reserva
                    WHERE rs.id_tutor = u.id_usuario) AS total_resenas
            FROM usuarios u
            INNER JOIN perfiles_tutores pt ON pt.id_tutor = u.id_usuario
            WHERE u.rol = 'Tutor'
            ORDER BY pt.calificacion_promedio DESC, u.nombre
            LIMIT 6
        ");
        $stmt->execute();
        $tutores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $errorPagina = 'No se pudieron cargar todos los datos. Revisa la conexión con la base de datos.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel <?php echo $rol === 'Tutor' ? 'del tutor' : 'del estudiante'; ?> - TutoresOnLine</title>
    <link rel="stylesheet" href="CSS/estilos.css?v=<?php echo filemtime(__DIR__ . '/CSS/estilos.css'); ?>">
</head>
<body class="app-page">
    <div class="app-layout">
        <aside class="app-sidebar">
            <a class="app-logo" href="dashboard.php"><img class="brand-logo-image" src="assets/logo-marca.png" alt=""><span>Tutores<span>OnLine</span></span></a>
            <p class="sidebar-role"><?php echo htmlspecialchars($rol, ENT_QUOTES, 'UTF-8'); ?></p>
            <nav class="app-nav" aria-label="Navegación principal">
                <a class="active" href="dashboard.php"><span>⌂</span> Inicio</a>
                <?php if ($rol === 'Estudiante'): ?>
                    <a href="buscar.php"><span>⌕</span> Buscar tutores</a>
                    <a href="mis_sesiones.php"><span>▦</span> Mis sesiones</a>
                    <a href="asistente_ia.php"><span>✦</span> Asistente IA</a>
                <?php else: ?>
                    <a href="perfil_tutor_config.php"><span>◉</span> Mi perfil de tutor</a>
                    <a href="solicitudes.php"><span>▤</span> Solicitudes</a>
                <?php endif; ?>
                <a href="perfil.php"><span>◌</span> Mi perfil y archivos</a>
                <a class="nav-logout" href="logout.php"><span>↪</span> Cerrar sesión</a>
            </nav>
            <div class="sidebar-user">
                <span class="user-avatar"><?php if ($fotoPerfil): ?><img src="<?php echo htmlspecialchars($fotoPerfil, ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php else: ?><?php echo htmlspecialchars(strtoupper(substr($nombre, 0, 1)), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></span>
                <span><strong><?php echo htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo htmlspecialchars($rol, ENT_QUOTES, 'UTF-8'); ?></small></span>
            </div>
        </aside>

        <main class="app-main">
            <header class="app-topbar">
                <div>
                    <p class="dashboard-kicker">PANEL DE <?php echo strtoupper($rol); ?></p>
                    <h1>Hola, <?php echo htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8'); ?> 👋</h1>
                </div>
                <a class="button button--light" href="perfil.php">Mi cuenta</a>
            </header>

            <?php if ($errorPagina !== ''): ?>
                <div class="notice notice--error" role="alert"><?php echo htmlspecialchars($errorPagina, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($rol === 'Tutor'): ?>
                <p class="dashboard-intro">Administra tu perfil profesional y responde a las solicitudes de tus estudiantes.</p>
                <section class="stat-grid" aria-label="Resumen de tutor">
                    <article class="stat-card"><span>Solicitudes pendientes</span><strong><?php echo $estadisticas['pendientes']; ?></strong><small>Esperan tu respuesta</small></article>
                    <article class="stat-card"><span>Próximas sesiones</span><strong><?php echo $estadisticas['proximas']; ?></strong><small>Reservas aceptadas</small></article>
                    <article class="stat-card"><span>Calificación</span><strong><?php echo $estadisticas['resenas'] > 0 ? number_format($estadisticas['calificacion'], 1) . ' / 5' : '—'; ?></strong><small><?php echo $estadisticas['resenas']; ?> reseñas recibidas</small></article>
                </section>

                <section class="dashboard-section">
                    <div class="section-heading">
                        <div><p class="dashboard-kicker">TU PERFIL PÚBLICO</p><h2>Información profesional</h2></div>
                        <a class="button button--primary" href="perfil_tutor_config.php">Editar perfil</a>
                    </div>
                    <?php if ($perfilTutor): ?>
                        <div class="professional-summary">
                            <div class="summary-price">$<?php echo number_format((float) $perfilTutor['tarifa_por_hora'], 2); ?><small> / hora</small></div>
                            <span class="status-pill"><?php echo htmlspecialchars($perfilTutor['modalidad_preferida'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <p><?php echo htmlspecialchars($perfilTutor['biografia'] ?: 'Agrega una biografía para que los estudiantes conozcan tu experiencia.', ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="empty-state"><h3>Completa tu perfil de tutor</h3><p>Añade tu experiencia, tarifa, materias y horarios para aparecer en las búsquedas de estudiantes.</p><a class="button button--primary" href="perfil_tutor_config.php">Configurar perfil</a></div>
                    <?php endif; ?>
                </section>

                <section class="dashboard-section">
                    <div class="section-heading"><div><p class="dashboard-kicker">ACTIVIDAD</p><h2>Solicitudes de tutoría</h2></div><a class="text-link" href="solicitudes.php">Ver todas →</a></div>
                    <p class="section-description">Revisa las solicitudes, acepta las tutorías que puedas impartir y consulta las próximas sesiones.</p>
                    <a class="button button--light" href="solicitudes.php">Abrir solicitudes (<?php echo $estadisticas['pendientes']; ?>)</a>
                </section>
            <?php else: ?>
                <p class="dashboard-intro">Encuentra un tutor, revisa su perfil y solicita una sesión que se ajuste a ti.</p>
                <section class="category-section">
                    <div class="section-heading"><div><p class="dashboard-kicker">EXPLORA</p><h2>Categorías de materias</h2></div><a class="text-link" href="buscar.php">Ver tutores →</a></div>
                    <?php if ($categorias): ?>
                        <div class="category-grid">
                            <?php foreach ($categorias as $categoria): ?>
                                <a class="category-card" href="buscar.php?categoria=<?php echo rawurlencode($categoria['categoria'] ?? ''); ?>"><span class="category-icon">▧</span><strong><?php echo htmlspecialchars($categoria['categoria'] ?: 'Otras materias', ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo (int) $categoria['total']; ?> materias</small></a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-inline">Las categorías aparecerán aquí cuando haya materias disponibles. <a href="buscar.php">Puedes buscar tutores</a>.</div>
                    <?php endif; ?>
                </section>

                <section class="dashboard-section">
                    <div class="section-heading"><div><p class="dashboard-kicker">RECOMENDADOS</p><h2>Tutores disponibles</h2></div><a class="text-link" href="buscar.php">Buscar y filtrar →</a></div>
                    <?php if ($tutores): ?>
                        <div class="tutor-grid">
                            <?php foreach ($tutores as $tutor): ?>
                                <article class="tutor-card-new">
                                    <div class="tutor-card-avatar"><?php if (!empty($tutor['foto_perfil'])): ?><img src="<?php echo htmlspecialchars($tutor['foto_perfil'], ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php else: ?><?php echo htmlspecialchars(strtoupper(substr($tutor['nombre'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></div>
                                    <div class="tutor-rating">★ <?php echo number_format((float) $tutor['calificacion_promedio'], 1); ?> <span>(<?php echo (int) $tutor['total_resenas']; ?> reseñas)</span></div>
                                    <h3><?php echo htmlspecialchars($tutor['nombre'] . ' ' . $tutor['apellido'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <p class="tutor-city"><?php echo htmlspecialchars($tutor['ciudad'] ?: 'Ubicación no indicada', ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($tutor['modalidad_preferida'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <p class="tutor-bio"><?php echo htmlspecialchars($tutor['biografia'] ?: 'Este tutor aún no ha agregado una biografía.', ENT_QUOTES, 'UTF-8'); ?></p>
                                    <div class="tutor-card-footer"><strong>$<?php echo number_format((float) $tutor['tarifa_por_hora'], 2); ?><small> / hora</small></strong><a class="button button--primary" href="perfil_tutor.php?id=<?php echo (int) $tutor['id_usuario']; ?>">Ver perfil</a></div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state"><h3>Aún no hay tutores disponibles</h3><p>Cuando un tutor complete su perfil, aparecerá aquí para que puedas conocerlo y solicitar una sesión.</p><a class="button button--primary" href="buscar.php">Buscar tutores</a></div>
                    <?php endif; ?>
                </section>
                <section class="student-shortcuts">
                    <a href="mis_sesiones.php"><strong>Mis sesiones</strong><span>Consulta reservas y solicitudes</span></a>
                    <a href="asistente_ia.php"><strong>Asistente de estudio</strong><span>Obtén recomendaciones de aprendizaje</span></a>
                </section>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
