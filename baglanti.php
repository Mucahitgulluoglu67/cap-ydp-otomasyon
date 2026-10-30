<?php
// baglanti.php
$host = 'sql204.infinityfree.com';
$dbname = 'if0_43283745_otomasyon';
$username = 'if0_43283745'; 
$password = 'gizli'; 

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    
} catch(PDOException $e) {
    die("Bağlantı hatası: " . $e->getMessage());
}
?>