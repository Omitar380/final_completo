<?php
session_start();
require 'conexcion.php';

$error = '';
$cookie_email = isset($_COOKIE['email_recuerdos']) ? $_COOKIE['email_recuerdos'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Manejo de Cookies (Recordar correo por 30 días)
    if (isset($_POST['remember'])) {
        setcookie('email_recuerdos', $email, time() + (86400 * 30), '/');
    } else {
        setcookie('email_recuerdos', '', time() - 3600, '/');
    }

    if (!empty($email) && !empty($password)) {
        try {
            $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
            $stmt->execute([$email]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verificamos si el usuario existe y la contraseña coincide
            if ($usuario && password_verify($password, $usuario['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['id_usuario'] = $usuario['id_usuario'];
                $_SESSION['nombre'] = $usuario['nombre'];
                $_SESSION['rol'] = $usuario['rol'];

                header('Location: dashboard.php');
                exit();
            } else {
                $error = 'Correo electrónico o contraseña incorrectos.';
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = 'Error de conexión con la base de datos.';
        }
    } else {
        $error = 'Por favor completa todos los campos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TutoresOnLine - Iniciar Sesión</title>
    <link rel="stylesheet" href="CSS/estilos.css?v=<?php echo filemtime(__DIR__ . '/CSS/estilos.css'); ?>">
</head>
<body class="auth-page">
    <main class="auth-card">
        <a class="auth-brand" href="index.php"><img class="brand-logo-image" src="assets/logo-marca.png" alt=""><span>Tutores<span>OnLine</span></span></a>
        <div class="auth-heading">
            <p class="auth-eyebrow">BIENVENIDO DE NUEVO</p>
            <h1>Iniciar sesión</h1>
            <p>Accede a tu espacio de aprendizaje o enseñanza.</p>
        </div>
        <?php if ($error !== ''): ?>
            <div class="auth-message auth-message--error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form class="auth-form" action="index.php" method="POST">
            <div class="auth-field">
                <label for="email">Correo electrónico</label>
                <input id="email" type="email" name="email" value="<?php echo htmlspecialchars($cookie_email, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="username" required>
            </div>
            <div class="auth-field">
                <label for="password">Contraseña</label>
                <input id="password" type="password" name="password" autocomplete="current-password" required>
            </div>
            <label class="auth-checkbox" for="remember"><input id="remember" type="checkbox" name="remember"> Recordarme en este dispositivo</label>
            <button class="auth-submit" type="submit">Iniciar sesión</button>
        </form>
        <div class="auth-register-prompt">
            <p>¿Aún no tienes cuenta?</p>
            <a class="button button--light" href="registro.php">Crear cuenta</a>
            <div class="role-shortcuts"><a href="registro.php?rol=Estudiante">Registrarme como estudiante</a><a href="registro.php?rol=Tutor">Registrarme como tutor</a></div>
        </div>
    </main>
</body>
</html>