=== Peta Bencana Magetan ===
Contributors: kominfo
Tags: peta, bencana, leaflet, magetan, gis, import excel
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later

Peta bencana per desa Kabupaten Magetan. Tabel data mengikuti kolom berkas Excel register bencana.

== Description ==

Menampilkan peta choropleth 236 desa / 18 kecamatan Kabupaten Magetan
(batas desa dari ekspor QGIS) dengan warna berdasarkan jumlah kejadian,
mirip tampilan peta BNPB. Pengelompokan data murni per desa/kecamatan.

Kolom tabel = kolom Excel apa adanya:
No, No Nota Dinas, Bencana, Dampak Bencana, Lokasi Bencana, Lintang, Bujur,
Desa, Kecamatan, Waktu Laporan, Jam Lapor, Waktu Kejadian, Jam Kejadian,
Dampak Manusia, Dampak Hewan, Dampak Rumah, Dampak Harta, Dampak Jalan,
Dampak Fasilitas Umum Bukan Jalan, Dampak Jembatan, Nilai Kerusakan, Keterangan.

Tidak ada kolom statistik BNPB nasional (meninggal/terluka/rumah rusak/terendam).
Fitur:

* Peta Leaflet + tile Esri (sama seperti app GIS BNPB), layer batas desa & kecamatan.
* Choropleth per desa: 0, 1-4, 5-19, 20-99, >=100 kejadian.
* Popup desa: total kejadian + AKUMULASI PER JENIS bencana.
* Kotak ringkasan "Kejadian per jenis bencana" untuk seluruh kabupaten.
* Impor massal .xlsx: kolom dideteksi otomatis (termasuk header bertingkat),
  koordinat dirapikan, desa dicocokkan ke peta, duplikat dilewati.
* Tombol hapus semua data lama untuk mengganti total dengan Excel terbaru.
* CRUD manual semua kolom Excel, filter (periode/jenis/kecamatan/pencarian),
  ekspor CSV dengan urutan kolom sama seperti Excel.
* REST API: /wp-json/pbm/v1/{rekap,kecamatan,total,laporan,desa}.
* Keamanan: capability manage_options, nonce semua aksi, whitelist kolom,
  prepared statement.

== Installation ==

1. Upload folder `peta-bencana-magetan` ke `/wp-content/plugins/`.
2. Aktifkan. Tabel dibuat otomatis (kolom sesuai Excel) + 236 desa terimpor.
3. Menu "Peta Bencana" -> Peta / Data Bencana / Impor Excel.
4. Shortcode: [peta_bencana_magetan tinggi="600" filter="yes"]

== Impor Excel ==

1. Menu Peta Bencana -> Impor Excel, pilih berkas .xlsx, klik Baca & Pratinjau.
2. Pratinjau menampilkan status tiap baris: baru / duplikat / desa di luar peta.
3. Klik "Impor N baris". Centang "Timpa baris duplikat" bila ingin memperbarui.

Header boleh di baris mana pun pada 25 baris pertama (termasuk header bertingkat
seperti file register bencana BPBD: baris "DAMPAK" digabung dengan sub-kolomnya).

Koordinat hanya dirapikan (spasi/kutip dibuang, koma menjadi titik) dan disimpan
sebagai teks. Koordinat TIDAK dipakai untuk memplot titik di peta.

== Shortcode ==

[peta_bencana_magetan tinggi="600" dari="2026-01-01" sampai="2026-12-31" bencana="Banjir" filter="yes"]

* tinggi - tinggi peta px (default 600)
* dari/sampai - periode (YYYY-MM-DD)
* bencana - jenis bencana (nama sesuai kolom Bencana Excel)
* filter = "yes" menampilkan bar filter

== FAQ ==

= Bagaimana data diperbarui? =
Input manual di menu Data Bencana, atau impor massal di menu Impor Excel.
Untuk mengganti seluruh data: gunakan tombol "Hapus semua data lama".

= Bagaimana jika kolom Excel berbeda urutan? =
Tidak masalah, kolom dideteksi berdasarkan nama header, bukan posisinya.

= Kenapa koordinat tidak muncul sebagai titik? =
Sesuai desain, pengelompokan dilakukan per desa/kecamatan. Koordinat tetap
tersimpan di tabel dan ikut terekspor ke CSV.
