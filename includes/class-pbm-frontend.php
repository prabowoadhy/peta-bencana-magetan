<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sisi publik: shortcode peta + enqueue aset (dipakai juga halaman admin).
 *
 * [peta_bencana_magetan tinggi="600" dari="" sampai="" bencana="" filter="yes"]
 */
class PBM_Frontend {

	public static function init() {
		add_shortcode( 'peta_bencana_magetan', array( __CLASS__, 'shortcode' ) );
	}

	public static function enqueue_map( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array( 'dari' => '', 'sampai' => '', 'bencana' => '', 'admin' => false )
		);

		wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
		wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );
		wp_enqueue_style( 'pbm-map', PBM_URL . 'assets/css/map.css', array( 'leaflet' ), PBM_VERSION );
		wp_enqueue_script( 'pbm-map', PBM_URL . 'assets/js/map.js', array( 'leaflet' ), PBM_VERSION, true );

		wp_localize_script(
			'pbm-map',
			'PBM',
			array(
				'rest'    => esc_url_raw( rest_url( 'pbm/v1' ) ),
				'desaUrl' => PBM_URL . 'assets/geojson/desa.geojson',
				'kecUrl'  => PBM_URL . 'assets/geojson/kecamatan.geojson',
				'dari'    => $args['dari'],
				'sampai'  => $args['sampai'],
				'bencana' => $args['bencana'],
				'isAdmin' => (bool) $args['admin'],
				'i18n'    => array(
					'muat'          => 'Memuat peta…',
					'gagal'         => 'Gagal memuat data peta.',
					'kosong'        => 'Tidak ada laporan pada periode ini.',
					'kejadian'      => 'Total kejadian',
					'desaTerdampak' => 'Desa terdampak',
					'kecamatan'     => 'Kecamatan terdampak',
					'jenis'         => 'Kejadian per jenis bencana',
					'perJenis'      => 'Per jenis bencana',
					'tanpaData'     => 'Belum ada laporan',
					'info'          => '236 desa / 18 kecamatan — klik desa untuk rincian per jenis bencana.',
				),
			)
		);
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'tinggi'  => '600',
				'dari'    => '',
				'sampai'  => '',
				'bencana' => '',
				'filter'  => 'no',
			),
			$atts,
			'peta_bencana_magetan'
		);

		self::enqueue_map( $atts );

		$tinggi = max( 300, absint( $atts['tinggi'] ) );
		$html   = '<div class="pbm-frontend">';
		$html  .= '<div id="pbm-map" class="pbm-map-publik" style="height:' . $tinggi . 'px"></div>';

		if ( 'yes' === $atts['filter'] ) {
			$html .= '<div class="pbm-filter pbm-filter-publik">'
				. '<label>Periode <input type="date" id="pbm-dari" value="' . esc_attr( $atts['dari'] ) . '"> s.d. <input type="date" id="pbm-sampai" value="' . esc_attr( $atts['sampai'] ) . '"></label>'
				. '<label>Jenis <select id="pbm-jenis"><option value="">Semua jenis</option>';
			foreach ( PBM_Laporan::jenis_terpakai() as $nama => $n ) {
				$html .= '<option value="' . esc_attr( $nama ) . '"' . selected( $atts['bencana'], $nama, false ) . '>'
					. esc_html( $nama ) . '</option>';
			}
			$html .= '</select></label>'
				. '<button type="button" class="pbm-btn" id="pbm-terapkan">Terapkan</button>'
				. '<button type="button" class="pbm-btn pbm-btn-ghost" id="pbm-reset">Reset</button>'
				. '</div>';
		}

		$html .= '<div class="pbm-stats" id="pbm-stats"></div>';
		$html .= '</div>';
		return $html;
	}
}