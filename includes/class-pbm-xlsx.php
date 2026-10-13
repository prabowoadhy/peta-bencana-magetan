<?php
/**
 * Pembaca .xlsx ringan (murni PHP, tanpa Composer/PhpSpreadsheet).
 * XLSX = arsip ZIP berisi XML: sharedStrings, styles, workbook, worksheets.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PBM_Xlsx {

	private $zip;
	private $shared      = array();
	private $date_xf     = array();
	private $sheets      = array();
	private $sheet_names = array();

	public function __construct( $file ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new Exception( 'Ekstensi PHP ZipArchive tidak tersedia di server ini.' );
		}
		if ( ! is_readable( $file ) ) {
			throw new Exception( 'Berkas tidak dapat dibaca.' );
		}
		$this->zip = new ZipArchive();
		if ( true !== $this->zip->open( $file ) ) {
			throw new Exception( 'Berkas bukan .xlsx valid (gagal dibuka sebagai ZIP).' );
		}
		$this->baca_shared_strings();
		$this->baca_styles();
		$this->baca_workbook();
	}

	public function __destruct() {
		if ( $this->zip ) {
			$this->zip->close();
		}
	}

	public function sheet_names() {
		return array_values( $this->sheet_names );
	}

	private function baca_shared_strings() {
		$xml = $this->zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false === $xml ) {
			return;
		}
		$doc = simplexml_load_string( $xml );
		if ( ! $doc ) {
			return;
		}
		foreach ( $doc->si as $si ) {
			$txt = isset( $si->t ) ? (string) $si->t : '';
			foreach ( $si->r as $r ) {
				$txt .= (string) $r->t;
			}
			$this->shared[] = $txt;
		}
	}

	private function baca_styles() {
		$xml = $this->zip->getFromName( 'xl/styles.xml' );
		if ( false === $xml ) {
			return;
		}
		$doc = simplexml_load_string( $xml );
		if ( ! $doc ) {
			return;
		}
		$bawaan = array( 14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47 );
		$custom = array();
		if ( isset( $doc->numFmts->numFmt ) ) {
			foreach ( $doc->numFmts->numFmt as $nf ) {
				$kode = preg_replace( '/\[[^\]]*\]/', '', (string) $nf['formatCode'] );
				$kode = preg_replace( '/"[^"]*"/', '', $kode );
				if ( preg_match( '/[dmy]/i', $kode ) && ! preg_match( '/^[#0.,%\s]+$/', $kode ) ) {
					$custom[ (int) $nf['numFmtId'] ] = true;
				}
			}
		}
		if ( isset( $doc->cellXfs->xf ) ) {
			$i = 0;
			foreach ( $doc->cellXfs->xf as $xf ) {
				$id                   = (int) $xf['numFmtId'];
				$this->date_xf[ $i ]  = in_array( $id, $bawaan, true ) || isset( $custom[ $id ] );
				$i++;
			}
		}
	}

	private function baca_workbook() {
		$rels = array();
		$xml  = $this->zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		if ( false !== $xml ) {
			$doc = simplexml_load_string( $xml );
			if ( $doc ) {
				foreach ( $doc->Relationship as $rel ) {
					$rels[ (string) $rel['Id'] ] = (string) $rel['Target'];
				}
			}
		}
		$xml = $this->zip->getFromName( 'xl/workbook.xml' );
		if ( false === $xml ) {
			return;
		}
		$doc = simplexml_load_string( $xml );
		if ( ! $doc ) {
			return;
		}
		$i = 0;
		foreach ( $doc->sheets->sheet as $sh ) {
			$rid  = (string) $sh->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )->id;
			$path = $rels[ $rid ] ?? '';
			if ( '' !== $path && '/' !== $path[0] ) {
				$path = 'xl/' . ltrim( $path, '/' );
			}
			$this->sheet_names[ $i ] = (string) $sh['name'];
			$this->sheets[ $i ]      = $path;
			$i++;
		}
	}

	/** "AA12" -> [baris, kolom] 0-index. */
	private static function ref_ke_indeks( $ref ) {
		if ( ! preg_match( '/^([A-Z]+)(\d+)$/', $ref, $m ) ) {
			return null;
		}
		$col = 0;
		foreach ( str_split( $m[1] ) as $ch ) {
			$col = $col * 26 + ( ord( $ch ) - 64 );
		}
		return array( (int) $m[2] - 1, $col - 1 );
	}

	/**
	 * Serial tanggal Excel -> Y-m-d.
	 *
	 * Epoch Excel: serial 25569 = 1970-01-01. Konstanta 25569 itu SUDAH
	 * mengandung koreksi bug tahun kabisat 1900, jadi serial tidak boleh
	 * dikurangi lagi sebelum dikurangi epoch — kesalahan lama membuat semua
	 * tanggal mundur 1 hari.
	 *
	 * Serial <= 59 (1900-01-01 s/d 1900-02-28) memakai epoch 25568 karena
	 * berada sebelum titik gabung sistem Excel dan Unix.
	 *
	 * @param mixed $n Serial Excel.
	 * @return string|null Y-m-d, atau null bila di luar rentang tanggal.
	 */
	public static function serial_ke_tanggal( $n ) {
		$n = (float) $n;
		if ( $n <= 0 || $n > 2958465 ) {
			return null;
		}
		$hari  = (int) floor( $n );
		$epoch = ( $hari > 59 ) ? 25569 : 25568;
		return gmdate( 'Y-m-d', ( $hari - $epoch ) * 86400 );
	}

	/**
	 * Baca satu sheet.
	 * @return array{rows: array<int, array<int, mixed>>, merges: array, maxCol: int}
	 */
	public function baca_sheet( $idx = 0 ) {
		$path = $this->sheets[ $idx ] ?? '';
		if ( '' === $path ) {
			throw new Exception( 'Sheet tidak ditemukan.' );
		}
		$xml = $this->zip->getFromName( $path );
		if ( false === $xml ) {
			throw new Exception( 'Isi sheet tidak dapat dibaca.' );
		}
		$doc = simplexml_load_string( $xml );
		if ( ! $doc ) {
			throw new Exception( 'XML sheet tidak valid.' );
		}

		$rows   = array();
		$merges = array();
		$maxcol = 0;

		/* Sel gabungan: header bertingkat Excel memakainya. */
		if ( isset( $doc->mergeCells->mergeCell ) ) {
			foreach ( $doc->mergeCells->mergeCell as $mc ) {
				$r = (string) $mc['ref'];
				if ( strpos( $r, ':' ) === false ) {
					continue;
				}
				list( $a, $b ) = explode( ':', $r, 2 );
				$pa = self::ref_ke_indeks( $a );
				$pb = self::ref_ke_indeks( $b );
				if ( $pa && $pb ) {
					$merges[] = array( $pa[0], $pa[1], $pb[0], $pb[1] );
				}
			}
		}

		foreach ( $doc->sheetData->row as $row ) {
			$r       = (int) $row['r'] - 1;
			$current = 0;
			foreach ( $row->c as $c ) {
				$pos = self::ref_ke_indeks( (string) $c['r'] );
				if ( $pos ) {
					$col     = $pos[1];
					$current = $col;
				} else {
					$col = $current;
					$current++;
				}
				$maxcol = max( $maxcol, $col );

				$tipe = (string) $c['t'];
				$val  = null;

				if ( 'inlineStr' === $tipe ) {
					$val = isset( $c->is->t ) ? (string) $c->is->t : '';
					foreach ( $c->is->r as $rr ) {
						$val .= (string) $rr->t;
					}
				} else {
					$v = isset( $c->v ) ? (string) $c->v : null;
					if ( null === $v ) {
						$rows[ $r ][ $col ] = null;
						continue;
					}
					if ( 's' === $tipe ) {
						$val = $this->shared[ (int) $v ] ?? '';
					} elseif ( 'str' === $tipe ) {
						$val = $v;
					} elseif ( 'b' === $tipe ) {
						$val = ( '1' === $v ) ? 'TRUE' : 'FALSE';
					} else {
						$style = isset( $c['s'] ) ? (int) $c['s'] : 0;
						if ( ! empty( $this->date_xf[ $style ] ) && is_numeric( $v ) ) {
							$t   = self::serial_ke_tanggal( $v );
							$val = ( null !== $t ) ? $t : $v;
						} else {
							$val = $v;
						}
					}
				}
				$rows[ $r ][ $col ] = $val;
			}
		}

		/* Isi sel gabungan dari jangkar kiri-atas. */
		foreach ( $merges as $m ) {
			list( $r1, $c1, $r2, $c2 ) = $m;
			$a = $rows[ $r1 ][ $c1 ] ?? null;
			if ( null === $a || '' === $a ) {
				continue;
			}
			for ( $r = $r1; $r <= $r2; $r++ ) {
				for ( $c = $c1; $c <= $c2; $c++ ) {
					if ( ! isset( $rows[ $r ][ $c ] ) || null === $rows[ $r ][ $c ] || '' === $rows[ $r ][ $c ] ) {
						$rows[ $r ][ $c ] = $a;
					}
				}
			}
		}

		ksort( $rows );
		foreach ( $rows as $k => $v ) {
			ksort( $v );
			for ( $c = 0; $c <= $maxcol; $c++ ) {
				if ( ! array_key_exists( $c, $v ) ) {
					$v[ $c ] = null;
				}
			}
			$rows[ $k ] = $v;
		}

		return array( 'rows' => $rows, 'merges' => $merges, 'maxCol' => $maxcol + 1 );
	}
}