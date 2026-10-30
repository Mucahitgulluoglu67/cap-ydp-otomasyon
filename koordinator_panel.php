<?php
session_start();

// Güvenlik: Sadece Koordinatör girebilir
if (!isset($_SESSION['kullanici_id']) || $_SESSION['rol'] !== 'koordinator') {
    header("Location: index.php");
    exit;
}

require_once 'baglanti.php';

$koordinator_id = (int) $_SESSION['kullanici_id'];

try {
    $durum_kolon = $db->query("SHOW COLUMNS FROM ogrenci_dersleri LIKE 'durum'")->fetch(PDO::FETCH_ASSOC);
    if ($durum_kolon && stripos((string) $durum_kolon['Type'], 'cikarildi') === false) {
        $db->exec("ALTER TABLE ogrenci_dersleri MODIFY COLUMN durum ENUM('planlandi', 'alindi', 'gecti', 'kaldi', 'muaf', 'cikarildi') DEFAULT 'planlandi'");
    }
    $mevcut_kolonlar = $db->query('SHOW COLUMNS FROM ogrenci_dersleri')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('deneme_sayisi', $mevcut_kolonlar, true)) {
        $db->exec('ALTER TABLE ogrenci_dersleri ADD COLUMN deneme_sayisi INT NOT NULL DEFAULT 1');
    }
    if (!in_array('alinan_donem', $mevcut_kolonlar, true)) {
        $db->exec('ALTER TABLE ogrenci_dersleri ADD COLUMN alinan_donem VARCHAR(50) NULL');
    }
} catch (PDOException $e) {
    error_log('ogrenci_dersleri şema güncellemesi: ' . $e->getMessage());
}

if (empty($_SESSION['ders_yonetim_csrf'])) {
    $_SESSION['ders_yonetim_csrf'] = bin2hex(random_bytes(32));
}
$ders_yonetim_bildirim = $_SESSION['ders_yonetim_bildirim'] ?? null;
unset($_SESSION['ders_yonetim_bildirim']);

