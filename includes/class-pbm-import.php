<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Impor register bencana dari Excel (.xlsx).
 *
 * Kolom Excel dipetakan 1:1 ke kolom tabel (lihat PBM_Skema::kolom()).
 * Nilai disimpan apa adanya sesuai Excel; satu-satunya perapian:
 *  - koordinat dibersihkan (spasi/kutip, koma -> titik) tanpa validasi ketat;
 *  - tanggal diubah ke format Y-m-d;
 *  - nama desa dicocokkan ke master desa untuk pengelompokan peta.
 */
class PBM_Import {

	const NONCE = 'pbm_import';

	/** Frasa header Excel -> nama kolom internal. */
	private static function petunjuk() {
		return array(
			'no'               => array( 'no' ),
			'nodin'            => array( 'no nota dinas', 'nodin', 'nomor nota dinas' ),
			'bencana'          => array( 'bencana' ),
			'dampak_bencana'   => array( 'dampak bencana' ),
			'lokasi'           => array( 'lokasi bencana', 'lokasi' ),
			'koordinat'        => array( 'koordinat', 'lintang', 'latitude', 'lat', 'bujur', 'longitude', 'lon' ),
			'desa'             => array( 'desa' ),
			'kecamatan'        => array( 'kecamatan' ),
			'waktu_laporan'    => array( 'waktu laporan', 'tanggal laporan', 'tanggal' ),
			'jam_lapor'        => array( 'jam lapor' ),
			'waktu_kejadian'   => array( 'waktu kejadian', 'tanggal kejadian' ),
			'jam_kejadian'     => array( 'jam kejadian' ),
			'dampak_manusia'   => array( 'manusia' ),
			'dampak_hewan'     => array( 'hewan', 'ternak' ),
			'dampak_rumah'     => array( 'rumah' ),
			'dampak_harta'     => array( 'harta', 'harta benda' ),
			'dampak_jalan'     => array( 'jalan' ),
			'dampak_fasilitas' => array( 'fasilitas umum bukan jalan', 'fasilitas umum', 'fasilitas' ),
			'dampak_jembatan'  => array( 'jembatan' ),
			'nilai_kerusakan'  => array( 'nilai kerusakan', 'kerugian' ),
			'keterangan'       => array( 'keterangan', 'uraian' ),
		);
	}

	public static function init() {
		add_action( 'admin_post_pbm_import_preview', array( __CLASS__, 'preview' ) );
		add_action( 'admin_post_pbm_import_proses', array( __CLASS__, 'proses' ) );
		add_action( 'admin_post_pbm_kosongkan', array( __CLASS__, 'kosongkan' ) );
	}

	/* ------------------------------------------------------------ util teks */

	private static function bersih( $s ) {
		$s = strtolower( trim( (string) $s ) );
		$s = preg_replace( '/[^a-z0-9 ]/', ' ', $s );
		return trim( preg_replace( '/\s+/', ' ', $s ) );
	}

	/* ---------------------------------------------------- deteksi header */

