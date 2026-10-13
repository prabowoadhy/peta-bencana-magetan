<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Akses tabel master desa (batas wilayah dari GeoJSON QGIS). */
class PBM_Desa {

	public static function table() {
		return PBM_Activator::tabel_desa();
	}

	/** @return object[] */
	public static function all( $kecamatan = '' ) {
		global $wpdb;
		$t = self::table();
		if ( '' !== $kecamatan ) {
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM $t WHERE kecamatan = %s ORDER BY nama", $kecamatan )
			);
		}
		return $wpdb->get_results( "SELECT * FROM $t ORDER BY kecamatan, nama" );
	}

	/** @return string[] */
	public static function kecamatan_list() {
		global $wpdb;
		return $wpdb->get_col( 'SELECT DISTINCT kecamatan FROM ' . self::table() . ' ORDER BY kecamatan' );
	}

	/** @return object|null */
	public static function find( $kode ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE kode = %s', $kode )
		);
	}

	/** Tambahkan desa bila belum ada (dipakai impor untuk desa di luar polygon). */
	public static function pastikan( $kode, $nama, $kecamatan ) {
		global $wpdb;
		if ( self::find( $kode ) ) {
			return $kode;
		}
		$wpdb->insert(
			self::table(),
			array(
				'kode'      => sanitize_text_field( $kode ),
				'nama'      => sanitize_text_field( $nama ),
				'kecamatan' => sanitize_text_field( $kecamatan ),
			),
			array( '%s', '%s', '%s' )
		);
		return $kode;
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}
}