<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API baca-saja untuk peta.
 *
 * /wp-json/pbm/v1/rekap      agregat per desa + akumulasi per jenis bencana
 * /wp-json/pbm/v1/kecamatan  agregat per kecamatan
 * /wp-json/pbm/v1/total      total kabupaten
 * /wp-json/pbm/v1/laporan    daftar laporan (kolom Excel)
 * /wp-json/pbm/v1/desa       master desa (kode, nama, kecamatan)
 */
class PBM_Rest {

	const NS = 'pbm/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	private static function filter( WP_REST_Request $r ) {
		return array(
			'dari'      => $r->get_param( 'dari' ),
			'sampai'    => $r->get_param( 'sampai' ),
			'bencana'   => $r->get_param( 'bencana' ),
			'kecamatan' => $r->get_param( 'kecamatan' ),
			'kode_desa' => $r->get_param( 'kode_desa' ),
		);
	}

	public static function routes() {
		$umum = array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
		);
		foreach ( array( 'rekap', 'kecamatan', 'total', 'laporan', 'desa' ) as $rute ) {
			register_rest_route(
				self::NS,
				'/' . $rute,
				array_merge( $umum, array( 'callback' => array( __CLASS__, $rute ) ) )
			);
		}
	}

	public static function rekap( WP_REST_Request $r ) {
		$f = self::filter( $r );
		return rest_ensure_response(
			array(
				'filter' => $f,
				'desa'   => PBM_Laporan::rekap_desa( $f ),
				'jenis'  => PBM_Laporan::rekap_jenis( $f ),
			)
		);
	}

	public static function kecamatan( WP_REST_Request $r ) {
		return rest_ensure_response( PBM_Laporan::rekap_kecamatan( self::filter( $r ) ) );
	}

	public static function total( WP_REST_Request $r ) {
		return rest_ensure_response( PBM_Laporan::total( self::filter( $r ) ) );
	}

	public static function laporan( WP_REST_Request $r ) {
		$f    = self::filter( $r );
		$lim  = min( 200, max( 1, absint( $r->get_param( 'limit' ) ?: 50 ) ) );
		$rows = PBM_Laporan::daftar( $f, $lim, 0 );
		$out  = array();
		foreach ( $rows as $row ) {
			unset( $row->user_id, $row->created_at ); // kolom internal.
			$out[] = $row;
		}
		return rest_ensure_response( $out );
	}

	public static function desa() {
		$out = array();
		foreach ( PBM_Desa::all() as $d ) {
			$out[] = array( 'kode' => $d->kode, 'nama' => $d->nama, 'kecamatan' => $d->kecamatan );
		}
		return rest_ensure_response( $out );
	}
}