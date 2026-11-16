<?php
session_start();
require 'conexcion.php';

if (!isset($_SESSION['id_usuario'])) {
    header('Location: index.php');
    exit();
}

$idUsuario = (int) $_SESSION['id_usuario'];
$mensaje = '';
$tipoMensaje = '';
$archivoSubido = '';
$fotoPerfil = null;

if (!isset($_SESSION['perfil_csrf'])) {
    $_SESSION['perfil_csrf'] = bin2hex(random_bytes(32));
}

$stmt = $pdo->prepare('SELECT foto_perfil FROM usuarios WHERE id_usuario = ?');
$stmt->execute([$idUsuario]);
$fotoPerfil = $stmt->fetchColumn() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['perfil_csrf'], $token)) {
        $mensaje = 'La sesión del formulario venció. Recarga la página e inténtalo de nuevo.';
        $tipoMensaje = 'error';
    } elseif (!isset($_FILES['foto_perfil'])) {
        $mensaje = 'Selecciona un archivo antes de continuar.';
        $tipoMensaje = 'error';
    } else {
        $archivo = $_FILES['foto_perfil'];
        $extensionesPermitidas = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
        ];

        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            $mensaje = $archivo['error'] === UPLOAD_ERR_NO_FILE
                ? 'Selecciona un archivo antes de continuar.'
                : 'No se pudo recibir el archivo. Inténtalo de nuevo.';
            $tipoMensaje = 'error';
        } elseif ($archivo['size'] > 5 * 1024 * 1024) {
            $mensaje = 'El archivo supera el límite permitido de 5 MB.';
            $tipoMensaje = 'error';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $tipoArchivo = $finfo->file($archivo['tmp_name']);

            if (!isset($extensionesPermitidas[$tipoArchivo])) {
                $mensaje = 'Formato no permitido. Sube una imagen JPG o PNG, o un documento PDF.';
                $tipoMensaje = 'error';
            } else {
                $esFoto = $tipoArchivo !== 'application/pdf';
                $subdirectorio = $esFoto ? 'perfiles' : 'documentos';
                $directorioSubidas = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $subdirectorio;

                if (!is_dir($directorioSubidas) && !mkdir($directorioSubidas, 0755, true) && !is_dir($directorioSubidas)) {
                    error_log('No se pudo crear el directorio de archivos: ' . $subdirectorio);
                    $mensaje = 'No se pudo preparar la carpeta para guardar el archivo.';
                    $tipoMensaje = 'error';
                } elseif (!is_writable($directorioSubidas)) {
                    error_log('El directorio de archivos no tiene permisos de escritura: ' . $subdirectorio);
                    $mensaje = 'No se puede guardar el archivo en este momento.';
                    $tipoMensaje = 'error';
                } else {
                    $nombreGuardado = $idUsuario . '-' . bin2hex(random_bytes(16)) . '.' . $extensionesPermitidas[$tipoArchivo];
                    $rutaDestino = $directorioSubidas . DIRECTORY_SEPARATOR . $nombreGuardado;
                    $rutaPublica = 'uploads/' . $subdirectorio . '/' . $nombreGuardado;

                    if (!move_uploaded_file($archivo['tmp_name'], $rutaDestino)) {
                        error_log('No se pudo guardar el archivo subido.');
                        $mensaje = 'No se pudo guardar el archivo. Inténtalo de nuevo.';
                        $tipoMensaje = 'error';
                    } elseif ($esFoto) {
                        try {
                            $stmt = $pdo->prepare('UPDATE usuarios SET foto_perfil = ? WHERE id_usuario = ?');
                            $stmt->execute([$rutaPublica, $idUsuario]);

                            if ($stmt->rowCount() === 0) {
                                $stmt = $pdo->prepare('SELECT foto_perfil FROM usuarios WHERE id_usuario = ?');
                                $stmt->execute([$idUsuario]);
                                if ($stmt->fetchColumn() === false) {
                                    throw new RuntimeException('No se encontró la cuenta para asociar la foto.');
                                }
                            }

                            if (is_string($fotoPerfil) && str_starts_with($fotoPerfil, 'uploads/perfiles/')) {
                                $rutaAnterior = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fotoPerfil);
                                $directorioReal = realpath($directorioSubidas);
                                $archivoAnteriorReal = realpath($rutaAnterior);
                                if ($directorioReal !== false && $archivoAnteriorReal !== false
                                    && str_starts_with($archivoAnteriorReal, $directorioReal . DIRECTORY_SEPARATOR)) {
                                    if (!unlink($archivoAnteriorReal)) {
                                        error_log('No se pudo eliminar la foto de perfil anterior.');
                                    }
                                }
                            }

                            $fotoPerfil = $rutaPublica;
                            $archivoSubido = $rutaPublica;
                            $mensaje = 'Tu foto de perfil se actualizó correctamente.';
                            $tipoMensaje = 'success';
                        } catch (PDOException | RuntimeException $exception) {
                            if (is_file($rutaDestino)) {
                                unlink($rutaDestino);
                            }
                            error_log('No se pudo asociar la foto al perfil: ' . $exception->getMessage());
                            $mensaje = 'La imagen se recibió, pero no se pudo actualizar tu perfil. Inténtalo de nuevo.';
                            $tipoMensaje = 'error';
                        }
                    } else {
                        $archivoSubido = $rutaPublica;
                        $mensaje = 'El documento se subió correctamente. No reemplaza tu foto de perfil.';
                        $tipoMensaje = 'success';
                    }
                }
            }
        }
    }
}

