<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD laporan bencana + agregasi untuk peta.
 *
 * Semua kolom yang disimpan/disimpan ulang berasal dari PBM_Skema::kolom(),
 * yaitu kolom berkas Excel. Tidak ada kolom statistik BNPB nasional.
 */
class PBM_Laporan {

	public static function table() {
		return PBM_Activator::tabel_laporan();
	}

	/** Kolom yang nilainya perlu disimpan sebagai tanggal. */
	private static function kolom_tanggal() {
		return array( 'waktu_laporan', 'waktu_kejadian' );
	}

	/** Cari laporan serupa (waktu laporan + desa + bencana) untuk deteksi duplikat. */
	public static function cari_duplikat( $tanggal, $kode_desa, $bencana ) {
		global $wpdb;
		if ( ! $tanggal || ! $kode_desa ) {
			return 0;
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table()
				. ' WHERE waktu_laporan = %s AND kode_desa = %s AND bencana = %s LIMIT 1',
				$tanggal,
				$kode_desa,
				$bencana
			)
		);
	}

	/**
	 * Simpan laporan (insert bila $id = 0).
	 *
	 * @return int|WP_Error
	 */
	public static function simpan( array $data, $id = 0 ) {
		global $wpdb;
		$t = self::table();

		$kode = sanitize_text_field( $data['kode_desa'] ?? '' );
		$desa = PBM_Desa::find( $kode );
		if ( ! $desa ) {
			return new WP_Error( 'pbm_desa', 'Desa tidak dikenal.' );
		}

		$row = array();
		foreach ( PBM_Skema::nama_kolom() as $k ) {
			$v = $data[ $k ] ?? '';
			if ( in_array( $k, self::kolom_tanggal(), true ) ) {
				$row[ $k ] = self::tanggal_sah( $v );
			} elseif ( 'no' === $k || 'nodin' === $k || 'lat' === $k || 'lon' === $k
				|| 'jam_lapor' === $k || 'jam_kejadian' === $k ) {
				$row[ $k ] = sanitize_text_field( (string) $v );
			} else {
				$row[ $k ] = sanitize_textarea_field( (string) $v );
			}
		}

		/* Bencana & waktu wajib; desa/kecamatan diambil dari master bila kosong. */
		if ( '' === trim( $row['bencana'] ) ) {
			return new WP_Error( 'pbm_bencana', 'Kolom Bencana wajib diisi.' );
		}
		if ( ! $row['waktu_laporan'] ) {
			return new WP_Error( 'pbm_tanggal', 'Waktu Laporan tidak valid.' );
		}
		if ( '' === trim( $row['desa'] ) ) {
			$row['desa'] = $desa->nama;
		}
		if ( '' === trim( $row['kecamatan'] ) ) {
			$row['kecamatan'] = $desa->kecamatan;
		}

		$row['kode_desa'] = $kode;

		if ( $id > 0 ) {
			$lama = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) );
			if ( ! $lama ) {
				return new WP_Error( 'pbm_missing', 'Laporan tidak ditemukan.' );
			}
			$row['user_id']    = (int) $lama->user_id;
			$row['created_at'] = $lama->created_at;
			$wpdb->update( $t, $row, array( 'id' => $id ) );
			return (int) $id;
		}

		$row['user_id']    = get_current_user_id();
		$row['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $t, $row );
		return (int) $wpdb->insert_id;
	}

	public static function hapus( $id ) {
		global $wpdb;
		return $wpdb->delete( self::table(), array( 'id' => absint( $id ) ) );
	}

	public static function find( $id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id )
		);
	}

	/* ------------------------------------------------------------- filter */

	private static function where( array $f ) {
		global $wpdb;
		$w    = array();
		$args = array();

		if ( ! empty( $f['dari'] ) ) {
			$w[]    = 'waktu_laporan >= %s';
			$args[] = self::tanggal_sah( $f['dari'] );
		}
		if ( ! empty( $f['sampai'] ) ) {
			$w[]    = 'waktu_laporan <= %s';
			$args[] = self::tanggal_sah( $f['sampai'] );
		}
		if ( ! empty( $f['bencana'] ) ) {
			$w[]    = 'bencana = %s';
			$args[] = sanitize_text_field( $f['bencana'] );
		}
		if ( ! empty( $f['kecamatan'] ) ) {
			$w[]    = 'kecamatan = %s';
			$args[] = sanitize_text_field( $f['kecamatan'] );
		}
		if ( ! empty( $f['kode_desa'] ) ) {
			$w[]    = 'kode_desa = %s';
			$args[] = sanitize_text_field( $f['kode_desa'] );
		}
		if ( ! empty( $f['cari'] ) ) {
			$q      = '%' . $wpdb->esc_like( sanitize_text_field( $f['cari'] ) ) . '%';
			$w[]    = '(desa LIKE %s OR kecamatan LIKE %s OR lokasi LIKE %s OR bencana LIKE %s OR nodin LIKE %s)';
			$args   = array_merge( $args, array( $q, $q, $q, $q, $q ) );
		}

		$sql = $w ? ( ' WHERE ' . implode( ' AND ', $w ) ) : '';
		return $args ? $wpdb->prepare( $sql, $args ) : $sql;
	}

	/**
	 * Kolom koordinat — titik adalah pemisah desimal.
	 * @return string[]
	 */
	private static function kolom_desimal() {
		return array( 'lat', 'lon' );
	}

	/**
	 * Kolom hitungan dan nilai rupiah.
	 *
	 * Disimpan sebagai teks mengikuti isi Excel, sehingga perlu dijadikan
	 * angka sebelum diurutkan — kalau tidak, 9000 mendahului 12000000.
	 * @return string[]
	 */
	private static function kolom_bilangan() {
		return array(
			'dampak_manusia', 'dampak_hewan', 'dampak_rumah', 'dampak_harta',
			'dampak_jalan', 'dampak_fasilitas', 'dampak_jembatan', 'nilai_kerusakan',
		);
	}

	/**
	 * Susun klausa ORDER BY dari parameter kolom — hanya lewat whitelist.
	 *
	 * Nama kolom dari URL tidak pernah masuk SQL apa adanya: hanya kolom
	 * milik skema Excel dan kolom internal tabel yang boleh dipakai, lalu
	 * dibungkus backtick. Tanpa langkah ini parameter `urut` akan menjadi
	 * celah injeksi SQL.
	 *
	 * @param string $kolom Kolom yang diminta.
	 * @param string $arah  'naik' | 'turun'.
	 * @return string Klausa ORDER BY, atau '' bila kolom tidak diizinkan.
	 */
	private static function urut_sql( $kolom, $arah ) {
		if ( '' === $kolom ) {
			return '';
		}
		$diizinkan = array_merge( array( 'id', 'kode_desa', 'user_id', 'created_at' ), PBM_Skema::nama_kolom() );
		if ( ! in_array( $kolom, $diizinkan, true ) ) {
			return '';
		}
		$d = ( 'turun' === $arah ) ? 'DESC' : 'ASC';

		/* Kolom tanggal: MySQL menaruh NULL sebagai nilai terendah, jadi ASC
		 * menempatkannya di paling atas. Disandingkan `IS NULL` agar baris
		 * tanpa tanggal selalu di belakang, di kedua arah. */
		if ( in_array( $kolom, array( 'waktu_laporan', 'waktu_kejadian' ), true ) ) {
			return "ORDER BY `$kolom` IS NULL, `$kolom` $d, id DESC";
		}

		if ( in_array( $kolom, self::kolom_desimal(), true ) ) {
			return "ORDER BY `$kolom` REGEXP '^[+-]?[0-9.]+$' DESC, "
				. "CAST(`$kolom` AS DECIMAL(20,10)) $d, `$kolom` $d, id DESC";
		}

		if ( in_array( $kolom, self::kolom_bilangan(), true ) ) {
			/* Pemisah ribuan dibuang dulu, baru dijadikan angka. Nilai yang
			 * tidak berbentuk angka dikelompokkan terpisah sehingga tetap
			 * terbaca, bukan semuanya berubah jadi nol. */
			$teks  = "`$kolom` REGEXP '^[[:space:]]*[+-]?[0-9][0-9.,[:space:]]*$'";
			$baku  = 'TRIM(`' . $kolom . '`)';
			$angka = "CAST(REPLACE(REPLACE(REPLACE($baku, '.', ''), ',', ''), ' ', '') AS DECIMAL(65,4))";
			return "ORDER BY IF($teks, 0, 1) ASC, $angka $d, `$kolom` $d, id DESC";
		}

		return "ORDER BY `$kolom` $d, id DESC";
	}

	/**
	 * Daftar laporan sesuai filter, kini bisa diurutkan.
	 *
	 * @param array  $f     Filter.
	 * @param int    $limit Jumlah baris.
	 * @param int    $offset Offset.
	 * @param string $urut  Kolom pengurutan (disaring ulang oleh urut_sql()).
	 * @param string $arah  'naik' | 'turun'.
	 * @return object[]
	 */
	public static function daftar( array $f = array(), $limit = 50, $offset = 0, $urut = '', $arah = '' ) {
		global $wpdb;
		$t = self::table();
		$d = self::where( $f );
		$o = self::urut_sql( $urut, $arah );
		if ( '' === $o ) {
			/* Tanpa perintah sortir yang sah, kembali ke urutan lama. */
			$o = 'ORDER BY waktu_laporan DESC, id DESC';
		}
		$limit  = max( 1, (int) $limit );
		$offset = max( 0, (int) $offset );
		return $wpdb->get_results( "SELECT * FROM $t $d $o LIMIT $limit OFFSET $offset" );
	}

	public static function hitung( array $f = array() ) {
		global $wpdb;
		$d = self::where( $f );
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " $d" );
	}

	/* ---------------------------------------------------------- agregasi */

	/**
	 * Rekap per desa: jumlah kejadian (dari kolom Excel, tanpa statistik BNPB).
	 *
	 * @return array kode_desa => {nama, desa, kecamatan, kejadian}
	 */
	public static function rekap_desa( array $f = array() ) {
		global $wpdb;
		$t = self::table();
		$d = self::where( $f );
		$rows = $wpdb->get_results(
			"SELECT kode_desa, desa AS nama, kecamatan, COUNT(*) AS kejadian
			 FROM $t $d GROUP BY kode_desa, desa, kecamatan"
		);
		$out = array();
		foreach ( $rows as $r ) {
			$r->kejadian          = (int) $r->kejadian;
			$out[ $r->kode_desa ] = $r;
		}
		return $out;
	}

	/**
	 * Akumulasi jumlah kejadian per jenis bencana, per desa.
	 * Jenis diambil apa adanya dari kolom "Bencana" pada Excel.
	 *
	 * @return array kode_desa => array(nama_bencana => jumlah)
	 */
	public static function rekap_jenis( array $f = array() ) {
		global $wpdb;
		$t = self::table();
		$d = self::where( $f );
		$rows = $wpdb->get_results(
			"SELECT kode_desa, bencana, COUNT(*) AS n FROM $t $d GROUP BY kode_desa, bencana"
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ $r->kode_desa ][ $r->bencana ] = (int) $r->n;
		}
		return $out;
	}

	/** Rekap per kecamatan: kejadian + jumlah desa terdampak. */
	public static function rekap_kecamatan( array $f = array() ) {
		$out = array();
		foreach ( self::rekap_desa( $f ) as $r ) {
			$kec = $r->kecamatan ?: '-';
			if ( ! isset( $out[ $kec ] ) ) {
				$o = new stdClass();
				$o->kecamatan      = $kec;
				$o->kejadian       = 0;
				$o->desa_terdampak = 0;
				$out[ $kec ]       = $o;
			}
			$out[ $kec ]->kejadian       += $r->kejadian;
			$out[ $kec ]->desa_terdampak += 1;
		}
		uasort(
			$out,
			static function ( $a, $b ) {
				return $b->kejadian <=> $a->kejadian;
			}
		);
		return $out;
	}

	/** Total kabupaten: kejadian + desa terdampak + kecamatan terdampak. */
	public static function total( array $f = array() ) {
		$t = new stdClass();
		$t->kejadian       = 0;
		$t->desa_terdampak = 0;
		foreach ( self::rekap_desa( $f ) as $r ) {
			$t->kejadian += $r->kejadian;
			$t->desa_terdampak++;
		}
		$t->kecamatan_terdampak = count( self::rekap_kecamatan( $f ) );
		return $t;
	}

	/**
	 * Daftar jenis bencana unik dari data (kolom "Bencana" Excel).
	 * @return array nama => jumlah
	 */
	public static function jenis_terpakai() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT bencana, COUNT(*) AS n FROM ' . self::table()
			. " WHERE bencana <> '' GROUP BY bencana ORDER BY n DESC, bencana ASC"
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ $r->bencana ] = (int) $r->n;
		}
		return $out;
	}

	/** Tanggal laporan terakhir. */
	public static function terakhir() {
		global $wpdb;
		return $wpdb->get_var( 'SELECT MAX(waktu_laporan) FROM ' . self::table() );
	}

	/** Validasi tanggal Y-m-d. */
	public static function tanggal_sah( $d ) {
		$d = sanitize_text_field( (string) $d );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m )
			&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $d;
		}
		return null;
	}
}