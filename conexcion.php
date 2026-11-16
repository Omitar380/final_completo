<?php
$host = 'sql302.infinityfree.com';
$dbname = 'if0_43449926_tutorias';
$username = 'if0_43449926';
$password = 'GKGLzPYFqiq0fbk';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>
