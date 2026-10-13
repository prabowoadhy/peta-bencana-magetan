<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Akses tabel master desa (batas wilayah dari GeoJSON QGIS).
 *
 * Master desa mempunyai dua sumber, ditandai kolom `sumber`:
 *  - 'peta'  baris bawaan dari GeoJSON, diisi otomatis saat aktivasi maupun
 *            pembaruan plugin. TIDAK BISA DIHAPUS karena menjadi acuan
 *            penggambaran polygon pada peta choropleth.
 *  - 'impor' baris yang otomatis tercatat saat impor Excel karena nama
 *            desanya tidak ada di peta, agar laporan tidak hilang. Boleh
 *            dihapus oleh operator bila ternyata ejaan yang salah.
 */
class PBM_Desa {

	const SUMBER_PETA  = 'peta';
	const SUMBER_IMPOR = 'impor';

	/** Nama tabel master desa lengkap dengan prefix. */
	public static function table() {
		return PBM_Activator::tabel_desa();
	}

	/** @return object[] */
	public static function all( $kecamatan = '', $sumber = '' ) {
		global $wpdb;
		$t     = self::nama_tabel();
		$where = array();
		$args  = array();

		if ( '' !== $kecamatan ) {
			$where[] = 'kecamatan = %s';
			$args[]  = $kecamatan;
		}
		if ( '' !== $sumber ) {
			$where[] = 'sumber = %s';
			$args[]  = $sumber;
		}

		$sql = 'SELECT * FROM ' . $t
			. ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' )
			. ' ORDER BY kecamatan, nama';

		return $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );
	}

	/** @deprecated Gunakan table(). */
	public static function nama_tabel() {
		return self::table();
	}

	/** @return string[] */
	public static function kecamatan_list() {
		global $wpdb;
		return $wpdb->get_col( 'SELECT DISTINCT kecamatan FROM ' . self::nama_tabel() . ' ORDER BY kecamatan' );
	}

	/** @return object|null */
	public static function find( $kode ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::nama_tabel() . ' WHERE kode = %s', $kode )
		);
	}

	/**
	 * Tambahkan desa bila belum ada (dipakai impor untuk desa di luar polygon).
	 *
	 * Sumber selalu 'impor' — baris ini bukan bagian dari peta, jadi boleh
	 * dihapus operator bila ternyata ejaan yang salah.
	 */
	public static function pastikan( $kode, $nama, $kecamatan ) {
		if ( self::find( $kode ) ) {
			return $kode;
		}
		self::tambah_impor( $kode, $nama, $kecamatan );
		return $kode;
	}

	/** Tambahkan baris desa dari impor. */
	private static function tambah_impor( $kode, $nama, $kecamatan ) {
		global $wpdb;
		$wpdb->insert(
			self::nama_tabel(),
			array(
				'kode'      => sanitize_text_field( $kode ),
				'nama'      => sanitize_text_field( $nama ),
				'kecamatan' => sanitize_text_field( $kecamatan ),
				'sumber'    => self::SUMBER_IMPOR,
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Hapus desa yang ditambahkan lewat impor.
	 *
	 * Desa bawaan peta sengaja dijaga di sini juga, bukan hanya di lapisan
	 * admin, supaya tidak bisa terhapus lewat pemanggilan mana pun.
	 *
	 * @param string $kode Kode desa.
	 * @return bool Berhasil atau tidak.
	 */
	public static function hapus_impor( $kode ) {
		global $wpdb;
		$d = self::find( $kode );
		if ( ! $d || self::SUMBER_PETA === ( $d->sumber ?? self::SUMBER_PETA ) ) {
			return false;
		}
		return (bool) $wpdb->delete( self::nama_tabel(), array( 'kode' => $kode ), array( '%s' ) );
	}

	/**
	 * Pindahkan seluruh laporan satu desa ke desa lain.
	 *
	 * Dipakai saat operator membersihkan desa hasil typo: laporan dipindah
	 * ke desa yang benar lebih dulu, baru desa salahnya dihapus, sehingga
	 * tidak ada data yang hilang.
	 *
	 * Tujuan wajib desa bawaan peta. Berpindah ke desa impor lain hanya
	 * memindahkan masalah dan tetap meninggalkan laporan di luar peta, jadi
	 * guard-nya diletakkan di sini agar berlaku pada setiap pemanggilan,
	 * bukan hanya dari halaman admin.
	 *
	 * @param string $dari Kode desa asal.
	 * @param string $ke   Kode desa tujuan (harus sumber peta).
	 * @return int Jumlah laporan yang dipindahkan, 0 bila tujuan tidak sah.
	 */
	public static function pindahkan_laporan( $dari, $ke ) {
		global $wpdb;
		$tujuan = self::find( $ke );
		if ( ! $tujuan || self::SUMBER_PETA !== ( $tujuan->sumber ?? self::SUMBER_PETA ) ) {
			return 0;
		}
		$t = PBM_Activator::tabel_laporan();
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $t . ' SET kode_desa = %s, desa = %s, kecamatan = %s WHERE kode_desa = %s',
				$tujuan->kode,
				$tujuan->nama,
				$tujuan->kecamatan,
				$dari
			)
		);
	}

	/** Jumlah laporan per kode desa. @return array<string,int> */
	public static function laporan_per_desa() {
		global $wpdb;
		$t = PBM_Activator::tabel_laporan();
		$out = array();
		foreach ( $wpdb->get_results( "SELECT kode_desa, COUNT(*) AS n FROM $t GROUP BY kode_desa" ) as $r ) {
			$out[ $r->kode_desa ] = (int) $r->n;
		}
		return $out;
	}

	/** Jumlah desa menurut sumber. */
	public static function count_sumber( $sumber ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::nama_tabel() . ' WHERE sumber = %s', $sumber )
		);
	}

	/**
	 * Desa impor yang namanya sangat mirip desa peta — kandidat ejaan salah.
	 *
	 * Inilah yang ingin dicegah halaman ini: satu huruf berbeda membuat
	 * impor mencatat desa baru padahal aslinya sudah ada di peta.
	 *
	 * @return object[] Berisi {impor, peta, skor}.
	 */
	public static function kandidat_typo() {
		$peta_semua = self::all( '', self::SUMBER_PETA );
		$per_kec    = array();
		foreach ( $peta_semua as $d ) {
			$per_kec[ $d->kecamatan ][] = $d;
		}

		$out = array();
		foreach ( self::all( '', self::SUMBER_IMPOR ) as $impor ) {
			$skor    = 0.0;
			$terbaik = null;

			/* Utamakan desa di kecamatan yang sama. */
			foreach ( $per_kec[ $impor->kecamatan ] ?? array() as $d ) {
				$s = PBM_Import::skor_nama( $impor->nama, $d->nama );
				if ( $s > $skor ) {
					$skor    = $s;
					$terbaik = $d;
				}
			}
			/* Kalau belum melewati ambang, telusuri seluruh kabupaten. */
			if ( $skor < PBM_Import::AMBANG_MIRIP ) {
				foreach ( $peta_semua as $d ) {
					$s = PBM_Import::skor_nama( $impor->nama, $d->nama );
					if ( $s > $skor ) {
						$skor    = $s;
						$terbaik = $d;
					}
				}
			}

			if ( $terbaik && $skor >= PBM_Import::AMBANG_MIRIP ) {
				$o        = new stdClass();
				$o->impor = $impor;
				$o->peta  = $terbaik;
				$o->skor  = $skor;
				$out[]    = $o;
			}
		}
		return $out;
	}

	/**
	 * Nama desa peta yang paling mirip sebuah ejaan — untuk menyarankan
	 * koreksi saat nama tidak ditemukan.
	 *
	 * @param string $nama       Nama yang dicari.
	 * @param string $kecamatan  Kecamatan asal (hanya untuk menaikkan peringkat).
	 * @param int    $limit      Jumlah saran maksimal.
	 * @param float  $min        Ambang bawah; di bawah ini dianggap tidak mirip
	 *                           apa pun dan tidak layak disarankan.
	 * @return object[] Berisi {desa, skor}, terurut kemiripan turun.
	 */
	public static function saran( $nama, $kecamatan = '', $limit = 5, $min = 0.5 ) {
		$skor = array();
		foreach ( self::all( '', self::SUMBER_PETA ) as $d ) {
			/* Kecamatan yang benar didahulukan, tapi bukan syarat. */
			$bonus = ( '' !== $kecamatan && $d->kecamatan === $kecamatan ) ? 0.01 : 0;
			$skor[ $d->kode ] = PBM_Import::skor_nama( $nama, $d->nama ) + $bonus;
		}
		arsort( $skor );

		$out = array();
		foreach ( array_slice( array_keys( $skor ), 0, $limit ) as $kode ) {
			if ( $skor[ $kode ] < $min ) {
				break; /* sisanya sudah tidak mirip — tak perlu menampilkan. */
			}
			$d   = self::find( $kode );
			$o   = new stdClass();
			$o->desa = $d;
			$o->skor = min( 1.0, $skor[ $kode ] );
			$out[]   = $o;
		}
		return $out;
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::nama_tabel() );
	}
}
