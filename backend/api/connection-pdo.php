<?php
$servername = "localhost";
$dbusername = "root";
$dbpassword = "";
$dbname = "hris";

try {
    $conn = new PDO(
        "mysql:host=$servername;dbname=$dbname;charset=utf8mb4",
        $dbusername,
        $dbpassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    throw new PDOException("Connection failed: " . $e->getMessage(), (int) $e->getCode());
}
?>