$inicial = strtoupper(substr($_SESSION['nombre'] ?? 'U', 0, 1));
$fotoSegura = is_string($fotoPerfil) && str_starts_with($fotoPerfil, 'uploads/perfiles/')
    ? $fotoPerfil
    : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mi perfil y archivos - TutoresOnLine</title>
    <link rel="stylesheet" href="CSS/estilos.css?v=<?php echo filemtime(__DIR__ . '/CSS/estilos.css'); ?>">
</head>
<body class="profile-page">
    <main class="profile-card">
        <a class="profile-back-link" href="dashboard.php">← Volver al panel</a>

        <div class="profile-heading">
            <div class="profile-avatar">
                <?php if ($fotoSegura !== null): ?>
                    <img src="<?php echo htmlspecialchars($fotoSegura, ENT_QUOTES, 'UTF-8'); ?>" alt="Foto de perfil">
                <?php else: ?>
                    <?php echo htmlspecialchars($inicial, ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
            </div>
            <div>
                <p class="profile-eyebrow">TU CUENTA</p>
                <h1>Mi perfil y archivos</h1>
                <p class="profile-description">Hola, <?php echo htmlspecialchars($_SESSION['nombre'] ?? 'usuario', ENT_QUOTES, 'UTF-8'); ?>. Cambia tu foto de perfil o sube un documento.</p>
            </div>
        </div>

        <section class="upload-section" aria-labelledby="upload-title">
            <h2 id="upload-title">Foto de perfil y archivos</h2>
            <p class="upload-description">La foto JPG o PNG se mostrará en tu cuenta. También puedes subir un PDF, que se guardará como documento.</p>

            <?php if ($mensaje !== ''): ?>
                <div class="upload-message upload-message--<?php echo $tipoMensaje; ?>" role="alert">
                    <?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($archivoSubido !== ''): ?>
                        <a href="<?php echo htmlspecialchars($archivoSubido, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Ver archivo</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form class="upload-form" action="perfil.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['perfil_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                <label class="upload-label" for="foto_perfil">Vista previa de tu foto</label>
                <p class="upload-help">La foto se recorta automáticamente en un círculo y se centra en el rostro, como en tu perfil.</p>
                <div class="profile-photo-preview" aria-live="polite">
                    <div class="profile-avatar" id="photo-preview">
                        <?php if ($fotoSegura !== null): ?>
                            <img src="<?php echo htmlspecialchars($fotoSegura, ENT_QUOTES, 'UTF-8'); ?>" alt="Vista previa de tu foto actual">
                        <?php else: ?>
                            <?php echo htmlspecialchars($inicial, ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </div>
                    <span id="photo-preview-label">Así aparecerá tu foto en la cuenta</span>
                </div>
                <label class="upload-label" for="foto_perfil">Selecciona una foto o documento</label>
                <input
                    class="upload-input"
                    id="foto_perfil"
                    type="file"
                    name="foto_perfil"
                    accept=".jpg,.jpeg,.png,.pdf"
                    aria-describedby="upload-help"
                    required
                >
                <p class="upload-help" id="upload-help">Fotos: JPG o PNG. Documentos: PDF. Tamaño máximo: 5 MB.</p>
                <button class="upload-button" type="submit">Guardar foto o documento</button>
            </form>
        </section>
    </main>
    <script>
        const photoInput = document.getElementById('foto_perfil');
        const photoPreview = document.getElementById('photo-preview');
        let previewUrl = null;

        photoInput.addEventListener('change', () => {
            const file = photoInput.files[0];
            if (!file || !file.type.startsWith('image/')) {
                return;
            }

            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
            previewUrl = URL.createObjectURL(file);

            let previewImage = photoPreview.querySelector('img');
            if (!previewImage) {
                previewImage = document.createElement('img');
                previewImage.alt = 'Vista previa de la nueva foto';
                photoPreview.replaceChildren(previewImage);
            }
            previewImage.src = previewUrl;
        });

        window.addEventListener('beforeunload', () => {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
        });
    </script>
</body>
</html>
