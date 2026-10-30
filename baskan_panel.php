<?php
session_start();

// Güvenlik: Sadece Bölüm Başkanı girebilir
if (!isset($_SESSION['kullanici_id']) || $_SESSION['rol'] !== 'bolum_baskani') {
    header("Location: index.php");
    exit;
}

require_once 'baglanti.php';

// 1. Bütün öğrencileri çek (Sadece izleme amaçlı)
$ogrenciler_sorgu = $db->query("SELECT kullanici_adi, ad_soyad, eposta, program_turu, aktif_mi FROM kullanicilar WHERE rol = 'ogrenci' ORDER BY ad_soyad ASC");
$ogrenciler = $ogrenciler_sorgu->fetchAll(PDO::FETCH_ASSOC);

// 2. Bütün işlem geçmişini (Logları) çek - En yeniden eskiye (Hocanın Denetim kuralı)
$log_sorgu = $db->query("
    SELECT l.*, k.ad_soyad, k.rol 
    FROM islem_gecmisi l 
    JOIN kullanicilar k ON l.kullanici_id = k.id 
    ORDER BY l.islem_tarihi DESC 
    LIMIT 50
");
$loglar = $log_sorgu->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Bölüm Başkanı Paneli (İzleme)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-secondary shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="#"> Bölüm Başkanı İzleme Paneli</a>
        <span class="navbar-text text-white">
            Sayın <strong><?= htmlspecialchars($_SESSION['ad_soyad']) ?></strong> 
            <a href="cikis.php" class="btn btn-sm btn-dark ms-3">Çıkış Yap</a>
        </span>
    </div>
</nav>

<div class="container-fluid mt-4 px-4">
    
    <div class="alert alert-info border-info shadow-sm">
        <strong>Bilgi:</strong> Bölüm Başkanı yetkisiyle giriş yaptınız. Sisteme sadece <u>okuma (read-only)</u> yetkiniz bulunmaktadır. Öğrencileri ve sistemde yapılan tüm değişiklik geçmişini aşağıdan denetleyebilirsiniz.
    </div>

    <div class="row">
        <!-- Öğrenciler Tablosu -->
        <div class="col-md-4">
 <div class="card shadow-sm border-0 mb-4">
 <div class="card-header bg-dark text-white fw-bold"> Sisteme Kayıtlı Öğrenciler</div>
 <div class="card-body p-0">
  <table class="table table-striped align-middle mb-0">
 <thead class="table-light">
                            <tr>
                                <th>Öğrenci No</th>
                                <th>Ad Soyad</th>
                                <th>Program</th>
                            </tr>
 </thead>
<tbody>
     <?php foreach($ogrenciler as $ogr): ?>
     <tr>
                                <td><?= htmlspecialchars($ogr['kullanici_adi']) ?></td>
                                <td><?= htmlspecialchars($ogr['ad_soyad']) ?></td>
                                <td><span class="badge bg-primary"><?= strtoupper($ogr['program_turu']) ?></span></td>
     </tr>
     <?php endforeach; ?>
    </tbody>
     </table>
   </div>
     </div>
        </div>

        <!-- İşlem Geçmişi (Audit Log) Tablosu -->
        <div class="col-md-8">
      <div class="card shadow-sm border-0">
  <div class="card-header bg-danger text-white fw-bold"> Sistem İşlem Denetim Kaydı (Loglar)</div>
 <div class="card-body p-0" style="max-height: 500px; overflow-y: auto;">
  <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
   <thead class="table-light">
     <tr>
        <th>Tarih</th>
         <th>İşlemi Yapan</th>
  <th>İşlem Türü</th>
     <th>Etkilenen Kayıt</th>
     <th>İşlem Detayı</th>
     </tr>
  </thead>
  <tbody>
 <?php if(count($loglar) > 0): ?>
 <?php foreach($loglar as $log): ?>
 <tr>
 <td><?= date('d.m.Y H:i', strtotime($log['islem_tarihi'])) ?></td>
<td><strong><?= htmlspecialchars($log['ad_soyad']) ?></strong> <br><small class="text-muted">(<?= $log['rol'] ?>)</small></td>
 <td><span class="badge bg-dark"><?= htmlspecialchars($log['islem_turu']) ?></span></td>
 <td><?= htmlspecialchars($log['tablo_adi']) ?> (ID: <?= $log['kayit_id'] ?>)</td>
     <td>
 <span class="badge bg-light text-dark border"><?= strtoupper($log['eski_deger'] ?? 'YOK') ?></span> 
     <strong class="text-muted mx-1">➔</strong> 
        <span class="badge bg-info text-dark"><?= strtoupper($log['yeni_deger']) ?></span>
          </td>
      </tr>
        <?php endforeach; ?>
      <?php else: ?>
       <tr><td colspan="5" class="text-center py-4 text-muted">Sistemde henüz kaydedilmiş bir işlem bulunmuyor.</td></tr>
       <?php endif; ?>
      </tbody>
          </table>
          </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>