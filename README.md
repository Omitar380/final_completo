TutoresOnLine
Sistema web para la gestión de tutorías en línea entre estudiantes y tutores.
Descripción del proyecto
TutoresOnLine es una aplicación PHP con MySQL que permite:
•	Registrar estudiantes y tutores.
•	Iniciar sesión con autenticación segura.
•	Buscar tutores por materia, categoría, ciudad, precio y modalidad.
•	Solicitar sesiones de tutoría.
•	Aceptar, rechazar y completar solicitudes.
•	Gestionar historial de sesiones.
•	Calificar tutores y dejar reseñas.
•	Subir foto de perfil y documentos.
•	Usar un asistente IA local y/or con Gemini para consultas generales.
Tecnologías utilizadas
•	PHP 8+
•	MySQL
•	PDO
•	HTML5
•	CSS3
•	JavaScript (si se usa en formularios o interacciones)
•	Sessions y cookies
•	Subida de archivos con validación
•	API externa Gemini (opcional)
Estructura del proyecto
•	index.php
•	registro.php
•	dashboard.php
•	buscar.php
•	reservar.php
•	solicitudes.php
•	recepcion_solicitudes.php
•	mis_sesiones.php
•	perfil.php
•	perfil_tutor.php
•	perfil_tutor_config.php
•	asistente_ia.php
•	logout.php
•	conexcion.php
•	CSS/
o	estilos.css
•	assets/
o	logo-marca.png
•	uploads/
o	perfiles/
o	documentos/
•	migraciones/
o	20261017_agregar_foto_perfil.sql
o	20261109_preferencias_estudiantes.sql
Requisitos
•	Servidor web con PHP
•	MySQL
•	Acceso a la base de datos
•	Permisos de escritura en la carpeta uploads
Instalación
1.	Clonar o descargar el proyecto en el directorio raíz de tu servidor web.
2.	Crear la base de datos en MySQL.
3.	Configurar la conexión en el archivo conexcion.php.
4.	Ejecutar los scripts SQL de la carpeta migraciones/.
5.	Asegurarse de que la carpeta uploads exista y tenga permisos de escritura.
6.	Abrir la aplicación desde el navegador.
Configuración de conexión a la base de datos
Archivo: conexcion.php
Debe configurarse algo como esto:
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
•	
•	
•	
•	
Base de datos
El sistema usa varias tablas principales:
usuarios
Almacena la información base del usuario.
Campos relevantes:
•	id_usuario
•	nombre
•	apellido
•	email
•	password_hash
•	rol
•	ciudad
•	foto_perfil
perfiles_tutores
Contiene la información pública del tutor.
Campos relevantes:
•	id_tutor
•	biografia
•	tarifa_por_hora
•	modalidad_preferida
•	calificacion_promedio
materias
Contiene las materias disponibles.
Campos relevantes:
•	id_materia
•	nombre_materia
•	categoria
tutores_materias
Relación entre tutores y materias.
horarios_disponibles
Disponibilidad horaria del tutor.
Campos relevantes:
•	id_horario
•	id_tutor
•	dia_semana
•	hora_inicio
•	hora_fin
reservas_sesiones
Solicitudes y reservas de tutorías.
Campos relevantes:
•	id_reserva
•	id_estudiante
•	id_tutor
•	id_materia
•	fecha_sesion
•	hora_inicio
•	hora_fin
•	modalidad
•	estado
•	enlace_videoconferencia
resenas_calificaciones
Reseñas dejadas por los estudiantes.
preferencias_estudiantes
Preferencias del estudiante para sugerencias de tutor.
disponibilidad_estudiantes
Horarios preferidos del estudiante.
Funcionalidades
1. Registro e inicio de sesión
•	Registro de estudiante y tutor.
•	Validación de email y contraseña.
•	Guardado de contraseña con password_hash.
•	Login con password_verify.
•	Cookies para recordar correo.
2. Dashboard
•	Panel según rol:
o	Estudiante
o	Tutor
•	Resumen de actividades.
•	Estadísticas generales.
3. Búsqueda de tutores
•	Filtros por:
o	nombre o tema
o	categoría
o	modalidad
o	precio máximo
o	ciudad
4. Solicitud de tutorías
•	El estudiante elige una materia, fecha y hora.
•	Se valida la disponibilidad del tutor.
•	La solicitud queda pendiente.
5. Gestión de solicitudes
•	El tutor acepta, rechaza o completa una sesión.
•	El sistema valida la acción según el estado actual.
6. Mis sesiones
•	Historial de clases.
•	Estado de cada reserva.
•	Acceso a videoconferencia si aplica.
•	Calificación y reseña después de la sesión.
7. Perfil de usuario
•	Cambio de foto de perfil.
•	Subida de documentos PDF.
•	Validación de tamaño y formato.
8. Perfil de tutor
•	Biografía profesional.
•	Información sobre materias.
•	Horarios de disponibilidad.
•	Tarifa por hora.
•	Modalidad preferida.
9. Asistente IA
•	Soporta modo auto, local y online.
•	Consulta información local segura.
•	Opción de usar Gemini para preguntas generales.
•	Protección de datos sensibles.
Descripción de archivos principales
index.php
Página de inicio de sesión.
Funciones:
•	autentica usuario
•	valida credenciales
•	guarda cookie con email
•	redirige a dashboard.php
registro.php
Registro de nuevas cuentas.
Funciones:
•	valida nombre, apellido, email y contraseña
•	soporta estudiantes y tutores
•	crea datos del perfil tutor si aplica
•	usa transacción SQL para guardar datos
dashboard.php
Panel principal del sistema.
Funciones:
•	carga datos según rol
•	muestra estadísticas
•	navega entre módulos
buscar.php
Búsqueda de tutores.
Funciones:
•	filtra profesores según criterios
•	muestra tutorías y reseñas
•	permite ver el perfil público
reservar.php
Permite solicitar una sesión con un tutor.
Funciones:
•	valida disponibilidad
•	valida materia
•	valida fecha/hora
•	verifica conflicto con otra sesión
•	registra reserva
solicitudes.php
Gestión de solicitudes por parte del tutor.
Funciones:
•	listar solicitudes
•	aceptar
•	rechazar
•	marcar como completada
mis_sesiones.php
Historial de tutorías del estudiante.
Funciones:
•	mostrar sesiones
•	poner reseñas
•	ver estado de tutorías
perfil.php
Gestión del perfil del usuario.
Funciones:
•	subir foto de perfil
•	subir documentos PDF
•	actualizar avatar del usuario
perfil_tutor.php
Perfil público del tutor.
Funciones:
•	mostrar información profesional
•	materias
•	disponibilidad
•	reseñas
•	botón para reservar
perfil_tutor_config.php
Configuración del perfil profesional del tutor.
Funciones:
•	editar biografía
•	añadir materias
•	asignar categoría
•	establecer horario
•	definir modalidad y tarifa
asistente_ia.php
Asistente de IA para apoyar al usuario.
Funciones:
•	historial de chat
•	modos de consulta
•	acceso a datos locales
•	integración opcional con Gemini
•	almacenamiento de preferencias del estudiante
conexcion.php
Archivo central de conexión.
Funciones:
•	crear la conexión PDO
•	manejar errores con PDOException
logout.php
Cierra sesión y redirige al login.
Seguridad
El proyecto incluye medidas básicas de seguridad:
•	Uso de PDO con prepared statements
•	Hashing de contraseñas con password_hash
•	Validación del rol de usuario
•	Verificación de sesión
•	Validación de formularios
•	Protección contra subida de archivos no permitidos
•	CSRF token en formularios sensibles
•	Redirección automática para páginas restringidas
Consideraciones de archivos subidos
La aplicación usa la carpeta uploads con subcarpetas:
•	uploads/perfiles
•	uploads/documentos
Archivos permitidos:
•	JPG
•	PNG
•	PDF
Límites:
•	tamaño máximo: 5 MB por archivo
Recomendaciones de despliegue
•	Configurar display_errors en producción como Off
•	Usar HTTPS
•	Proteger la base de datos con credenciales seguras
•	No compartir variables sensibles en repositorios
•	Usar .htaccess o configuración del servidor para evitar acceso directo a carpetas sensibles
•	Mantener backups de la base de datos
Variables de entorno
El asistente IA usa la variable:

GEMINI_API_KEY
Esto se obtiene con getenv('GEMINI_API_KEY').
Si no está configurada, el sistema puede trabajar solo con información local.
Migraciones
La carpeta migraciones contiene scripts SQL para:
•	agregar foto de perfil
•	crear tablas para preferencias y horarios del estudiante
Ejemplo:
•	20261017_agregar_foto_perfil.sql
•	20261109_preferencias_estudiantes.sql
Limitaciones actuales
•	El sistema está basado en páginas PHP separadas, no sigue un patrón MVC completo.
•	La lógica de negocio está distribuida en varias páginas.
•	Algunas partes dependen del acceso directo a archivos del servidor.
•	No hay sistema de pagos ni panel administrativo avanzado.
•	La integración con IA está limitada a la configuración del entorno.
Estado del proyecto
Proyecto funcional con gestión completa de tutorías, perfil de usuario, búsqueda, reserva y asistente IA. Es adecuado para uso académico, demostración y extensión hacia producción con mejoras de seguridad y arquitectura.
