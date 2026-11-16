<?php
session_start();
require 'conexcion.php';

$mensaje = '';
$tipoMensaje = '';
$datos = [
    'nombre' => '',
    'apellido' => '',
    'email' => '',
    'rol' => 'Estudiante',
    'ciudad' => '',
    'biografia' => '',
    'tarifa_por_hora' => '25.00',
    'modalidad_preferida' => 'Ambas',
];
if (in_array($_GET['rol'] ?? '', ['Estudiante', 'Tutor'], true)) {
    $datos['rol'] = $_GET['rol'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($datos as $campo => $valor) {
        $datos[$campo] = trim((string) ($_POST[$campo] ?? $valor));
    }
    $password = $_POST['password'] ?? '';
    $rolValido = in_array($datos['rol'], ['Estudiante', 'Tutor'], true);
    $datosPersonalesValidos = $datos['nombre'] !== ''
        && $datos['apellido'] !== ''
        && filter_var($datos['email'], FILTER_VALIDATE_EMAIL)
        && strlen($password) >= 8
        && $rolValido;
    $perfilTutorValido = $datos['rol'] !== 'Tutor' || (
        $datos['biografia'] !== ''
        && is_numeric($datos['tarifa_por_hora'])
        && (float) $datos['tarifa_por_hora'] > 0
        && in_array($datos['modalidad_preferida'], ['Virtual', 'Presencial', 'Ambas'], true)
    );

    if (!$datosPersonalesValidos || !$perfilTutorValido) {
        $mensaje = 'Revisa los datos. La contraseña debe tener al menos 8 caracteres y el perfil de tutor necesita biografía y tarifa válida.';
        $tipoMensaje = 'error';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO usuarios (nombre, apellido, email, password_hash, rol, ciudad) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $datos['nombre'],
                $datos['apellido'],
                $datos['email'],
                password_hash($password, PASSWORD_DEFAULT),
                $datos['rol'],
                $datos['ciudad'] !== '' ? $datos['ciudad'] : null,
            ]);
            $idUsuario = (int) $pdo->lastInsertId();

            if ($datos['rol'] === 'Tutor') {
                $stmt = $pdo->prepare('INSERT INTO perfiles_tutores (id_tutor, biografia, tarifa_por_hora, modalidad_preferida) VALUES (?, ?, ?, ?)');
                $stmt->execute([
                    $idUsuario,
                    $datos['biografia'],
                    $datos['tarifa_por_hora'],
                    $datos['modalidad_preferida'],
                ]);
            }

            $pdo->commit();
            $mensaje = '¡Cuenta creada! Ya puedes iniciar sesión.';
            $tipoMensaje = 'success';
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            $mensaje = $exception->getCode() === '23000'
                ? 'Ese correo electrónico ya está registrado.'
                : 'No se pudo completar el registro. Inténtalo de nuevo.';
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
    <title>Crear cuenta - TutoresOnLine</title>
    <link rel="stylesheet" href="CSS/estilos.css?v=<?php echo filemtime(__DIR__ . '/CSS/estilos.css'); ?>">
</head>
<body class="auth-page">
    <main class="auth-card auth-card--wide">
        <a class="auth-brand" href="index.php" aria-label="TutoresOnLine, inicio">
            <img class="brand-logo-image" src="assets/logo-marca.png" alt="">
            <span>Tutores<span>OnLine</span></span>
        </a>
        <div class="auth-heading">
            <p class="auth-eyebrow">ÚNETE A LA COMUNIDAD</p>
            <h1>Crear una cuenta</h1>
            <p>Elige cómo quieres participar en TutoresOnLine.</p>
        </div>

        <?php if ($mensaje !== ''): ?>
            <div class="auth-message auth-message--<?php echo $tipoMensaje; ?>" role="alert">
                <?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form class="auth-form" action="registro.php" method="POST">
            <div class="auth-form-grid">
                <div class="auth-field">
                    <label for="nombre">Nombres</label>
                    <input id="nombre" type="text" name="nombre" value="<?php echo htmlspecialchars($datos['nombre'], ENT_QUOTES, 'UTF-8'); ?>" autocomplete="given-name" required>
                </div>
                <div class="auth-field">
                    <label for="apellido">Apellidos</label>
                    <input id="apellido" type="text" name="apellido" value="<?php echo htmlspecialchars($datos['apellido'], ENT_QUOTES, 'UTF-8'); ?>" autocomplete="family-name" required>
                </div>
                <div class="auth-field auth-field--full">
                    <label for="email">Correo electrónico</label>
                    <input id="email" type="email" name="email" value="<?php echo htmlspecialchars($datos['email'], ENT_QUOTES, 'UTF-8'); ?>" autocomplete="email" required>
                </div>
                <div class="auth-field auth-field--full">
                    <label for="password">Contraseña</label>
                    <input id="password" type="password" name="password" autocomplete="new-password" minlength="8" required>
                    <small>Usa al menos 8 caracteres.</small>
                </div>
                <div class="auth-field">
                    <label for="rol">Quiero registrarme como</label>
                    <select name="rol" id="rol" required>
                        <option value="Estudiante" <?php echo $datos['rol'] === 'Estudiante' ? 'selected' : ''; ?>>Estudiante</option>
                        <option value="Tutor" <?php echo $datos['rol'] === 'Tutor' ? 'selected' : ''; ?>>Tutor</option>
                    </select>
                </div>
                <div class="auth-field">
                    <label for="ciudad">Ciudad <span>(opcional)</span></label>
                    <input id="ciudad" type="text" name="ciudad" value="<?php echo htmlspecialchars($datos['ciudad'], ENT_QUOTES, 'UTF-8'); ?>" autocomplete="address-level2">
                </div>
            </div>

            <section class="tutor-registration-fields" id="tutor-fields" <?php echo $datos['rol'] !== 'Tutor' ? 'hidden' : ''; ?>>
                <h2>Tu perfil de tutor</h2>
                <p>Estos datos aparecerán cuando los estudiantes busquen tutores.</p>
                <div class="auth-field">
                    <label for="biografia">Biografía y experiencia</label>
                    <textarea id="biografia" name="biografia" rows="4" maxlength="2000"><?php echo htmlspecialchars($datos['biografia'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="auth-form-grid">
                    <div class="auth-field">
                        <label for="tarifa_por_hora">Tarifa por hora ($)</label>
                        <input id="tarifa_por_hora" type="number" name="tarifa_por_hora" min="1" max="99999" step="0.01" value="<?php echo htmlspecialchars($datos['tarifa_por_hora'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="auth-field">
                        <label for="modalidad_preferida">Modalidad</label>
                        <select id="modalidad_preferida" name="modalidad_preferida">
                            <option value="Ambas" <?php echo $datos['modalidad_preferida'] === 'Ambas' ? 'selected' : ''; ?>>Virtual o presencial</option>
                            <option value="Virtual" <?php echo $datos['modalidad_preferida'] === 'Virtual' ? 'selected' : ''; ?>>Virtual</option>
                            <option value="Presencial" <?php echo $datos['modalidad_preferida'] === 'Presencial' ? 'selected' : ''; ?>>Presencial</option>
                        </select>
                    </div>
                </div>
            </section>

            <button class="auth-submit" type="submit">Crear cuenta</button>
        </form>
        <p class="auth-footer">¿Ya tienes una cuenta? <a href="index.php">Inicia sesión</a></p>
    </main>
    <script>
        const roleSelect = document.getElementById('rol');
        const tutorFields = document.getElementById('tutor-fields');
        const biographyField = document.getElementById('biografia');

        function updateTutorFields() {
            const isTutor = roleSelect.value === 'Tutor';
            tutorFields.hidden = !isTutor;
            biographyField.required = isTutor;
        }

        roleSelect.addEventListener('change', updateTutorFields);
        updateTutorFields();
    </script>
</body>
</html>
