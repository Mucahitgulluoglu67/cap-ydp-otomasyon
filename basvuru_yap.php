<?php
session_start();

// Giriş yapılmamışsa login sayfasına at
if (!isset($_SESSION['kullanici_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'baglanti.php';

$kullanici_id = $_SESSION['kullanici_id'];
$tur = isset($_GET['tur']) ? $_GET['tur'] : '';
if ($tur !== 'cap' && $tur !== 'yandal') {
    die("Geçersiz başvuru türü.");
}
$mesaj = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $hedef_bolum = trim($_POST['hedef_bolum']);

    // cap yap kontrolü
    $kontrol_sorgu = $db->prepare("SELECT id FROM basvurular WHERE ogrenci_id = :id AND basvuru_turu = :tur");
    $kontrol_sorgu->execute(['id' => $kullanici_id, 'tur' => $tur]);

    if ($kontrol_sorgu->rowCount() > 0) {
        $mesaj = "<div class='alert alert-warning'>Sistemde zaten değerlendirmeyi bekleyen bir " . strtoupper($tur) . " başvurunuz bulunuyor.</div>";
    } else {
        // Başvuru vtye kaydediyorum
        $ekle = $db->prepare("INSERT INTO basvurular (ogrenci_id, basvuru_turu, hedef_bolum, durum) VALUES (:ogrenci_id, :tur, :hedef_bolum, 'bekliyor')");
        $basarili = $ekle->execute([
            'ogrenci_id' => $kullanici_id,
            'tur' => $tur,
            'hedef_bolum' => $hedef_bolum
        ]);

        if ($basarili) {
            $mesaj = "<div class='alert alert-success fw-bold'>Tebrikler! Başvurunuz başarıyla alındı ve Koordinatör onayına iletildi. <a href='panel.php' class='alert-link'>Panele Dön</a></div>";
        } else {
            $mesaj = "<div class='alert alert-danger'>Başvuru sırasında bir hata oluştu.</div>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Başvuru Formu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-5">
 <div class="row justify-content-center">
        <div class="col-md-6">
 <div class="card shadow">
 <div class="card-header bg-primary text-white fw-bold">
                    <?= $tur == 'cap' ? 'Çift Anadal (ÇAP)' : 'Yandal' ?> Başvuru Formu
                </div>
                <div class="card-body p-4">
                    
  <?= $mesaj ?>

 <?php if (empty($mesaj) || strpos($mesaj, 'alert-danger') !== false): ?>
                    <form method="POST" action="" enctype="multipart/form-data">
                        
<div class="mb-3">
         <label class="form-label fw-semibold">Hedef Bölüm / Program</label>
         <select name="hedef_bolum" class="form-select" required>
 <option value="">Lütfen bölüm seçiniz...</option>
    <option value="Bilgisayar Mühendisliği">Bilgisayar Mühendisliği</option>
         <option value="Endüstri Mühendisliği">Endüstri Mühendisliği</option>
         <option value="Makine Mühendisliği">Makine Mühendisliği</option>
     <option value="Yazılım Mühendisliği">Yazılım Mühendisliği</option>
     </select>
     <div class="form-text">Protokol anlaşması olan bölümler listelenmektedir.</div>
       </div>

     <div class="mb-4">
    <label class="form-label fw-semibold">Güncel Transkript Belgesi (PDF)</label>
         <input type="file" name="transkript" class="form-control" accept=".pdf" required>
      <div class="form-text">Yönerge gereği e-imzalı veya onaylı transkript yüklenmelidir.</div>
     </div>
 <div class="d-flex justify-content-between">
  <a href="panel.php" class="btn btn-secondary">Vazgeç</a>
 <button type="submit" class="btn btn-success fw-bold">Başvuruyu Tamamla</button>
 </div>
 </form>
  <?php endif; ?>

 </div>
 </div>
 </div>
 </div>
</div>

</body>
</html>