	/**
	 * Cari baris header & pemetaan kolom pada 25 baris pertama.
	 * Header bertingkat (3 baris) digabung agar sel gabungan terbaca.
	 *
	 * @return array{header_row:int, kolom:array<string,int>}
	 */
	public static function deteksi_kolom( array $rows ) {
		$petunjuk = self::petunjuk();
		$terbaik  = null;
		$skor_max = 0;

		foreach ( $rows as $ri => $row ) {
			if ( $ri > 25 ) {
				break;
			}
			$gabung = array();
			foreach ( $row as $ci => $v ) {
				$gabung[ $ci ] = self::bersih( $v );
			}
			/* Gabung 2 baris di bawahnya untuk header bertingkat. */
			for ( $d = 1; $d <= 2; $d++ ) {
				foreach ( ( $rows[ $ri + $d ] ?? array() ) as $ci => $v ) {
					$t = self::bersih( $v );
					if ( '' !== $t && empty( $gabung[ $ci ] ) ) {
						$gabung[ $ci ] = $t;
					}
				}
			}

			$kandidat = array();
			foreach ( $petunjuk as $field => $frasa ) {
				if ( 'koordinat' === $field ) {
					continue;
				}
				foreach ( $gabung as $ci => $teks ) {
					if ( '' === $teks ) {
						continue;
					}
					foreach ( $frasa as $fr ) {
						if ( $teks === $fr && ! in_array( $ci, $kandidat, true ) ) {
							$kandidat[ $field ] = $ci;
							break 2;
						}
					}
				}
			}
			/* Kolom KOORDINAT: kolom pertama = lintang, berikutnya = bujur. */
			foreach ( $gabung as $ci => $teks ) {
				if ( in_array( 'koordinat', self::petunjuk_keys_match( $teks ), true ) ) {
					$kandidat['koordinat'] = $ci;
					break;
				}
			}
			if ( isset( $kandidat['koordinat'] ) ) {
				$kandidat['lat'] = $kandidat['koordinat'];
				$kandidat['lon'] = $kandidat['koordinat'] + 1;
			}

			$wajib = array( 'bencana', 'desa', 'kecamatan' );
			$punya = count( array_intersect( $wajib, array_keys( $kandidat ) ) );
			$skor  = count( $kandidat ) + $punya * 3;

			if ( $punya >= 2 && $skor > $skor_max ) {
				$skor_max = $skor;
				$terbaik  = array( 'header_row' => $ri, 'kolom' => $kandidat );
			}
		}

		if ( ! $terbaik ) {
			/* Cadangan: susunan kolom Excel BPBD Magetan. */
			$terbaik = array(
				'header_row' => 3,
				'kolom'      => array(
					'no' => 0, 'nodin' => 1, 'bencana' => 2, 'dampak_bencana' => 3,
					'lokasi' => 4, 'lat' => 5, 'lon' => 6, 'desa' => 7, 'kecamatan' => 8,
					'waktu_laporan' => 9, 'jam_lapor' => 10, 'waktu_kejadian' => 11,
					'jam_kejadian' => 12, 'dampak_manusia' => 13, 'dampak_hewan' => 14,
					'dampak_rumah' => 15, 'dampak_harta' => 16, 'dampak_jalan' => 17,
					'dampak_fasilitas' => 18, 'dampak_jembatan' => 19,
					'nilai_kerusakan' => 20, 'keterangan' => 21,
				),
			);
		}
		return $terbaik;
	}

	private static function petunjuk_keys_match( $teks ) {
		return ( 'koordinat' === $teks ) ? array( 'koordinat' ) : array();
	}

	/* -------------------------------------------------------- koordinat */

	/**
	 * Rapikan sepasang koordinat.
	 *
	 * Tidak dipakai untuk pointing peta, jadi tidak divalidasi rentangnya —
	 * cukup dibersihkan agar rapi: buang spasi/kutip, koma desimal -> titik,
	 * pulihkan titik desimal yang hilang saat Excel menyimpan angka.
	 *
	 * @return array{lat:string, lon:string}
	 */
	public static function koordinat( $lat_raw, $lon_raw ) {
		$rapi = static function ( $v, $jenis ) {
			if ( null === $v ) {
				return '';
			}
			$s = (string) $v;
			$s = str_replace( array( "\xC2\xA0", '"', "'", '°', 'º' ), '', $s );
			$s = trim( $s );
			$s = trim( $s, "\"'" );

			$negatif = ( false !== strpos( $s, '-' ) );
			$angka   = preg_replace( '/[^0-9.,]/', '', $s );
			if ( '' === $angka ) {
				return '';
			}
			$t = str_replace( ',', '.', $angka );
			if ( false === strpos( $t, '.' ) ) {
				/* Titik desimal hilang: 76709168 / 11129288063 / 769903. */
				$guna = ltrim( $angka, '0' );
				if ( strlen( $guna ) >= 6 ) {
					$t = ( 'lat' === $jenis )
						? substr( $guna, 0, 1 ) . '.' . substr( $guna, 1 )
						: substr( $guna, 0, 3 ) . '.' . substr( $guna, 3 );
				}
			} else {
				$p = explode( '.', $t );
				if ( count( $p ) > 2 ) {
					$t = $p[0] . '.' . $p[1]; /* -7,5271160, -> -7.5271160 */
				}
			}
			$t = rtrim( $t, '.' );
			if ( '' === $t ) {
				return '';
			}
			if ( $negatif && 0 !== strpos( $t, '-' ) ) {
				$t = '-' . $t;
			}
			/* Buang derau floating point: 7.7200870000000004 -> 7.720087. */
			if ( is_numeric( $t ) ) {
				$t = rtrim( rtrim( number_format( (float) $t, 8, '.', '' ), '0' ), '.' );
			}
			return $t;
		};

		return array(
			'lat' => $rapi( $lat_raw, 'lat' ),
			'lon' => $rapi( $lon_raw, 'lon' ),
		);
	}

