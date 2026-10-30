-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Anamakine: 127.0.0.1
-- Üretim Zamanı: 26 Eki 2026, 09:17:45
-- Sunucu sürümü: 10.4.32-MariaDB
-- PHP Sürümü: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Veritabanı: `cap_yandal_otomasyon`
--

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `belgeler`
--

CREATE TABLE `belgeler` (
  `id` int(11) NOT NULL,
  `ogrenci_id` int(11) NOT NULL,
  `yukleyen_id` int(11) NOT NULL,
  `belge_turu` varchar(50) DEFAULT NULL,
  `dosya_yolu` varchar(255) NOT NULL,
  `onay_durumu` enum('bekliyor','onaylandi','reddedildi') DEFAULT 'bekliyor',
  `yuklenme_tarihi` timestamp NOT NULL DEFAULT current_timestamp(),
  `iptal_mi` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

--
-- Tablo döküm verisi `belgeler`
--

INSERT INTO `belgeler` (`id`, `ogrenci_id`, `yukleyen_id`, `belge_turu`, `dosya_yolu`, `onay_durumu`, `yuklenme_tarihi`, `iptal_mi`) VALUES
(1, 3, 3, 'ÇAP Transkripti', 'uploads/belge_6acf5daed87865.07232519.pdf', 'onaylandi', '2026-10-14 10:47:10', 0);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `ders_katalogu`
--

CREATE TABLE `ders_katalogu` (
  `id` int(11) NOT NULL,
  `ders_kodu` varchar(20) NOT NULL,
  `ders_adi` varchar(150) NOT NULL,
  `kredi` int(11) DEFAULT NULL,
  `akts` int(11) DEFAULT NULL,
  `aktif_mi` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

--
-- Tablo döküm verisi `ders_katalogu`
--

INSERT INTO `ders_katalogu` (`id`, `ders_kodu`, `ders_adi`, `kredi`, `akts`, `aktif_mi`) VALUES
(1, 'BIL101', 'Programlamaya Giriş', 3, 5, 1),
(2, 'BIL102', 'Nesne Yönelimli Programlama', 3, 6, 1),
(3, 'MAT101', 'Matematik I', 4, 6, 1),
(4, 'END201', 'Yöneylem Araştırması', 3, 5, 1),
(5, '23616', 'veritabanı', 4, 5, 1);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `islem_gecmisi`
--

CREATE TABLE `islem_gecmisi` (
  `id` int(11) NOT NULL,
  `kullanici_id` int(11) NOT NULL,
  `islem_turu` varchar(50) NOT NULL,
  `tablo_adi` varchar(50) NOT NULL,
  `kayit_id` int(11) NOT NULL,
  `eski_deger` text DEFAULT NULL,
  `yeni_deger` text DEFAULT NULL,
  `islem_tarihi` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

--
-- Tablo döküm verisi `islem_gecmisi`
--

INSERT INTO `islem_gecmisi` (`id`, `kullanici_id`, `islem_turu`, `tablo_adi`, `kayit_id`, `eski_deger`, `yeni_deger`, `islem_tarihi`) VALUES
(1, 1, 'ONAYLANDI', 'ogrenci_dersleri', 1, 'bekliyor', 'onaylandi', '2026-10-14 10:38:03'),
(2, 1, 'ONAYLANDI', 'ogrenci_dersleri', 1, 'onaylandi', 'onaylandi', '2026-10-14 10:46:22'),
(3, 3, 'NOT_GIRISI', 'ogrenci_dersleri', 1, NULL, 'AA', '2026-10-14 11:32:46'),
(4, 1, 'ONAYLANDI', 'ogrenci_dersleri', 1, 'bekliyor', 'onaylandi', '2026-10-14 11:33:46'),
(5, 1, 'ONAYLANDI', 'ogrenci_dersleri', 1, 'onaylandi', 'onaylandi', '2026-10-14 12:15:55'),
(8, 1, 'ONAYLANDI', 'belgeler', 1, 'bekliyor', 'onaylandi', '2026-10-14 12:30:50'),
(9, 1, 'ONAYLANDI', 'belgeler', 1, 'onaylandi', 'onaylandi', '2026-10-14 12:30:54'),
(12, 1, 'OGRENCI_EKLENDI', 'kullanicilar', 4, NULL, '2099999', '2026-10-14 12:49:58'),
(13, 1, 'KATALOG_EKLENDI', 'ders_katalogu', 5, NULL, '{\"id\":5,\"ders_kodu\":\"23616\",\"ders_adi\":\"veritabanı\",\"kredi\":4,\"akts\":5,\"aktif_mi\":1}', '2026-10-15 12:22:16');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `kullanicilar`
--

CREATE TABLE `kullanicilar` (
  `id` int(11) NOT NULL,
  `kullanici_adi` varchar(50) NOT NULL,
  `sifre` varchar(255) NOT NULL,
  `ad_soyad` varchar(100) NOT NULL,
  `eposta` varchar(100) DEFAULT NULL,
  `rol` enum('ogrenci','koordinator','bolum_baskani') NOT NULL,
  `program_turu` enum('cap','yandal','yok') DEFAULT 'yok',
  `kabul_donemi` varchar(20) DEFAULT NULL,
  `aktif_mi` tinyint(1) DEFAULT 1,
  `kayit_tarihi` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

--
-- Tablo döküm verisi `kullanicilar`
--

INSERT INTO `kullanicilar` (`id`, `kullanici_adi`, `sifre`, `ad_soyad`, `eposta`, `rol`, `program_turu`, `kabul_donemi`, `aktif_mi`, `kayit_tarihi`) VALUES
(1, 'koordinator', '123456', 'Sistem Koordinatörü', 'koordinator@uni.edu.tr', 'koordinator', 'yok', NULL, 1, '2026-10-14 10:07:40'),
(2, 'baskan', '123456', 'Bölüm Başkanı', 'baskan@uni.edu.tr', 'bolum_baskani', 'yok', NULL, 1, '2026-10-14 10:07:40'),
(3, '2026001', '123456', 'Örnek Öğrenci', '2026001@ogrenci.uni.edu.tr', 'ogrenci', 'cap', NULL, 1, '2026-10-14 10:07:40');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `mesajlar`
--

CREATE TABLE `mesajlar` (
  `id` int(11) NOT NULL,
  `gonderen_id` int(11) NOT NULL,
  `alici_id` int(11) NOT NULL,
  `mesaj` text NOT NULL,
  `okundu_mu` tinyint(1) DEFAULT 0,
  `gonderim_tarihi` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

--
-- Tablo döküm verisi `mesajlar`
--

INSERT INTO `mesajlar` (`id`, `gonderen_id`, `alici_id`, `mesaj`, `okundu_mu`, `gonderim_tarihi`) VALUES
(1, 3, 1, 'merhabalar hocam belgelerimi kontrol edebilirmisniz', 1, '2026-10-14 11:09:17'),
(2, 1, 3, 'bakıyorumm', 0, '2026-10-14 11:15:03'),
(3, 1, 3, 'tamam', 0, '2026-10-14 12:16:50');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `ogrenci_dersleri`
--

CREATE TABLE `ogrenci_dersleri` (
  `id` int(11) NOT NULL,
  `ogrenci_id` int(11) NOT NULL,
  `ders_kodu` varchar(20) NOT NULL,
  `ders_adi` varchar(150) NOT NULL,
  `kredi` int(11) DEFAULT NULL,
  `akts` int(11) DEFAULT NULL,
  `akademik_yil_donem` varchar(20) DEFAULT NULL,
  `harf_notu` varchar(5) DEFAULT NULL,
  `durum` enum('planlandi','alindi','gecti','kaldi','muaf','cikarildi') DEFAULT 'planlandi',
  `onay_durumu` enum('bekliyor','onaylandi','reddedildi') DEFAULT 'bekliyor',
  `koordinator_notu` text DEFAULT NULL,
  `iptal_mi` tinyint(1) DEFAULT 0,
  `deneme_sayisi` int(11) NOT NULL DEFAULT 1,
  `alinan_donem` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

--
-- Tablo döküm verisi `ogrenci_dersleri`
--

INSERT INTO `ogrenci_dersleri` (`id`, `ogrenci_id`, `ders_kodu`, `ders_adi`, `kredi`, `akts`, `akademik_yil_donem`, `harf_notu`, `durum`, `onay_durumu`, `koordinator_notu`, `iptal_mi`, `deneme_sayisi`, `alinan_donem`) VALUES
(1, 3, 'END201', 'Yöneylem Araştırması', 3, 5, '2026', 'AA', 'gecti', 'onaylandi', NULL, 0, 1, NULL);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sistem_ayarlari`
--

CREATE TABLE `sistem_ayarlari` (
  `ayar_anahtari` varchar(50) NOT NULL,
  `ayar_degeri` varchar(255) NOT NULL,
  `aciklama` varchar(255) DEFAULT NULL,
  `guncellenme_tarihi` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

--
-- Tablo döküm verisi `sistem_ayarlari`
--

INSERT INTO `sistem_ayarlari` (`ayar_anahtari`, `ayar_degeri`, `aciklama`, `guncellenme_tarihi`) VALUES
('cap_min_gano', '3.20', 'ÇAP başvurusu için gereken minimum GANO', '2026-10-14 11:29:00'),
('max_belge_boyutu_mb', '5', 'Yüklenecek belgeler için maksimum boyut', '2026-10-14 10:07:40'),
('yandal_min_gano', '2.60', 'Yandal başvurusu için gereken minimum GANO', '2026-10-14 11:29:19');

--
-- Dökümü yapılmış tablolar için indeksler
--

--
-- Tablo için indeksler `belgeler`
--
ALTER TABLE `belgeler`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ogrenci_id` (`ogrenci_id`),
  ADD KEY `yukleyen_id` (`yukleyen_id`);

--
-- Tablo için indeksler `ders_katalogu`
--
ALTER TABLE `ders_katalogu`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ders_kodu` (`ders_kodu`);

--
-- Tablo için indeksler `islem_gecmisi`
--
ALTER TABLE `islem_gecmisi`
  ADD PRIMARY KEY (`id`),
  ADD KEY `kullanici_id` (`kullanici_id`);

--
-- Tablo için indeksler `kullanicilar`
--
ALTER TABLE `kullanicilar`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `kullanici_adi` (`kullanici_adi`);

--
-- Tablo için indeksler `mesajlar`
--
ALTER TABLE `mesajlar`
  ADD PRIMARY KEY (`id`),
  ADD KEY `gonderen_id` (`gonderen_id`),
  ADD KEY `alici_id` (`alici_id`);

--
-- Tablo için indeksler `ogrenci_dersleri`
--
ALTER TABLE `ogrenci_dersleri`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ogrenci_id` (`ogrenci_id`);

--
-- Tablo için indeksler `sistem_ayarlari`
--
ALTER TABLE `sistem_ayarlari`
  ADD PRIMARY KEY (`ayar_anahtari`);

--
-- Dökümü yapılmış tablolar için AUTO_INCREMENT değeri
--

--
-- Tablo için AUTO_INCREMENT değeri `belgeler`
--
ALTER TABLE `belgeler`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Tablo için AUTO_INCREMENT değeri `ders_katalogu`
--
ALTER TABLE `ders_katalogu`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Tablo için AUTO_INCREMENT değeri `islem_gecmisi`
--
ALTER TABLE `islem_gecmisi`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- Tablo için AUTO_INCREMENT değeri `kullanicilar`
--
ALTER TABLE `kullanicilar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Tablo için AUTO_INCREMENT değeri `mesajlar`
--
ALTER TABLE `mesajlar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Tablo için AUTO_INCREMENT değeri `ogrenci_dersleri`
--
ALTER TABLE `ogrenci_dersleri`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Dökümü yapılmış tablolar için kısıtlamalar
--

--
-- Tablo kısıtlamaları `belgeler`
--
ALTER TABLE `belgeler`
  ADD CONSTRAINT `belgeler_ibfk_1` FOREIGN KEY (`ogrenci_id`) REFERENCES `kullanicilar` (`id`),
  ADD CONSTRAINT `belgeler_ibfk_2` FOREIGN KEY (`yukleyen_id`) REFERENCES `kullanicilar` (`id`);

--
-- Tablo kısıtlamaları `islem_gecmisi`
--
ALTER TABLE `islem_gecmisi`
  ADD CONSTRAINT `islem_gecmisi_ibfk_1` FOREIGN KEY (`kullanici_id`) REFERENCES `kullanicilar` (`id`);

--
-- Tablo kısıtlamaları `mesajlar`
--
ALTER TABLE `mesajlar`
  ADD CONSTRAINT `mesajlar_ibfk_1` FOREIGN KEY (`gonderen_id`) REFERENCES `kullanicilar` (`id`),
  ADD CONSTRAINT `mesajlar_ibfk_2` FOREIGN KEY (`alici_id`) REFERENCES `kullanicilar` (`id`);

--
-- Tablo kısıtlamaları `ogrenci_dersleri`
--
ALTER TABLE `ogrenci_dersleri`
  ADD CONSTRAINT `ogrenci_dersleri_ibfk_1` FOREIGN KEY (`ogrenci_id`) REFERENCES `kullanicilar` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
