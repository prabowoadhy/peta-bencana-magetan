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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_menu_page(
			'Peta Bencana Magetan', 'Peta Bencana', 'manage_options', 'pbm-peta',
			array( __CLASS__, 'halaman_peta' ), 'dashicons-chart-area', 25
		);
		add_submenu_page( 'pbm-peta', 'Peta', 'Peta', 'manage_options', 'pbm-peta', array( __CLASS__, 'halaman_peta' ) );
		add_submenu_page( 'pbm-peta', 'Data Bencana', 'Data Bencana', 'manage_options', 'pbm-data', array( __CLASS__, 'halaman_data' ) );
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
		$rows   = PBM_Laporan::daftar( $f, $per, ( $paged - 1 ) * $per );

		echo '<h2>Daftar Data <span class="pbm-count">(' . number_format_i18n( $jumlah ) . ' baris)</span></h2>';

		echo '<form method="get" class="pbm-filter-form">';
		echo '<input type="hidden" name="page" value="pbm-data">';
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

		$kolom_tampil = array( 'no', 'nodin', 'bencana', 'dampak_bencana', 'lokasi', 'lat', 'lon', 'desa', 'kecamatan', 'waktu_laporan', 'jam_lapor', 'waktu_kejadian', 'jam_kejadian', 'dampak_manusia', 'dampak_hewan', 'dampak_rumah', 'dampak_harta', 'dampak_jalan', 'dampak_fasilitas', 'dampak_jembatan', 'nilai_kerusakan', 'keterangan' );

		echo '<div class="pbm-scroll"><table class="widefat striped pbm-table"><thead><tr>';
		foreach ( $kolom_tampil as $k ) {
			echo '<th>' . esc_html( PBM_Skema::label( $k ) ) . '</th>';
		}
		echo '<th>Aksi</th></tr></thead><tbody>';

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
				$url = add_query_arg( array_merge( $f, array( 'page' => 'pbm-data', 'paged' => $i ) ), admin_url( 'admin.php' ) );
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

	private static function pratinjau( $data ) {
		$hasil = $data['hasil'];
		$n_baru = $n_dup = $n_luar = $n_tanpa_koord = 0;
		foreach ( $hasil as $h ) {
			$h['_existing'] ? $n_dup++ : $n_baru++;
			if ( 'baru' === $h['_match'] ) {
				$n_luar++;
			}
			if ( '' === $h['lat'] || '' === $h['lon'] ) {
				$n_tanpa_koord++;
			}
		}

		echo '<h2>2. Pratinjau <span class="pbm-count">' . count( $hasil ) . ' baris dari ' . esc_html( $data['file'] ) . '</span></h2>';
		echo '<div class="pbm-cards">';
		foreach ( array( 'Siap diimpor' => $n_baru, 'Duplikat dilewati' => $n_dup, 'Desa di luar peta' => $n_luar, 'Tanpa koordinat' => $n_tanpa_koord ) as $label => $n ) {
			echo '<div class="pbm-card"><span class="pbm-card-n">' . esc_html( $n ) . '</span><span class="pbm-card-l">' . esc_html( $label ) . '</span></div>';
		}
		echo '</div>';

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
			if ( 'baru' === $h['_match'] ) {
				$status .= ' <span class="pbm-tag pbm-tag-warn">desa di luar peta</span>';
			}
			echo '<tr>';
			echo '<td>' . esc_html( $h['_excel_row'] ) . '</td>';
			echo '<td>' . esc_html( $h['no'] ) . '</td>';
			echo '<td>' . esc_html( $h['_bencana'] ) . '</td>';
			echo '<td>' . esc_html( $h['_desa'] ) . '</td>';
			echo '<td>' . esc_html( $h['_kecamatan'] ) . '</td>';
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