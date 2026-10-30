<?php
session_start();

// Güvenlik: Sadece giriş yapmış ÖĞRENCİLER bu sayfayı görebilir
if (!isset($_SESSION['kullanici_id']) || $_SESSION['rol'] !== 'ogrenci') {
    header("Location: index.php");
    exit;
}

require_once 'baglanti.php';

$kullanici_id = $_SESSION['kullanici_id'];
$mesaj = '';
$mesaj_tur = '';

// Yeni Ders Talebi Formu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ders_talebi_gonder'])) {
    $katalog_id = (int) ($_POST['katalog_id'] ?? 0);
    $akademik_yil_donem = trim($_POST['akademik_yil_donem'] ?? '');

    if ($katalog_id > 0 && $akademik_yil_donem !== '') {
        $katalog_sorgu = $db->prepare("SELECT * FROM ders_katalogu WHERE id = :id AND aktif_mi = 1");
        $katalog_sorgu->execute(['id' => $katalog_id]);
        $secilen_ders = $katalog_sorgu->fetch(PDO::FETCH_ASSOC);

        if ($secilen_ders) {
            $ekle = $db->prepare("
                INSERT INTO ogrenci_dersleri
                    (ogrenci_id, ders_kodu, ders_adi, kredi, akts, akademik_yil_donem, durum, onay_durumu, iptal_mi)
                VALUES
                    (:ogrenci_id, :ders_kodu, :ders_adi, :kredi, :akts, :akademik_yil_donem, 'planlandi', 'bekliyor', 0)
            ");
            $ekle->execute([
                'ogrenci_id' => $kullanici_id,
                'ders_kodu' => $secilen_ders['ders_kodu'],
                'ders_adi' => $secilen_ders['ders_adi'],
                'kredi' => $secilen_ders['kredi'],
                'akts' => $secilen_ders['akts'],
                'akademik_yil_donem' => $akademik_yil_donem
            ]);
            $mesaj = 'Ders talebiniz başarıyla kaydedildi.';
            $mesaj_tur = 'success';
        } else {
            $mesaj = 'Seçilen ders bulunamadı veya aktif değil.';
            $mesaj_tur = 'danger';
        }
    } else {
            $mesaj = 'Lütfen ders ve dönem bilgilerini eksiksiz girin.';
        $mesaj_tur = 'warning';
    }
}

