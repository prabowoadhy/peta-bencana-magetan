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
			array( 'dari' => '', 'sampai' => '', 'bencana' => '', 'kecamatan' => '', 'admin' => false )
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
				'kecamatan' => $args['kecamatan'],
				'isAdmin' => (bool) $args['admin'],
				/* Kepala tabel bencana per desa. Dibuat di server dari seluruh
				 * jenis yang ada pada data (tak disaring), supaya susunan kolom
				 * tetap walau saringan menyisakan sedikit jenis. */
				'kolomJenis' => array_keys( PBM_Laporan::jenis_terpakai() ),
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
					'info'          => number_format_i18n( PBM_Desa::count() ) . ' desa / '
						. number_format_i18n( count( PBM_Desa::kecamatan_list() ) )
						. ' kecamatan — klik desa untuk rincian per jenis bencana.',
					/* Daftar desa & tabel bencana per desa. */
					'belumAda'      => 'Belum ada laporan pada filter ini.',
					'total'         => 'Total kejadian',
					'tanpaPeta'     => 'Batas desa ini tidak tersedia di peta.',
					'memuatTabel'   => 'Memuat tabel…',
					'tabelKosong'   => 'Tidak ada desa yang cocok.',
					'labelNo'       => 'No',
					'labelDesa'     => 'Nama Desa',
					'labelKec'      => 'Nama Kecamatan',
					'labelTotal'    => 'Jumlah Total',
					'semuaKec'      => 'Semua kecamatan',
				),
			)
		);
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'tinggi'    => '600',
				'dari'      => '',
				'sampai'    => '',
				'bencana'   => '',
				'kecamatan' => '',
				'filter'    => 'no',
			),
			$atts,
			'peta_bencana_magetan'
		);

		self::enqueue_map( $atts );

		$jenis  = PBM_Laporan::jenis_terpakai();
		$kecs   = PBM_Desa::kecamatan_list();
		$tinggi = max( 300, absint( $atts['tinggi'] ) );
		$html   = '<div class="pbm-frontend">';

		/* Saringan dulu, baru peta — sama urutannya dengan halaman admin.
		 * Rentang tanggal, jenis, dan kecamatan memakai satu saringan yang
		 * sama untuk peta, kartu, grafik, dan tabel. */
		if ( 'yes' === $atts['filter'] ) {
			$html .= '<div class="pbm-filter pbm-filter-publik">'
				. '<label>Periode <input type="date" id="pbm-dari" value="' . esc_attr( $atts['dari'] ) . '"> s.d. <input type="date" id="pbm-sampai" value="' . esc_attr( $atts['sampai'] ) . '"></label>'
				. '<label>Kecamatan <select id="pbm-kecamatan"><option value="">Semua kecamatan</option>';
			foreach ( $kecs as $k ) {
				$html .= '<option value="' . esc_attr( $k ) . '"' . selected( $atts['kecamatan'], $k, false ) . '>'
					. esc_html( $k ) . '</option>';
			}
			$html .= '</select></label>'
				. '<label>Jenis <select id="pbm-jenis"><option value="">Semua jenis</option>';
			foreach ( $jenis as $nama => $n ) {
				$html .= '<option value="' . esc_attr( $nama ) . '"' . selected( $atts['bencana'], $nama, false ) . '>'
					. esc_html( $nama ) . ' (' . (int) $n . ')</option>';
			}
			$html .= '</select></label>'
				. '<button type="button" class="pbm-btn" id="pbm-terapkan">Terapkan</button>'
				. '<button type="button" class="pbm-btn pbm-btn-ghost" id="pbm-reset">Reset</button>'
				. '</div>';
		}

		$html .= '<div id="pbm-map" class="pbm-map-publik" style="height:' . $tinggi . 'px"></div>';
		$html .= '<div class="pbm-stats" id="pbm-stats"></div>';

		$html .= '<div class="pbm-stack">';

		/* Akumulasi kejadian per jenis bencana. */
		$html .= '<section class="pbm-panel pbm-panel-jenis">';
		$html .= '<div class="pbm-jenis" id="pbm-panel-jenis"></div>';
		$html .= '</section>';

		/* Tabel bencana per desa: satu baris per desa, satu kolom per jenis.
		 * Kepala kolom disusun di server supaya seluruh jenis ikut tampil,
		 * walau saringan menyisakan satu jenis saja. */
		$n_kolom = 4 + count( $jenis );
		$html   .= '<section class="pbm-panel pbm-panel-tabel">';
		$html   .= '<div class="pbm-tabel-bar">';
		$html   .= '<h3 class="pbm-panel-t">Bencana per Desa <span class="pbm-panel-k" id="pbm-tabel-jumlah"></span></h3>';
		$html   .= '<input type="search" class="pbm-cari pbm-cari-kecil" id="pbm-cari-desa" autocomplete="off" '
			. 'placeholder="Cari nama desa atau kode…" aria-label="Cari desa">';
		$html   .= '</div>';
		$html   .= '<div class="pbm-tabel-scroll">';
		$html   .= '<table class="pbm-tabel" id="pbm-tabel-desa"><thead><tr id="pbm-tabel-kepala"></tr></thead>';
		$html   .= '<tbody id="pbm-tabel-badan"><tr><td class="pbm-tabel-kosong" colspan="' . $n_kolom . '">'
			. 'Memuat tabel…' . '</td></tr></tbody></table>';
		$html   .= '</div></section>';

		$html .= '</div>';

		$html .= '<div class="pbm-rekap" id="pbm-rekap"></div>';
		$html .= '</div>';
		return $html;
	}
}