// Protect the new course-management actions against cross-site requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['katalog_islem']) || isset($_POST['ders_cikar']))) {
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['ders_yonetim_csrf'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Geçersiz işlem. Sayfayı yenileyip tekrar deneyin.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['katalog_islem'])) {
    $katalog_islem = $_POST['katalog_islem'];
    $katalog_id = filter_var($_POST['katalog_id'] ?? 0, FILTER_VALIDATE_INT);
    try {
        if (!in_array($katalog_islem, ['ekle', 'guncelle', 'pasife_al'], true)) {
            throw new RuntimeException('Geçersiz katalog işlemi.');
        }
        if ($katalog_islem !== 'ekle' && (!$katalog_id || $katalog_id < 1)) {
            throw new RuntimeException('Geçerli bir ders seçin.');
        }
        if ($katalog_islem !== 'pasife_al') {
            $ders_kodu = is_string($_POST['ders_kodu'] ?? null) ? trim($_POST['ders_kodu']) : '';
            $ders_adi = is_string($_POST['ders_adi'] ?? null) ? trim($_POST['ders_adi']) : '';
            $kredi = filter_var($_POST['kredi'] ?? '', FILTER_VALIDATE_FLOAT);
            $akts = filter_var($_POST['akts'] ?? '', FILTER_VALIDATE_FLOAT);
            if ($ders_kodu === '' || mb_strlen($ders_kodu) > 20 || $ders_adi === '' || mb_strlen($ders_adi) > 255
                || $kredi === false || !is_finite($kredi) || $kredi < 0 || $kredi > 99.99
                || $akts === false || !is_finite($akts) || $akts < 0 || $akts > 99.99) {
                throw new RuntimeException('Ders kodu, adı, kredi ve AKTS bilgilerini geçerli şekilde girin.');
            }
        }

        $db->beginTransaction();
        $eski_katalog = null;
        if ($katalog_islem !== 'ekle') {
            $katalog_kontrol = $db->prepare('SELECT * FROM ders_katalogu WHERE id = ? FOR UPDATE');
            $katalog_kontrol->execute([$katalog_id]);
            $eski_katalog = $katalog_kontrol->fetch(PDO::FETCH_ASSOC);
            if (!$eski_katalog) {
                throw new RuntimeException('Katalog dersi bulunamadı.');
            }
        }
        if ($katalog_islem !== 'pasife_al') {
            $kod_kontrol = $db->prepare('SELECT id FROM ders_katalogu WHERE ders_kodu = ? AND id <> ?');
            $kod_kontrol->execute([$ders_kodu, $katalog_islem === 'ekle' ? 0 : $katalog_id]);
            if ($kod_kontrol->fetch()) {
                throw new RuntimeException('Bu ders kodu katalogda zaten kayıtlı.');
            }
        }
        if ($katalog_islem === 'ekle') {
            $katalog_kaydet = $db->prepare('INSERT INTO ders_katalogu (ders_kodu, ders_adi, kredi, akts, aktif_mi) VALUES (?, ?, ?, ?, 1)');
            $katalog_kaydet->execute([$ders_kodu, $ders_adi, $kredi, $akts]);
            $katalog_id = (int) $db->lastInsertId();
            $log_turu = 'KATALOG_EKLENDI';
        } elseif ($katalog_islem === 'guncelle') {
            $katalog_kaydet = $db->prepare('UPDATE ders_katalogu SET ders_kodu = ?, ders_adi = ?, kredi = ?, akts = ? WHERE id = ?');
            $katalog_kaydet->execute([$ders_kodu, $ders_adi, $kredi, $akts, $katalog_id]);
            $log_turu = 'KATALOG_GUNCELLENDI';
        } else {
            if (!(int) $eski_katalog['aktif_mi']) {
                throw new RuntimeException('Bu ders zaten pasif.');
            }
            $katalog_kaydet = $db->prepare('UPDATE ders_katalogu SET aktif_mi = 0 WHERE id = ?');
            $katalog_kaydet->execute([$katalog_id]);
            $log_turu = 'KATALOG_PASIFE_ALINDI';
        }
        $katalog_son = $db->prepare('SELECT * FROM ders_katalogu WHERE id = ?');
        $katalog_son->execute([$katalog_id]);
        $katalog_log = $db->prepare("INSERT INTO islem_gecmisi (kullanici_id, islem_turu, tablo_adi, kayit_id, eski_deger, yeni_deger, islem_tarihi) VALUES (?, ?, 'ders_katalogu', ?, ?, ?, NOW())");
        $katalog_log->execute([
            $koordinator_id, $log_turu, $katalog_id,
            $eski_katalog ? json_encode($eski_katalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            json_encode($katalog_son->fetch(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        ]);
        $db->commit();
        $_SESSION['ders_yonetim_bildirim'] = ['tur' => 'success', 'mesaj' => 'Ders kataloğu başarıyla güncellendi.'];
        header('Location: koordinator_panel.php?sekme=ders-katalogu');
        exit;
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Course catalog operation failed: ' . $e->getMessage());
        $ders_yonetim_bildirim = ['tur' => 'danger', 'mesaj' => 'Katalog işlemi kaydedilemedi. Ders kodunu ve veritabanı yapısını kontrol edin.'];
    } catch (RuntimeException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $ders_yonetim_bildirim = ['tur' => 'warning', 'mesaj' => $e->getMessage()];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Course catalog operation failed: ' . $e->getMessage());
        $ders_yonetim_bildirim = ['tur' => 'danger', 'mesaj' => 'Katalog işlemi tamamlanamadı.'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ders_cikar'])) {
    $cikar_ders_id = filter_var($_POST['cikar_ders_id'] ?? 0, FILTER_VALIDATE_INT);
    $gerekce = is_string($_POST['cikarma_gerekcesi'] ?? null) ? trim($_POST['cikarma_gerekcesi']) : '';
    try {
        if (!$cikar_ders_id || $cikar_ders_id < 1 || $gerekce === '' || mb_strlen($gerekce) > 2000) {
            throw new RuntimeException('Geçerli bir ders ve en fazla 2000 karakterlik çıkarma gerekçesi girin.');
        }
        // Keep the status change and its dated audit entry atomic.
        $db->beginTransaction();
        $cikar_kontrol = $db->prepare("SELECT d.id, d.ogrenci_id, d.durum FROM ogrenci_dersleri d JOIN kullanicilar k ON k.id = d.ogrenci_id WHERE d.id = ? AND d.iptal_mi = 0 AND k.rol = 'ogrenci' FOR UPDATE");
        $cikar_kontrol->execute([$cikar_ders_id]);
        $cikar_ders = $cikar_kontrol->fetch(PDO::FETCH_ASSOC);
        if (!$cikar_ders || $cikar_ders['durum'] === 'cikarildi') {
            throw new RuntimeException('Ders bulunamadı veya daha önce çıkarılmış.');
        }
        $cikar_guncelle = $db->prepare("UPDATE ogrenci_dersleri SET durum = 'cikarildi' WHERE id = ? AND iptal_mi = 0 AND durum <> 'cikarildi'");
        $cikar_guncelle->execute([$cikar_ders_id]);
        if ($cikar_guncelle->rowCount() !== 1) {
            throw new RuntimeException('Ders çıkarılamadı. Listeyi yenileyip tekrar deneyin.');
        }
        $cikar_log = $db->prepare("INSERT INTO islem_gecmisi (kullanici_id, islem_turu, tablo_adi, kayit_id, eski_deger, yeni_deger, islem_tarihi) VALUES (?, 'DERS_CIKARILDI', 'ogrenci_dersleri', ?, ?, ?, NOW())");
        $cikar_log->execute([
            $koordinator_id, $cikar_ders_id, $cikar_ders['durum'],
            json_encode(['durum' => 'cikarildi', 'gerekce' => $gerekce], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        ]);
        $db->commit();
        $_SESSION['ders_yonetim_bildirim'] = ['tur' => 'success', 'mesaj' => 'Ders çıkarıldı; kayıt korundu, gerekçe ve işlem tarihi kaydedildi.'];
        header('Location: koordinator_panel.php?sekme=ogrenci-yonetimi&ogrenci_id=' . (int) $cikar_ders['ogrenci_id']);
        exit;
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Course removal failed: ' . $e->getMessage());
        $ders_yonetim_bildirim = ['tur' => 'danger', 'mesaj' => 'Ders çıkarılamadı. Veritabanı güncellemesinin uygulandığından emin olun.'];
    } catch (RuntimeException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $ders_yonetim_bildirim = ['tur' => 'warning', 'mesaj' => $e->getMessage()];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Course removal failed: ' . $e->getMessage());
        $ders_yonetim_bildirim = ['tur' => 'danger', 'mesaj' => 'Ders çıkarma işlemi tamamlanamadı.'];
    }
}

// AJAX: "Yanıtla" butonuna basılınca öğrencinin mesajlarını okundu olarak işaretle
if (isset($_GET['mesaj_oku_id'])) {
    $mesaj_oku_id = (int) $_GET['mesaj_oku_id'];
    $okundu_yap = $db->prepare("UPDATE mesajlar SET okundu_mu = 1 WHERE gonderen_id = ? AND alici_id = ?");
    $okundu_yap->execute([$mesaj_oku_id, $koordinator_id]);
    exit;
}

// Koordinatör Onay/Ret işlemi yaptığında çalışacak blok (İşlem Geçmişi kuralı ile)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['islem_turu']) && isset($_POST['kayit_id'])) {
    $kayit_id = $_POST['kayit_id'];
    $yeni_durum = $_POST['islem_turu'] == 'onayla' ? 'onaylandi' : 'reddedildi';
    
    // Log için eski durumu alıyoruz
    $eski_sorgu = $db->prepare("SELECT onay_durumu FROM ogrenci_dersleri WHERE id = ? AND iptal_mi = 0 AND durum <> 'cikarildi'");
    $eski_sorgu->execute([$kayit_id]);
    $eski_deger = $eski_sorgu->fetchColumn();

    // Dersi güncelle
    $guncelle = $db->prepare("UPDATE ogrenci_dersleri SET onay_durumu = ? WHERE id = ? AND iptal_mi = 0 AND durum <> 'cikarildi'");
    if ($guncelle->execute([$yeni_durum, $kayit_id]) && $guncelle->rowCount() > 0) {
        // HOCANIN KURALI: İşlem Geçmişi (Audit Log) Tablosuna kayıt atıyoruz
        $log = $db->prepare("INSERT INTO islem_gecmisi (kullanici_id, islem_turu, tablo_adi, kayit_id, eski_deger, yeni_deger) VALUES (?, ?, 'ogrenci_dersleri', ?, ?, ?)");
        $log->execute([$_SESSION['kullanici_id'], strtoupper($yeni_durum), $kayit_id, $eski_deger, $yeni_durum]);
    }
}

// Not Onay/Ret işlemi (İşlem Geçmişi kuralı ile)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['not_islem_turu']) && isset($_POST['not_kayit_id'])) {
    $not_kayit_id = (int) $_POST['not_kayit_id'];
    $not_yeni_durum = $_POST['not_islem_turu'] == 'onayla' ? 'onaylandi' : 'reddedildi';

    // Log için eski durumu alıyoruz
    $not_eski_sorgu = $db->prepare("SELECT onay_durumu FROM ogrenci_dersleri WHERE id = ? AND iptal_mi = 0 AND durum <> 'cikarildi'");
    $not_eski_sorgu->execute([$not_kayit_id]);
    $not_eski_deger = $not_eski_sorgu->fetchColumn();

    // Not kaydını güncelle
    $not_guncelle = $db->prepare("UPDATE ogrenci_dersleri SET onay_durumu = ? WHERE id = ? AND iptal_mi = 0 AND durum <> 'cikarildi'");
    if ($not_guncelle->execute([$not_yeni_durum, $not_kayit_id]) && $not_guncelle->rowCount() > 0) {
        // HOCANIN KURALI: İşlem Geçmişi (Audit Log) Tablosuna kayıt atıyoruz
        $not_log = $db->prepare("INSERT INTO islem_gecmisi (kullanici_id, islem_turu, tablo_adi, kayit_id, eski_deger, yeni_deger) VALUES (?, ?, 'ogrenci_dersleri', ?, ?, ?)");
        $not_log->execute([$_SESSION['kullanici_id'], strtoupper($not_yeni_durum), $not_kayit_id, $not_eski_deger, $not_yeni_durum]);
    }
}

// Belge Onay/Ret işlemi (İşlem Geçmişi kuralı ile)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['belge_islem_turu']) && isset($_POST['belge_id'])) {
    $belge_id = (int) $_POST['belge_id'];
    $belge_yeni_durum = $_POST['belge_islem_turu'] == 'onayla' ? 'onaylandi' : 'reddedildi';

    // Log için eski durumu alıyoruz
    $belge_eski_sorgu = $db->prepare("SELECT onay_durumu FROM belgeler WHERE id = ?");
    $belge_eski_sorgu->execute([$belge_id]);
    $belge_eski_deger = $belge_eski_sorgu->fetchColumn();

    // Belgeyi güncelle
    $belge_guncelle = $db->prepare("UPDATE belgeler SET onay_durumu = ? WHERE id = ?");
    if ($belge_guncelle->execute([$belge_yeni_durum, $belge_id])) {
        // HOCANIN KURALI: İşlem Geçmişi (Audit Log) Tablosuna kayıt atıyoruz
        $belge_log = $db->prepare("INSERT INTO islem_gecmisi (kullanici_id, islem_turu, tablo_adi, kayit_id, eski_deger, yeni_deger) VALUES (?, ?, 'belgeler', ?, ?, ?)");
        $belge_log->execute([$_SESSION['kullanici_id'], strtoupper($belge_yeni_durum), $belge_id, $belge_eski_deger, $belge_yeni_durum]);
    }
}

// Öğrenciye mesaj yanıtlama
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mesaj_gonder'])) {
    $alici_id = (int) ($_POST['alici_id'] ?? 0);
    $mesaj_metni = trim($_POST['mesaj_metni'] ?? '');

    if ($alici_id > 0 && $mesaj_metni !== '') {
        $ogrenci_kontrol = $db->prepare("SELECT id FROM kullanicilar WHERE id = :id AND rol = 'ogrenci'");
        $ogrenci_kontrol->execute(['id' => $alici_id]);

        if ($ogrenci_kontrol->fetch()) {
            $ekle = $db->prepare("
                INSERT INTO mesajlar (gonderen_id, alici_id, mesaj)
                VALUES (:gonderen_id, :alici_id, :mesaj)
            ");
            $ekle->execute([
                'gonderen_id' => $koordinator_id,
                'alici_id' => $alici_id,
                'mesaj' => $mesaj_metni
            ]);
            header('Location: koordinator_panel.php?yanit=' . $alici_id);
            exit;
        }
    }
}

$ayar_mesaj = '';
$ayar_mesaj_tur = '';
$ayar_kaydedildi = false;

// Sistem ayarlarını kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ayarlari_kaydet'])) {
    $cap_min_gano = trim($_POST['cap_min_gano'] ?? '');
    $yandal_min_gano = trim($_POST['yandal_min_gano'] ?? '');
    $max_belge_boyutu_mb = trim($_POST['max_belge_boyutu_mb'] ?? '');

    if ($cap_min_gano === '' || $yandal_min_gano === '' || $max_belge_boyutu_mb === '') {
        $ayar_mesaj = 'Lütfen tüm ayar alanlarını doldurun.';
        $ayar_mesaj_tur = 'warning';
        $ayar_kaydedildi = true;
    } else {
        $guncelle_ayar = $db->prepare("UPDATE sistem_ayarlari SET ayar_degeri = :deger WHERE ayar_anahtari = :anahtar");
        $guncelle_ayar->execute(['deger' => $cap_min_gano, 'anahtar' => 'cap_min_gano']);
        $guncelle_ayar->execute(['deger' => $yandal_min_gano, 'anahtar' => 'yandal_min_gano']);
        $guncelle_ayar->execute(['deger' => $max_belge_boyutu_mb, 'anahtar' => 'max_belge_boyutu_mb']);
        $ayar_mesaj = 'Sistem ayarları başarıyla kaydedildi.';
        $ayar_mesaj_tur = 'success';
        $ayar_kaydedildi = true;
    }
}

$ogrenci_mesaj = '';
$ogrenci_mesaj_tur = '';
$ogrenci_form_goster = false;

// Yeni öğrenci tanımlama
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ogrenci_ekle'])) {
    $yeni_ad_soyad = trim($_POST['ad_soyad'] ?? '');
    $yeni_kullanici_adi = trim($_POST['kullanici_adi'] ?? '');
    $yeni_sifre = trim($_POST['gecici_sifre'] ?? '');
    $yeni_program_turu = trim($_POST['program_turu'] ?? '');
    $izinli_program_turleri = ['cap', 'yandal'];

    if ($yeni_ad_soyad === '' || $yeni_kullanici_adi === '' || $yeni_sifre === '' || !in_array($yeni_program_turu, $izinli_program_turleri, true)) {
        $ogrenci_mesaj = 'Lütfen tüm alanları doldurun ve geçerli bir program tipi seçin.';
        $ogrenci_mesaj_tur = 'warning';
        $ogrenci_form_goster = true;
    } else {
        $yeni_kontrol = $db->prepare("SELECT id FROM kullanicilar WHERE kullanici_adi = ?");
        $yeni_kontrol->execute([$yeni_kullanici_adi]);

        if ($yeni_kontrol->fetch()) {
            $ogrenci_mesaj = 'Bu öğrenci numarası sistemde zaten kayıtlı.';
            $ogrenci_mesaj_tur = 'warning';
            $ogrenci_form_goster = true;
        } else {
            // Giriş sayfası düz metin karşılaştırma yaptığı için şifre olduğu gibi kaydedilir
            $ekle_ogrenci = $db->prepare("INSERT INTO kullanicilar (kullanici_adi, sifre, ad_soyad, rol, program_turu, aktif_mi) VALUES (?, ?, ?, 'ogrenci', ?, 1)");
            if ($ekle_ogrenci->execute([$yeni_kullanici_adi, $yeni_sifre, $yeni_ad_soyad, $yeni_program_turu])) {
                // HOCANIN KURALI: İşlem Geçmişi (Audit Log) Tablosuna kayıt atıyoruz
                $ogrenci_log = $db->prepare("INSERT INTO islem_gecmisi (kullanici_id, islem_turu, tablo_adi, kayit_id, yeni_deger) VALUES (?, 'OGRENCI_EKLENDI', 'kullanicilar', ?, ?)");
                $ogrenci_log->execute([$_SESSION['kullanici_id'], (int) $db->lastInsertId(), $yeni_kullanici_adi]);
                header('Location: koordinator_panel.php?ogrenci_eklendi=1');
                exit;
            }
        }
    }
}

if (isset($_GET['ogrenci_eklendi'])) {
    $ogrenci_mesaj = 'Yeni öğrenci başarıyla tanımlandı.';
    $ogrenci_mesaj_tur = 'success';
}

// Ana Sayfa istatistikleri (POST işlemlerinden sonra: güncel sayıları yansıtır)
$bekleyen_ders_sayisi = (int) $db->query("SELECT COUNT(*) FROM ogrenci_dersleri WHERE onay_durumu = 'bekliyor' AND iptal_mi = 0 AND durum <> 'cikarildi'")->fetchColumn();

$okunmamis_sorgu = $db->prepare("SELECT COUNT(*) FROM mesajlar WHERE okundu_mu = 0 AND alici_id = ?");
$okunmamis_sorgu->execute([$koordinator_id]);
$okunmamis_mesaj_sayisi = (int) $okunmamis_sorgu->fetchColumn();

$bekleyen_belge_sayisi = (int) $db->query("SELECT COUNT(*) FROM belgeler WHERE onay_durumu = 'bekliyor'")->fetchColumn();

// Son 5 sistem aktivitesi (kullanıcı adlarıyla)
$son_islemler = $db->query("
    SELECT g.*, k.ad_soyad, k.kullanici_adi, k.rol
    FROM islem_gecmisi g
    JOIN kullanicilar k ON g.kullanici_id = k.id
    ORDER BY g.islem_tarihi DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Onay Bekleyen Dersleri Çek
$dersler_sorgu = $db->query("
    SELECT d.*, k.ad_soyad, k.kullanici_adi 
    FROM ogrenci_dersleri d 
    JOIN kullanicilar k ON d.ogrenci_id = k.id 
    WHERE d.onay_durumu = 'bekliyor' AND d.iptal_mi = 0 AND d.durum <> 'cikarildi'
    ORDER BY d.id DESC
");
$bekleyen_dersler = $dersler_sorgu->fetchAll(PDO::FETCH_ASSOC);

// Not Girilmiş, Onay Bekleyen Dersleri Çek (öğrenci ve katalog bilgisiyle)
$not_sorgu = $db->query("
    SELECT d.*, k.ad_soyad, k.kullanici_adi, kat.ders_adi AS katalog_ders_adi
    FROM ogrenci_dersleri d
    JOIN kullanicilar k ON d.ogrenci_id = k.id
    LEFT JOIN ders_katalogu kat ON kat.ders_kodu = d.ders_kodu
    WHERE d.onay_durumu = 'bekliyor' AND d.harf_notu IS NOT NULL AND d.harf_notu != '' AND d.iptal_mi = 0 AND d.durum <> 'cikarildi'
    ORDER BY d.id DESC
");
$bekleyen_notlar = $not_sorgu->fetchAll(PDO::FETCH_ASSOC);

// Onay Bekleyen Belgeleri Çek (öğrenci adıyla)
$belgeler_sorgu = $db->query("
    SELECT b.*, k.ad_soyad, k.kullanici_adi
    FROM belgeler b
    JOIN kullanicilar k ON b.ogrenci_id = k.id
    WHERE b.onay_durumu = 'bekliyor'
    ORDER BY b.id DESC
");
$bekleyen_belgeler = $belgeler_sorgu->fetchAll(PDO::FETCH_ASSOC);

// Sisteme kayıtlı öğrencileri çek
$ogrenciler = $db->query("SELECT * FROM kullanicilar WHERE rol='ogrenci'")->fetchAll(PDO::FETCH_ASSOC);

$ders_katalogu = $db->query('SELECT id, ders_kodu, ders_adi, kredi, akts, aktif_mi FROM ders_katalogu ORDER BY aktif_mi DESC, ders_kodu ASC')->fetchAll(PDO::FETCH_ASSOC);
$secili_ogrenci_id = max(0, (int) ($_GET['ogrenci_id'] ?? 0));
$ogrenci_ders_sorgu = $db->prepare("
    SELECT d.*, k.ad_soyad, k.kullanici_adi
    FROM ogrenci_dersleri d
    JOIN kullanicilar k ON k.id = d.ogrenci_id
    WHERE k.rol = 'ogrenci' AND d.iptal_mi = 0 AND (? = 0 OR d.ogrenci_id = ?)
    ORDER BY k.ad_soyad ASC, d.akademik_yil_donem DESC, d.id DESC
");
$ogrenci_ders_sorgu->execute([$secili_ogrenci_id, $secili_ogrenci_id]);
$ogrenci_ders_listesi = $ogrenci_ders_sorgu->fetchAll(PDO::FETCH_ASSOC);
$ders_durum_etiketleri = ['planlandi' => 'Planlandı', 'alindi' => 'Alındı', 'gecti' => 'Geçti', 'kaldi' => 'Kaldı', 'muaf' => 'Muaf', 'cikarildi' => 'Çıkarıldı'];

// Öğrenci mesajları: her öğrencinin son mesajı
$ogrenci_mesaj_sorgu = $db->prepare("
    SELECT
        ogrenci.id AS ogrenci_id,
        ogrenci.ad_soyad,
        ogrenci.kullanici_adi,
        son.mesaj AS son_mesaj,
        son.gonderim_tarihi
    FROM kullanicilar ogrenci
    JOIN (
        SELECT
            CASE WHEN gonderen_id = :k1 THEN alici_id ELSE gonderen_id END AS ogrenci_id,
            MAX(id) AS son_id
        FROM mesajlar
        WHERE gonderen_id = :k2 OR alici_id = :k3
        GROUP BY CASE WHEN gonderen_id = :k4 THEN alici_id ELSE gonderen_id END
    ) t ON t.ogrenci_id = ogrenci.id
    JOIN mesajlar son ON son.id = t.son_id
    WHERE ogrenci.rol = 'ogrenci'
    ORDER BY son.gonderim_tarihi DESC
");
$ogrenci_mesaj_sorgu->execute([
    'k1' => $koordinator_id,
    'k2' => $koordinator_id,
    'k3' => $koordinator_id,
    'k4' => $koordinator_id
]);
$ogrenci_mesajlari = $ogrenci_mesaj_sorgu->fetchAll(PDO::FETCH_ASSOC);

// Koordinatörün tüm sohbet geçmişi
$sohbet_sorgu = $db->prepare("
    SELECT m.*, k.rol AS gonderen_rol, k.ad_soyad AS gonderen_ad
    FROM mesajlar m
    JOIN kullanicilar k ON k.id = m.gonderen_id
    WHERE m.gonderen_id = :gonderen_id OR m.alici_id = :alici_id
    ORDER BY m.gonderim_tarihi ASC
");
$sohbet_sorgu->execute([
    'gonderen_id' => $koordinator_id,
    'alici_id' => $koordinator_id
]);
$tum_sohbet = $sohbet_sorgu->fetchAll(PDO::FETCH_ASSOC);

$sohbetler = [];
foreach ($tum_sohbet as $sm) {
    $diger_id = ((int) $sm['gonderen_id'] === $koordinator_id)
        ? (int) $sm['alici_id']
        : (int) $sm['gonderen_id'];
    $sohbetler[$diger_id][] = $sm;
}

$acik_yanit = isset($_GET['yanit']) ? (int) $_GET['yanit'] : 0;
$istenen_sekme = $_GET['sekme'] ?? 'ana-sayfa';
$izinli_sekmeler = ['ana-sayfa', 'ders-onaylari', 'ders-katalogu', 'not-onaylari', 'belge-onaylari', 'mesajlar', 'ogrenci-yonetimi', 'sistem-ayarlari'];
$aktif_sekme = in_array($istenen_sekme, $izinli_sekmeler, true) ? $istenen_sekme : 'ana-sayfa';
if ($acik_yanit > 0) {
    $aktif_sekme = 'mesajlar';
}
if (isset($_POST['katalog_islem'])) {
    $aktif_sekme = 'ders-katalogu';
}
if (isset($_POST['ders_cikar'])) {
    $aktif_sekme = 'ogrenci-yonetimi';
}
if ($ayar_kaydedildi) {
    $aktif_sekme = 'sistem-ayarlari';
}
if ($ogrenci_form_goster || isset($_GET['ogrenci_eklendi'])) {
    $aktif_sekme = 'ogrenci-yonetimi';
}

$ayar_satirlari = $db->query("SELECT ayar_anahtari, ayar_degeri FROM sistem_ayarlari")->fetchAll(PDO::FETCH_ASSOC);
$sistem_ayarlari = [];
foreach ($ayar_satirlari as $satir) {
    $sistem_ayarlari[$satir['ayar_anahtari']] = $satir['ayar_degeri'];
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Koordinatör Paneli</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .sohbet-alani { max-height: 320px; overflow-y: auto; background: #f8f9fa; }
        .sohbet-balon { max-width: 80%; }
        .sohbet-ogrenci { background: #e9ecef; color: #212529; }
        .sohbet-koordinator { background: #0d6efd; color: #fff; }
        .dashboard-sidebar { min-height: calc(100vh - 56px); background: #212529; }
        .dashboard-sidebar .nav-link { color: #ced4da; border-radius: 0.4rem; }
        .dashboard-sidebar .nav-link:hover { color: #fff; background: rgba(255,255,255,.08); }
        .dashboard-sidebar .nav-link.active { color: #fff; background: #0d6efd; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="#">👨‍🏫 Koordinatör Paneli</a>
        <span class="navbar-text text-white">
            Hoş Geldiniz, <strong><?= htmlspecialchars($_SESSION['ad_soyad']) ?></strong> 
            <a href="cikis.php" class="btn btn-sm btn-danger ms-3">Çıkış Yap</a>
        </span>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-3 col-lg-2 px-0 dashboard-sidebar">
            <div class="p-3">
                <div class="text-white-50 small text-uppercase fw-bold mb-3">Menü</div>
                <nav class="nav nav-pills flex-column gap-1" id="panelTab" role="tablist">
                    <button class="nav-link <?= $aktif_sekme === 'ana-sayfa' ? 'active' : '' ?> text-start" id="ana-sayfa-tab" data-bs-toggle="pill" data-bs-target="#ana-sayfa" type="button" role="tab">🏠 Ana Sayfa</button>
                    <button class="nav-link <?= $aktif_sekme === 'ders-onaylari' ? 'active' : '' ?> text-start" id="ders-onaylari-tab" data-bs-toggle="pill" data-bs-target="#ders-onaylari" type="button" role="tab">📚 Ders Onayları</button>
                    <button class="nav-link <?= $aktif_sekme === 'ders-katalogu' ? 'active' : '' ?> text-start" id="ders-katalogu-tab" data-bs-toggle="pill" data-bs-target="#ders-katalogu" type="button" role="tab" aria-controls="ders-katalogu" aria-selected="<?= $aktif_sekme === 'ders-katalogu' ? 'true' : 'false' ?>">📚 Ders Kataloğu</button>
                    <button class="nav-link <?= $aktif_sekme === 'not-onaylari' ? 'active' : '' ?> text-start" id="not-onaylari-tab" data-bs-toggle="pill" data-bs-target="#not-onaylari" type="button" role="tab">💯 Not Onayları</button>
                    <button class="nav-link <?= $aktif_sekme === 'belge-onaylari' ? 'active' : '' ?> text-start" id="belge-onaylari-tab" data-bs-toggle="pill" data-bs-target="#belge-onaylari" type="button" role="tab">📄 Belge Onayları</button>
                    <button class="nav-link <?= $aktif_sekme === 'mesajlar' ? 'active' : '' ?> text-start" id="mesajlar-tab" data-bs-toggle="pill" data-bs-target="#mesajlar" type="button" role="tab">💬 Mesajlar</button>
                    <button class="nav-link <?= $aktif_sekme === 'ogrenci-yonetimi' ? 'active' : '' ?> text-start" id="ogrenci-yonetimi-tab" data-bs-toggle="pill" data-bs-target="#ogrenci-yonetimi" type="button" role="tab">👥 Öğrenci Yönetimi</button>
                    <button class="nav-link <?= $aktif_sekme === 'sistem-ayarlari' ? 'active' : '' ?> text-start" id="sistem-ayarlari-tab" data-bs-toggle="pill" data-bs-target="#sistem-ayarlari" type="button" role="tab">⚙️ Sistem Ayarları</button>
                </nav>
            </div>
        </div>

        <div class="col-md-9 col-lg-10 py-4 px-4">
            <?php if ($ders_yonetim_bildirim): ?>
                <div class="alert alert-<?= htmlspecialchars($ders_yonetim_bildirim['tur']) ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($ders_yonetim_bildirim['mesaj']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
                </div>
            <?php endif; ?>
            <div class="tab-content" id="panelTabContent">
                <div class="tab-pane fade <?= $aktif_sekme === 'ana-sayfa' ? 'show active' : '' ?>" id="ana-sayfa" role="tabpanel">
                    <h4 class="fw-bold mb-1">Hoş geldiniz, <?= htmlspecialchars($_SESSION['ad_soyad']) ?></h4>
                    <p class="text-muted mb-4">Koordinatör yönetim paneline genel bakış.</p>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm h-100 border-start border-warning border-4">
                                <div class="card-body">
                                    <h6 class="text-muted mb-2">🟡 Bekleyen Ders Onayı</h6>
                                    <div class="d-flex align-items-baseline gap-2">
                                        <span class="display-5 fw-bold mb-0"><?= $bekleyen_ders_sayisi ?? 0 ?></span>
                                        <small class="text-muted">talep</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm h-100 border-start border-success border-4">
                                <div class="card-body">
                                    <h6 class="text-muted mb-2">🟢 Okunmamış Mesaj</h6>
                                    <div class="d-flex align-items-baseline gap-2">
                                        <span class="display-5 fw-bold mb-0"><?= $okunmamis_mesaj_sayisi ?? 0 ?></span>
                                        <small class="text-muted">mesaj</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm h-100 border-start border-primary border-4">
                                <div class="card-body">
                                    <h6 class="text-muted mb-2">🔵 İncelenecek Belge</h6>
                                    <div class="d-flex align-items-baseline gap-2">
                                        <span class="display-5 fw-bold mb-0"><?= $bekleyen_belge_sayisi ?? 0 ?></span>
                                        <small class="text-muted">belge</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-dark text-white fw-bold">
                            🕐 Son Sistem Aktiviteleri
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Kullanıcı</th>
                                        <th>İşlem</th>
                                        <th>Kayıt</th>
                                        <th>Tarih</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($son_islemler) > 0): ?>
                                        <?php foreach ($son_islemler as $si): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($si['ad_soyad']) ?></strong><br>
                                                    <small class="text-muted"><?= htmlspecialchars($si['kullanici_adi']) ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($si['islem_turu'] === 'ONAYLANDI'): ?>
                                                        <span class="badge bg-success">ONAYLANDI</span>
                                                    <?php elseif ($si['islem_turu'] === 'REDDEDILDI'): ?>
                                                        <span class="badge bg-danger">REDDEDILDI</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary"><?= htmlspecialchars($si['islem_turu']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><code><?= htmlspecialchars($si['tablo_adi']) ?>#<?= (int) $si['kayit_id'] ?></code></td>
                                                <td><?= date('d.m.Y H:i', strtotime($si['islem_tarihi'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">Henüz sistem işlemi bulunmuyor.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?= $aktif_sekme === 'ders-onaylari' ? 'show active' : '' ?>" id="ders-onaylari" role="tabpanel">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-warning fw-bold text-dark">
                            ⏳ Onay Bekleyen Ders Talepleri
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Öğrenci</th>
                                        <th>Dönem</th>
                                        <th>Ders</th>
                                        <th>Kredi/AKTS</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($bekleyen_dersler) > 0): ?>
                                        <?php foreach ($bekleyen_dersler as $d): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($d['ad_soyad']) ?></strong><br>
                                                    <small class="text-muted"><?= htmlspecialchars($d['kullanici_adi']) ?></small>
                                                </td>
                                                <td><?= htmlspecialchars($d['akademik_yil_donem']) ?></td>
                                                <td><strong><?= htmlspecialchars($d['ders_kodu']) ?></strong> - <?= htmlspecialchars($d['ders_adi']) ?></td>
                                                <td><?= $d['kredi'] ?> / <?= $d['akts'] ?></td>
                                                <td>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="kayit_id" value="<?= $d['id'] ?>">
                                                        <button type="submit" name="islem_turu" value="onayla" class="btn btn-sm btn-success fw-bold">Onayla</button>
                                                        <button type="submit" name="islem_turu" value="reddet" class="btn btn-sm btn-danger fw-bold">Reddet</button>
                                                    </form>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#dersCikarModal"
                                                            data-ders-id="<?= (int) $d['id'] ?>" data-ders-bilgi="<?= htmlspecialchars($d['ad_soyad'] . ' / ' . $d['ders_kodu'], ENT_QUOTES, 'UTF-8') ?>">Dersi Çıkar</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted">Onay bekleyen ders talebi bulunmuyor.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?= $aktif_sekme === 'ders-katalogu' ? 'show active' : '' ?>" id="ders-katalogu" role="tabpanel" aria-labelledby="ders-katalogu-tab">
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-primary text-white fw-bold">📚 Ders Kataloğu — Yeni Ders</div>
                        <div class="card-body">
                            <form method="POST" action="koordinator_panel.php?sekme=ders-katalogu">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['ders_yonetim_csrf']) ?>">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label for="katalog_ders_kodu" class="form-label">Ders Kodu</label>
                                        <input type="text" class="form-control" id="katalog_ders_kodu" name="ders_kodu" maxlength="20" required>
                                    </div>
                                    <div class="col-md-5">
                                        <label for="katalog_ders_adi" class="form-label">Ders Adı</label>
                                        <input type="text" class="form-control" id="katalog_ders_adi" name="ders_adi" maxlength="255" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label for="katalog_kredi" class="form-label">Kredi</label>
                                        <input type="number" class="form-control" id="katalog_kredi" name="kredi" min="0" max="99.99" step="0.01" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label for="katalog_akts" class="form-label">AKTS</label>
                                        <input type="number" class="form-control" id="katalog_akts" name="akts" min="0" max="99.99" step="0.01" required>
                                    </div>
                                </div>
                                <button type="submit" name="katalog_islem" value="ekle" class="btn btn-primary mt-3">Ders Ekle</button>
                            </form>
                        </div>
                    </div>
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white fw-bold">Katalog Dersleri</div>
                        <div class="card-body p-0 table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light"><tr><th>Ders Kodu</th><th>Ders Adı</th><th>Kredi / AKTS</th><th>Durum</th><th>İşlem</th></tr></thead>
                                <tbody>
                                    <?php foreach ($ders_katalogu as $kat): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($kat['ders_kodu']) ?></td>
                                            <td><?= htmlspecialchars($kat['ders_adi']) ?></td>
                                            <td><?= htmlspecialchars((string) $kat['kredi']) ?> / <?= htmlspecialchars((string) $kat['akts']) ?></td>
                                            <td><span class="badge <?= $kat['aktif_mi'] ? 'bg-success' : 'bg-secondary' ?>"><?= $kat['aktif_mi'] ? 'Aktif' : 'Pasif' ?></span></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#katalogDuzenleModal"
                                                        data-katalog-id="<?= (int) $kat['id'] ?>" data-ders-kodu="<?= htmlspecialchars($kat['ders_kodu'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-ders-adi="<?= htmlspecialchars($kat['ders_adi'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-kredi="<?= htmlspecialchars((string) $kat['kredi'], ENT_QUOTES, 'UTF-8') ?>" data-akts="<?= htmlspecialchars((string) $kat['akts'], ENT_QUOTES, 'UTF-8') ?>">Güncelle</button>
                                                <?php if ($kat['aktif_mi']): ?>
                                                    <form method="POST" action="koordinator_panel.php?sekme=ders-katalogu" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['ders_yonetim_csrf']) ?>">
                                                        <input type="hidden" name="katalog_id" value="<?= (int) $kat['id'] ?>">
                                                        <button type="submit" name="katalog_islem" value="pasife_al" class="btn btn-sm btn-outline-danger">Pasife Al</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$ders_katalogu): ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted">Katalogda henüz ders bulunmuyor.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer text-muted small">Pasif dersler yeni ders taleplerinde seçilemez. Mevcut öğrenci ders kayıtları korunur.</div>
                    </div>
                </div>

                <div class="tab-pane fade <?= $aktif_sekme === 'not-onaylari' ? 'show active' : '' ?>" id="not-onaylari" role="tabpanel">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-success text-white fw-bold">
                            💯 Onay Bekleyen Not Girişleri
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Öğrenci</th>
                                        <th>Dönem</th>
                                        <th>Ders</th>
                                        <th>Girilen Not</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($bekleyen_notlar) > 0): ?>
                                        <?php foreach ($bekleyen_notlar as $n): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($n['ad_soyad']) ?></strong><br>
                                                    <small class="text-muted"><?= htmlspecialchars($n['kullanici_adi']) ?></small>
                                                </td>
                                                <td><?= htmlspecialchars($n['akademik_yil_donem']) ?></td>
                                                <td><strong><?= htmlspecialchars($n['ders_kodu']) ?></strong> - <?= htmlspecialchars($n['katalog_ders_adi'] ?: $n['ders_adi']) ?></td>
                                                <td><span class="badge bg-dark fs-6"><?= htmlspecialchars($n['harf_notu']) ?></span></td>
                                                <td>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="not_kayit_id" value="<?= (int) $n['id'] ?>">
                                                        <button type="submit" name="not_islem_turu" value="onayla" class="btn btn-sm btn-success fw-bold">Onayla</button>
                                                        <button type="submit" name="not_islem_turu" value="reddet" class="btn btn-sm btn-danger fw-bold">Reddet</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted">Onay bekleyen not girişi bulunmuyor.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?= $aktif_sekme === 'belge-onaylari' ? 'show active' : '' ?>" id="belge-onaylari" role="tabpanel">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-primary text-white fw-bold">
                            📄 Onay Bekleyen Belge Talepleri
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Öğrenci</th>
                                        <th>Belge Türü</th>
                                        <th>Tarih</th>
                                        <th>Dosya</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($bekleyen_belgeler) > 0): ?>
                                        <?php foreach ($bekleyen_belgeler as $b): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($b['ad_soyad']) ?></strong><br>
                                                    <small class="text-muted"><?= htmlspecialchars($b['kullanici_adi']) ?></small>
                                                </td>
                                                <td><?= htmlspecialchars($b['belge_turu']) ?></td>
                                                <td><?= date('d.m.Y H:i', strtotime($b['yuklenme_tarihi'])) ?></td>
                                                <td>
                                                    <a href="<?= htmlspecialchars($b['dosya_yolu']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">Görüntüle</a>
                                                </td>
                                                <td>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="belge_id" value="<?= (int) $b['id'] ?>">
                                                        <button type="submit" name="belge_islem_turu" value="onayla" class="btn btn-sm btn-success fw-bold">Onayla</button>
                                                        <button type="submit" name="belge_islem_turu" value="reddet" class="btn btn-sm btn-danger fw-bold">Reddet</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted">Onay bekleyen belge bulunmuyor.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?= $aktif_sekme === 'mesajlar' ? 'show active' : '' ?>" id="mesajlar" role="tabpanel">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-info text-white fw-bold">
                            💬 Öğrenci Mesajları
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Öğrenci Adı</th>
                                        <th>Son Mesaj</th>
                                        <th>Tarih</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($ogrenci_mesajlari) > 0): ?>
                                        <?php foreach ($ogrenci_mesajlari as $om): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($om['ad_soyad']) ?></strong><br>
                                                    <small class="text-muted"><?= htmlspecialchars($om['kullanici_adi']) ?></small>
                                                </td>
                                                <td><?= htmlspecialchars(mb_strimwidth($om['son_mesaj'], 0, 80, '...')) ?></td>
                                                <td><?= date('d.m.Y H:i', strtotime($om['gonderim_tarihi'])) ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-primary fw-bold"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#yanitModal<?= (int) $om['ogrenci_id'] ?>"
                                                            onclick="mesajlariOkunduIsaretle(<?= (int) $om['ogrenci_id'] ?>)">
                                                        Yanıtla
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">Henüz öğrenci mesajı bulunmuyor.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?= $aktif_sekme === 'ogrenci-yonetimi' ? 'show active' : '' ?>" id="ogrenci-yonetimi" role="tabpanel">
                    <?php if ($ogrenci_mesaj): ?>
                        <div class="alert alert-<?= htmlspecialchars($ogrenci_mesaj_tur) ?> alert-dismissible fade show mb-4" role="alert">
                            <?= htmlspecialchars($ogrenci_mesaj) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
                        </div>
                    <?php endif; ?>

                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-info text-white fw-bold">
                            🧑‍🎓 Yeni Öğrenci Tanımla
                        </div>
                        <div class="card-body">
                            <form method="POST" action="koordinator_panel.php">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label for="yeni_ad_soyad" class="form-label">Ad Soyad</label>
                                        <input type="text" class="form-control" name="ad_soyad" id="yeni_ad_soyad" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="yeni_kullanici_adi" class="form-label">Öğrenci Numarası</label>
                                        <input type="text" class="form-control" name="kullanici_adi" id="yeni_kullanici_adi" required>
                                        <div class="form-text">Sisteme giriş kullanıcı adı olarak kullanılır.</div>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="yeni_gecici_sifre" class="form-label">Geçici Şifre</label>
                                        <input type="text" class="form-control" name="gecici_sifre" id="yeni_gecici_sifre" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="yeni_program_turu" class="form-label">Program Tipi</label>
                                        <select class="form-select" name="program_turu" id="yeni_program_turu" required>
                                            <option value="">— Seçiniz —</option>
                                            <option value="cap">ÇAP</option>
                                            <option value="yandal">Yandal</option>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" name="ogrenci_ekle" value="1" class="btn btn-primary fw-bold mt-3">Öğrenciyi Kaydet</button>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-dark text-white fw-bold">
                            👥 Sisteme Kayıtlı Öğrenciler
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Ad Soyad</th>
                                        <th>Öğrenci Numarası</th>
                                        <th>Program Tipi</th>
                                        <th>Kayıt Tarihi</th>
                                        <th>Durum</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($ogrenciler) > 0): ?>
                                        <?php foreach ($ogrenciler as $o): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($o['ad_soyad']) ?></strong></td>
                                                <td><?= htmlspecialchars($o['kullanici_adi']) ?></td>
                                                <td><span class="badge bg-info text-dark"><?= strtoupper($o['program_turu'] ?? '-') ?></span></td>
                                                <td><?= date('d.m.Y H:i', strtotime($o['kayit_tarihi'])) ?></td>
                                                <td>
                                                    <?php if ($o['aktif_mi']): ?>
                                                        <span class="badge bg-success">Aktif</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Pasif</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted">Sisteme kayıtlı öğrenci bulunmuyor.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 mt-4">
                        <div class="card-header bg-warning text-dark fw-bold">📚 Öğrenci Ders Listesi</div>
                        <div class="card-body">
                            <form method="GET" action="koordinator_panel.php" class="row g-2 align-items-end mb-3">
                                <input type="hidden" name="sekme" value="ogrenci-yonetimi">
                                <div class="col-md-8">
                                    <label for="ogrenci_id" class="form-label">Öğrenci</label>
                                    <select class="form-select" name="ogrenci_id" id="ogrenci_id">
                                        <option value="0">Tüm öğrenciler</option>
                                        <?php foreach ($ogrenciler as $o): ?>
                                            <option value="<?= (int) $o['id'] ?>" <?= $secili_ogrenci_id === (int) $o['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($o['ad_soyad'] . ' (' . $o['kullanici_adi'] . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <button type="submit" class="btn btn-dark w-100">Listele</button>
                                </div>
                            </form>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Öğrenci</th>
                                            <th>Dönem</th>
                                            <th>Ders</th>
                                            <th>Durum</th>
                                            <th>Onay</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($ogrenci_ders_listesi) > 0): ?>
                                            <?php foreach ($ogrenci_ders_listesi as $od): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($od['ad_soyad']) ?></strong><br>
                                                        <small class="text-muted"><?= htmlspecialchars($od['kullanici_adi']) ?></small>
                                                    </td>
                                                    <td><?= htmlspecialchars($od['akademik_yil_donem'] ?: ($od['alinan_donem'] ?? '-')) ?></td>
                                                    <td><strong><?= htmlspecialchars($od['ders_kodu']) ?></strong> - <?= htmlspecialchars($od['ders_adi']) ?></td>
                                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($ders_durum_etiketleri[$od['durum']] ?? $od['durum']) ?></span></td>
                                                    <td><?= htmlspecialchars($od['onay_durumu']) ?></td>
                                                    <td>
                                                        <?php if ($od['durum'] !== 'cikarildi'): ?>
                                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                                    data-bs-toggle="modal" data-bs-target="#dersCikarModal"
                                                                    data-ders-id="<?= (int) $od['id'] ?>"
                                                                    data-ders-bilgi="<?= htmlspecialchars($od['ad_soyad'] . ' / ' . $od['ders_kodu'] . ' - ' . $od['ders_adi'], ENT_QUOTES, 'UTF-8') ?>">
                                                                Dersi Çıkar
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="text-muted small">Çıkarılmış</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="6" class="text-center py-4 text-muted">Gösterilecek ders kaydı yok.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-muted small mb-0 mt-3">Çıkarma işlemi kaydı silmez; durum <code>cikarildi</code> olur ve gerekçe işlem geçmişine yazılır.</p>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?= $aktif_sekme === 'sistem-ayarlari' ? 'show active' : '' ?>" id="sistem-ayarlari" role="tabpanel">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-secondary text-white fw-bold">⚙️ Sistem Ayarları</div>
                        <div class="card-body">
                            <?php if ($ayar_mesaj): ?>
                                <div class="alert alert-<?= htmlspecialchars($ayar_mesaj_tur) ?> alert-dismissible fade show" role="alert">
                                    <?= htmlspecialchars($ayar_mesaj) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
                                </div>
                            <?php endif; ?>
                            <form method="POST" action="">
                                <div class="mb-3">
                                    <label for="cap_min_gano" class="form-label">ÇAP Minimum GANO</label>
                                    <input type="number" step="0.01" min="0" max="4" class="form-control" name="cap_min_gano" id="cap_min_gano"
                                           value="<?= htmlspecialchars((string) ($sistem_ayarlari['cap_min_gano'] ?? '')) ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="yandal_min_gano" class="form-label">Yandal Minimum GANO</label>
                                    <input type="number" step="0.01" min="0" max="4" class="form-control" name="yandal_min_gano" id="yandal_min_gano"
                                           value="<?= htmlspecialchars((string) ($sistem_ayarlari['yandal_min_gano'] ?? '')) ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="max_belge_boyutu_mb" class="form-label">Maksimum Belge Boyutu (MB)</label>
                                    <input type="number" step="1" min="1" class="form-control" name="max_belge_boyutu_mb" id="max_belge_boyutu_mb"
                                           value="<?= htmlspecialchars((string) ($sistem_ayarlari['max_belge_boyutu_mb'] ?? '')) ?>" required>
                                </div>
                                <button type="submit" name="ayarlari_kaydet" value="1" class="btn btn-primary fw-bold">Ayarları Kaydet</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php foreach ($ogrenci_mesajlari as $om): ?>
    <?php $oid = (int) $om['ogrenci_id']; ?>
    <div class="modal fade" id="yanitModal<?= $oid ?>" tabindex="-1" aria-labelledby="yanitModalLabel<?= $oid ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="yanitModalLabel<?= $oid ?>">
                        <?= htmlspecialchars($om['ad_soyad']) ?> ile sohbet
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <div class="sohbet-alani border rounded p-3 mb-3">
                        <?php if (!empty($sohbetler[$oid])): ?>
                            <?php foreach ($sohbetler[$oid] as $sm): ?>
                                <?php $koordinator_mesaji = ($sm['gonderen_rol'] === 'koordinator'); ?>
                                <div class="d-flex mb-2 <?= $koordinator_mesaji ? 'justify-content-end' : 'justify-content-start' ?>">
                                    <div class="sohbet-balon rounded-3 px-3 py-2 <?= $koordinator_mesaji ? 'sohbet-koordinator' : 'sohbet-ogrenci' ?>">
                                        <div class="small fw-bold mb-1">
                                            <?= htmlspecialchars($koordinator_mesaji ? 'Siz' : $om['ad_soyad']) ?>
                                        </div>
                                        <div><?= nl2br(htmlspecialchars($sm['mesaj'])) ?></div>
                                        <div class="small opacity-75 mt-1">
                                            <?= date('d.m.Y H:i', strtotime($sm['gonderim_tarihi'])) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">Bu öğrenciyle henüz mesaj yok.</p>
                        <?php endif; ?>
                    </div>
                    <form method="POST" action="">
                        <input type="hidden" name="alici_id" value="<?= $oid ?>">
                        <div class="mb-2">
                            <label for="mesaj_metni_<?= $oid ?>" class="form-label">Yanıtınız</label>
                            <textarea class="form-control" name="mesaj_metni" id="mesaj_metni_<?= $oid ?>" rows="3" required placeholder="Cevabınızı yazın..."></textarea>
                        </div>
                        <div class="d-grid">
                            <button type="submit" name="mesaj_gonder" value="1" class="btn btn-info text-white">Gönder</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<div class="modal fade" id="katalogDuzenleModal" tabindex="-1" aria-labelledby="katalogDuzenleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="koordinator_panel.php?sekme=ders-katalogu">
                <div class="modal-header">
                    <h5 class="modal-title" id="katalogDuzenleModalLabel">Ders Güncelle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['ders_yonetim_csrf']) ?>">
                    <input type="hidden" name="katalog_id" id="duzenle_katalog_id" value="">
                    <div class="mb-3">
                        <label for="duzenle_ders_kodu" class="form-label">Ders Kodu</label>
                        <input type="text" class="form-control" id="duzenle_ders_kodu" name="ders_kodu" maxlength="20" required>
                    </div>
                    <div class="mb-3">
                        <label for="duzenle_ders_adi" class="form-label">Ders Adı</label>
                        <input type="text" class="form-control" id="duzenle_ders_adi" name="ders_adi" maxlength="255" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="duzenle_kredi" class="form-label">Kredi</label>
                            <input type="number" class="form-control" id="duzenle_kredi" name="kredi" min="0" max="99.99" step="0.01" required>
                        </div>
                        <div class="col-md-6">
                            <label for="duzenle_akts" class="form-label">AKTS</label>
                            <input type="number" class="form-control" id="duzenle_akts" name="akts" min="0" max="99.99" step="0.01" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" name="katalog_islem" value="guncelle" class="btn btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="dersCikarModal" tabindex="-1" aria-labelledby="dersCikarModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="koordinator_panel.php?sekme=ogrenci-yonetimi">
                <div class="modal-header">
                    <h5 class="modal-title" id="dersCikarModalLabel">Dersi Çıkar</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Kayıt silinmez; durum <strong>cikarildi</strong> olarak güncellenir.</p>
                    <p class="fw-bold" id="cikar_ders_bilgi"></p>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['ders_yonetim_csrf']) ?>">
                    <input type="hidden" name="cikar_ders_id" id="cikar_ders_id" value="">
                    <div class="mb-3">
                        <label for="cikarma_gerekcesi" class="form-label">Çıkarma Gerekçesi</label>
                        <textarea class="form-control" name="cikarma_gerekcesi" id="cikarma_gerekcesi" rows="4" maxlength="2000" required placeholder="Gerekçeyi yazın..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="submit" name="ders_cikar" value="1" class="btn btn-danger">Çıkarmayı Onayla</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function mesajlariOkunduIsaretle(ogrenciId) {
        fetch('koordinator_panel.php?mesaj_oku_id=' + ogrenciId)
            .catch(function (hata) {
                console.error('Mesajlar okundu işaretlenirken hata oluştu:', hata);
            });
    }

    document.querySelectorAll('.modal').forEach(function (modalEl) {
        modalEl.addEventListener('shown.bs.modal', function () {
            const alan = modalEl.querySelector('.sohbet-alani');
            if (alan) {
                alan.scrollTop = alan.scrollHeight;
            }
        });
    });
    <?php if ($acik_yanit > 0): ?>
    const acikModal = document.getElementById('yanitModal<?= $acik_yanit ?>');
    if (acikModal) {
        new bootstrap.Modal(acikModal).show();
    }
    <?php endif; ?>

    const katalogDuzenleModal = document.getElementById('katalogDuzenleModal');
    if (katalogDuzenleModal) {
        katalogDuzenleModal.addEventListener('show.bs.modal', function (event) {
            const dugme = event.relatedTarget;
            if (!dugme) return;
            document.getElementById('duzenle_katalog_id').value = dugme.getAttribute('data-katalog-id') || '';
            document.getElementById('duzenle_ders_kodu').value = dugme.getAttribute('data-ders-kodu') || '';
            document.getElementById('duzenle_ders_adi').value = dugme.getAttribute('data-ders-adi') || '';
            document.getElementById('duzenle_kredi').value = dugme.getAttribute('data-kredi') || '';
            document.getElementById('duzenle_akts').value = dugme.getAttribute('data-akts') || '';
        });
    }

    const dersCikarModal = document.getElementById('dersCikarModal');
    if (dersCikarModal) {
        dersCikarModal.addEventListener('show.bs.modal', function (event) {
            const dugme = event.relatedTarget;
            if (!dugme) return;
            document.getElementById('cikar_ders_id').value = dugme.getAttribute('data-ders-id') || '';
            document.getElementById('cikar_ders_bilgi').textContent = dugme.getAttribute('data-ders-bilgi') || '';
            document.getElementById('cikarma_gerekcesi').value = '';
        });
    }
</script>
</body>
</html>