// Yeni Belge Yükleme Formu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['belge_yukle_gonder'])) {
    $izinli_turler = ['ÇAP Transkripti', 'Ana Dal Transkripti', 'Diğer'];
    $belge_turu = trim($_POST['belge_turu'] ?? '');
    $dosya = $_FILES['belge'] ?? null;

    if (!in_array($belge_turu, $izinli_turler, true)) {
        $mesaj = 'Lütfen geçerli bir belge türü seçin.';
        $mesaj_tur = 'warning';
    } elseif (!$dosya || ($dosya['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $mesaj = 'Lütfen yüklenecek bir dosya seçin.';
        $mesaj_tur = 'warning';
    } elseif ($dosya['error'] !== UPLOAD_ERR_OK) {
        $mesaj = 'Dosya yüklenirken bir hata oluştu.';
        $mesaj_tur = 'danger';
    } else {
        $uzanti = strtolower(pathinfo($dosya['name'], PATHINFO_EXTENSION));
        $izinli_uzantilar = ['pdf', 'jpg', 'jpeg', 'png'];

        if (!in_array($uzanti, $izinli_uzantilar, true)) {
            $mesaj = 'Yalnızca PDF, JPG, JPEG veya PNG dosyaları yükleyebilirsiniz.';
            $mesaj_tur = 'warning';
        } else {
            $uploads_dir = __DIR__ . '/uploads';
            if (!is_dir($uploads_dir)) {
                mkdir($uploads_dir, 0755, true);
            }

            $yeni_ad = uniqid('belge_', true) . '.' . $uzanti;
            $hedef_yol = $uploads_dir . '/' . $yeni_ad;
            $dosya_yolu = 'uploads/' . $yeni_ad;

            if (move_uploaded_file($dosya['tmp_name'], $hedef_yol)) {
                $ekle = $db->prepare("
                    INSERT INTO belgeler
                        (ogrenci_id, yukleyen_id, belge_turu, dosya_yolu, onay_durumu)
                    VALUES
                        (:ogrenci_id, :yukleyen_id, :belge_turu, :dosya_yolu, 'bekliyor')
                ");
                $ekle->execute([
                    'ogrenci_id' => $kullanici_id,
                    'yukleyen_id' => $kullanici_id,
                    'belge_turu' => $belge_turu,
                    'dosya_yolu' => $dosya_yolu
                ]);
                $mesaj = 'Belge başarıyla yüklendi.';
                $mesaj_tur = 'success';
            } else {
                $mesaj = 'Dosya kaydedilemedi. Lütfen tekrar deneyin.';
                $mesaj_tur = 'danger';
            }
        }
    }
}

$koordinator_id = 1;
$koordinator_sorgu = $db->query("SELECT id FROM kullanicilar WHERE rol = 'koordinator' ORDER BY id ASC LIMIT 1");
$koordinator_kayit = $koordinator_sorgu->fetch(PDO::FETCH_ASSOC);
if ($koordinator_kayit) {
    $koordinator_id = (int) $koordinator_kayit['id'];
}

// Koordinatöre Mesaj Gönderme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mesaj_gonder'])) {
    $mesaj_metni = trim($_POST['mesaj_metni'] ?? '');

    if ($mesaj_metni !== '') {
        $ekle = $db->prepare("
            INSERT INTO mesajlar (gonderen_id, alici_id, mesaj)
            VALUES (:gonderen_id, :alici_id, :mesaj)
        ");
        $ekle->execute([
            'gonderen_id' => $kullanici_id,
            'alici_id' => $koordinator_id,
            'mesaj' => $mesaj_metni
        ]);
        header('Location: panel.php?sohbet=1');
        exit;
    }
}

// Not girişi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['not_gir_gonder'])) {
    $ders_id = (int) ($_POST['ders_id'] ?? 0);
    $harf_notu = trim($_POST['harf_notu'] ?? '');
    $ders_durum = trim($_POST['durum'] ?? '');
    $izinli_harfler = ['AA', 'BA', 'BB', 'CB', 'CC', 'DC', 'DD', 'FD', 'FF'];
    $izinli_durumlar = ['gecti', 'kaldi'];

    if ($ders_id <= 0 || !in_array($harf_notu, $izinli_harfler, true) || !in_array($ders_durum, $izinli_durumlar, true)) {
        $mesaj = 'Lütfen harf notu ve durum bilgilerini geçerli şekilde seçin.';
        $mesaj_tur = 'warning';
    } else {
        $ders_kontrol = $db->prepare("
            SELECT id, harf_notu, onay_durumu
            FROM ogrenci_dersleri
            WHERE id = :id AND ogrenci_id = :ogrenci_id AND iptal_mi = 0
        ");
        $ders_kontrol->execute([
            'id' => $ders_id,
            'ogrenci_id' => $kullanici_id
        ]);
        $mevcut_ders = $ders_kontrol->fetch(PDO::FETCH_ASSOC);
        $harf_bos = $mevcut_ders && (
            $mevcut_ders['harf_notu'] === null
            || trim((string) $mevcut_ders['harf_notu']) === ''
            || trim((string) $mevcut_ders['harf_notu']) === '-'
        );

        if ($mevcut_ders && $mevcut_ders['onay_durumu'] === 'onaylandi' && $harf_bos) {
            $guncelle = $db->prepare("
                UPDATE ogrenci_dersleri
                SET harf_notu = :harf_notu, durum = :durum, onay_durumu = 'bekliyor'
                WHERE id = :id AND ogrenci_id = :ogrenci_id
            ");
            if ($guncelle->execute([
                'harf_notu' => $harf_notu,
                'durum' => $ders_durum,
                'id' => $ders_id,
                'ogrenci_id' => $kullanici_id
            ])) {
                $log = $db->prepare("
                    INSERT INTO islem_gecmisi (kullanici_id, islem_turu, tablo_adi, kayit_id, yeni_deger)
                    VALUES (:kullanici_id, 'NOT_GIRISI', 'ogrenci_dersleri', :kayit_id, :yeni_deger)
                ");
                $log->execute([
                    'kullanici_id' => $kullanici_id,
                    'kayit_id' => $ders_id,
                    'yeni_deger' => $harf_notu
                ]);
                $mesaj = 'Notunuz kaydedildi ve koordinatör onayına gönderildi.';
                $mesaj_tur = 'success';
            }
        } else {
            $mesaj = 'Bu derse not giremezsiniz.';
            $mesaj_tur = 'danger';
        }
    }
}

// 1. Öğrenci Bilgilerini Çek
$sorgu = $db->prepare("SELECT * FROM kullanicilar WHERE id = :id");
$sorgu->execute(['id' => $kullanici_id]);
$ogrenci = $sorgu->fetch(PDO::FETCH_ASSOC);

// 2. Öğrencinin Derslerini Çek (Hocanın "Ders Takibi" maddesi)
$ders_sorgu = $db->prepare("SELECT * FROM ogrenci_dersleri WHERE ogrenci_id = :id AND iptal_mi = 0 ORDER BY akademik_yil_donem DESC");
$ders_sorgu->execute(['id' => $kullanici_id]);
$dersler = $ders_sorgu->fetchAll(PDO::FETCH_ASSOC);

// 3. Öğrencinin Belgelerini Çek (Hocanın "Belge Yönetimi" maddesi)
$belge_sorgu = $db->prepare("SELECT * FROM belgeler WHERE ogrenci_id = :id AND iptal_mi = 0 ORDER BY yuklenme_tarihi DESC");
$belge_sorgu->execute(['id' => $kullanici_id]);
$belgeler = $belge_sorgu->fetchAll(PDO::FETCH_ASSOC);

// 4. Aktif ders kataloğunu çek (modal select için)
$katalog_liste = $db->query("SELECT id, ders_kodu, ders_adi FROM ders_katalogu WHERE aktif_mi = 1 ORDER BY ders_kodu ASC")->fetchAll(PDO::FETCH_ASSOC);

// 5. Öğrencinin mesaj geçmişini çek
$sohbet_sorgu = $db->prepare("
    SELECT m.*, k.rol AS gonderen_rol, k.ad_soyad AS gonderen_ad
    FROM mesajlar m
    JOIN kullanicilar k ON k.id = m.gonderen_id
    WHERE m.gonderen_id = :gonderen_id OR m.alici_id = :alici_id
    ORDER BY m.gonderim_tarihi ASC
");
$sohbet_sorgu->execute([
    'gonderen_id' => $kullanici_id,
    'alici_id' => $kullanici_id
]);
$sohbet_mesajlari = $sohbet_sorgu->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Öğrenci Paneli - ÇAP/YDP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .sohbet-alani { max-height: 320px; overflow-y: auto; background: #f8f9fa; }
        .sohbet-balon { max-width: 80%; }
        .sohbet-ogrenci { background: #0d6efd; color: #fff; }
        .sohbet-koordinator { background: #e9ecef; color: #212529; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-primary shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="#">🎓 ÇAP / YDP Öğrenci Sistemi</a>
        <span class="navbar-text text-white">
            Hoş Geldin, <strong><?= htmlspecialchars($ogrenci['ad_soyad']) ?></strong> 
            <a href="cikis.php" class="btn btn-sm btn-danger ms-3">Çıkış Yap</a>
        </span>
    </div>
</nav>

<div class="container-fluid mt-4 px-4">
    <?php if ($mesaj): ?>
        <div class="alert alert-<?= htmlspecialchars($mesaj_tur) ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($mesaj) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- SOL SÜTUN: Profil Bilgileri -->
        <div class="col-md-3">
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-dark text-white fw-bold">Öğrenci Profili</div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item"><strong>Öğrenci No:</strong> <?= htmlspecialchars($ogrenci['kullanici_adi']) ?></li>
                    <li class="list-group-item"><strong>E-Posta:</strong> <?= htmlspecialchars($ogrenci['eposta']) ?></li>
                    <li class="list-group-item"><strong>Kayıtlı Program:</strong> <span class="badge bg-success"><?= strtoupper($ogrenci['program_turu']) ?></span></li>
                </ul>
            </div>
            
            <div class="d-grid gap-2">
                <button type="button" class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#dersTalebiModal">Yeni Ders Talebi Gir</button>
                <button type="button" class="btn btn-secondary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#belgeYukleModal">Yeni Belge Yükle</button>
                <button type="button" class="btn btn-info text-white fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#mesajModal">Koordinatöre Mesaj Yaz</button>
            </div>
        </div>

        <!-- SAĞ SÜTUN: Dersler ve Belgeler -->
        <div class="col-md-9">
            
            <!-- DERSLER TABLOSU -->
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-white fw-bold text-primary d-flex justify-content-between align-items-center">
                    <span>📚 Ders Takip Listesi</span>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Dönem</th>
                                <th>Ders Kodu</th>
                                <th>Ders Adı</th>
                                <th>Kredi/AKTS</th>
                                <th>Harf Notu</th>
                                <th>Ders Durumu</th>
                                <th>Onay Durumu</th>
                                <th>İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($dersler) > 0): ?>
                                <?php foreach ($dersler as $d): ?>
                                    <?php
                                        $harf_bos = ($d['harf_notu'] === null || trim((string) $d['harf_notu']) === '' || trim((string) $d['harf_notu']) === '-');
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($d['akademik_yil_donem']) ?></td>
                                        <td><strong><?= htmlspecialchars($d['ders_kodu']) ?></strong></td>
                                        <td><?= htmlspecialchars($d['ders_adi']) ?></td>
                                        <td><?= $d['kredi'] ?> / <?= $d['akts'] ?></td>
                                        <td><strong><?= $d['harf_notu'] ? htmlspecialchars($d['harf_notu']) : '-' ?></strong></td>
                                        <td><span class="badge bg-secondary"><?= strtoupper($d['durum']) ?></span></td>
                                        <td>
                                            <?php 
                                                if($d['onay_durumu'] == 'bekliyor') echo '<span class="badge bg-warning text-dark">Onay Bekliyor</span>';
                                                elseif($d['onay_durumu'] == 'onaylandi') echo '<span class="badge bg-success">Onaylandı 🔒</span>';
                                                else echo '<span class="badge bg-danger">Reddedildi</span>';
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($d['onay_durumu'] === 'onaylandi' && $harf_bos): ?>
                                                <button type="button"
                                                        class="btn btn-sm btn-warning fw-bold"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#notGirModal"
                                                        data-ders-id="<?= (int) $d['id'] ?>">
                                                    Not Gir
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">Sisteme henüz bir ders girmediniz.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- BELGELER TABLOSU -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold text-success d-flex justify-content-between align-items-center">
                    <span>📄 Doküman Arşivi</span>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Yüklenme Tarihi</th>
                                <th>Belge Türü</th>
                                <th>Dosya</th>
                                <th>Onay Durumu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($belgeler) > 0): ?>
                                <?php foreach ($belgeler as $b): ?>
                                    <tr>
                                        <td><?= date('d.m.Y H:i', strtotime($b['yuklenme_tarihi'])) ?></td>
                                        <td><?= htmlspecialchars($b['belge_turu']) ?></td>
                                        <td><a href="<?= htmlspecialchars($b['dosya_yolu']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">Görüntüle</a></td>
                                        <td>
                                            <?php 
                                                if($b['onay_durumu'] == 'bekliyor') echo '<span class="badge bg-warning text-dark">İnceleniyor</span>';
                                                elseif($b['onay_durumu'] == 'onaylandi') echo '<span class="badge bg-success">Onaylandı</span>';
                                                else echo '<span class="badge bg-danger">Reddedildi</span>';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">Arşivinizde henüz belge bulunmuyor.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Yeni Ders Talebi Modal -->
<div class="modal fade" id="dersTalebiModal" tabindex="-1" aria-labelledby="dersTalebiModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title" id="dersTalebiModalLabel">Yeni Ders Talebi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="katalog_id" class="form-label">Ders Seçin</label>
                        <select class="form-select" name="katalog_id" id="katalog_id" required>
                            <option value="">— Ders seçiniz —</option>
                            <?php foreach ($katalog_liste as $ders): ?>
                                <option value="<?= (int) $ders['id'] ?>">
                                    <?= htmlspecialchars($ders['ders_kodu'] . ' - ' . $ders['ders_adi']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="akademik_yil_donem" class="form-label">Akademik Yıl / Dönem</label>
                        <input type="text" class="form-control" name="akademik_yil_donem" id="akademik_yil_donem"
                               placeholder="Örn: 2026-2027 Güz" maxlength="20" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" name="ders_talebi_gonder" value="1" class="btn btn-primary">Talebi Gönder</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Yeni Belge Yükle Modal -->
<div class="modal fade" id="belgeYukleModal" tabindex="-1" aria-labelledby="belgeYukleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title" id="belgeYukleModalLabel">Yeni Belge Yükle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="belge_turu" class="form-label">Belge Türü</label>
                        <select class="form-select" name="belge_turu" id="belge_turu" required>
                            <option value="">— Belge türü seçiniz —</option>
                            <option value="ÇAP Transkripti">ÇAP Transkripti</option>
                            <option value="Ana Dal Transkripti">Ana Dal Transkripti</option>
                            <option value="Diğer">Diğer</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="belge" class="form-label">Dosya</label>
                        <input type="file" class="form-control" name="belge" id="belge" accept=".pdf,.jpg,.jpeg,.png" required>
                        <div class="form-text">Yalnızca PDF ve resim dosyaları (JPG, JPEG, PNG).</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" name="belge_yukle_gonder" value="1" class="btn btn-success">Belgeyi Yükle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Koordinatöre Mesaj Yaz Modal -->
<div class="modal fade" id="mesajModal" tabindex="-1" aria-labelledby="mesajModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mesajModalLabel">Koordinatöre Mesaj Yaz</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <div id="sohbetAlani" class="sohbet-alani border rounded p-3 mb-3">
                    <?php if (count($sohbet_mesajlari) > 0): ?>
                        <?php foreach ($sohbet_mesajlari as $sm): ?>
                            <?php $koordinator_mesaji = ($sm['gonderen_rol'] === 'koordinator'); ?>
                            <div class="d-flex mb-2 <?= $koordinator_mesaji ? 'justify-content-start' : 'justify-content-end' ?>">
                                <div class="sohbet-balon rounded-3 px-3 py-2 <?= $koordinator_mesaji ? 'sohbet-koordinator' : 'sohbet-ogrenci' ?>">
                                    <div class="small fw-bold mb-1">
                                        <?= htmlspecialchars($koordinator_mesaji ? 'Koordinatör' : 'Siz') ?>
                                    </div>
                                    <div><?= nl2br(htmlspecialchars($sm['mesaj'])) ?></div>
                                    <div class="small opacity-75 mt-1">
                                        <?= date('d.m.Y H:i', strtotime($sm['gonderim_tarihi'])) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted text-center mb-0">Henüz mesaj yok. İlk mesajı siz yazabilirsiniz.</p>
                    <?php endif; ?>
                </div>
                <form method="POST" action="">
                    <div class="mb-2">
                        <label for="mesaj_metni" class="form-label">Yeni Mesaj</label>
                        <textarea class="form-control" name="mesaj_metni" id="mesaj_metni" rows="3" required placeholder="Mesajınızı yazın..."></textarea>
                    </div>
                    <div class="d-grid">
                        <button type="submit" name="mesaj_gonder" value="1" class="btn btn-info text-white">Gönder</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Not Gir Modal -->
<div class="modal fade" id="notGirModal" tabindex="-1" aria-labelledby="notGirModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title" id="notGirModalLabel">Not Gir</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="ders_id" id="not_ders_id" value="">
                    <div class="mb-3">
                        <label for="harf_notu" class="form-label">Harf Notu</label>
                        <select class="form-select" name="harf_notu" id="harf_notu" required>
                            <option value="">— Harf notu seçiniz —</option>
                            <option value="AA">AA</option>
                            <option value="BA">BA</option>
                            <option value="BB">BB</option>
                            <option value="CB">CB</option>
                            <option value="CC">CC</option>
                            <option value="DC">DC</option>
                            <option value="DD">DD</option>
                            <option value="FD">FD</option>
                            <option value="FF">FF</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="ders_durum" class="form-label">Durum</label>
                        <select class="form-select" name="durum" id="ders_durum" required>
                            <option value="">— Durum seçiniz —</option>
                            <option value="gecti">gecti</option>
                            <option value="kaldi">kaldi</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" name="not_gir_gonder" value="1" class="btn btn-warning fw-bold">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const sohbetModal = document.getElementById('mesajModal');
    const sohbetAlani = document.getElementById('sohbetAlani');
    sohbetModal.addEventListener('shown.bs.modal', function () {
        sohbetAlani.scrollTop = sohbetAlani.scrollHeight;
    });
    <?php if (isset($_GET['sohbet'])): ?>
    new bootstrap.Modal(sohbetModal).show();
    <?php endif; ?>

    const notGirModal = document.getElementById('notGirModal');
    notGirModal.addEventListener('show.bs.modal', function (event) {
        const dugme = event.relatedTarget;
        document.getElementById('not_ders_id').value = dugme.getAttribute('data-ders-id');
    });
</script>
</body>
</html>
