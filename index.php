<?php
session_start();

// GÜVENLİK ÖNLEMİ 1: Oturum (Session) Çalınmasına karşı ID yenileme
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

require_once 'baglanti.php';
$hata_mesaji = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Boşlukları temizliyoruz (Güvenlik)
    $kullanici_adi = trim($_POST['kullanici_adi']);
    $sifre = trim($_POST['sifre']); 

    if (empty($kullanici_adi) || empty($sifre)) {
        $hata_mesaji = "Lütfen tüm alanları doldurun.";
    } else {
        // GÜVENLİK ÖNLEMİ 2: PDO Prepared Statements ile SQL Injection Koruması
        // Yeni veritabanı mimarisine göre 'kullanici_adi' ve 'aktif_mi' kontrolü yapıyoruz
        $sorgu = $db->prepare("SELECT * FROM kullanicilar WHERE kullanici_adi = :kullanici_adi AND sifre = :sifre AND aktif_mi = 1");
        $sorgu->execute(['kullanici_adi' => $kullanici_adi, 'sifre' => $sifre]);
        $kullanici = $sorgu->fetch(PDO::FETCH_ASSOC);

        if ($kullanici) {
            // GÜVENLİK ÖNLEMİ 3: Başarılı girişte Session ID kesinlikle yenilenmelidir
            session_regenerate_id(true); 
            
            $_SESSION['kullanici_id'] = $kullanici['id'];
            $_SESSION['ad_soyad'] = $kullanici['ad_soyad'];
            $_SESSION['rol'] = $kullanici['rol'];
            
            // HOCANIN İSTEDİĞİ 3 YENİ ROLE GÖRE YÖNLENDİRME
            if ($kullanici['rol'] == 'koordinator') {
                header("Location: koordinator_panel.php");
            } elseif ($kullanici['rol'] == 'bolum_baskani') {
                header("Location: baskan_panel.php");
            } else {
                header("Location: panel.php");
            }
            exit;
        } else {
            $hata_mesaji = "Hatalı Kullanıcı Adı veya Şifre (Ya da hesabınız pasif)!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>ÇAP / YDP Otomasyonu - Güvenli Giriş</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">
    
    <div class="card shadow-lg" style="width: 25rem;">
        <div class="card-body p-5">
            <div class="text-center mb-4">
                <h4 class="fw-bold text-primary">ÇAP / YDP Otomasyonu</h4>
                <p class="text-muted">Güvenli Sisteme Giriş</p>
            </div>
            
            <?php if($hata_mesaji): ?>
                <div class="alert alert-danger p-2 text-center"><?= $hata_mesaji ?></div>
            <?php endif; ?>

            <form method="POST" action="" autocomplete="off">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Kullanıcı Adı / Öğrenci No</label>
                    <!-- Veritabanındaki ogrenci_no sütunu kullanici_adi olarak değiştiği için name="kullanici_adi" yaptık -->
                    <input type="text" name="kullanici_adi" class="form-control" required autocomplete="off">
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold">Şifre</label>
                    <input type="password" name="sifre" class="form-control" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary w-100 fw-bold">Giriş Yap</button>
            </form>
        </div>
    </div>

</body>
</html>