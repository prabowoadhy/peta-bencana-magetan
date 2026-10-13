<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Definisi skema: kolom tabel laporan PERSIS mengikuti berkas Excel
 * "register bencana" BPBD Magetan. Tidak ada kolom turunan ala BNPB
 * nasional (meninggal, terluka, rumah rusak, terendam, dst).
 *
 * Susunan kolom Excel (baris 4-5 header, data mulai baris 7):
 *   No | No Nota Dinas | Bencana | Dampak Bencana | Lokasi Bencana |
 *   KOORDINAT (lintang, bujur) | Desa | Kecamatan |
 *   Waktu Laporan | Jam Lapor | Waktu Kejadian | Jam Kejadian |
 *   DAMPAK: Manusia | Hewan | Rumah | Harta | Jalan |
 *   Fasilitas Umum Bukan Jalan | Jembatan | Nilai Kerusakan | Keterangan
 */
class PBM_Skema {

	/** Prefiks tabel tanpa prefix WordPress. */
	const TABEL_LAPORAN = 'pbm_laporan';
	const TABEL_DESA    = 'pbm_desa';

	/**
	 * Definisi kolom tabel laporan: nama => [tipe SQL, label].
	 * Urutannya mengikuti urutan kolom pada Excel.
	 */
	public static function kolom() {
		return array(
			'no'               => array( 'varchar(10) NOT NULL DEFAULT \'\'', 'No' ),
			'nodin'            => array( 'varchar(80) NOT NULL DEFAULT \'\'', 'No Nota Dinas' ),
			'bencana'          => array( 'varchar(120) NOT NULL DEFAULT \'\'', 'Bencana' ),
			'dampak_bencana'   => array( 'text DEFAULT NULL', 'Dampak Bencana' ),
			'lokasi'           => array( 'text DEFAULT NULL', 'Lokasi Bencana' ),
			'lat'              => array( 'varchar(30) NOT NULL DEFAULT \'\'', 'Lintang' ),
			'lon'              => array( 'varchar(30) NOT NULL DEFAULT \'\'', 'Bujur' ),
			'desa'             => array( 'varchar(120) NOT NULL DEFAULT \'\'', 'Desa' ),
			'kecamatan'        => array( 'varchar(120) NOT NULL DEFAULT \'\'', 'Kecamatan' ),
			'waktu_laporan'    => array( 'date DEFAULT NULL', 'Waktu Laporan' ),
			'jam_lapor'        => array( 'varchar(30) NOT NULL DEFAULT \'\'', 'Jam Lapor' ),
			'waktu_kejadian'   => array( 'date DEFAULT NULL', 'Waktu Kejadian' ),
			'jam_kejadian'     => array( 'varchar(30) NOT NULL DEFAULT \'\'', 'Jam Kejadian' ),
			'dampak_manusia'   => array( 'text DEFAULT NULL', 'Dampak Manusia' ),
			'dampak_hewan'     => array( 'text DEFAULT NULL', 'Dampak Hewan' ),
			'dampak_rumah'     => array( 'text DEFAULT NULL', 'Dampak Rumah' ),
			'dampak_harta'     => array( 'text DEFAULT NULL', 'Dampak Harta' ),
			'dampak_jalan'     => array( 'text DEFAULT NULL', 'Dampak Jalan' ),
			'dampak_fasilitas' => array( 'text DEFAULT NULL', 'Dampak Fasilitas Umum Bukan Jalan' ),
			'dampak_jembatan'  => array( 'text DEFAULT NULL', 'Dampak Jembatan' ),
			'nilai_kerusakan'  => array( 'text DEFAULT NULL', 'Nilai Kerusakan' ),
			'keterangan'       => array( 'text DEFAULT NULL', 'Keterangan' ),
		);
	}

	/** Nama kolom saja (untuk whitelist). */
	public static function nama_kolom() {
		return array_keys( self::kolom() );
	}

	/** Label ramah tiap kolom. */
	public static function label( $nama ) {
		$k = self::kolom();
		return $k[ $nama ][1] ?? $nama;
	}

	/**
	 * SQL pembuat tabel laporan lengkap (tanpa prefix).
	 *
	 * @param string $charset Kolasi dari $wpdb->get_charset_collate().
	 */
	public static function sql_buat_tabel( $charset ) {
		$baris = array();
		foreach ( self::kolom() as $nama => $def ) {
			$baris[] = "\t\t\t\t$nama {$def[0]}";
		}
		$kolom_sql = implode( ",\n", $baris );

		return "CREATE TABLE {TABEL} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
$kolom_sql,
				kode_desa char(12) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY waktu_laporan (waktu_laporan),
				KEY kode_desa (kode_desa),
				KEY kecamatan (kecamatan),
				KEY bencana (bencana)
			) $charset;";
	}
}