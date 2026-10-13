<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Halaman admin: Peta, Data (CRUD), dan Impor Excel.
 * Semua kolom tabel/form mengikuti PBM_Skema (kolom Excel).
 */
class PBM_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_pbm_simpan', array( __CLASS__, 'simpan' ) );
		add_action( 'admin_post_pbm_hapus', array( __CLASS__, 'hapus' ) );
		add_action( 'admin_post_pbm_export', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_pbm_hapus_desa', array( __CLASS__, 'hapus_desa' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_menu_page(
			'Peta Bencana Magetan', 'Peta Bencana', 'manage_options', 'pbm-peta',
			array( __CLASS__, 'halaman_peta' ), 'dashicons-chart-area', 25
		);
		add_submenu_page( 'pbm-peta', 'Peta', 'Peta', 'manage_options', 'pbm-peta', array( __CLASS__, 'halaman_peta' ) );
		add_submenu_page( 'pbm-peta', 'Data Bencana', 'Data Bencana', 'manage_options', 'pbm-data', array( __CLASS__, 'halaman_data' ) );
		add_submenu_page( 'pbm-peta', 'Master Desa', 'Master Desa', 'manage_options', 'pbm-desa', array( __CLASS__, 'halaman_desa' ) );
		add_submenu_page( 'pbm-peta', 'Impor Excel', 'Impor Excel', 'manage_options', 'pbm-import', array( __CLASS__, 'halaman_import' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'pbm-' ) ) {
			return;
		}
		PBM_Frontend::enqueue_map( array( 'admin' => true ) );
		wp_enqueue_style( 'pbm-admin', PBM_URL . 'assets/css/admin.css', array(), PBM_VERSION );
		wp_enqueue_script( 'pbm-admin', PBM_URL . 'assets/js/admin.js', array(), PBM_VERSION, true );

		$desa = array();
		foreach ( PBM_Desa::all() as $d ) {
			$desa[] = array( 'kode' => $d->kode, 'nama' => $d->nama, 'kecamatan' => $d->kecamatan );
		}
		wp_localize_script( 'pbm-admin', 'PBM_DESA', $desa );
	}

	/* -------------------------------------------------------------- PETA */

	public static function halaman_peta() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$total    = PBM_Laporan::total();
		$terakhir = PBM_Laporan::terakhir();
		$jenis    = PBM_Laporan::jenis_terpakai();

		echo '<div class="wrap pbm-wrap">';
		echo '<h1>Peta Bencana Kabupaten Magetan</h1>';
		echo '<p class="pbm-sub">Batas desa: ekspor QGIS (236 desa / 18 kecamatan). Data: register bencana (kolom sesuai Excel). '
			. ( $terakhir ? 'Laporan terakhir ' . esc_html( date_i18n( 'd M Y', strtotime( $terakhir ) ) ) . '.' : 'Belum ada laporan.' )
			. '</p>';

		self::kartu( $total, $jenis );

		echo '<div class="pbm-filter">';
		echo '<label>Periode <input type="date" id="pbm-dari"> s.d. <input type="date" id="pbm-sampai"></label>';
		echo '<label>Jenis <select id="pbm-jenis"><option value="">Semua jenis</option>';
		foreach ( $jenis as $nama => $n ) {
			echo '<option value="' . esc_attr( $nama ) . '">' . esc_html( $nama ) . ' (' . (int) $n . ')</option>';
		}
		echo '</select></label>';
		echo '<button type="button" class="button button-primary" id="pbm-terapkan">Terapkan</button>';
		echo '<button type="button" class="button" id="pbm-reset">Reset</button>';
		echo '</div>';

		echo '<div id="pbm-map" class="pbm-map-admin"></div>';
		echo '<div class="pbm-rekap" id="pbm-rekap"></div>';
		echo '</div>';
	}

	/** Kartu statistik + akumulasi per jenis (semua dari kolom Excel). */
	private static function kartu( $t, $jenis ) {
		$kartu = array(
			'Kejadian'            => $t->kejadian,
			'Desa terdampak'      => $t->desa_terdampak . ' / ' . PBM_Desa::count(),
			'Kecamatan terdampak' => $t->kecamatan_terdampak,
			'Jenis bencana'       => count( $jenis ),
		);
		echo '<div class="pbm-cards">';
		foreach ( $kartu as $label => $nilai ) {
			echo '<div class="pbm-card"><span class="pbm-card-n">' . esc_html( $nilai ) . '</span><span class="pbm-card-l">' . esc_html( $label ) . '</span></div>';
		}
		echo '</div>';

		if ( $jenis ) {
			echo '<div class="pbm-jenis-box"><b>Kejadian per jenis bencana</b><ul>';
			foreach ( $jenis as $nama => $n ) {
				echo '<li><span>' . esc_html( $nama ) . '</span><b>' . (int) $n . '</b></li>';
			}
			echo '</ul></div>';
		}
	}

	/* -------------------------------------------------------------- DATA */

	public static function halaman_data() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$edit_id = absint( $_GET['edit'] ?? 0 );
		$row     = $edit_id ? PBM_Laporan::find( $edit_id ) : null;
		$notice  = sanitize_text_field( $_GET['pbm_notice'] ?? '' );

		echo '<div class="wrap pbm-wrap">';
		echo '<h1>' . ( $row ? 'Edit Data Bencana' : 'Tambah Data Bencana' ) . '</h1>';

		if ( 'tersimpan' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>Data tersimpan.</p></div>';
		} elseif ( 'dihapus' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>Data dihapus.</p></div>';
		} elseif ( 'gagal' === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>Data gagal disimpan. Periksa kolom Bencana &amp; Waktu Laporan.</p></div>';
		}

		self::form( $row );
		self::tabel_data();
		echo '</div>';
	}

	private static function form( $row ) {
		$kecs    = PBM_Desa::kecamatan_list();
		$sel_kec = $row ? ( PBM_Desa::find( $row->kode_desa )->kecamatan ?? '' ) : '';
		$label   = PBM_Skema::label( 'bencana' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pbm-form">';
		echo '<input type="hidden" name="action" value="pbm_simpan">';
		wp_nonce_field( 'pbm_simpan' );
		if ( $row ) {
			echo '<input type="hidden" name="id" value="' . esc_attr( $row->id ) . '">';
		}

		echo '<div class="pbm-form-grid">';
		echo '<label>No <input type="text" name="no" value="' . esc_attr( $row->no ?? '' ) . '"></label>';
		echo '<label>No Nota Dinas <input type="text" name="nodin" value="' . esc_attr( $row->nodin ?? '' ) . '"></label>';
		echo '<label>' . esc_html( $label ) . ' <input type="text" name="bencana" list="pbm-bencana-list" required value="' . esc_attr( $row->bencana ?? '' ) . '">';
		echo '<datalist id="pbm-bencana-list">';
		foreach ( array_keys( PBM_Laporan::jenis_terpakai() ) as $n ) {
			echo '<option value="' . esc_attr( $n ) . '"></option>';
		}
		echo '</datalist></label>';
		echo '<label>Dampak Bencana <input type="text" name="dampak_bencana" value="' . esc_attr( $row->dampak_bencana ?? '' ) . '"></label>';
		echo '</div>';

		echo '<div class="pbm-form-grid">';
		echo '<label>Waktu Laporan <input type="date" name="waktu_laporan" required value="' . esc_attr( $row->waktu_laporan ?? current_time( 'Y-m-d' ) ) . '"></label>';
		echo '<label>Jam Lapor <input type="text" name="jam_lapor" value="' . esc_attr( $row->jam_lapor ?? '' ) . '" placeholder="06.25"></label>';
		echo '<label>Waktu Kejadian <input type="date" name="waktu_kejadian" value="' . esc_attr( $row->waktu_kejadian ?? '' ) . '"></label>';
		echo '<label>Jam Kejadian <input type="text" name="jam_kejadian" value="' . esc_attr( $row->jam_kejadian ?? '' ) . '" placeholder="23.00"></label>';
		echo '</div>';

		echo '<div class="pbm-form-grid">';
		echo '<label>Kecamatan <select name="kecamatan" id="pbm-f-kec" required><option value="">— pilih —</option>';
		foreach ( $kecs as $k ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $sel_kec, $k, false ) . '>' . esc_html( $k ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>Desa <select name="kode_desa" id="pbm-f-desa" required><option value="">— pilih kecamatan dulu —</option>';
		foreach ( PBM_Desa::all( $sel_kec ) as $d ) {
			echo '<option value="' . esc_attr( $d->kode ) . '"' . selected( $row->kode_desa ?? '', $d->kode, false ) . '>' . esc_html( $d->nama ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>Lokasi Bencana <input type="text" name="lokasi" value="' . esc_attr( $row->lokasi ?? '' ) . '"></label>';
		echo '<label>Lintang <input type="text" name="lat" value="' . esc_attr( $row->lat ?? '' ) . '" placeholder="-7.6534"></label>';
		echo '<label>Bujur <input type="text" name="lon" value="' . esc_attr( $row->lon ?? '' ) . '" placeholder="111.3473"></label>';
		echo '</div>';

		echo '<fieldset class="pbm-fs"><legend>Kolom DAMPAK (sesuai Excel)</legend><div class="pbm-form-grid">';
		foreach ( array(
			'dampak_manusia'   => 'Manusia',
			'dampak_hewan'     => 'Hewan',
			'dampak_rumah'     => 'Rumah',
			'dampak_harta'     => 'Harta',
			'dampak_jalan'     => 'Jalan',
			'dampak_fasilitas' => 'Fasilitas Umum Bukan Jalan',
			'dampak_jembatan'  => 'Jembatan',
			'nilai_kerusakan'  => 'Nilai Kerusakan',
		) as $nama => $teks ) {
			echo '<label>' . esc_html( $teks ) . ' <input type="text" name="' . esc_attr( $nama ) . '" value="' . esc_attr( $row->$nama ?? '' ) . '"></label>';
		}
		echo '</div></fieldset>';

		echo '<label>Keterangan <textarea name="keterangan" rows="4">' . esc_textarea( $row->keterangan ?? '' ) . '</textarea></label>';

		echo '<div class="pbm-form-aksi">';
		echo '<button type="submit" class="button button-primary">' . ( $row ? 'Simpan Perubahan' : 'Simpan Data' ) . '</button>';
		if ( $row ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=pbm-data' ) ) . '">Batal</a>';
		}
		echo '</div></form>';
	}

	/* -------------------------------------------------------- header urut */

	/**
	 * Kolom yang sedang diurutkan, sudah lewat whitelist.
	 *
	 * @param string[] $boleh Kolom yang boleh dipakai halaman ini.
	 * @param string   $awal  Nilai bila URL belum menentukan kolom.
	 * @return string
	 */
	private static function urut_aktif( array $boleh, $awal ) {
		$urut = sanitize_text_field( wp_unslash( $_GET['urut'] ?? '' ) );
		return in_array( $urut, $boleh, true ) ? $urut : $awal;
	}

	/**
	 * Arah pengurutan aktif, hanya 'naik' atau 'turun'.
	 *
	 * Saat URL belum menyebut arah, arah bawaan mengikuti kolomnya: kolom
	 * tanggal dibuka dari yang terbaru supaya sejatif dengan tampilan
	 * sebelum ada sortir.
	 *
	 * @param string   $kolom     Kolom yang sedang diurutkan.
	 * @param string[] $awal_desc Kolom yang bawaannya turun (terbaru dulu).
	 * @return string
	 */
	private static function arah_aktif( $kolom, array $awal_desc = array() ) {
		$arah = sanitize_text_field( wp_unslash( $_GET['arah'] ?? '' ) );
		if ( 'naik' === $arah || 'turun' === $arah ) {
			return $arah;
		}
		return in_array( $kolom, $awal_desc, true ) ? 'turun' : 'naik';
	}

	/**
	 * URL untuk mengurutkan satu kolom.
	 *
	 * Parameter filter ikut dibawa agar pencarian/halaman tidak hilang saat
	 * sortir diklik, sedangkan `paged` dibuang karena pengurutan baru selalu
	 * dimulai dari halaman pertama.
	 *
	 * @param string   $kolom Kolom tujuan.
	 * @param string   $arah  Arah tujuan.
	 * @param string[] $buang Parameter yang tidak ikut dibawa.
	 * @return string
	 */
	private static function url_urut( $kolom, $arah, array $buang = array() ) {
		$ambil = array();
		foreach ( $_GET as $k => $v ) {
			$k = preg_replace( '/[^a-z0-9_]/i', '', (string) $k );
			if ( '' === $k || in_array( $k, $buang, true ) || ! is_scalar( $v ) ) {
				continue;
			}
			$ambil[ $k ] = sanitize_text_field( wp_unslash( $v ) );
		}
		$ambil['urut'] = $kolom;
		$ambil['arah'] = $arah;
		unset( $ambil['paged'] );
		return add_query_arg( $ambil, admin_url( 'admin.php' ) );
	}

	/**
	 * Satu sel header tabel yang bisa diklik untuk mengurutkan.
	 *
	 * @param string $kolom Kode kolom, dikirim lewat parameter `urut`.
	 * @param string $label Tampilan.
	 * @param string $urut  Kolom yang sedang aktif.
	 * @param string $arah  Arah yang sedang aktif.
	 * @param string $awal  Arah pertama saat kolom ini belum pernah diklik.
	 */
	private static function th_urut( $kolom, $label, $urut, $arah, $awal = 'naik' ) {
		$aktif = ( $urut === $kolom );
		$tuju  = $aktif ? ( 'naik' === $arah ? 'turun' : 'naik' ) : $awal;
		$url   = self::url_urut( $kolom, $tuju, array( 'urut', 'arah' ) );

		printf(
			'<th scope="col" class="sortable-column%s%s" aria-sort="%s">'
			. '<a href="%s"><span>%s</span><span class="pbm-sort-ind" aria-hidden="true"></span></a></th>',
			$aktif ? ' sorted' : '',
			$aktif && 'naik' === $arah ? ' asc' : ( $aktif ? ' desc' : '' ),
			$aktif ? ( 'naik' === $arah ? 'ascending' : 'descending' ) : 'none',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/** Header biasa untuk kolom yang tidak bisa diurutkan (mis. Aksi). */
	private static function th_biasa( $label ) {
		printf( '<th scope="col" class="sortable-column-none">%s</th>', esc_html( $label ) );
	}

	/**
	 * Sembunyikan kolom sortir aktif di dalam form filter.
	 *
	 * Form memakai method GET, jadi tanpa input tersembunyi urutan akan
	 * hilang begitu petugas menekan tombol Filter.
	 *
	 * @param string $urut Kolom aktif.
	 * @param string $arah Arah aktif.
	 */
	private static function input_urut_tersembunyi( $urut, $arah ) {
		printf(
			'<input type="hidden" name="urut" value="%s"><input type="hidden" name="arah" value="%s">',
			esc_attr( $urut ),
			esc_attr( $arah )
		);
	}

	private static function tabel_data() {
		$f = array(
			'dari'      => sanitize_text_field( $_GET['dari'] ?? '' ),
			'sampai'    => sanitize_text_field( $_GET['sampai'] ?? '' ),
			'bencana'   => sanitize_text_field( $_GET['bencana'] ?? '' ),
			'kecamatan' => sanitize_text_field( $_GET['kecamatan'] ?? '' ),
			'cari'      => sanitize_text_field( $_GET['cari'] ?? '' ),
		);
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per    = 25;
		$jumlah = PBM_Laporan::hitung( $f );

		$kolom_tampil = array( 'no', 'nodin', 'bencana', 'dampak_bencana', 'lokasi', 'lat', 'lon', 'desa', 'kecamatan', 'waktu_laporan', 'jam_lapor', 'waktu_kejadian', 'jam_kejadian', 'dampak_manusia', 'dampak_hewan', 'dampak_rumah', 'dampak_harta', 'dampak_jalan', 'dampak_fasilitas', 'dampak_jembatan', 'nilai_kerusakan', 'keterangan' );
		$urut = self::urut_aktif( $kolom_tampil, 'waktu_laporan' );
		/* Kolom tanggal dibuka dari terbaru dulu — sama seperti sebelum ada sortir. */
		$arah = self::arah_aktif( $urut, array( 'waktu_laporan', 'waktu_kejadian' ) );
		$rows = PBM_Laporan::daftar( $f, $per, ( $paged - 1 ) * $per, $urut, $arah );

		echo '<h2>Daftar Data <span class="pbm-count">(' . number_format_i18n( $jumlah ) . ' baris)</span></h2>';

		echo '<form method="get" class="pbm-filter-form">';
		echo '<input type="hidden" name="page" value="pbm-data">';
		self::input_urut_tersembunyi( $urut, $arah );
		echo '<label>Cari <input type="search" name="cari" value="' . esc_attr( $f['cari'] ) . '" placeholder="desa / lokasi / nodin"></label>';
		echo '<label>Dari <input type="date" name="dari" value="' . esc_attr( $f['dari'] ) . '"></label>';
		echo '<label>Sampai <input type="date" name="sampai" value="' . esc_attr( $f['sampai'] ) . '"></label>';
		echo '<label>Jenis <select name="bencana"><option value="">Semua</option>';
		foreach ( PBM_Laporan::jenis_terpakai() as $nama => $n ) {
			echo '<option value="' . esc_attr( $nama ) . '"' . selected( $f['bencana'], $nama, false ) . '>' . esc_html( $nama ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>Kecamatan <select name="kecamatan"><option value="">Semua</option>';
		foreach ( PBM_Desa::kecamatan_list() as $k ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['kecamatan'], $k, false ) . '>' . esc_html( $k ) . '</option>';
		}
		echo '</select></label>';
		echo '<button class="button">Filter</button>';
		echo '</form>';

		echo '<div class="pbm-scroll"><table class="widefat striped pbm-table"><thead><tr>';
		foreach ( $kolom_tampil as $k ) {
			self::th_urut(
				$k,
				PBM_Skema::label( $k ),
				$urut,
				$arah,
				in_array( $k, array( 'waktu_laporan', 'waktu_kejadian' ), true ) ? 'turun' : 'naik'
			);
		}
		self::th_biasa( 'Aksi' );
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="' . ( count( $kolom_tampil ) + 1 ) . '">Belum ada data.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$edit  = admin_url( 'admin.php?page=pbm-data&edit=' . $r->id );
			$hapus = wp_nonce_url( admin_url( 'admin-post.php?action=pbm_hapus&id=' . $r->id ), 'pbm_hapus_' . $r->id );
			echo '<tr>';
			foreach ( $kolom_tampil as $k ) {
				$v = (string) ( $r->$k ?? '' );
				$teks = ( mb_strlen( $v ) > 60 ) ? mb_substr( $v, 0, 60 ) . '…' : $v;
				echo '<td>' . esc_html( $teks ) . '</td>';
			}
			echo '<td><a href="' . esc_url( $edit ) . '">Edit</a> | <a href="' . esc_url( $hapus ) . '" class="pbm-hapus">Hapus</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';

		$total_hal = (int) ceil( $jumlah / $per );
		if ( $total_hal > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			for ( $i = 1; $i <= $total_hal; $i++ ) {
				$url = add_query_arg(
					array_merge( $f, array( 'page' => 'pbm-data', 'paged' => $i, 'urut' => $urut, 'arah' => $arah ) ),
					admin_url( 'admin.php' )
				);
				echo ( $i === $paged )
					? '<span class="paging-input current">' . $i . '</span> '
					: '<a class="page-numbers" href="' . esc_url( $url ) . '">' . $i . '</a> ';
			}
			echo '</div></div>';
		}

		$export = wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action'    => 'pbm_export',
						'dari'      => $f['dari'],
						'sampai'    => $f['sampai'],
						'bencana'   => $f['bencana'],
						'kecamatan' => $f['kecamatan'],
					)
				),
				admin_url( 'admin-post.php' )
			),
			'pbm_export'
		);
		echo '<p><a class="button" href="' . esc_url( $export ) . '">Ekspor CSV (kolom sesuai Excel, filter aktif)</a></p>';
	}

	/* ------------------------------------------------------------ IMPOR */

	/* ------------------------------------------------- halaman master desa */

	/** @return int[] Peta desa bawaan, sekali muat untuk seluruh halaman. */
	private static function daftar_peta() {
		static $cache = null;
		if ( null === $cache ) {
			$cache = PBM_Desa::all( '', PBM_Desa::SUMBER_PETA );
		}
		return $cache;
	}

	/** @return array<string,int> Jumlah laporan per kode desa, sekali muat. */
	private static function hitung_laporan() {
		static $cache = null;
		if ( null === $cache ) {
			$cache = PBM_Desa::laporan_per_desa();
		}
		return $cache;
	}

	/** Halaman Master Desa: daftar desa terdaftar + alat cek ejaan. */
	public static function halaman_desa() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = sanitize_text_field( wp_unslash( $_GET['pbm_notice'] ?? '' ) );
		$impor  = PBM_Desa::count_sumber( PBM_Desa::SUMBER_IMPOR );

		echo '<div class="wrap pbm-wrap">';
		echo '<h1>Master Desa</h1>';
		printf(
			'<p class="pbm-sub">Daftar desa yang dikenali sistem, diambil dari batas wilayah peta (ekspor QGIS). '
			. '<b>%d desa bawaan tidak bisa dihapus</b> karena menjadi acuan penggambaran polygon. '
			. 'Sebelum mengimpor Excel, pastikan nama desa sudah terdaftar di sini — '
			. 'ejaan yang salah justru membuat sistem mencatat desa baru di luar peta.</p>',
			(int) PBM_Desa::count_sumber( PBM_Desa::SUMBER_PETA )
		);

		self::notice_desa( $notice );
		self::kartu_desa();
		self::box_typo();
		self::cek_desa();
		self::tabel_master_desa();

		echo '</div>';
	}

	/** Notifikasi hasil aksi hapus/perbaikan. */
	private static function notice_desa( $notice ) {
		if ( 'desa_dihapus' === $notice ) {
			$pindah = absint( $_GET['pindah'] ?? 0 );
			printf(
				'<div class="notice notice-success is-dismissible"><p>Desa dihapus%s.</p></div>',
				$pindah ? sprintf( ' dan <b>%d laporan</b> dipindahkan ke desa tujuan', $pindah ) : ''
			);
		} elseif ( 'desa_gagal' === $notice ) {
			echo '<div class="notice notice-error"><p><b>Desa bawaan peta tidak bisa dihapus.</b> '
				. 'Hanya desa yang tercatat lewat impor yang boleh dihapus.</p></div>';
		} elseif ( 'desa_tujuan' === $notice ) {
			echo '<div class="notice notice-error"><p>Desa tujuan tidak valid. '
				. 'Pilih desa bawaan peta sebagai tujuan pemindahan laporan.</p></div>';
		} elseif ( 'desa_punya_laporan' === $notice ) {
			echo '<div class="notice notice-error"><p>Desa ini masih mempunyai laporan. '
				. 'Pindahkan laporannya ke desa yang benar lebih dulu agar tidak ada data yang hilang.</p></div>';
		}
	}

	/** Ringkasan jumlah desa menurut sumber. */
	private static function kartu_desa() {
		$kartu = array(
			/* label => [jumlah, kelas tambahan bila perlu ditonjolkan] */
			'Total desa'     => array( PBM_Desa::count(), '' ),
			'Bawaan peta'    => array( PBM_Desa::count_sumber( PBM_Desa::SUMBER_PETA ), '' ),
			'Ditambah impor' => array(
				PBM_Desa::count_sumber( PBM_Desa::SUMBER_IMPOR ),
				PBM_Desa::count_sumber( PBM_Desa::SUMBER_IMPOR ) ? ' pbm-card-hitung' : '',
			),
			'Kecamatan'      => array( count( PBM_Desa::kecamatan_list() ), '' ),
		);

		echo '<div class="pbm-cards">';
		foreach ( $kartu as $label => $k ) {
			printf(
				'<div class="pbm-card%s"><span class="pbm-card-n">%d</span><span class="pbm-card-l">%s</span></div>',
				esc_attr( $k[1] ),
				(int) $k[0],
				esc_html( $label )
			);
		}
		echo '</div>';
	}

	/**
	 * Desa impor yang namanya mirip desa peta — kandidat salah ejaan.
	 *
	 * Inilah akar masalah yang ingin dicegah: satu huruf berbeda membuat
	 * impor mencatat desa baru padahal aslinya sudah ada di peta.
	 */
	private static function box_typo() {
		$kandidat = PBM_Desa::kandidat_typo();
		if ( ! $kandidat ) {
			return;
		}

		echo '<h2>Kemungkinan salah ejaan <span class="pbm-count">' . count( $kandidat ) . ' desa</span></h2>';
		echo '<div class="pbm-box pbm-box-warn">';
		echo '<p>Desa berikut <b>bukan bagian dari peta</b>, tetapi namanya sangat mirip desa bawaan. '
			. 'Kemungkinan besar ejaan yang salah saat impor. Perbaiki dengan memindahkan laporannya '
			. 'ke desa yang benar, lalu desa salahnya dihapus.</p>';
		echo '<div class="pbm-scroll"><table class="widefat striped pbm-table"><thead><tr>';
		echo '<th>Desa impor</th><th>Kecamatan</th><th>Mirip dengan</th><th>Kecamatan</th>'
			. '<th>Kemiripan</th><th>Laporan</th><th>Perbaikan</th>';
		echo '</tr></thead><tbody>';

		foreach ( $kandidat as $k ) {
			$impor = $k->impor;
			$peta  = $k->peta;
			$n     = self::hitung_laporan()[ $impor->kode ] ?? 0;

			echo '<tr>';
			echo '<td><b>' . esc_html( $impor->nama ) . '</b> <span class="pbm-muted">' . esc_html( $impor->kode ) . '</span></td>';
			echo '<td>' . esc_html( $impor->kecamatan ) . '</td>';
			echo '<td>' . esc_html( $peta->nama ) . ' <span class="pbm-muted">' . esc_html( $peta->kode ) . '</span></td>';
			echo '<td>' . esc_html( $peta->kecamatan ) . '</td>';
			echo '<td><span class="pbm-tag pbm-tag-cek">' . (int) round( $k->skor * 100 ) . '%</span></td>';
			echo '<td>' . ( $n ? '<b>' . (int) $n . '</b>' : '<span class="pbm-muted">0</span>' ) . '</td>';
			echo '<td>';
			self::form_hapus_desa( $impor, $n );
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div></div>';
	}

	/**
	 * Formulir hapus desa impor — dengan pemindahan laporan bila ada.
	 *
	 * @param object $impor Baris desa impor.
	 * @param int    $n     Jumlah laporan pada desa tersebut.
	 */
	private static function form_hapus_desa( $impor, $n ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pbm-form-desa">';
		echo '<input type="hidden" name="action" value="pbm_hapus_desa">';
		echo '<input type="hidden" name="kode" value="' . esc_attr( $impor->kode ) . '">';
		wp_nonce_field( 'pbm_hapus_desa_' . $impor->kode );

		if ( $n > 0 ) {
			$peta = self::daftar_peta();
			/* Desa di kecamatan sama didahulukan, biasanya itu yang benar. */
			usort(
				$peta,
				static function ( $a, $b ) use ( $impor ) {
					$sa  = ( $a->kecamatan === $impor->kecamatan ) ? 0 : 1;
					$sb  = ( $b->kecamatan === $impor->kecamatan ) ? 0 : 1;
					return ( $sa <=> $sb ) ?: strcmp( $a->nama, $b->nama );
				}
			);
			echo '<select name="pindah_ke" class="pbm-sel-desa">';
			foreach ( $peta as $d ) {
				echo '<option value="' . esc_attr( $d->kode ) . '">'
					. esc_html( $d->nama . ' — ' . $d->kecamatan ) . '</option>';
			}
			echo '</select>';
			echo '<button type="submit" class="button button-primary">Pindahkan &amp; hapus</button>';
		} else {
			echo '<input type="hidden" name="pindah_ke" value="">';
			echo '<button type="submit" class="button">Hapus</button>';
		}
		echo '</form>';
	}

	/** Alat cek: apakah nama desa di Excel sudah terdaftar? */
	private static function cek_desa() {
		$cari = sanitize_text_field( wp_unslash( $_GET['cek_desa'] ?? '' ) );
		$kec  = sanitize_text_field( wp_unslash( $_GET['cek_kec'] ?? '' ) );

		echo '<h2>Cek nama desa sebelum impor</h2>';
		echo '<div class="pbm-box">';
		echo '<p class="pbm-sub">Ketik nama persis seperti tertulis di berkas Excel. '
			. 'Ini langkah paling cepat untuk mencegah tercatatnya desa baru yang sebenarnya salah eja.</p>';
		echo '<form method="get" class="pbm-cari-desa">';
		echo '<input type="hidden" name="page" value="pbm-desa">';
		echo '<label><span>Nama desa</span> <input type="search" name="cek_desa" value="' . esc_attr( $cari )
			. '" placeholder="mis. Mategal" autocomplete="off"></label>';
		echo '<label><span>Kecamatan</span> <select name="cek_kec"><option value="">Semua kecamatan</option>';
		foreach ( PBM_Desa::kecamatan_list() as $k ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $kec, $k, false ) . '>'
				. esc_html( $k ) . '</option>';
		}
		echo '</select></label>';
		echo '<button class="button button-primary">Cek</button>';
		echo '</form>';

		if ( '' !== $cari ) {
			self::hasil_cek( $cari, $kec );
		}
		echo '</div>';
	}

	/** Hasil pengecekan: ditemukan (beserta sumbernya) atau tidak + saran. */
	private static function hasil_cek( $cari, $kec ) {
		$metode = $skor = null;
		$hasil  = PBM_Import::cari_desa( $kec, $cari, $metode, $skor );

		if ( ! $hasil ) {
			echo '<div class="pbm-cek pbm-cek-gagal">';
			printf(
				'<p><code>%s</code> <b>tidak ditemukan</b> di master desa. Bila tetap diimpor, '
				. 'sistem akan mencatatnya sebagai <b>desa baru di luar peta</b>.</p>',
				esc_html( $cari )
			);

			$saran = PBM_Desa::saran( $cari, $kec, 5, 0.5 );
			if ( $saran ) {
				echo '<p>Paling mendekati:</p><ul>';
				foreach ( $saran as $s ) {
					printf(
						'<li><b>%s</b> — %s <span class="pbm-tag pbm-tag-cek">%d%% mirip</span></li>',
						esc_html( $s->desa->nama ),
						esc_html( $s->desa->kecamatan ),
						(int) round( $s->skor * 100 )
					);
				}
				echo '</ul>';
			} else {
				echo '<p>Tidak ada nama di peta yang mendekati — kemungkinan memang desa baru, '
					. 'bukan salah eja.</p>';
			}
			echo '<p class="pbm-muted">Periksa kembali ejaan di Excel, lalu unggah ulang agar pratinjau bersih.</p>';
			echo '</div>';
			return;
		}

		$d      = PBM_Desa::find( $hasil['kode'] );
		$ada    = PBM_Desa::SUMBER_PETA === ( $d->sumber ?? PBM_Desa::SUMBER_PETA );
		$n      = self::hitung_laporan()[ $d->kode ] ?? 0;
		$tag    = $ada ? 'pbm-tag-ok' : 'pbm-tag-warn';
		$label  = $ada ? 'bawaan peta' : 'ditambah lewat impor';

		echo '<div class="pbm-cek pbm-cek-ok">';
		printf(
			'<p><code>%s</code> — %s</p>',
			esc_html( $d->nama ),
			esc_html( $d->kecamatan )
		);
		echo '<p><span class="pbm-tag ' . $tag . '">' . esc_html( $label ) . '</span> ';
		echo '<span class="pbm-tag pbm-tag-dup">' . (int) $n . ' laporan</span> ';
		echo '<span class="pbm-muted">kode ' . esc_html( $d->kode ) . '</span></p>';

		if ( $ada ) {
			echo '<p class="pbm-muted">Nama aman dipakai — impor tidak akan menambahkan desa baru.</p>';
		} else {
			echo '<p>Desa ini <b>bukan bagian dari peta</b>, jadi tidak akan tampil di peta choropleth. '
				. 'Bila seharusnya ada di peta, perbaiki ejaannya di Excel.</p>';
		}

		/* Kecocokan otomatis yang kurang meyakinkan tetap dilaporkan. */
		if ( 'persis' !== $metode ) {
			printf(
				'<p class="pbm-muted">Dicocokkan dengan metode <b>%s</b>%s — periksa kembali bila ragu.</p>',
				esc_html( self::label_metode( $metode ) ),
				( null !== $skor ) ? sprintf( ' (%d%% mirip)', (int) round( $skor * 100 ) ) : ''
			);
		}
		echo '</div>';
	}

	/** Tabel seluruh desa terdaftar, dengan pencarian dan filter. */
	private static function tabel_master_desa() {
		$q       = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		$kec     = sanitize_text_field( wp_unslash( $_GET['kec'] ?? '' ) );
		$sumber  = sanitize_text_field( wp_unslash( $_GET['sumber'] ?? '' ) );
		$perbaiki = sanitize_text_field( wp_unslash( $_GET['perbaiki'] ?? '' ) );

		/* Sumber & jumlah laporan adalah kolom turunan, jadi disaring di sini. */
		$boleh = array( 'kode', 'nama', 'kecamatan', 'sumber', 'laporan' );
		$urut  = self::urut_aktif( $boleh, 'kecamatan' );
		$arah  = self::arah_aktif( $urut );

		echo '<h2>Seluruh desa terdaftar <span class="pbm-count">' . PBM_Desa::count() . '</span></h2>';

		if ( '' !== $perbaiki ) {
			self::panel_perbaikan( $perbaiki );
		}

		$semua = PBM_Desa::all( $kec, $sumber );
		if ( '' !== $q ) {
			$semua = array_values(
				array_filter(
					$semua,
					static function ( $d ) use ( $q ) {
						return false !== stripos( $d->nama, $q )
							|| false !== stripos( $d->kecamatan, $q )
							|| 0 === stripos( $d->kode, $q );
					}
				)
			);
		}

		$lapor = self::hitung_laporan();

		/* Sortir di PHP: seluruh master sudah termuat, tak perlu query lagi. */
		usort(
			$semua,
			static function ( $a, $b ) use ( $urut, $arah, $lapor ) {
				if ( 'laporan' === $urut ) {
					$hasil = ( $lapor[ $a->kode ] ?? 0 ) <=> ( $lapor[ $b->kode ] ?? 0 );
				} else {
					$hasil = strcasecmp( (string) ( $a->$urut ?? '' ), (string) ( $b->$urut ?? '' ) );
				}
				/* Baris kembar disusun tetap: kecamatan lalu nama. */
				if ( 0 === $hasil ) {
					$hasil = strcasecmp( $a->kecamatan, $b->kecamatan ) ?: strcasecmp( $a->nama, $b->nama );
				}
				return 'turun' === $arah ? -$hasil : $hasil;
			}
		);

		echo '<form method="get" class="pbm-filter-form">';
		echo '<input type="hidden" name="page" value="pbm-desa">';
		self::input_urut_tersembunyi( $urut, $arah );
		echo '<input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="Cari nama / kode desa">';
		echo '<select name="kec"><option value="">Semua kecamatan</option>';
		foreach ( PBM_Desa::kecamatan_list() as $k ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $kec, $k, false ) . '>'
				. esc_html( $k ) . '</option>';
		}
		echo '</select>';
		echo '<select name="sumber"><option value="">Semua sumber</option>';
		echo '<option value="' . esc_attr( PBM_Desa::SUMBER_PETA ) . '"' . selected( $sumber, 'peta', false ) . '>Bawaan peta</option>';
		echo '<option value="' . esc_attr( PBM_Desa::SUMBER_IMPOR ) . '"' . selected( $sumber, 'impor', false ) . '>Ditambah impor</option>';
		echo '</select>';
		echo '<button class="button">Terapkan</button>';
		echo '</form>';

		echo '<div class="pbm-scroll"><table class="widefat striped pbm-table"><thead><tr>';
		$label = array(
			'kode'      => 'Kode',
			'nama'      => 'Desa',
			'kecamatan' => 'Kecamatan',
			'sumber'    => 'Sumber',
			'laporan'   => 'Laporan',
		);
		foreach ( $label as $k => $t ) {
			self::th_urut( $k, $t, $urut, $arah, 'naik' );
		}
		self::th_biasa( 'Aksi' );
		echo '</tr></thead><tbody>';

		if ( ! $semua ) {
			echo '<tr><td colspan="6" class="pbm-muted">Tidak ada desa yang cocok dengan filter.</td></tr>';
		}

		foreach ( $semua as $d ) {
			$impor = PBM_Desa::SUMBER_IMPOR === ( $d->sumber ?? PBM_Desa::SUMBER_PETA );
			$n     = $lapor[ $d->kode ] ?? 0;

			echo '<tr>';
			echo '<td><span class="pbm-muted">' . esc_html( $d->kode ) . '</span></td>';
			echo '<td><b>' . esc_html( $d->nama ) . '</b></td>';
			echo '<td>' . esc_html( $d->kecamatan ) . '</td>';
			echo '<td>' . ( $impor
				? '<span class="pbm-tag pbm-tag-warn">ditambah impor</span>'
				: '<span class="pbm-tag pbm-tag-ok">bawaan peta</span>' ) . '</td>';
			echo '<td>' . ( $n ? (int) $n : '<span class="pbm-muted">0</span>' ) . '</td>';
			echo '<td>';
			if ( $impor ) {
				if ( $n > 0 ) {
					printf(
						'<a class="button button-small" href="%s">Perbaiki &amp; hapus</a>',
						esc_url( admin_url( 'admin.php?page=pbm-desa&perbaiki=' . rawurlencode( $d->kode ) ) )
					);
				} else {
					self::form_hapus_desa( $d, 0 );
				}
			} else {
				echo '<span class="pbm-muted">tidak bisa dihapus</span>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Panel pemindahan laporan satu desa impor ke desa peta. */
	private static function panel_perbaikan( $kode ) {
		$d = PBM_Desa::find( $kode );
		if ( ! $d || PBM_Desa::SUMBER_PETA === ( $d->sumber ?? PBM_Desa::SUMBER_PETA ) ) {
			return;
		}
		$n = self::hitung_laporan()[ $d->kode ] ?? 0;

		echo '<div class="pbm-box pbm-box-warn">';
		printf(
			'<p><b>Perbaiki:</b> <code>%s</code> (%s) — kode %s, mempunyai <b>%d laporan</b>.</p>',
			esc_html( $d->nama ),
			esc_html( $d->kecamatan ),
			esc_html( $d->kode ),
			$n
		);
		echo '<p>Pindahkan laporannya ke desa bawaan peta yang benar, lalu desa ini dihapus. '
			. 'Desa bawaan peta tetap ada karena menjadi acuan gambar polygon.</p>';
		self::form_hapus_desa( $d, $n );
		echo '</div>';
	}

	/** Proses hapus desa impor, dengan pemindahan laporan bila ada. */
	public static function hapus_desa() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}

		$kode = sanitize_text_field( wp_unslash( $_POST['kode'] ?? '' ) );
		check_admin_referer( 'pbm_hapus_desa_' . $kode );

		$balik = admin_url( 'admin.php?page=pbm-desa' );
		$d     = PBM_Desa::find( $kode );

		if ( ! $d || PBM_Desa::SUMBER_PETA === ( $d->sumber ?? PBM_Desa::SUMBER_PETA ) ) {
			wp_safe_redirect( $balik . '&pbm_notice=desa_gagal' );
			exit;
		}

		$pindah = sanitize_text_field( wp_unslash( $_POST['pindah_ke'] ?? '' ) );
		$n_lama = self::hitung_laporan()[ $kode ] ?? 0;

		/* Jangan sampai ada laporan yang yatim. */
		if ( $n_lama > 0 && '' === $pindah ) {
			wp_safe_redirect( $balik . '&pbm_notice=desa_punya_laporan&perbaiki=' . rawurlencode( $kode ) );
			exit;
		}

		$n = 0;
		if ( '' !== $pindah ) {
			$tujuan = PBM_Desa::find( $pindah );
			if ( ! $tujuan || PBM_Desa::SUMBER_PETA !== ( $tujuan->sumber ?? PBM_Desa::SUMBER_PETA ) ) {
				wp_safe_redirect( $balik . '&pbm_notice=desa_tujuan&perbaiki=' . rawurlencode( $kode ) );
				exit;
			}
			$n = PBM_Desa::pindahkan_laporan( $kode, $pindah );
		}

		if ( ! PBM_Desa::hapus_impor( $kode ) ) {
			wp_safe_redirect( $balik . '&pbm_notice=desa_gagal' );
			exit;
		}

		wp_safe_redirect( $balik . '&pbm_notice=desa_dihapus&pindah=' . $n );
		exit;
	}

	public static function halaman_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$step   = sanitize_text_field( $_GET['step'] ?? 'upload' );
		$notice = sanitize_text_field( $_GET['pbm_notice'] ?? '' );
		$data   = get_transient( 'pbm_import_' . get_current_user_id() );

		echo '<div class="wrap pbm-wrap">';
		echo '<h1>Impor Data dari Excel</h1>';
		echo '<p class="pbm-sub">Unggah berkas register bencana (.xlsx). Kolom dideteksi otomatis dan disimpan apa adanya sesuai Excel '
			. '(tanpa kolom statistik tambahan). Desa dicocokkan ke peta untuk pengelompokan.</p>';

		if ( 'selesai' === $notice ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>Impor selesai: <b>%d baru</b>, <b>%d diperbarui</b>, %d dilewati (duplikat), %d gagal.</p></div>',
				absint( $_GET['baru'] ?? 0 ), absint( $_GET['ubah'] ?? 0 ),
				absint( $_GET['lewat'] ?? 0 ), absint( $_GET['gagal'] ?? 0 )
			);
		} elseif ( 'dikosongkan' === $notice ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>Seluruh data lama (%d baris) dihapus. Silakan impor Excel terbaru.</p></div>',
				absint( $_GET['n'] ?? 0 )
			);
		} elseif ( 'kadaluarsa' === $notice ) {
			echo '<div class="notice notice-error"><p>Sesi pratinjau kedaluwarsa, silakan unggah ulang.</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" class="pbm-form">';
		echo '<input type="hidden" name="action" value="pbm_import_preview">';
		wp_nonce_field( PBM_Import::NONCE );
		echo '<h2>1. Pilih berkas</h2>';
		echo '<label>Berkas Excel (.xlsx) <input type="file" name="pbm_file" accept=".xlsx" required></label>';
		echo '<label>Sheet <input type="number" name="pbm_sheet" value="0" min="0" style="max-width:90px"> <span class="description">(0 = sheet pertama)</span></label>';
		echo '<div class="pbm-form-aksi"><button type="submit" class="button button-primary">Baca &amp; Pratinjau</button></div>';
		echo '</form>';

		$jumlah_lama = PBM_Laporan::hitung();
		if ( $jumlah_lama > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pbm-form pbm-danger" onsubmit="return confirm(\'Hapus SEMUA ' . esc_js( $jumlah_lama ) . ' baris data lama? Tindakan ini tidak bisa dibatalkan.\');">';
			echo '<input type="hidden" name="action" value="pbm_kosongkan">';
			wp_nonce_field( PBM_Import::NONCE );
			echo '<h2>Hapus data lama</h2>';
			printf( '<p>Tersimpan <b>%d baris</b>. Pakai tombol ini untuk mengganti seluruhnya dengan Excel terbaru.</p>', $jumlah_lama );
			echo '<div class="pbm-form-aksi"><button type="submit" class="button pbm-btn-hapus">Hapus semua data lama</button></div>';
			echo '</form>';
		}

		if ( $data && 'preview' === $step ) {
			self::pratinjau( $data );
			echo '</div>';
			return;
		}

		echo '<h2>Format yang didukung</h2>';
		echo '<p>Header boleh di baris mana pun pada 25 baris pertama (termasuk header bertingkat). '
			. 'Kolom yang dikenali: <code>No</code>, <code>No Nota Dinas</code>, <code>Bencana</code>, '
			. '<code>Dampak Bencana</code>, <code>Lokasi Bencana</code>, <code>KOORDINAT</code> (2 kolom: lintang &amp; bujur), '
			. '<code>Desa</code>, <code>Kecamatan</code>, <code>Waktu Laporan</code>, <code>Jam Lapor</code>, '
			. '<code>Waktu Kejadian</code>, <code>Jam Kejadian</code>, <code>Manusia</code>, <code>Hewan</code>, '
			. '<code>Rumah</code>, <code>Harta</code>, <code>Jalan</code>, <code>Fasilitas Umum Bukan Jalan</code>, '
			. '<code>Jembatan</code>, <code>Nilai Kerusakan</code>, <code>Keterangan</code>.</p>';
		echo '<p>Koordinat hanya dirapikan (spasi/kutip dibuang, koma menjadi titik) dan disimpan sebagai teks — '
			. 'tidak dipakai untuk memplot titik di peta.</p>';
		echo '</div>';
	}

	/**
	 * Metode pencocokan desa satu baris hasil.
	 *
	 * @return string|null null bila desa tidak ditemukan di peta.
	 */
	private static function metode_baris( $h ) {
		if ( isset( $h['_metode'] ) ) {
			return $h['_metode'];
		}
		/* Pratinjau lama, dibuat sebelum kolom _metode tersedia. */
		return ( 'baru' === ( $h['_match'] ?? '' ) ) ? null : 'persis';
	}

	/**
	 * Apakah metode ini perlu diperiksa petugas?
	 *
	 * persis dan alias dianggap aman (nama persis ada di peta, atau salah
	 * eja yang sudah terdaftar di tabel alias). Metode lain hasil pencocokan
	 * otomatis yang bisa salah, jadi dilaporkan bukan disembunyikan.
	 */
	private static function metode_perlu_tinjau( $metode ) {
		return ! in_array( $metode, array( 'persis', 'alias' ), true );
	}

	/** Keterangan singkat untuk tiap metode pencocokan. */
	private static function label_metode( $metode, $skor = 0 ) {
		switch ( $metode ) {
			case 'persis':
				return '';
			case 'alias':
				return 'alias dikenal';
			case 'alias_kecamatan':
				return 'ejaan kecamatan diperbaiki';
			case 'lintas_kecamatan':
				return 'desa ada di kecamatan lain';
			case 'mirip':
				return sprintf( 'kemungkinan salah eja (%d%%)', (int) round( (float) $skor * 100 ) );
			case 'mirip_lintas':
				return sprintf( 'salah eja, lintas kecamatan (%d%%)', (int) round( (float) $skor * 100 ) );
		}
		return 'tidak ditemukan di peta';
	}

	/** Nama hasil pencocokan, disertai nama asli dari Excel bila berbeda. */
	private static function tampil_nama( $hasil_kolom, $asli_kolom, $h ) {
		$res  = (string) ( $h[ $hasil_kolom ] ?? '' );
		$asli = trim( (string) ( $h[ $asli_kolom ] ?? '' ) );
		$out  = esc_html( $res );
		if ( '' !== $asli && 0 !== strcasecmp( $asli, trim( $res ) ) ) {
			$out .= ' <span class="pbm-muted">(Excel: ' . esc_html( $asli ) . ')</span>';
		}
		return $out;
	}

	private static function pratinjau( $data ) {
		$hasil = $data['hasil'];
		$n_baru = $n_dup = $n_luar = $n_tinjau = $n_tanpa_koord = 0;

		foreach ( $hasil as $h ) {
			$h['_existing'] ? $n_dup++ : $n_baru++;
			if ( '' === $h['lat'] || '' === $h['lon'] ) {
				$n_tanpa_koord++;
			}
			$metode = self::metode_baris( $h );
			if ( null === $metode ) {
				$n_luar++;
			} elseif ( self::metode_perlu_tinjau( $metode ) ) {
				$n_tinjau++;
			}
		}

		echo '<h2>2. Pratinjau <span class="pbm-count">' . count( $hasil ) . ' baris dari ' . esc_html( $data['file'] ) . '</span></h2>';
		echo '<div class="pbm-cards">';
		$kartu = array(
			/* label => [jumlah, kelas tambahan bila perlu ditonjolkan] */
			'Siap diimpor'      => array( $n_baru, '' ),
			'Duplikat dilewati' => array( $n_dup, '' ),
			'Perlu dicek'       => array( $n_tinjau, $n_tinjau ? ' pbm-card-hitung' : '' ),
			'Desa di luar peta' => array( $n_luar, $n_luar ? ' pbm-card-hitung' : '' ),
			'Tanpa koordinat'   => array( $n_tanpa_koord, '' ),
		);
		foreach ( $kartu as $label => $k ) {
			printf(
				'<div class="pbm-card%s"><span class="pbm-card-n">%d</span><span class="pbm-card-l">%s</span></div>',
				esc_attr( $k[1] ),
				(int) $k[0],
				esc_html( $label )
			);
		}
		echo '</div>';

		if ( $n_tinjau + $n_luar > 0 ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				'<b>%d baris</b> memakai nama desa yang tidak persis cocok dengan data peta: '
				. '<b>%d</b> mirip dengan desa di peta (kemungkinan salah eja) dan <b>%d</b> tidak ditemukan sama sekali.',
				$n_tinjau + $n_luar,
				$n_tinjau,
				$n_luar
			);
			echo '<br>Periksa kolom <i>Status</i> pada tabel di bawah. Nama hasil pencocokan otomatis ditampilkan '
				. 'bersama nama asli dari Excel — perbaiki ejaan di Excel bila seharusnya cocok, '
				. 'lalu unggah ulang agar pratinjau bersih.</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="pbm_import_proses">';
		wp_nonce_field( PBM_Import::NONCE );
		echo '<label class="pbm-inline"><input type="checkbox" name="pbm_overwrite" value="1"> Timpa baris duplikat dengan data Excel</label>';
		echo '<div class="pbm-form-aksi">';
		echo '<button type="submit" class="button button-primary">Impor ' . esc_html( $n_baru ) . ' baris</button>';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=pbm-import' ) ) . '">Batal</a>';
		echo '</div></form>';

		echo '<div class="pbm-scroll"><table class="widefat striped pbm-table"><thead><tr>';
		echo '<th>Excel</th><th>No</th><th>Bencana</th><th>Desa</th><th>Kecamatan</th><th>Waktu Laporan</th><th>Lintang</th><th>Bujur</th><th>Status</th>';
		echo '</tr></thead><tbody>';
		foreach ( $hasil as $h ) {
			$status = $h['_existing']
				? '<span class="pbm-tag pbm-tag-dup">duplikat</span>'
				: '<span class="pbm-tag pbm-tag-ok">baru</span>';

			$metode = self::metode_baris( $h );
			if ( null === $metode ) {
				$status .= ' <span class="pbm-tag pbm-tag-warn">desa di luar peta</span>';
			} elseif ( 'persis' !== $metode ) {
				$status .= ' <span class="pbm-tag pbm-tag-cek">'
					. esc_html( self::label_metode( $metode, $h['_skor'] ?? 0 ) ) . '</span>';
			}

			echo '<tr>';
			echo '<td>' . esc_html( $h['_excel_row'] ) . '</td>';
			echo '<td>' . esc_html( $h['no'] ) . '</td>';
			echo '<td>' . esc_html( $h['_bencana'] ) . '</td>';
			echo '<td>' . self::tampil_nama( '_desa', '_desa_excel', $h ) . '</td>';
			echo '<td>' . self::tampil_nama( '_kecamatan', '_kec_excel', $h ) . '</td>';
			echo '<td>' . esc_html( $h['waktu_laporan'] ?: '—' ) . '</td>';
			echo '<td>' . ( '' === $h['lat'] ? '<span class="pbm-muted">—</span>' : esc_html( $h['lat'] ) ) . '</td>';
			echo '<td>' . ( '' === $h['lon'] ? '<span class="pbm-muted">—</span>' : esc_html( $h['lon'] ) ) . '</td>';
			echo '<td>' . $status . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/* -------------------------------------------------------- AKSI FORM */

	public static function simpan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}
		check_admin_referer( 'pbm_simpan' );

		$id  = absint( $_POST['id'] ?? 0 );
		$res = PBM_Laporan::simpan( wp_unslash( $_POST ), $id );

		wp_safe_redirect(
			admin_url( 'admin.php?page=pbm-data&pbm_notice=' . ( is_wp_error( $res ) ? 'gagal' : 'tersimpan' ) )
		);
		exit;
	}

	public static function hapus() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'pbm_hapus_' . $id );
		PBM_Laporan::hapus( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=pbm-data&pbm_notice=dihapus' ) );
		exit;
	}

	public static function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}
		check_admin_referer( 'pbm_export' );

		$f = array(
			'dari'      => sanitize_text_field( $_GET['dari'] ?? '' ),
			'sampai'    => sanitize_text_field( $_GET['sampai'] ?? '' ),
			'bencana'   => sanitize_text_field( $_GET['bencana'] ?? '' ),
			'kecamatan' => sanitize_text_field( $_GET['kecamatan'] ?? '' ),
		);
		$rows = PBM_Laporan::daftar( $f, 100000, 0 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=register-bencana-magetan-' . gmdate( 'Ymd' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM agar Excel membaca UTF-8.

		$header = array();
		foreach ( PBM_Skema::nama_kolom() as $k ) {
			$header[] = PBM_Skema::label( $k );
		}
		$header[] = 'Kode Desa';
		fputcsv( $out, $header );

		foreach ( $rows as $r ) {
			$line = array();
			foreach ( PBM_Skema::nama_kolom() as $k ) {
				$line[] = $r->$k;
			}
			$line[] = $r->kode_desa;
			fputcsv( $out, $line );
		}
		fclose( $out );
		exit;
	}
}