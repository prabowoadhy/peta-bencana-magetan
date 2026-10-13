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
				PRIMARY KEY  (id),
				UNIQUE KEY kode (kode),
				KEY kecamatan (kecamatan)
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

	/** Impor master desa dari GeoJSON bawaan plugin. */
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

		$ada = array_flip( (array) $wpdb->get_col( "SELECT kode FROM $table" ) );
		$n   = 0;
		foreach ( $json['features'] as $f ) {
			$p    = $f['properties'];
			$kode = sanitize_text_field( $p['kode'] ?? '' );
			if ( '' === $kode || isset( $ada[ $kode ] ) ) {
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'kode'      => $kode,
					'nama'      => sanitize_text_field( $p['nama'] ?? '' ),
					'kecamatan' => sanitize_text_field( $p['kecamatan'] ?? '' ),
				),
				array( '%s', '%s', '%s' )
			);
			$n++;
		}
		return $n;
	}
}