<?php
session_start();

// Güvenlik: Sadece admin girebilir
if (!isset($_SESSION['kullanici_id']) || $_SESSION['rol'] !== 'admin') {
    header("Location: index.php");
    exit;
}

require_once 'baglanti.php';

// Tüm başvuruları veritabanından çekiyoruz
$sorgu = $db->query("
    SELECT b.id AS basvuru_id, b.basvuru_turu, b.hedef_bolum, b.durum, b.basvuru_tarihi,
           k.ogrenci_no, k.ad_soyad, k.gano, k.bolum AS mevcut_bolum
    FROM basvurular b
    JOIN kullanicilar k ON b.ogrenci_id = k.id
    ORDER BY b.basvuru_tarihi DESC
");
$basvurular = $sorgu->fetchAll(PDO::FETCH_ASSOC);

// İstatistikler için sayıları alalım
$toplam = count($basvurular);
$onaylanan = 0;
foreach($basvurular as $b) { if($b['durum'] == 'onaylandi') $onaylanan++; }
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Öğrenci İşleri (Admin) Paneli</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-danger shadow">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="#">🏢 Öğrenci İşleri Yönetim Paneli</a>
  <span class="navbar-text text-white">
            Hoş Geldiniz, <strong><?= htmlspecialchars($_SESSION['ad_soyad']) ?></strong> 
     <a href="cikis.php" class="btn btn-sm btn-dark ms-3">Çıkış Yap</a>
        </span>
    </div>
</nav>

<div class="container-fluid mt-4 px-4">
    
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-white shadow-sm border-start border-primary border-4">
            <div class="card-body">
                    <h6 class="text-muted">Toplam Başvuru</h6>
                    <h3 class="fw-bold"><?= $toplam ?></h3>
   </div>
   </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-white shadow-sm border-start border-success border-4">
      <div class="card-body">
                    <h6 class="text-muted">Koordinatör Onaylı</h6>
                    <h3 class="fw-bold text-success"><?= $onaylanan ?></h3>
      </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white fw-bold">Genel Başvuru Listesi</div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
  <tr>
     <th>Öğrenci</th>
   <th>Bölüm Geçişi</th>
 <th>GANO</th>
 <th>Tür</th>
    <th>Koordinatör Durumu</th>
  <th>Kesin Kayıt İşlemi</th>
                    </tr>
      </thead>
      <tbody>
        <?php foreach ($basvurular as $b): ?>
         <tr>
             <td>
                <strong><?= htmlspecialchars($b['ad_soyad']) ?></strong><br>
                      <small class="text-muted"><?= htmlspecialchars($b['ogrenci_no']) ?></small>
     </td>
                            <td><?= htmlspecialchars($b['mevcut_bolum']) ?> ➔ <br><strong><?= htmlspecialchars($b['hedef_bolum']) ?></strong></td>
           <td><?= number_format($b['gano'], 2) ?></td>
     <td><span class="badge bg-secondary"><?= strtoupper($b['basvuru_turu']) ?></span></td>
              <td>
               <?php 
                          if ($b['durum'] == 'bekliyor') echo '<span class="badge bg-warning text-dark">Bekliyor</span>';
                elseif ($b['durum'] == 'intibak_asamasinda') echo '<span class="badge bg-info text-dark">İntibak Aşamasında</span>';
                          elseif ($b['durum'] == 'onaylandi') echo '<span class="badge bg-success">Onaylandı</span>';
                 elseif ($b['durum'] == 'reddedildi') echo '<span class="badge bg-danger">Reddedildi</span>';
              ?>
                            </td>
       <td>
              <?php if ($b['durum'] == 'onaylandi'): ?>
            <button class="btn btn-sm btn-success fw-bold">Kesin Kaydı Tamamla</button>
           <?php else: ?>
         <span class="text-muted small">Onay Bekleniyor</span>
                                <?php endif; ?>
        </td>
                        </tr>
         <?php endforeach; ?>
        </tbody>
            </table>
 </div>
    </div>
</div>

</body>
</html>