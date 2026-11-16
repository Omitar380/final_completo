# TutoresOnLine

Sistema web para la gestión de tutorías en línea entre estudiantes y tutores.

## Descripción

TutoresOnLine es una aplicación desarrollada en PHP con MySQL, diseñada para facilitar la conexión entre estudiantes y tutores. La plataforma permite a los estudiantes buscar tutores, solicitar sesiones, revisar su historial y calificar la experiencia, mientras que los tutores pueden administrar su perfil profesional, aceptar o rechazar solicitudes y llevar un control de sus sesiones.

## Objetivo

Crear una plataforma funcional de tutorías en línea que permita:

- Registro y autenticación de usuarios.
- Gestión de perfiles para estudiantes y tutores.
- Búsqueda de tutores por materia, ciudad, modalidad y presupuesto.
- Reservación de sesiones de tutoría.
- Aceptación, rechazo y finalización de solicitudes.
- Historial de sesiones y reseñas.
- Subida de fotos de perfil y documentos.
- Asistente IA para consultas generales y locales.

## Tecnologías utilizadas

- PHP
- MySQL
- PDO
- HTML5
- CSS3
- Sessions y cookies
- Subida de archivos
- API externa Gemini (opcional para modo online)

## Estructura del proyecto

```text
TutoresOnLine/
├── index.php
├── registro.php
├── dashboard.php
├── buscar.php
├── reservar.php
├── solicitudes.php
├── recepcion_solicitudes.php
├── mis_sesiones.php
├── perfil.php
├── perfil_tutor.php
├── perfil_tutor_config.php
├── asistente_ia.php
├── logout.php
├── conexcion.php
├── CSS/
│   └── estilos.css
├── assets/
│   └── logo-marca.png
├── uploads/
│   ├── perfiles/
│   └── documentos/
├── migraciones/
│   ├── 20261017_agregar_foto_perfil.sql
│   └── 20261109_preferencias_estudiantes.sql
└── README.md
```

<?php
$host = 'localhost';
$dbname = 'tutorias';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>

Funcionalidades
Registro e inicio de sesión
Registro de estudiantes y tutores.
Validación de correo y contraseña.
Hash de contraseñas mediante password_hash.
Inicio de sesión con password_verify.
Recordar correo mediante cookies.
Panel principal
Dashboard específico según el rol del usuario.
Estadísticas generales de tutor o estudiante.
Acceso rápido a todas las secciones del sistema.
Búsqueda de tutores
Búsqueda por nombre, materia y ciudad.
Filtrado por tipo de modalidad.
Filtro por precio máximo por hora.
Visualización del perfil del tutor.
Solicitud de tutorías
Selección de materia, fecha y hora.
Validación de disponibilidad del tutor.
Control de conflictos con otras reservas.
Estado pendiente hasta aprobación.
Gestión de solicitudes
El tutor puede aceptar, rechazar o completar la sesión.
El estudiante puede ver el estado de cada tutoría en su historial.
Reseñas y calificaciones
Al finalizar una tutoría, el estudiante puede dejar una reseña.
Se actualiza la calificación promedio del tutor.
Perfil del usuario
Cambio de foto de perfil.
Subida de documentos PDF.
Validación de tipos y tamaño de archivo.
Asistente IA
Modo local para consultas seguras del sistema.
Modo online opcional con Gemini.
Historial de conversación por sesión.
Recomendaciones de tutores según preferencias.
Seguridad
Se implementan medidas básicas de seguridad, entre otras:

Uso de PDO con prepared statements.
Encriptación de contraseñas con password_hash.
Validación del rol del usuario.
Control de acceso a páginas restringidas.
Validación de formularios y campos.
Protección contra archivos no permitidos.
Uso de tokens CSRF en formularios sensibles.
Uso
Estudiante
Registrar cuenta.
Buscar tutor.
Solicitar sesión.
Ver historial de tutorías.
Calificar finalización de tutorías.
Consultar al asistente IA.
Tutor
Registrar cuenta como tutor.
Completar perfil profesional.
Agregar materias y disponibilidad.
Revisar solicitudes.
Aceptar o rechazar tutorías.
Marcar sesiones como completadas.
Consideraciones del despliegue
Configurar display_errors en modo producción como Off.
Usar HTTPS en entorno real.
Evitar exposer credenciales sensibles en repositorios.
Mantener copias de seguridad de la base de datos.
No dejar carpetas privadas con permisos de escritura innecesarios.