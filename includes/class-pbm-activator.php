<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aktivasi plugin: buat tabel desa + laporan, impor master desa dari GeoJSON,
 * dan bersihkan kolom lama yang bukan bagian dari berkas Excel.
 */
class PBM_Activator {

	const DESA_FILE = 'assets/geojson/desa.geojson';

	public static function activate() {
		self::create_tables();
		self::import_desa();
		self::sesuaikan_kolom();
		self::tandai_sumber();
		update_option( 'pbm_db_version', PBM_VERSION );
	}

	/**
	 * Sinkronkan master desa bila versi plugin berubah.
	 *
	 * Aktivasi hanya terjadi saat plugin diaktifkan, sedangkan perubahan pada
	 * GeoJSON (desa baru, perbaikan nama/kecamatan) datang bersama pembaruan
	 * kode. Tanpa langkah ini master desa tetap memakai data lama sampai
	 * plugin diaktifkan ulang.
	 */
	public static function maybe_sync() {
		if ( get_option( 'pbm_db_version' ) === PBM_VERSION ) {
			return;
		}
		/* Hanya di admin/CLI agar permintaan frontend tidak menaruh upgrade.php. */
		if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		self::create_tables();
		self::import_desa();
		self::sesuaikan_kolom();
		self::tandai_sumber();
		update_option( 'pbm_db_version', PBM_VERSION );
	}

	/** Nama tabel laporan lengkap dengan prefix. */
	public static function tabel_laporan() {
		global $wpdb;
		return $wpdb->prefix . PBM_Skema::TABEL_LAPORAN;
	}

	/** Nama tabel desa lengkap dengan prefix. */
	public static function tabel_desa() {
		global $wpdb;
		return $wpdb->prefix . PBM_Skema::TABEL_DESA;
	}