	/* ----------------------------------------------------------- tanggal */

	/** Tanggal dari berbagai format -> Y-m-d. */
	public static function tanggal( $v ) {
		if ( null === $v || '' === $v ) {
			return null;
		}
		$s = trim( (string) $v );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $s, $m ) ) {
			return $m[0];
		}
		$bulan = array(
			'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6,
			'juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
		);
		if ( preg_match( '/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/', $s, $m ) ) {
			$b = $bulan[ strtolower( $m[2] ) ] ?? null;
			if ( $b ) {
				return sprintf( '%s-%02d-%02d', $m[3], $b, (int) $m[1] );
			}
		}
		if ( preg_match( '/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})/', $s, $m ) ) {
			return sprintf( '%s-%02d-%02d', $m[3], (int) $m[2], (int) $m[1] );
		}
		if ( is_numeric( $s ) && $s > 20000 && $s < 60000 ) {
			$hari  = (int) floor( (float) $s );
			$hari -= ( $hari > 59 ) ? 1 : 0;
			return gmdate( 'Y-m-d', ( $hari - 25569 ) * 86400 );
		}
		return null;
	}

	/** Jam: buang akhiran WIB, sisanya apa adanya. */
	public static function jam( $v ) {
		$s = trim( (string) $v );
		if ( '' === $s ) {
			return '';
		}
		return trim( preg_replace( '/\s*WIB\s*$/i', '', $s ) );
	}

	/** Nama bencana dirapikan kapitalisasinya, tanpa mengubah istilahnya. */
	public static function bencana( $teks ) {
		$s = trim( preg_replace( '/\s+/', ' ', (string) $teks ) );
		if ( '' === $s ) {
			return '';
		}
		$kunci = strtolower( $s );
		$peta  = array(
			'cuaca ekstrem'                             => 'Cuaca Ekstrem',
			'tanah longsor'                             => 'Tanah Longsor',
			'banjir'                                    => 'Banjir',
			'kebakaran hutan dan lahan'                 => 'Kebakaran Hutan dan Lahan',
			'kebakaran'                                 => 'Kebakaran',
			'kekeringan'                                => 'Kekeringan',
			'gempabumi'                                 => 'Gempabumi',
			'erupsi gunung api'                         => 'Erupsi Gunung Api',
			'gelombang pasang dan abrasi'               => 'Gelombang Pasang dan Abrasi',
			'evakuasi pencarian dan pertolongan'        => 'Evakuasi Pencarian dan Pertolongan',
			'evakuasi pencarian dan pertolongan hewan'  => 'Evakuasi Pencarian dan Pertolongan Hewan',
			'kejadian tidak terduga'                    => 'Kejadian Tidak Terduga',
		);
		return $peta[ $kunci ] ?? ucwords( $kunci );
	}

	/* ------------------------------------------------------ pemetaan desa */

	private static function norm_desa( $s ) {
		$s = strtoupper( trim( (string) $s ) );
		$s = preg_replace( '/^(KEL\.?|DS\.?|DESA|KELURAHAN|DUSUN|DK\.?|DUKUH)\s+/', '', $s );
		return preg_replace( '/[^A-Z0-9]/', '', $s );
	}

	private static function peta_desa() {
		static $peta = null;
		if ( null !== $peta ) {
			return $peta;
		}
		$peta = array();
		foreach ( PBM_Desa::all() as $d ) {
			$peta[ self::norm_desa( $d->kecamatan ) ][ self::norm_desa( $d->nama ) ] = array(
				'kode'      => $d->kode,
				'nama'      => $d->nama,
				'kecamatan' => $d->kecamatan,
			);
		}
		return $peta;
	}

	private static function mirip( $needle, array $kandidat ) {
		$terbaik = null;
		$skor    = 0.0;
		foreach ( $kandidat as $h ) {
			$maks = max( strlen( $needle ), strlen( $h ) );
			if ( 0 === $maks ) {
				continue;
			}
			$s = ( $maks - levenshtein( $needle, $h ) ) / $maks;
			if ( $s > $skor ) {
				$skor    = $s;
				$terbaik = $h;
			}
		}
		return ( $skor >= 0.82 ) ? $terbaik : null;
	}

	/** Cocokkan desa+kecamatan Excel ke master desa. */
	public static function cari_desa( $kecamatan, $desa ) {
		$peta = self::peta_desa();
		$k    = self::norm_desa( $kecamatan );
		$d    = self::norm_desa( $desa );

		if ( isset( $peta[ $k ][ $d ] ) ) {
			return $peta[ $k ][ $d ];
		}
		/* Alias salah tulis yang sering muncul. */
		$alias = array(
			'ALATUWO'        => 'ALASTUWO',
			'KELMAGETAN'     => 'MAGETAN',
			'KELMANGKUJAYAN' => 'MANGKUJAYAN',
			'BULUKERO'       => 'BULUKERTO',
			'BAYEMTAMAN'     => 'BAYEMTAMAN',
			'SENDANGAGUNG'   => 'SENDANGAGUNG',
		);
		if ( isset( $alias[ $d ], $peta[ $k ][ $alias[ $d ] ] ) ) {
			return $peta[ $k ][ $alias[ $d ] ];
		}
		$kec_alias = array( 'PARAG' => 'PARANG', 'MAGETANKOTA' => 'MAGETAN' );
		if ( isset( $kec_alias[ $k ], $peta[ $kec_alias[ $k ] ][ $d ] ) ) {
			return $peta[ $kec_alias[ $k ] ][ $d ];
		}
		/* Nama desa sama di kecamatan lain. */
		foreach ( $peta as $list ) {
			if ( isset( $list[ $d ] ) ) {
				return $list[ $d ];
			}
		}
		/* Kemiripan nama dalam kecamatan yang sama, lalu lintas kecamatan. */
		if ( isset( $peta[ $k ] ) ) {
			$m = self::mirip( $d, array_keys( $peta[ $k ] ) );
			if ( $m ) {
				return $peta[ $k ][ $m ];
			}
		}
		foreach ( $peta as $list ) {
			$m = self::mirip( $d, array_keys( $list ) );
			if ( $m ) {
				return $list[ $m ];
			}
		}
		return null;
	}

	/* ---------------------------------------------------------- pembacaan */

	/** Baca berkas unggahan -> baris data + pemetaan kolom. */
	private static function baca_unggahan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}
		check_admin_referer( self::NONCE );

		if ( empty( $_FILES['pbm_file']['tmp_name'] ) ) {
			wp_die( 'Tidak ada berkas yang diunggah.' );
		}
		$nama = sanitize_file_name( $_FILES['pbm_file']['name'] ?? '' );
		if ( ! preg_match( '/\.xlsx$/i', $nama ) ) {
			wp_die( 'Format harus .xlsx (untuk .xls simpan ulang sebagai .xlsx).' );
		}

		$xlsx = new PBM_Xlsx( $_FILES['pbm_file']['tmp_name'] );
		$idx  = absint( $_POST['pbm_sheet'] ?? 0 );
		$data = $xlsx->baca_sheet( $idx );
		$det  = self::deteksi_kolom( $data['rows'] );

		$mulai = $det['header_row'] + 1;
		$out   = array();
		$total = count( $data['rows'] );
		for ( $i = 0; $i < $total; $i++ ) {
			if ( $i < $mulai || ! isset( $data['rows'][ $i ] ) ) {
				continue;
			}
			$row   = $data['rows'][ $i ];
			$ambil = static function ( $field ) use ( $row, $det ) {
				$ci = $det['kolom'][ $field ] ?? null;
				return ( null === $ci ) ? null : ( $row[ $ci ] ?? null );
			};

			/* Lewati baris nomor urut kolom (1..22) & baris kosong. */
			$bencana = trim( (string) $ambil( 'bencana' ) );
			$desa    = trim( (string) $ambil( 'desa' ) );
			$kec     = trim( (string) $ambil( 'kecamatan' ) );
			if ( '' === $bencana && '' === $desa && '' === $kec ) {
				continue;
			}
			if ( preg_match( '/^\d+$/', $bencana ) && preg_match( '/^\d+$/', (string) $ambil( 'dampak_bencana' ) ) ) {
				continue;
			}
			$out[] = array( 'ambil' => $ambil, '_excel_row' => $i + 1 );
		}

		return array( 'det' => $det, 'baris' => $out, 'sheet' => $xlsx->sheet_names() );
	}

	/** Ubah satu baris Excel -> array siap simpan. */
	public static function normalisasi( array $item ) {
		$ambil = $item['ambil'];
		$get   = static function ( $f ) use ( $ambil ) {
			$v = $ambil( $f );
			if ( is_string( $v ) ) {
				$v = trim( preg_replace( '/\s+/', ' ', $v ) );
			}
			return $v;
		};

		$desa_raw = (string) $get( 'desa' );
		$kec_raw  = (string) $get( 'kecamatan' );
		$master   = self::cari_desa( $kec_raw, $desa_raw );

		if ( $master ) {
			$kode    = $master['kode'];
			$desa    = $master['nama'];
			$kec     = $master['kecamatan'];
			$catatan = '';
		} else {
			/* Desa belum ada di peta: daftarkan agar laporan tidak hilang. */
			$kode = 'X' . substr( preg_replace( '/[^A-Z]/', '', strtoupper( $kec_raw ) ), 0, 3 )
				. substr( preg_replace( '/[^A-Z]/', '', strtoupper( $desa_raw ) ), 0, 8 );
			$desa = ucwords( strtolower( $desa_raw ) );
			$kec  = ucwords( strtolower( $kec_raw ) );
			if ( strlen( $kode ) > 1 ) {
				PBM_Desa::pastikan( $kode, $desa ?: '(tanpa nama)', $kec ?: '-' );
			}
			$catatan = 'Desa belum ada di peta polygon (kode sementara). ';
		}

		$koord    = self::koordinat( $get( 'lat' ), $get( 'lon' ) );
		$tgl      = self::tanggal( $get( 'waktu_laporan' ) ) ?: self::tanggal( $get( 'waktu_kejadian' ) );
		$bencana  = self::bencana( $get( 'bencana' ) );
		$keterangan = trim( (string) $get( 'keterangan' ) );

		return array(
			'no'               => (string) $get( 'no' ),
			'nodin'            => (string) $get( 'nodin' ),
			'bencana'          => $bencana,
			'dampak_bencana'   => (string) $get( 'dampak_bencana' ),
			'lokasi'           => (string) $get( 'lokasi' ),
			'lat'              => $koord['lat'],
			'lon'              => $koord['lon'],
			'desa'             => $desa,
			'kecamatan'        => $kec,
			'waktu_laporan'    => $tgl,
			'jam_lapor'        => self::jam( $get( 'jam_lapor' ) ),
			'waktu_kejadian'   => self::tanggal( $get( 'waktu_kejadian' ) ),
			'jam_kejadian'     => self::jam( $get( 'jam_kejadian' ) ),
			'dampak_manusia'   => (string) $get( 'dampak_manusia' ),
			'dampak_hewan'     => (string) $get( 'dampak_hewan' ),
			'dampak_rumah'     => (string) $get( 'dampak_rumah' ),
			'dampak_harta'     => (string) $get( 'dampak_harta' ),
			'dampak_jalan'     => (string) $get( 'dampak_jalan' ),
			'dampak_fasilitas' => (string) $get( 'dampak_fasilitas' ),
			'dampak_jembatan'  => (string) $get( 'dampak_jembatan' ),
			'nilai_kerusakan'  => (string) $get( 'nilai_kerusakan' ),
			'keterangan'       => $catatan . $keterangan,
			'kode_desa'        => $kode,
			/* meta pratinjau */
			'_excel_row'   => $item['_excel_row'],
			'_desa'        => $desa,
			'_kecamatan'   => $kec,
			'_bencana'     => $bencana,
			'_match'       => $master ? 'ok' : 'baru',
			'_existing'    => PBM_Laporan::cari_duplikat( $tgl, $kode, $bencana ),
		);
	}

	/* ------------------------------------------------------- handler admin */

	public static function preview() {
		$res   = self::baca_unggahan();
		$hasil = array();
		foreach ( $res['baris'] as $item ) {
			$hasil[] = self::normalisasi( $item );
		}
		set_transient(
			'pbm_import_' . get_current_user_id(),
			array(
				'hasil' => $hasil,
				'file'  => sanitize_file_name( $_FILES['pbm_file']['name'] ?? '' ),
				'kolom' => $res['det']['kolom'],
			),
			HOUR_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=pbm-import&step=preview' ) );
		exit;
	}

	public static function proses() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}
		check_admin_referer( self::NONCE );

		$data = get_transient( 'pbm_import_' . get_current_user_id() );
		if ( ! $data ) {
			wp_safe_redirect( admin_url( 'admin.php?page=pbm-import&pbm_notice=kadaluarsa' ) );
			exit;
		}

		$timpa = ! empty( $_POST['pbm_overwrite'] );
		$baru = $ubah = $lewat = $gagal = 0;

		foreach ( $data['hasil'] as $row ) {
			if ( $row['_existing'] && $timpa ) {
				is_wp_error( PBM_Laporan::simpan( $row, (int) $row['_existing'] ) ) ? $gagal++ : $ubah++;
			} elseif ( $row['_existing'] ) {
				$lewat++;
			} else {
				is_wp_error( PBM_Laporan::simpan( $row ) ) ? $gagal++ : $baru++;
			}
		}

		delete_transient( 'pbm_import_' . get_current_user_id() );
		wp_safe_redirect(
			admin_url( 'admin.php?page=pbm-import&pbm_notice=selesai&baru=' . $baru . '&ubah=' . $ubah . '&lewat=' . $lewat . '&gagal=' . $gagal )
		);
		exit;
	}

	/** Hapus seluruh laporan (ganti total dengan Excel terbaru). */
	public static function kosongkan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Akses ditolak.' );
		}
		check_admin_referer( self::NONCE );
		global $wpdb;
		$t = PBM_Laporan::table();
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" );
		$wpdb->query( "TRUNCATE TABLE $t" );
		wp_safe_redirect( admin_url( 'admin.php?page=pbm-import&pbm_notice=dikosongkan&n=' . $n ) );
		exit;
	}
}