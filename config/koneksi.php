<?php
$host     = "127.0.0.1";
$username = "root";
$password = "root";
$dbname   = "db_siswakita";
$port     = 3306; // GANTI menjadi 3307 jika di XAMPP Control Panel tertulis 3307

mysqli_report(MYSQLI_REPORT_OFF);

$conn = mysqli_connect($host, $username, $password, $dbname, $port);

if (!$conn) {
    die("Koneksi database gagal: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");
?>