	/** Buat/ubah tabel agar sesuai skema Excel. */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		/* Tabel master desa (batas wilayah dari GeoJSON QGIS). */
		dbDelta(
			'CREATE TABLE ' . self::tabel_desa() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kode char(12) NOT NULL,
				nama varchar(120) NOT NULL,
				kecamatan varchar(120) NOT NULL,
				sumber varchar(10) NOT NULL DEFAULT 'peta',
				PRIMARY KEY  (id),
				UNIQUE KEY kode (kode),
				KEY kecamatan (kecamatan),
				KEY sumber (sumber)
			) $charset;"
		);

		/* Tabel laporan: kolom mengikuti Excel. */
		$sql = str_replace( '{TABEL}', self::tabel_laporan(), PBM_Skema::sql_buat_tabel( $charset ) );
		dbDelta( $sql );
	}

	/**
	 * Pastikan tabel laporan hanya punya kolom Excel + kolom internal.
	 *
	 * dbDelta tidak menghapus kolom, jadi kolom sisa versi lama
	 * (meninggal, terluka, rumah_ringan, terendam, koordinat, dst.)
	 * dibuang di sini supaya skema benar-benar bersih.
	 */
	public static function sesuaikan_kolom() {
		global $wpdb;
		$t = self::tabel_laporan();

		$diizinkan = array_merge(
			array( 'id', 'kode_desa', 'user_id', 'created_at' ),
			PBM_Skema::nama_kolom()
		);

		$ada = $wpdb->get_results( "SHOW COLUMNS FROM $t" );
		if ( ! $ada ) {
			return array( 'drop' => array(), 'add' => array() );
		}

		$drop = array();
		foreach ( $ada as $c ) {
			if ( ! in_array( $c->Field, $diizinkan, true ) ) {
				$wpdb->query( "ALTER TABLE $t DROP COLUMN `{$c->Field}`" );
				$drop[] = $c->Field;
			}
		}

		/* Tambahkan kolom Excel yang belum ada (instalasi lama). */
		$sekarang = wp_list_pluck( $wpdb->get_results( "SHOW COLUMNS FROM $t" ), 'Field' );
		$add      = array();
		foreach ( PBM_Skema::kolom() as $nama => $def ) {
			if ( ! in_array( $nama, $sekarang, true ) ) {
				$wpdb->query( "ALTER TABLE $t ADD COLUMN `$nama` {$def[0]}" );
				$add[] = $nama;
			}
		}

		return array( 'drop' => $drop, 'add' => $add );
	}

	/**
	 * Impor master desa dari GeoJSON bawaan plugin.
	 *
	 * Desa baru ditambahkan; nama/kecamatan pada desa yang sudah ada ikut
	 * disesuaikan dengan isi GeoJSON terbaru, supaya pencocokan nama desa
	 * selalu mengacu ke peta yang dipakai untuk menggambar polygon.
	 *
	 * @return int Jumlah baris yang ditambah atau diperbarui.
	 */
	public static function import_desa() {
		global $wpdb;
		$table = self::tabel_desa();

		$path = PBM_PATH . self::DESA_FILE;
		if ( ! file_exists( $path ) ) {
			return 0;
		}

		$json = json_decode( file_get_contents( $path ), true );
		if ( empty( $json['features'] ) ) {
			return 0;
		}

		$ada = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, kode, nama, kecamatan FROM $table" ) as $r ) {
			$ada[ $r->kode ] = $r;
		}

		$n         = 0;
		$diperbarui = 0;
		foreach ( $json['features'] as $f ) {
			$p    = $f['properties'];
			$kode = sanitize_text_field( $p['kode'] ?? '' );
			if ( '' === $kode ) {
				continue;
			}
			$nama = sanitize_text_field( $p['nama'] ?? '' );
			$kec  = sanitize_text_field( $p['kecamatan'] ?? '' );

			if ( ! isset( $ada[ $kode ] ) ) {
				$wpdb->insert(
					$table,
					array(
						'kode'      => $kode,
						'nama'      => $nama,
						'kecamatan' => $kec,
						'sumber'    => PBM_Desa::SUMBER_PETA,
					),
					array( '%s', '%s', '%s', '%s' )
				);
				$n++;
				continue;
			}

			/* Perbarui nama/kecamatan bila berubah di peta. */
			$baris = $ada[ $kode ];
			if ( $baris->nama !== $nama || $baris->kecamatan !== $kec ) {
				$wpdb->update(
					$table,
					array(
						'nama'      => $nama,
						'kecamatan' => $kec,
						'sumber'    => PBM_Desa::SUMBER_PETA,
					),
					array( 'kode' => $kode ),
					array( '%s', '%s', '%s' ),
					array( '%s' )
				);
				$diperbarui++;
			}
		}
		return $n + $diperbarui;
	}

	/**
	 * Tandai sumber baris master desa yang belum punya penanda.
	 *
	 * Kolom `sumber` ditambahkan bersama halaman Master Desa. Baris lama
	 * ditandai dari bentuk kodenya: kode numerik berasal dari GeoJSON peta,
	 * sedangkan kode berawalan huruf adalah rekaman desa di luar polygon
	 * yang tercatat oleh impor sebelumnya. Kedua jenis baris ada sejak
	 * lama, jadi penandaan ini cukup dijalankan sekali setiap kali kolom
	 * baru muncul.
	 *
	 * @return int Jumlah baris yang ditandai ulang.
	 */
	public static function tandai_sumber() {
		global $wpdb;
		$t = self::tabel_desa();

		if ( ! $wpdb->get_var( "SHOW COLUMNS FROM $t LIKE 'sumber'" ) ) {
			return 0;
		}

		/* Kode non-numerik = hasil impor (bukan bagian peta). */
		$n = (int) $wpdb->query(
			"UPDATE $t SET sumber = '" . PBM_Desa::SUMBER_IMPOR . "'"
			. " WHERE kode NOT REGEXP '^[0-9]+$' AND sumber = '" . PBM_Desa::SUMBER_PETA . "'"
		);
		/* Kode numerik = bawaan GeoJSON. */
		$n += (int) $wpdb->query(
			"UPDATE $t SET sumber = '" . PBM_Desa::SUMBER_PETA . "'"
			. " WHERE kode REGEXP '^[0-9]+$' AND sumber = '" . PBM_Desa::SUMBER_IMPOR . "'"
		);
		return $n;
	}
}