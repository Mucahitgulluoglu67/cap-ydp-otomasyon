<?php
session_start();
session_destroy(); // Oturumu tamamen sıfırla
header("Location: index.php"); // Giriş sayfasına geri gönder
exit;
?>