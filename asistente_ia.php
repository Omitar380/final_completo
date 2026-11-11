<?php
session_start();
require 'conexcion.php';

if (!isset($_SESSION['id_usuario'], $_SESSION['rol'])
    || !in_array($_SESSION['rol'], ['Estudiante', 'Tutor'], true)) {
    header('Location: dashboard.php');
    exit();
}

$idUsuario = (int) $_SESSION['id_usuario'];
$rolUsuario = $_SESSION['rol'];
$nombreUsuario = $_SESSION['nombre'] ?? 'estudiante';
$apiKey = getenv('GEMINI_API_KEY');
$geminiConfigured = is_string($apiKey) && trim($apiKey) !== '';
$modo = $_GET['modo'] ?? ($_POST['modo'] ?? 'auto');
$modo = in_array($modo, ['auto', 'local', 'online'], true) ? $modo : 'auto';
$propietarioHistorial = $rolUsuario . ':' . $idUsuario;

if (($_SESSION['ai_chat_owner'] ?? '') !== $propietarioHistorial) {
    unset($_SESSION['ai_chat_history'], $_SESSION['ai_online_activated']);
    $_SESSION['ai_chat_owner'] = $propietarioHistorial;
}

if (empty($_SESSION['ai_csrf_token'])) {
    $_SESSION['ai_csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['ai_chat_history'])) {
    $_SESSION['ai_chat_history'] = [
        'auto' => [
            ['sender' => 'bot', 'text' => "¡Hola $nombreUsuario! En modo automático respondo con la información de tu cuenta y la base local cuando preguntas sobre tus sesiones, solicitudes o tutores. Gemini solo recibe preguntas generales después de que lo actives."],
        ],
        'local' => [
            ['sender' => 'bot', 'text' => "¡Hola $nombreUsuario! Soy tu asistente local. Solo puedo consultar la información pública necesaria y los datos propios de tu cuenta."],
        ],
        'online' => [
            ['sender' => 'bot', 'text' => "¡Hola $nombreUsuario! Puedes consultarme con Gemini para preguntas generales. La información privada de tu cuenta siempre se responde localmente y nunca se envía a Gemini."],
        ],
    ];
    if (isset($_SESSION['chat_history']) && is_array($_SESSION['chat_history'])) {
        $_SESSION['ai_chat_history']['online'] = array_slice($_SESSION['chat_history'], -24);
        unset($_SESSION['chat_history']);
    }
}
foreach (['auto', 'local', 'online'] as $modoHistorial) {
    if (!isset($_SESSION['ai_chat_history'][$modoHistorial]) || !is_array($_SESSION['ai_chat_history'][$modoHistorial])) {
        $_SESSION['ai_chat_history'][$modoHistorial] = [
            ['sender' => 'bot', 'text' => "¡Hola $nombreUsuario! Estoy listo para ayudarte."],
        ];
    }
}

$tutoresLocales = [];
$categoriasLocales = [];
$horariosPorTutor = [];
$preferenciasEstudiante = ['nivel_academico' => '', 'presupuesto_maximo' => ''];
$horariosEstudiante = [];
$sesionesEstudiante = [];
$perfilTutorPropio = null;
$materiasTutorPropio = [];
$horariosTutorPropio = [];
$solicitudesTutorPropias = [];
$resumenTutorPropio = null;
$errorDatosLocales = '';

try {
    if ($rolUsuario === 'Estudiante') {
        $stmt = $pdo->prepare('SELECT nivel_academico, presupuesto_maximo FROM Preferencias_Estudiantes WHERE id_estudiante = ?');
        $stmt->execute([$idUsuario]);
        $preferenciasEstudiante = $stmt->fetch(PDO::FETCH_ASSOC) ?: $preferenciasEstudiante;

        $stmt = $pdo->prepare('SELECT dia_semana, hora_inicio, hora_fin FROM Disponibilidad_Estudiantes WHERE id_estudiante = ? ORDER BY id_disponibilidad');
        $stmt->execute([$idUsuario]);
        $horariosEstudiante = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("
            SELECT u.id_usuario, u.nombre, u.apellido, u.ciudad,
                   pt.biografia, pt.tarifa_por_hora, pt.calificacion_promedio,
                   pt.modalidad_preferida,
                   GROUP_CONCAT(DISTINCT m.nombre_materia ORDER BY m.nombre_materia SEPARATOR ', ') AS materias,
                   GROUP_CONCAT(DISTINCT m.categoria ORDER BY m.categoria SEPARATOR ', ') AS categorias,
                   COALESCE(resenas.total_resenas, 0) AS total_resenas,
                   COALESCE(resenas.calificacion_real, 0) AS calificacion_real,
                   COALESCE(resenas.resenas_positivas, 0) AS resenas_positivas
            FROM Usuarios u
            INNER JOIN Perfiles_Tutores pt ON pt.id_tutor = u.id_usuario
            LEFT JOIN Tutores_Materias tm ON tm.id_tutor = u.id_usuario
            LEFT JOIN Materias m ON m.id_materia = tm.id_materia
            LEFT JOIN (
                SELECT rs.id_tutor, COUNT(*) AS total_resenas,
                       AVG(rc.puntuacion) AS calificacion_real,
                       SUM(CASE WHEN rc.puntuacion >= 4 THEN 1 ELSE 0 END) AS resenas_positivas
                FROM Resenas_Calificaciones rc
                INNER JOIN Reservas_Sesiones rs ON rs.id_reserva = rc.id_reserva
                GROUP BY rs.id_tutor
            ) resenas ON resenas.id_tutor = u.id_usuario
            WHERE u.rol = 'Tutor'
            GROUP BY u.id_usuario, u.nombre, u.apellido, u.ciudad, pt.biografia,
                     pt.tarifa_por_hora, pt.calificacion_promedio, pt.modalidad_preferida,
                     resenas.total_resenas, resenas.calificacion_real, resenas.resenas_positivas
            ORDER BY pt.calificacion_promedio DESC, u.nombre
            LIMIT 100
        ");
        $tutoresLocales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("
            SELECT id_tutor, dia_semana, hora_inicio, hora_fin
            FROM Horarios_Disponibles
            ORDER BY id_tutor, id_horario
        ");
        $horariosPorTutor = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $horarioTutor) {
            $horariosPorTutor[(int) $horarioTutor['id_tutor']][] = $horarioTutor;
        }

        $stmt = $pdo->query("
            SELECT categoria, COUNT(DISTINCT id_materia) AS total
            FROM Materias
            WHERE categoria IS NOT NULL AND categoria <> ''
            GROUP BY categoria
            ORDER BY categoria
        ");
        $categoriasLocales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT rs.fecha_sesion, rs.hora_inicio, rs.hora_fin, rs.modalidad, rs.estado,
                   m.nombre_materia, t.nombre AS nombre_tutor, t.apellido AS apellido_tutor,
                   rc.puntuacion AS mi_calificacion
            FROM Reservas_Sesiones rs
            INNER JOIN Materias m ON m.id_materia = rs.id_materia
            INNER JOIN Usuarios t ON t.id_usuario = rs.id_tutor
            LEFT JOIN Resenas_Calificaciones rc ON rc.id_reserva = rs.id_reserva
            WHERE rs.id_estudiante = ?
            ORDER BY rs.fecha_sesion DESC, rs.hora_inicio DESC
            LIMIT 30
        ");
        $stmt->execute([$idUsuario]);
        $sesionesEstudiante = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT u.nombre, u.apellido, u.ciudad, pt.biografia,
                   pt.tarifa_por_hora, pt.calificacion_promedio, pt.modalidad_preferida
            FROM Usuarios u
            INNER JOIN Perfiles_Tutores pt ON pt.id_tutor = u.id_usuario
            WHERE u.id_usuario = ? AND u.rol = 'Tutor'
        ");
        $stmt->execute([$idUsuario]);
        $perfilTutorPropio = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt = $pdo->prepare("
            SELECT m.nombre_materia, m.categoria
            FROM Tutores_Materias tm
            INNER JOIN Materias m ON m.id_materia = tm.id_materia
            WHERE tm.id_tutor = ?
            ORDER BY m.nombre_materia
        ");
        $stmt->execute([$idUsuario]);
        $materiasTutorPropio = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT dia_semana, hora_inicio, hora_fin
            FROM Horarios_Disponibles
            WHERE id_tutor = ?
            ORDER BY id_horario
        ");
        $stmt->execute([$idUsuario]);
        $horariosTutorPropio = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT rs.fecha_sesion, rs.hora_inicio, rs.hora_fin, rs.modalidad,
                   rs.estado, m.nombre_materia
            FROM Reservas_Sesiones rs
            INNER JOIN Materias m ON m.id_materia = rs.id_materia
            WHERE rs.id_tutor = ?
            ORDER BY rs.fecha_sesion DESC, rs.hora_inicio DESC
            LIMIT 50
        ");
        $stmt->execute([$idUsuario]);
        $solicitudesTutorPropias = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total_resenas, COALESCE(AVG(rc.puntuacion), 0) AS promedio,
                   COALESCE(SUM(CASE WHEN rc.puntuacion >= 4 THEN 1 ELSE 0 END), 0) AS positivas
            FROM Resenas_Calificaciones rc
            INNER JOIN Reservas_Sesiones rs ON rs.id_reserva = rc.id_reserva
            WHERE rs.id_tutor = ?
        ");
        $stmt->execute([$idUsuario]);
        $resumenTutorPropio = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $exception) {
    error_log('No se pudo cargar la información local del asistente: ' . $exception->getMessage());
    $errorDatosLocales = 'No se pudo consultar toda la información local. Revisa la conexión y las migraciones de la base de datos.';
}

$responderLocalmente = static function ($mensaje) use (
    $rolUsuario,
    $idUsuario,
    $tutoresLocales,
    $categoriasLocales,
    $horariosPorTutor,
    $preferenciasEstudiante,
    $horariosEstudiante,
    $sesionesEstudiante,
    $perfilTutorPropio,
    $materiasTutorPropio,
    $horariosTutorPropio,
    $solicitudesTutorPropias,
    $resumenTutorPropio,
    $errorDatosLocales
) {
    if ($errorDatosLocales !== '') {
        return $errorDatosLocales;
    }

    $mensajeNormalizado = function_exists('mb_strtolower')
        ? mb_strtolower($mensaje, 'UTF-8')
        : strtolower($mensaje);

    if ($rolUsuario === 'Tutor') {
        if (!$perfilTutorPropio) {
            return 'No se encontró el perfil de tutor de tu propia cuenta.';
        }

        $lineasPerfil = [
            'Este es el perfil de tu propia cuenta:',
            '- Nombre: ' . trim($perfilTutorPropio['nombre'] . ' ' . $perfilTutorPropio['apellido']),
            '- Ciudad: ' . ($perfilTutorPropio['ciudad'] ?: 'no indicada'),
            '- Biografía: ' . ($perfilTutorPropio['biografia'] ?: 'sin biografía'),
            '- Tarifa: $' . number_format((float) $perfilTutorPropio['tarifa_por_hora'], 2) . ' por hora',
            '- Modalidad: ' . $perfilTutorPropio['modalidad_preferida'],
            '- Materias: ' . ($materiasTutorPropio
                ? implode(', ', array_column($materiasTutorPropio, 'nombre_materia'))
                : 'sin materias registradas'),
            '- Horarios publicados: ' . ($horariosTutorPropio
                ? implode('; ', array_map(static function ($horario) {
                    return $horario['dia_semana'] . ' ' . substr($horario['hora_inicio'], 0, 5)
                        . '-' . substr($horario['hora_fin'], 0, 5);
                }, $horariosTutorPropio))
                : 'sin disponibilidad registrada'),
        ];

        if (preg_match('/\b(solicitudes?|reservas?|aceptad[ao]s?|rechazad[ao]s?|pendientes?|completad[ao]s?|cancelad[ao]s?|estado)\b/u', $mensajeNormalizado)) {
            $conteos = array_fill_keys(['Pendiente', 'Aceptada', 'Rechazada', 'Completada', 'Cancelada'], 0);
            foreach ($solicitudesTutorPropias as $solicitud) {
                if (isset($conteos[$solicitud['estado']])) {
                    $conteos[$solicitud['estado']]++;
                }
            }
            $lineasPerfil[] = 'Solicitudes asociadas únicamente a tu perfil: '
                . implode(', ', array_map(static function ($estado, $total) {
                    return $estado . ': ' . $total;
                }, array_keys($conteos), array_values($conteos))) . '.';
            $lineasPerfil[] = 'Últimas solicitudes (sin datos personales de estudiantes):';
            if (!$solicitudesTutorPropias) {
                $lineasPerfil[] = '- No tienes solicitudes registradas.';
            } else {
                foreach (array_slice($solicitudesTutorPropias, 0, 10) as $solicitud) {
                    $lineasPerfil[] = '- ' . $solicitud['nombre_materia'] . '; '
                        . $solicitud['fecha_sesion'] . ' '
                        . substr($solicitud['hora_inicio'], 0, 5) . '; '
                        . $solicitud['modalidad'] . '; estado: ' . $solicitud['estado'] . '.';
                }
            }
        }

        if ($resumenTutorPropio) {
            $lineasPerfil[] = 'Reseñas de tu perfil: ' . (int) $resumenTutorPropio['total_resenas']
                . ' en total; promedio ' . number_format((float) $resumenTutorPropio['promedio'], 1)
                . '/5; ' . (int) $resumenTutorPropio['positivas'] . ' calificaciones positivas (4 o 5 estrellas).';
        }

        return implode("\n", $lineasPerfil)
            . "\n\nSolo consulté datos del perfil y de las solicitudes vinculadas a tu propia cuenta.";
    }

    $esConsultaPersonal = (
        preg_match('/\b(mi|mis|m[ií]a|m[ií]as)\b/u', $mensajeNormalizado)
        && preg_match('/\b(sesi[oó]n|solicitud|reserva|perfil|cuenta|horario|presupuesto|nivel|preferencia|calificaci[oó]n|tutor[ií]a|dato|correo|email|contraseñ|archivo|documento)\w*\b/u', $mensajeNormalizado)
    ) || preg_match('/\b(aceptaron|rechazaron|aceptada|rechazada|pendiente|completada|cancelada)\b/u', $mensajeNormalizado)
        || preg_match('/\b(solicitudes?|reservas?)\b.{0,30}\b(tengo|m[ií]|aceptad[ao]|rechazad[ao]|estado|pendientes?|completad[ao])\w*\b/u', $mensajeNormalizado);
    $respuestaPersonal = '';
    $solicitaRecomendacion = preg_match('/\b(recomiend\w*|mejor|ideal|conviene|compatible)\b/u', $mensajeNormalizado) === 1;
    if ($esConsultaPersonal) {
        $lineasPersonales = [
            'Solo consulté tus propias preferencias y sesiones; no tengo acceso a las de otros estudiantes.',
            '- Nivel académico: ' . ($preferenciasEstudiante['nivel_academico'] ?: 'no indicado'),
            '- Presupuesto máximo: ' . ($preferenciasEstudiante['presupuesto_maximo'] !== ''
                ? '$' . number_format((float) $preferenciasEstudiante['presupuesto_maximo'], 2) . ' por hora'
                : 'no indicado'),
            '- Horarios preferidos: ' . ($horariosEstudiante
                ? implode('; ', array_map(static function ($horario) {
                    return $horario['dia_semana'] . ' ' . substr($horario['hora_inicio'], 0, 5)
                        . '-' . substr($horario['hora_fin'], 0, 5);
                }, $horariosEstudiante))
                : 'no indicados'),
            'Tus sesiones y solicitudes:',
        ];
        if (!$sesionesEstudiante) {
            $lineasPersonales[] = '- No tienes sesiones ni solicitudes registradas.';
        } else {
            foreach (array_slice($sesionesEstudiante, 0, 15) as $sesion) {
                $lineasPersonales[] = '- ' . $sesion['nombre_materia'] . ' con '
                    . $sesion['nombre_tutor'] . ' ' . $sesion['apellido_tutor'] . '; '
                    . $sesion['fecha_sesion'] . ' '
                    . substr($sesion['hora_inicio'], 0, 5) . '; '
                    . $sesion['modalidad'] . '; estado: ' . $sesion['estado']
                    . ($sesion['mi_calificacion'] !== null
                        ? '; tu calificación: ' . (int) $sesion['mi_calificacion'] . '/5'
                        : '') . '.';
            }
        }
        $respuestaPersonal = implode("\n", $lineasPersonales);
        if (!$solicitaRecomendacion) {
            return $respuestaPersonal;
        }
    }

    if (preg_match('/\b(categor[ií]as?|materias?)\b/u', $mensajeNormalizado)) {
        if (!$categoriasLocales) {
            return 'Todavía no hay categorías de materias registradas en la plataforma.';
        }

        $lineas = array_map(static function ($categoria) {
            return '- ' . $categoria['categoria'] . ' (' . (int) $categoria['total'] . ' materias)';
        }, $categoriasLocales);

        return "Estas son las categorías disponibles en la base de datos:\n" . implode("\n", $lineas)
            . "\n\nEscríbeme una materia o ciudad para buscar tutores relacionados.";
    }

    $palabras = preg_split('/[^\p{L}\p{N}]+/u', $mensajeNormalizado, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($palabras)) {
        return 'No pude interpretar la pregunta. Prueba con el nombre de una materia, tutor o ciudad.';
    }

    $ignoradas = array_fill_keys([
        'para', 'como', 'donde', 'quien', 'quienes', 'quiero', 'busco', 'buscar',
        'necesito', 'puedes', 'podrias', 'ayuda', 'ayudar', 'tutor', 'tutores',
        'profesor', 'profesora', 'clase', 'clases', 'materia', 'materias',
        'enseña', 'enseñan', 'disponible', 'disponibles', 'hay', 'una', 'uno',
        'con', 'por', 'que', 'del', 'las', 'los', 'una', 'me', 'gustaria',
        'recomiendame', 'recomiéndame', 'recomienda', 'recomiendas', 'mejor', 'ideal', 'conviene',
        'compatible', 'nivel', 'presupuesto', 'horario', 'horarios',
    ], true);
    $nivelNormalizado = function_exists('mb_strtolower')
        ? mb_strtolower((string) ($preferenciasEstudiante['nivel_academico'] ?? ''), 'UTF-8')
        : strtolower((string) ($preferenciasEstudiante['nivel_academico'] ?? ''));
    $terminos = array_values(array_unique(array_filter($palabras, static function ($palabra) use ($ignoradas, $nivelNormalizado) {
        return strlen($palabra) > 2
            && !isset($ignoradas[$palabra])
            && ($nivelNormalizado === '' || strpos($nivelNormalizado, $palabra) === false);
    })));

    $coincidencias = [];
    foreach ($tutoresLocales as $tutor) {
        $textoTutor = implode(' ', [
            $tutor['nombre'],
            $tutor['apellido'],
            $tutor['ciudad'] ?? '',
            $tutor['biografia'] ?? '',
            $tutor['materias'] ?? '',
            $tutor['categorias'] ?? '',
            $tutor['modalidad_preferida'] ?? '',
        ]);
        if (function_exists('mb_strtolower')) {
            $textoTutor = mb_strtolower($textoTutor, 'UTF-8');
        }
        $puntuacion = 0;
        foreach ($terminos as $termino) {
            if (strpos($textoTutor, $termino) !== false) {
                $puntuacion++;
            }
        }
        $presupuesto = $preferenciasEstudiante['presupuesto_maximo'] ?? null;
        if ($presupuesto !== null && $presupuesto !== '' && (float) $tutor['tarifa_por_hora'] > (float) $presupuesto) {
            continue;
        }

        $coincideHorario = false;
        foreach ($horariosEstudiante as $horarioEstudiante) {
            foreach ($horariosPorTutor[(int) $tutor['id_usuario']] ?? [] as $horarioTutor) {
                if ($horarioEstudiante['dia_semana'] === $horarioTutor['dia_semana']
                    && $horarioEstudiante['hora_inicio'] < $horarioTutor['hora_fin']
                    && $horarioEstudiante['hora_fin'] > $horarioTutor['hora_inicio']) {
                    $coincideHorario = true;
                    break 2;
                }
            }
        }
        if ($horariosEstudiante && !$coincideHorario) {
            continue;
        }

        if ($puntuacion > 0 || !$terminos) {
            $tutor['_puntuacion_busqueda'] = $puntuacion;
            $tutor['_coincide_horario'] = $coincideHorario;
            $coincidencias[] = $tutor;
        }
    }

    usort($coincidencias, static function ($a, $b) {
        $comparacion = $b['_puntuacion_busqueda'] <=> $a['_puntuacion_busqueda'];
        if ($comparacion !== 0) {
            return $comparacion;
        }
        $positivas = (int) $b['resenas_positivas'] <=> (int) $a['resenas_positivas'];
        return $positivas !== 0
            ? $positivas
            : ((float) $b['calificacion_real'] <=> (float) $a['calificacion_real']);
    });

    $coincidencias = array_slice($coincidencias, 0, 5);

    if (!$coincidencias) {
        $categorias = array_column($categoriasLocales, 'categoria');
        $sugerencia = $categorias
            ? ' Algunas categorías disponibles son: ' . implode(', ', array_slice($categorias, 0, 8)) . '.'
            : '';
        return 'No encontré tutores que coincidan con tu consulta y tus filtros guardados.'
            . ($preferenciasEstudiante['presupuesto_maximo'] !== ''
                ? ' Puedes aumentar o quitar tu presupuesto máximo en tus preferencias.'
                : '')
            . ($horariosEstudiante ? ' También puedes ampliar tus días y horarios disponibles.' : '')
            . $sugerencia . ' Prueba con una materia, ciudad o modalidad, o visita Buscar tutores.';
    }

    $lineas = [];
    foreach ($coincidencias as $tutor) {
        $nombre = trim($tutor['nombre'] . ' ' . $tutor['apellido']);
        $materias = $tutor['materias'] ?: 'materias por definir';
        $ciudad = $tutor['ciudad'] ?: 'ubicación no indicada';
        $calificacionPromedio = (int) $tutor['total_resenas'] > 0
            ? (float) $tutor['calificacion_real']
            : (float) $tutor['calificacion_promedio'];
        $calificacion = number_format($calificacionPromedio, 1);
        $tarifa = number_format((float) $tutor['tarifa_por_hora'], 2);
        $lineas[] = "- $nombre: $materias; $ciudad; modalidad "
            . $tutor['modalidad_preferida'] . "; $" . $tarifa . '/hora; calificación '
            . $calificacion . '/5; ' . (int) $tutor['resenas_positivas']
            . ' reseñas positivas de ' . (int) $tutor['total_resenas'] . '; '
            . (!empty($tutor['_coincide_horario']) ? 'coincide con tu horario' : 'horario por confirmar') . '.';
    }

    $notaNivel = !empty($preferenciasEstudiante['nivel_academico'])
        ? "\nNivel académico indicado: " . $preferenciasEstudiante['nivel_academico']
            . ". La plataforma no guarda el nivel que enseña cada tutor, así que no lo supongo."
        : "\nAún no indicas tu nivel académico; agrégalo en tus preferencias para orientar mejor las sugerencias.";
    return ($respuestaPersonal !== '' ? $respuestaPersonal . "\n\n" : '')
        . "Encontré estos tutores usando tu presupuesto, horario y las materias de la consulta. Ordené por coincidencia de materia y luego por reseñas positivas y promedio:\n"
        . implode("\n", $lineas) . $notaNivel
        . "\n\nPuedes abrir Buscar tutores para revisar sus perfiles y disponibilidad.";
};

$preguntaUsaDatosLocales = static function ($mensaje) {
    $mensajeNormalizado = function_exists('mb_strtolower')
        ? mb_strtolower($mensaje, 'UTF-8')
        : strtolower($mensaje);

    return preg_match(
        '/\b(tutor(?:es)?|profesor(?:es|as)?|maestr[oa]s?|clases?|buscar|busco|encuentra|encontrar|materias?|categor[ií]as?|disponibilidad|disponible|horarios?|precio|precios|tarifa|tarifas|cuesta|costo|ciudad|modalidad|calificaci[oó]n|reseña|reseñas)\b/u',
        $mensajeNormalizado
    ) === 1;
};

$preguntaEsPrivada = static function ($mensaje) use ($rolUsuario) {
    $mensajeNormalizado = function_exists('mb_strtolower')
        ? mb_strtolower($mensaje, 'UTF-8')
        : strtolower($mensaje);

    if ($rolUsuario === 'Tutor') {
        return preg_match(
            '/\b(perfil|biograf[ií]a|mis materias|mis horarios|mi tarifa|mi modalidad|mis solicitudes|mis reservas|solicitudes?|reservas?|horarios?|tarifa|reseñas?|calificaci[oó]n|aceptad[ao]s?|rechazad[ao]s?|pendientes?|completad[ao]s?|cancelad[ao]s?|correo|email|contraseñ|cuenta)\b/u',
            $mensajeNormalizado
        ) === 1;
    }

    return preg_match(
        '/\b(mi|mis|m[ií]a|m[ií]as)\b.{0,50}\b(sesi[oó]n|solicitud|reserva|perfil|cuenta|horario|presupuesto|nivel|preferencia|calificaci[oó]n|tutor[ií]a|dato|correo|email|contraseñ|archivo|documento)\w*\b/u',
        $mensajeNormalizado
    ) === 1
        || preg_match('/\b(aceptaron|rechazaron|aceptada|rechazada|mis pendientes|mis completadas)\b/u', $mensajeNormalizado) === 1
        || preg_match('/\b(solicitudes?|reservas?)\b.{0,30}\b(tengo|m[ií]|aceptad[ao]|rechazad[ao]|estado|pendientes?|completad[ao])\w*\b/u', $mensajeNormalizado) === 1;
};

$crearContextoLocal = static function ($mensaje) use ($tutoresLocales, $categoriasLocales, $errorDatosLocales) {
    if ($errorDatosLocales !== '') {
        return '';
    }

    $mensajeNormalizado = function_exists('mb_strtolower')
        ? mb_strtolower($mensaje, 'UTF-8')
        : strtolower($mensaje);
    $palabras = preg_split('/[^\p{L}\p{N}]+/u', $mensajeNormalizado, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($palabras)) {
        return '';
    }

    $ignoradas = array_fill_keys([
        'para', 'como', 'donde', 'quien', 'quienes', 'quiero', 'busco', 'buscar',
        'necesito', 'puedes', 'podrias', 'ayuda', 'ayudar', 'tutor', 'tutores',
        'profesor', 'profesores', 'profesora', 'profesoras', 'clase', 'clases',
        'materia', 'materias', 'disponible', 'disponibles', 'hay', 'una', 'uno',
        'con', 'por', 'que', 'del', 'las', 'los', 'me', 'gustaria',
    ], true);
    $terminos = array_values(array_unique(array_filter($palabras, static function ($palabra) use ($ignoradas) {
        return strlen($palabra) > 2 && !isset($ignoradas[$palabra]);
    })));

    $coincidencias = [];
    foreach ($tutoresLocales as $tutor) {
        $textoTutor = implode(' ', [
            $tutor['nombre'],
            $tutor['apellido'],
            $tutor['ciudad'] ?? '',
            $tutor['biografia'] ?? '',
            $tutor['materias'] ?? '',
            $tutor['categorias'] ?? '',
            $tutor['modalidad_preferida'] ?? '',
        ]);
        if (function_exists('mb_strtolower')) {
            $textoTutor = mb_strtolower($textoTutor, 'UTF-8');
        }

        $puntuacion = 0;
        foreach ($terminos as $termino) {
            if (strpos($textoTutor, $termino) !== false) {
                $puntuacion++;
            }
        }
        if ($puntuacion > 0) {
            $tutor['_puntuacion_busqueda'] = $puntuacion;
            $coincidencias[] = $tutor;
        }
    }

    usort($coincidencias, static function ($a, $b) {
        $comparacion = $b['_puntuacion_busqueda'] <=> $a['_puntuacion_busqueda'];
        return $comparacion !== 0
            ? $comparacion
            : ((float) $b['calificacion_promedio'] <=> (float) $a['calificacion_promedio']);
    });

    if (!$coincidencias && preg_match('/\b(tutor|profesor|profesora|clase|clases|enseñ|disponibles|precio|tarifa|modalidad|calificaci[oó]n)\b/u', $mensajeNormalizado)) {
        $coincidencias = array_slice($tutoresLocales, 0, 5);
    } else {
        $coincidencias = array_slice($coincidencias, 0, 5);
    }

    $contexto = '';
    if ($coincidencias) {
        $lineas = [];
        foreach ($coincidencias as $tutor) {
            $lineas[] = [
                'tutor' => trim($tutor['nombre'] . ' ' . $tutor['apellido']),
                'ciudad' => $tutor['ciudad'] ?: 'No indicada',
                'materias' => $tutor['materias'] ?: 'No indicadas',
                'categorias' => $tutor['categorias'] ?: 'No indicadas',
                'modalidad' => $tutor['modalidad_preferida'],
                'tarifa_por_hora' => (float) $tutor['tarifa_por_hora'],
                'calificacion' => (float) $tutor['calificacion_promedio'],
                'resenas_positivas' => (int) $tutor['resenas_positivas'],
                'total_resenas' => (int) $tutor['total_resenas'],
                'biografia' => substr((string) ($tutor['biografia'] ?? ''), 0, 300),
            ];
        }
        $contexto .= "Tutores coincidentes:\n" . json_encode($lineas, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    if (preg_match('/\b(categor[ií]as?|materias?)\b/u', $mensajeNormalizado) && $categoriasLocales) {
        $datosCategorias = array_map(static function ($categoria) {
            return ['categoria' => $categoria['categoria'], 'cantidad_materias' => (int) $categoria['total']];
        }, $categoriasLocales);
        $contexto .= ($contexto === '' ? '' : "\n\n")
            . "Categorías de materias:\n"
            . json_encode($datosCategorias, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    return $contexto;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['ai_csrf_token'], $token)) {
        $_SESSION['ai_notice'] = 'La sesión del formulario venció. Actualiza la página e inténtalo de nuevo.';
    } elseif (($_POST['accion'] ?? '') === 'guardar_preferencias' && $rolUsuario === 'Estudiante') {
        $nivelAcademico = trim((string) ($_POST['nivel_academico'] ?? ''));
        $presupuestoMaximo = trim((string) ($_POST['presupuesto_maximo'] ?? ''));
        $diasPermitidos = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
        $diasPreferidos = array_values(array_unique(array_intersect(
            $diasPermitidos,
            is_array($_POST['dias_preferidos'] ?? null) ? $_POST['dias_preferidos'] : []
        )));
        $horaInicioPreferida = trim((string) ($_POST['hora_inicio_preferida'] ?? ''));
        $horaFinPreferida = trim((string) ($_POST['hora_fin_preferida'] ?? ''));
        $presupuestoValido = $presupuestoMaximo === ''
            || (is_numeric($presupuestoMaximo) && (float) $presupuestoMaximo > 0 && (float) $presupuestoMaximo <= 99999);
        $horarioValido = !$diasPreferidos
            ? $horaInicioPreferida === '' && $horaFinPreferida === ''
            : preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horaInicioPreferida) === 1
                && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horaFinPreferida) === 1
                && $horaInicioPreferida < $horaFinPreferida;

        if (strlen($nivelAcademico) > 100 || !$presupuestoValido || !$horarioValido) {
            $_SESSION['ai_notice'] = 'Revisa tu nivel, presupuesto máximo y el horario seleccionado.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("
                    INSERT INTO Preferencias_Estudiantes (id_estudiante, nivel_academico, presupuesto_maximo)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE nivel_academico = VALUES(nivel_academico),
                                            presupuesto_maximo = VALUES(presupuesto_maximo)
                ");
                $stmt->execute([
                    $idUsuario,
                    $nivelAcademico !== '' ? $nivelAcademico : null,
                    $presupuestoMaximo !== '' ? (float) $presupuestoMaximo : null,
                ]);

                $stmt = $pdo->prepare('DELETE FROM Disponibilidad_Estudiantes WHERE id_estudiante = ?');
                $stmt->execute([$idUsuario]);
                if ($diasPreferidos) {
                    $stmt = $pdo->prepare("
                        INSERT INTO Disponibilidad_Estudiantes (id_estudiante, dia_semana, hora_inicio, hora_fin)
                        VALUES (?, ?, ?, ?)
                    ");
                    foreach ($diasPreferidos as $diaPreferido) {
                        $stmt->execute([
                            $idUsuario,
                            $diaPreferido,
                            $horaInicioPreferida . ':00',
                            $horaFinPreferida . ':00',
                        ]);
                    }
                }
                $pdo->commit();
                $_SESSION['ai_notice'] = 'Guardé tus preferencias. Solo se usarán para recomendarte tutores a ti.';
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('No se pudieron guardar las preferencias del estudiante: ' . $exception->getMessage());
                $_SESSION['ai_notice'] = 'No se pudieron guardar tus preferencias. Verifica que la migración de preferencias esté instalada.';
            }
        }
        header('Location: asistente_ia.php?modo=' . urlencode($modo));
        exit();
    } elseif (($_POST['accion'] ?? '') === 'activar_online') {
        $_SESSION['ai_online_activated'] = true;
        header('Location: asistente_ia.php?modo=' . urlencode($modo));
        exit();
    } elseif (isset($_POST['mensaje'])) {
        $mensajeUsuario = trim($_POST['mensaje']);
        $caracteresMensaje = [];
        $cantidadCaracteres = preg_match_all('/./us', $mensajeUsuario, $caracteresMensaje);

        if ($mensajeUsuario === '') {
            $_SESSION['ai_notice'] = 'Escribe una pregunta antes de enviarla.';
        } elseif ($cantidadCaracteres === false) {
            $_SESSION['ai_notice'] = 'El mensaje contiene caracteres no válidos. Corrígelo e inténtalo de nuevo.';
        } elseif ($cantidadCaracteres > 4000) {
            $_SESSION['ai_notice'] = 'Tu mensaje es demasiado largo. El límite es de 4000 caracteres.';
        } else {
            $consultaDatosLocales = $preguntaUsaDatosLocales($mensajeUsuario);
            $consultaPrivada = $preguntaEsPrivada($mensajeUsuario);
            $usarGemini = !$consultaPrivada && ($modo === 'online'
                || ($modo === 'auto' && !($consultaDatosLocales && empty($_SESSION['ai_online_activated']))));
            if ($usarGemini && empty($_SESSION['ai_online_activated'])) {
                $_SESSION['ai_notice'] = 'Esta pregunta general requiere Gemini. Lee la advertencia y actívalo; las consultas privadas siempre se responden localmente.';
            } else {
            unset($_SESSION['ai_notice']);
            $_SESSION['ai_chat_history'][$modo][] = ['sender' => 'user', 'text' => $mensajeUsuario];
            $respuesta = '';

            if (!$usarGemini) {
                $respuesta = $responderLocalmente($mensajeUsuario);
                if ($consultaPrivada && $modo !== 'local') {
                    $_SESSION['ai_notice'] = 'Esta consulta usa únicamente datos de tu propia cuenta y no se envió a Gemini.';
                }
            } else {
                $errorOnline = '';
                if (!$geminiConfigured) {
                    $errorOnline = 'Falta configurar GEMINI_API_KEY en el servidor.';
                } elseif (!function_exists('curl_init')) {
                    $errorOnline = 'La extensión cURL de PHP no está disponible en el servidor.';
                } else {
                    $payload = json_encode([
                        'system_instruction' => [
                            'parts' => [[
                                'text' => 'Eres TutorBot, un asistente educativo amable que responde en español. Contesta preguntas abiertas con explicaciones claras y útiles, adapta el nivel al estudiante y muestra los pasos cuando ayude. Si no sabes algo, dilo con honestidad. No afirmes haber visto archivos que no se han enviado. No inventes nombres, calificaciones, precios, materias ni disponibilidad de tutores. Si recibes contexto de la base local, úsalo como fuente de verdad para datos de tutores; si el contexto no contiene la respuesta, indícalo claramente. Para reservar o revisar disponibilidad exacta, invita a usar las secciones correspondientes de la plataforma.'
                                    . ($consultaDatosLocales
                                        ? "\n\nInformación local pertinente de la base de datos:\n" . $crearContextoLocal($mensajeUsuario)
                                        : ''),
                            ]],
                        ],
                        'contents' => [[
                            'role' => 'user',
                            'parts' => [['text' => $mensajeUsuario]],
                        ]],
                        'generationConfig' => [
                            'temperature' => 0.7,
                            'maxOutputTokens' => 800,
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

                    if ($payload === false) {
                        $errorOnline = 'No se pudo preparar la solicitud de Gemini.';
                    } else {
                        $curl = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent');
                        if ($curl === false) {
                            $errorOnline = 'No se pudo iniciar la conexión con Gemini.';
                        } else {
                            curl_setopt_array($curl, [
                                CURLOPT_POST => true,
                                CURLOPT_POSTFIELDS => $payload,
                                CURLOPT_HTTPHEADER => [
                                    'Content-Type: application/json',
                                    'x-goog-api-key: ' . trim($apiKey),
                                ],
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_CONNECTTIMEOUT => 10,
                                CURLOPT_TIMEOUT => 45,
                            ]);

                            $responseBody = curl_exec($curl);
                            $curlError = curl_error($curl);
                            $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
                            curl_close($curl);

                            if ($responseBody === false) {
                                error_log('Error de transporte al llamar a Gemini: ' . $curlError);
                                $errorOnline = 'No se pudo conectar con Gemini. Revisa internet, DNS o la conexión del servidor.';
                            } else {
                                $responseData = json_decode($responseBody, true);
                                $textoGemini = '';
                                if (is_array($responseData)) {
                                    $candidates = $responseData['candidates'] ?? [];
                                    $candidate = is_array($candidates) ? ($candidates[0] ?? []) : [];
                                    $content = is_array($candidate) ? ($candidate['content'] ?? []) : [];
                                    $parts = is_array($content) ? ($content['parts'] ?? []) : [];
                                    $part = is_array($parts) ? ($parts[0] ?? []) : [];
                                    if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                                        $textoGemini = $part['text'];
                                    }
                                }
                                if ($httpStatus < 200 || $httpStatus >= 300) {
                                    $providerError = is_array($responseData) ? ($responseData['error'] ?? []) : [];
                                    $providerMessage = is_array($providerError)
                                        ? ($providerError['message'] ?? 'Respuesta HTTP ' . $httpStatus)
                                        : 'Respuesta HTTP ' . $httpStatus;
                                    error_log('Gemini devolvió un error: ' . $providerMessage);
                                    if ($httpStatus === 429) {
                                        $errorOnline = 'Gemini alcanzó su límite de solicitudes; espera un momento.';
                                    } elseif ($httpStatus === 401 || $httpStatus === 403) {
                                        $errorOnline = 'Gemini rechazó la clave o los permisos de la API.';
                                    } else {
                                        $errorOnline = 'Gemini no pudo responder (HTTP ' . $httpStatus . '). Revisa la configuración de la API.';
                                    }
                                } elseif (!is_string($textoGemini) || trim($textoGemini) === '') {
                                    error_log('Gemini devolvió una respuesta vacía o con formato inesperado.');
                                    $errorOnline = 'Gemini devolvió una respuesta vacía o inesperada.';
                                } else {
                                    $respuesta = trim($textoGemini);
                                }
                            }
                        }
                    }
                }

                if ($errorOnline !== '') {
                    $respuestaLocal = $responderLocalmente($mensajeUsuario);
                    $respuesta = "No fue posible usar Gemini: $errorOnline\n"
                        . "Te respondo con la información local de la plataforma:\n\n"
                        . $respuestaLocal;
                    $_SESSION['ai_notice'] = 'Gemini no está disponible; recibiste una respuesta basada en la base de datos local.';
                }
            }

            $_SESSION['ai_chat_history'][$modo][] = ['sender' => 'bot', 'text' => $respuesta];
            if (count($_SESSION['ai_chat_history'][$modo]) > 24) {
                $_SESSION['ai_chat_history'][$modo] = array_slice($_SESSION['ai_chat_history'][$modo], -24);
            }
            }
        }
    }

    header('Location: asistente_ia.php?modo=' . urlencode($modo));
    exit();
}

$historial = $_SESSION['ai_chat_history'][$modo];
$aviso = $_SESSION['ai_notice'] ?? '';
unset($_SESSION['ai_notice']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TutorBot IA - TutoresOnLine</title>
    <link rel="stylesheet" href="CSS/estilos.css?v=<?php echo filemtime(__DIR__ . '/CSS/estilos.css'); ?>">
    <style>
        .layout { display: flex; min-height: 100vh; background: #f4f6f9; }
        .sidebar { width: 240px; flex: 0 0 240px; background: #fff; border-right: 1px solid #e0e0e0; padding: 20px; }
        .chat-brand { display: inline-flex; align-items: center; gap: 10px; margin-bottom: 30px; color: #26354a; font-size: 16px; font-weight: 700; text-decoration: none; }
        .chat-brand > span > span { color: #1a73e8; }
        .chat-brand .brand-logo-image { width: 34px; height: 34px; }
        .sidebar nav a { display: block; padding: 12px 15px; color: #555; text-decoration: none; border-radius: 6px; margin-bottom: 8px; font-weight: 500; }
        .sidebar nav a.active, .sidebar nav a:hover { background-color: #e8f0fe; color: #1a73e8; }
        .content { flex: 1; width: min(100% - 40px, 900px); margin: 0 auto; padding: 28px 20px; }
        .assistant-heading { margin-bottom: 20px; }
        .assistant-heading h1 { margin: 4px 0 8px; color: #202124; font-size: 26px; }
        .assistant-heading p { color: #5f6368; }
        .mode-tabs { display: flex; gap: 10px; margin: 20px 0; }
        .mode-tab { display: inline-block; padding: 11px 18px; border: 1px solid #d5dce5; border-radius: 8px; background: #fff; color: #344054; text-decoration: none; font-weight: 700; }
        .mode-tab.active { border-color: #1a73e8; background: #e8f0fe; color: #1557b0; }
        .data-summary, .online-warning, .notice-box { padding: 15px 18px; border-radius: 10px; margin: 14px 0; }
        .data-summary { background: #eaf5ee; color: #245c37; }
        .data-summary p, .online-warning p { margin: 5px 0 0; line-height: 1.5; }
        .preference-panel { margin: 14px 0; padding: 18px; border: 1px solid #e0e5ec; border-radius: 10px; background: #fff; }
        .preference-panel h2 { margin: 0 0 8px; color: #26354a; font-size: 17px; }
        .preference-panel > p { margin: 0 0 14px; color: #687386; font-size: 13px; line-height: 1.5; }
        .preference-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .preference-field { display: flex; flex-direction: column; gap: 6px; }
        .preference-field label, .schedule-label { color: #344054; font-size: 13px; font-weight: 700; }
        .preference-field input { width: 100%; padding: 10px 12px; border: 1px solid #ccd3de; border-radius: 7px; font: inherit; }
        .schedule-options { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin: 8px 0 12px; }
        .schedule-options label { display: flex; align-items: center; gap: 6px; color: #475467; font-size: 13px; }
        .schedule-time { display: flex; gap: 12px; }
        .schedule-time .preference-field { flex: 1; }
        .save-preferences { justify-self: start; border: 0; border-radius: 7px; padding: 10px 15px; color: #fff; background: #1a73e8; font-weight: 700; cursor: pointer; }
        .privacy-note { margin-top: 12px !important; color: #245c37 !important; }
        .tutor-own-summary { line-height: 1.6; }
        .online-warning { border: 1px solid #f0c36d; background: #fff8e6; color: #654d1b; }
        .notice-box { background: #fff1f0; color: #a12622; }
        .activate-form { margin-top: 14px; }
        .activate-button { border: 0; border-radius: 7px; padding: 11px 16px; color: #fff; background: #1a73e8; font-weight: 700; cursor: pointer; }
        .status-label { color: #466; font-size: 13px; font-weight: 700; }
        .chat-container { background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); display: flex; flex-direction: column; min-height: 480px; height: 62vh; }
        .chat-header { padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .chat-header h2 { color: #202124; font-size: 16px; margin: 0; }
        .chat-messages { flex: 1; padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }
        .message { max-width: 90%; padding: 12px 16px; border-radius: 12px; font-size: 14px; line-height: 1.55; white-space: pre-line; overflow-wrap: anywhere; }
        .message.bot { background: #f1f3f4; color: #202124; align-self: flex-start; border-bottom-left-radius: 2px; }
        .message.user { background: #1a73e8; color: #fff; align-self: flex-end; border-bottom-right-radius: 2px; }
        .chat-footer { padding: 15px; border-top: 1px solid #eee; }
        .input-box { display: flex; gap: 10px; align-items: center; }
        .input-box input[type="text"] { flex: 1; min-width: 0; padding: 11px 14px; border: 1px solid #ccc; border-radius: 20px; font-size: 14px; outline: none; }
        .input-box button { background: #1a73e8; color: #fff; border: 0; padding: 11px 18px; border-radius: 20px; font-weight: bold; cursor: pointer; }
        .input-box button:hover, .activate-button:hover { background: #1557b0; }
        .help-text { margin: 10px 0 0; color: #687386; font-size: 12px; }
        @media (max-width: 700px) {
            .layout { display: block; }
            .sidebar { width: auto; padding: 12px 16px; border-right: 0; border-bottom: 1px solid #e0e0e0; }
            .chat-brand { margin-bottom: 10px; }
            .sidebar nav { display: flex; flex-wrap: wrap; gap: 4px; }
            .sidebar nav a { margin: 0; padding: 8px 10px; }
            .content { width: auto; padding: 20px 14px; }
            .chat-container { height: 60vh; min-height: 420px; }
        }
        @media (max-width: 480px) {
            .mode-tabs { flex-direction: column; }
            .mode-tab { text-align: center; }
            .chat-header { align-items: flex-start; flex-direction: column; }
            .input-box { align-items: stretch; flex-direction: column; }
            .input-box button { width: 100%; }
            .preference-form { grid-template-columns: 1fr; }
            .schedule-options { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <a class="chat-brand" href="dashboard.php"><img class="brand-logo-image" src="assets/logo-marca.png" alt=""><span>Tutores<span>OnLine</span></span></a>
            <nav aria-label="Navegación principal">
                <a href="dashboard.php">Dashboard</a>
                <?php if ($rolUsuario === 'Estudiante'): ?>
                    <a href="buscar.php">Buscar tutores</a>
                    <a href="mis_sesiones.php">Mis sesiones</a>
                <?php else: ?>
                    <a href="perfil_tutor_config.php">Mi perfil de tutor</a>
                    <a href="solicitudes.php">Mis solicitudes</a>
                <?php endif; ?>
                <a href="asistente_ia.php?modo=auto" class="active">Asistente IA</a>
                <a href="perfil.php">Mi Perfil y Archivos</a>
                <a href="logout.php" style="color: #d93025; margin-top: 20px;">Cerrar Sesión</a>
            </nav>
        </aside>

        <main class="content">
            <header class="assistant-heading">
                <p class="dashboard-kicker">ASISTENTE DE ESTUDIO</p>
                <h1>TutorBot IA</h1>
                <p><?php echo $rolUsuario === 'Estudiante'
                    ? 'Tus sesiones, preferencias y datos personales se consultan únicamente en tu cuenta. Gemini nunca recibe información privada tuya ni el historial de este chat.'
                    : 'El asistente consulta solo tu perfil y las solicitudes asociadas a tu cuenta. Nunca muestra ni envía datos personales de estudiantes.'; ?></p>
            </header>

            <nav class="mode-tabs" aria-label="Modo del asistente">
                <a class="mode-tab <?php echo $modo === 'auto' ? 'active' : ''; ?>" href="asistente_ia.php?modo=auto" <?php echo $modo === 'auto' ? 'aria-current="page"' : ''; ?>>Automático</a>
                <a class="mode-tab <?php echo $modo === 'local' ? 'active' : ''; ?>" href="asistente_ia.php?modo=local" <?php echo $modo === 'local' ? 'aria-current="page"' : ''; ?>>Información local</a>
                <a class="mode-tab <?php echo $modo === 'online' ? 'active' : ''; ?>" href="asistente_ia.php?modo=online" <?php echo $modo === 'online' ? 'aria-current="page"' : ''; ?>>IA en línea (Gemini)</a>
            </nav>

            <?php if ($aviso !== ''): ?>
                <div class="notice-box" role="status"><?php echo htmlspecialchars($aviso, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($modo !== 'online' && $rolUsuario === 'Estudiante'): ?>
                <section class="data-summary" aria-label="Información disponible localmente">
                    <strong><?php echo $modo === 'auto' ? 'Base local lista:' : 'Modo local:'; ?> <?php echo count($tutoresLocales); ?> perfiles de tutores y <?php echo count($categoriasLocales); ?> categorías disponibles. Tus consultas privadas solo buscan tus propios datos.</strong>
                    <p><?php echo $modo === 'auto'
                        ? 'El modo automático usa tus preferencias y sesiones propias, además de información pública de tutores, para responder localmente.'
                        : 'Pregunta por materias, ciudad, modalidad, tarifas o calificaciones. Tus mensajes se procesan aquí con la información de la base de datos.'; ?></p>
                </section>
            <?php endif; ?>

            <?php if ($modo !== 'online' && $rolUsuario === 'Tutor'): ?>
                <section class="data-summary tutor-own-summary" aria-label="Resumen privado de tu perfil de tutor">
                    <strong>Asistente de tu perfil</strong>
                    <p>Solo puede consultar tu propio perfil, horarios, materias, reseñas agregadas y solicitudes de tutoría. Los nombres y datos de estudiantes no se incluyen en sus respuestas.</p>
                    <?php if ($perfilTutorPropio && $resumenTutorPropio): ?>
                        <p>Reseñas: <?php echo (int) $resumenTutorPropio['total_resenas']; ?> · promedio <?php echo number_format((float) $resumenTutorPropio['promedio'], 1); ?>/5 · positivas <?php echo (int) $resumenTutorPropio['positivas']; ?>.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($errorDatosLocales !== ''): ?>
                <div class="notice-box" role="alert"><?php echo htmlspecialchars($errorDatosLocales, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($modo !== 'online' && $rolUsuario === 'Estudiante'): ?>
                <?php
                $diasSemanaPreferidos = array_column($horariosEstudiante, 'dia_semana');
                $primerHorarioPreferido = $horariosEstudiante[0] ?? null;
                ?>
                <section class="preference-panel" aria-label="Tus preferencias privadas para recomendaciones">
                    <h2>Mis preferencias para encontrar tutor</h2>
                    <p>Se guardan solo en tu cuenta y se usan para filtrar recomendaciones por nivel, presupuesto, horario y reseñas positivas.</p>
                    <form class="preference-form" method="POST" action="asistente_ia.php?modo=<?php echo urlencode($modo); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['ai_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="modo" value="<?php echo htmlspecialchars($modo, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="accion" value="guardar_preferencias">
                        <div class="preference-field">
                            <label for="nivel_academico">Nivel académico (opcional)</label>
                            <input id="nivel_academico" name="nivel_academico" type="text" maxlength="100" value="<?php echo htmlspecialchars((string) $preferenciasEstudiante['nivel_academico'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ej. secundaria, universidad">
                        </div>
                        <div class="preference-field">
                            <label for="presupuesto_maximo">Presupuesto máximo por hora (opcional)</label>
                            <input id="presupuesto_maximo" name="presupuesto_maximo" type="number" min="1" max="99999" step="0.01" value="<?php echo htmlspecialchars((string) ($preferenciasEstudiante['presupuesto_maximo'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="$">
                        </div>
                        <div style="grid-column: 1 / -1">
                            <span class="schedule-label">Días disponibles para estudiar (opcional)</span>
                            <div class="schedule-options">
                                <?php foreach (['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'] as $diaPreferido): ?>
                                    <label><input type="checkbox" name="dias_preferidos[]" value="<?php echo htmlspecialchars($diaPreferido, ENT_QUOTES, 'UTF-8'); ?>" <?php echo in_array($diaPreferido, $diasSemanaPreferidos, true) ? 'checked' : ''; ?>><?php echo htmlspecialchars($diaPreferido, ENT_QUOTES, 'UTF-8'); ?></label>
                                <?php endforeach; ?>
                            </div>
                            <div class="schedule-time">
                                <div class="preference-field"><label for="hora_inicio_preferida">Desde</label><input id="hora_inicio_preferida" name="hora_inicio_preferida" type="time" value="<?php echo htmlspecialchars($primerHorarioPreferido ? substr($primerHorarioPreferido['hora_inicio'], 0, 5) : '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                                <div class="preference-field"><label for="hora_fin_preferida">Hasta</label><input id="hora_fin_preferida" name="hora_fin_preferida" type="time" value="<?php echo htmlspecialchars($primerHorarioPreferido ? substr($primerHorarioPreferido['hora_fin'], 0, 5) : '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                            </div>
                        </div>
                        <button class="save-preferences" type="submit">Guardar mis preferencias</button>
                    </form>
                    <p class="privacy-note">Privacidad: estas preferencias y tus sesiones solo se leen con tu ID de cuenta y nunca se envían a Gemini.</p>
                </section>
            <?php endif; ?>

            <?php if ($modo === 'auto' || $modo === 'online'): ?>
                <section class="online-warning" aria-label="Advertencia del modo en línea">
                    <strong><?php echo $modo === 'auto' ? 'Modo automático: revisa cuándo se usa Gemini' : 'Antes de activar Gemini, ten en cuenta:'; ?></strong>
                    <p>Solo las preguntas generales que escribas se envían a Gemini después de activar el servicio. Nunca se envían tus preferencias, horarios personales, sesiones, solicitudes, perfiles privados ni el historial del chat. Las consultas sobre tus datos se responden localmente. Gemini puede fallar por token ausente o inválido, permisos, cuota, conexión, tiempo de espera o errores del proveedor.</p>
                    <?php if (!$geminiConfigured): ?>
                        <p><strong>Estado actual:</strong> no hay una clave GEMINI_API_KEY configurada. El modo automático seguirá respondiendo consultas de tutores con la base local; Gemini no estará disponible para preguntas generales.</p>
                    <?php endif; ?>
                    <?php if (empty($_SESSION['ai_online_activated'])): ?>
                        <form class="activate-form" method="POST" action="asistente_ia.php?modo=<?php echo urlencode($modo); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['ai_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="modo" value="<?php echo htmlspecialchars($modo, ENT_QUOTES, 'UTF-8'); ?>">
                            <button class="activate-button" type="submit" name="accion" value="activar_online">Entiendo y activar Gemini</button>
                        </form>
                    <?php else: ?>
                        <p class="status-label">Gemini activado. En automático, la pregunta determina si se consulta la base local o la API.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($modo !== 'online' || !empty($_SESSION['ai_online_activated'])): ?>
                <section class="chat-container" aria-label="Conversación con TutorBot">
                    <div class="chat-header">
                        <h2><?php echo $modo === 'local' ? 'Asistente con información local' : ($modo === 'auto' ? 'Asistente automático: local + Gemini' : 'Asistente con Gemini y respaldo local'); ?></h2>
                        <span class="status-label"><?php echo $modo === 'local'
                            ? 'Sin conexión externa'
                            : (!$geminiConfigured
                                ? 'Sin token; Gemini no disponible'
                                : (empty($_SESSION['ai_online_activated']) ? 'Gemini requiere activación' : 'Gemini activado')); ?></span>
                    </div>
                    <div class="chat-messages" aria-live="polite">
                        <?php foreach ($historial as $msg): ?>
                            <div class="message <?php echo $msg['sender'] === 'user' ? 'user' : 'bot'; ?>">
                                <?php echo htmlspecialchars($msg['text'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="chat-footer">
                        <form method="POST" action="asistente_ia.php?modo=<?php echo urlencode($modo); ?>" class="input-box">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['ai_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="modo" value="<?php echo htmlspecialchars($modo, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="text" name="mensaje" placeholder="Ej. ¿Qué tutores enseñan matemáticas en línea?" maxlength="4000" required autocomplete="off">
                            <button type="submit">Enviar</button>
                        </form>
                        <?php if ($modo !== 'local'): ?>
                            <p class="help-text"><?php echo $modo === 'auto' ? 'El modo automático usa la base de tutores para consultas de la plataforma y Gemini para preguntas generales.' : 'Si Gemini falla, tu pregunta se responderá con la información local disponible.'; ?></p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
