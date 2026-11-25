<?php
/**
 * Excel reader for the price list from a file (CX-SID-195): .xls (BIFF5 and BIFF8 in an OLE file) and .xlsx, the first
 * sheet, every cell as the owner sees it in Excel.
 *
 * Our own reader, not a library: PhpSpreadsheet would add 1.2 MB to the plugin and needs ext-zip and gd (CX-SID-195,
 * proposal 4252). OLE and BIFF are plain PHP (unpack). .xlsx needs zlib (inflate_init) and the XML parser (ext/xml); the
 * zip is read from the uploaded bytes and inflated in small steps, so nothing of it is ever written to disk.
 *
 * "As the owner sees it": text exactly as written ("12.050", "047", "518,80 €"); a number through its cell's format,
 * always with Croatian separators (dot for thousands, comma for decimals: Excel takes them from the computer's language,
 * not from the file); a format this reader does not know shows the whole number (never rounded or cut).
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the first sheet of an Excel file into a grid of displayed texts.
 */
class CX_Sidrena_Import_Excel {

	/** Columns read; a cell further right is not read (docs/changes/CX-SID-195.md). */
	const MAX_COLS = 1024;

	/** Inflated size of one sheet or of the shared strings. Over it: the file can't be read (a zip bomb, U4). */
	const MAX_SHEET_BYTES = 67108864;

	/** Inflated size of a small part: workbook, relationships, styles. */
	const MAX_PART_BYTES = 4194304;

	/** Inflated size of everything read from one file. */
	const MAX_TOTAL_BYTES = 134217728;

	/** Compressed bytes given to zlib at once: a deflate block grows at most ~1032 times, so one step stays under 9 MB. */
	const INFLATE_STEP = 8192;

	/** Rows with content read beyond the import's own limit (the header row of CX-SID-196 may be lower than the 1st). */
	const EXTRA_ROWS = 50;

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/** @var int Inflated bytes of the file being read. */
	private $inflated = 0;

	/** @var int Memory in use when the reading started, and PHP's limit (0 = none). */
	private $mem0 = 0;

	/** @var int Memory limit in bytes (0 = none). */
	private $mem_limit = 0;

	/** @var bool The workbook counts days from 1904 (old Mac Excel). */
	private $date1904 = false;

	/** @var array<int, string> Number format codes by id (the file's own; built-in ones come from builtin_format()). */
	private $formats = array();

	/** @var array<int, int> Number format id of each cell format (XF). */
	private $xf_format = array();

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * What container an upload is, from its first bytes: 'ole' (Excel 95/97, also Word .doc), 'zip' (.xlsx, .ods,
	 * .docx), or '' (anything else: CSV, XML, text).
	 *
	 * @param string $contents File bytes.
	 * @return string
	 */
	public static function container( $contents ) {
		if ( 0 === strncmp( (string) $contents, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8 ) ) {
			return 'ole';
		}
		if ( 0 === strncmp( (string) $contents, "PK\x03\x04", 4 ) || 0 === strncmp( (string) $contents, "PK\x05\x06", 4 ) ) {
			return 'zip';
		}

		return '';
	}

	/**
	 * Whether this server can read .xlsx: zlib for the zip and PHP's XML parser for its parts. .xls needs neither.
	 * The filter lets a test (or a host) say no, to show the owner the "save it as CSV" text (U5).
	 *
	 * @return bool
	 */
	public static function xlsx_readable() {
		$can = function_exists( 'inflate_init' ) && function_exists( 'inflate_add' ) && function_exists( 'inflate_get_status' ) && function_exists( 'xml_parser_create' );

		return (bool) apply_filters( 'cx_sidrena_excel_readable', $can );
	}

	/**
	 * Read the first sheet.
	 *
	 * Errors (WP_Error code, the screen's text by it): 'xls' = an Excel file that can't be read (damaged, cut, password,
	 * a limit, the server can't read .xlsx); 'ods' = an OpenDocument spreadsheet; 'other' = a zip or OLE file that is not
	 * a spreadsheet (.docx, .doc); 'empty' = the first sheet has no cell with content.
	 *
	 * @param string $contents File bytes.
	 * @param int    $max_rows Rows with content wanted (more are not read).
	 * @return array{rows: array<int, array<int, string>>, sheets: array{count: int, first: string}}|WP_Error
	 *         rows: row number as in Excel (1 = the first), in order => column (0 = A) => displayed text; only cells with
	 *         text, columns not necessarily in order.
	 */
	public function read( $contents, $max_rows ) {
		$this->inflated  = 0;
		$this->date1904  = false;
		$this->formats   = array();
		$this->xf_format = array();
		$this->mem0      = memory_get_usage( true );
		$this->mem_limit = self::bytes( (string) ini_get( 'memory_limit' ) );
		$max_rows        = max( 1, (int) $max_rows ) + self::EXTRA_ROWS;

		try {
			$box = self::container( $contents );
			if ( 'ole' === $box ) {
				$out = $this->read_xls( (string) $contents, $max_rows );
			} elseif ( 'zip' === $box ) {
				$out = $this->read_zip( (string) $contents, $max_rows );
			} else {
				throw new CX_Sidrena_Excel_Error( 'other' );
			}
		} catch ( CX_Sidrena_Excel_Error $e ) {
			return new WP_Error( $e->getMessage(), $e->getMessage() );
		}

		if ( empty( $out['rows'] ) ) {
			return new WP_Error( 'empty', 'empty' );
		}

		return $out;
	}

	/**
	 * Stop before PHP runs out of memory: a fatal error is a blank 500 for the owner (U4). The rows read so far will
	 * need about half as much again when they become the import's rows (parse_grid()), plus room for the rest of the
	 * request. Checked every 256 rows; without a limit never.
	 */
	private function room() {
		if ( $this->mem_limit <= 0 ) {
			return;
		}
		$now = memory_get_usage( true );
		if ( $now + (int) ( 0.6 * max( 0, $now - $this->mem0 ) ) + 8388608 > $this->mem_limit ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
	}

	/**
	 * A php.ini size ("128M", "1G", "-1") in bytes; 0 = no limit.
	 *
	 * @param string $v Value.
	 * @return int
	 */
	private static function bytes( $v ) {
		$v = trim( $v );
		if ( '' === $v || '-1' === $v ) {
			return 0;
		}
		$n    = (int) $v;
		$unit = strtolower( substr( $v, -1 ) );
		$mult = array(
			'k' => 1024,
			'm' => 1048576,
			'g' => 1073741824,
		);

		return $n * ( isset( $mult[ $unit ] ) ? $mult[ $unit ] : 1 );
	}

	/* ---------------------------------------------------------------------------------------------------------------
	 * .xls: OLE compound file
	 * ------------------------------------------------------------------------------------------------------------- */

	/**
	 * The workbook stream of an OLE file and the cells of its first sheet.
	 *
	 * @param string $d        File bytes.
	 * @param int    $max_rows Rows with content wanted.
	 * @return array{rows: array<int, array<int, string>>, sheets: array{count: int, first: string}}
	 */
	private function read_xls( $d, $max_rows ) {
		$ole = $this->ole_open( $d );
		$hit = null;
		foreach ( array( 'workbook', 'book' ) as $want ) { // Workbook = BIFF8 (Excel 97+), Book = BIFF5 (Excel 95)
			foreach ( $ole['entries'] as $entry ) {
				if ( 2 === $entry['type'] && $want === strtolower( $entry['name'] ) ) {
					$hit = $entry;
					break 2;
				}
			}
		}
		if ( null === $hit ) {
			foreach ( $ole['entries'] as $entry ) {
				if ( in_array( strtolower( $entry['name'] ), array( 'encryptedpackage', 'encryptioninfo' ), true ) ) {
					throw new CX_Sidrena_Excel_Error( 'xls' ); // an .xlsx with a password: Excel, but not readable
				}
			}
			throw new CX_Sidrena_Excel_Error( 'other' ); // Word .doc and other OLE files
		}

		return $this->read_biff( $this->ole_stream( $ole, $hit ), $max_rows );
	}

	/**
	 * Header, FAT and directory of an OLE file. Every chain is checked: a sector outside the file, a loop, or a chain
	 * longer than the file is damage (U4), never an endless loop.
	 *
	 * @param string $d File bytes.
	 * @return array<string, mixed>
	 */
	private function ole_open( $d ) {
		$len = strlen( $d );
		if ( $len < 512 ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		$h      = unpack( 'vminor/vmajor/vorder/vsshift/vmshift', substr( $d, 24, 10 ) );
		$sshift = (int) $h['sshift'];
		if ( 0xFFFE !== (int) $h['order'] || ! ( ( 3 === (int) $h['major'] && 9 === $sshift ) || ( 4 === (int) $h['major'] && 12 === $sshift ) ) || 6 !== (int) $h['mshift'] ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		$ss = 1 << $sshift;
		$v  = unpack( 'Vnfat/Vdir/Vx/Vcutoff/Vminifat/Vnminifat/Vdifat/Vndifat', substr( $d, 44, 32 ) );
		if ( 4096 !== (int) $v['cutoff'] || $len <= $ss ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		$nsect = (int) ceil( ( $len - $ss ) / $ss ); // sectors that start inside the file

		// The FAT's own sectors: 109 in the header, the rest in the DIFAT chain.
		$fat_ids = array();
		$head    = array_values( unpack( 'V109', substr( $d, 76, 436 ) ) );
		$nfat    = (int) $v['nfat'];
		if ( $nfat < 1 || $nfat > $nsect ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		for ( $i = 0; $i < 109 && count( $fat_ids ) < $nfat; $i++ ) {
			$fat_ids[] = (int) $head[ $i ];
		}
		$sid  = (int) $v['difat'];
		$seen = array();
		$per  = (int) ( $ss / 4 ) - 1;
		for ( $n = 0; count( $fat_ids ) < $nfat; $n++ ) {
			if ( $n >= (int) $v['ndifat'] || $sid >= $nsect || isset( $seen[ $sid ] ) ) {
				throw new CX_Sidrena_Excel_Error( 'xls' );
			}
			$seen[ $sid ] = true;
			$ids          = array_values( unpack( 'V' . ( $per + 1 ), $this->sector( $d, $sid, $ss ) ) );
			for ( $i = 0; $i < $per && count( $fat_ids ) < $nfat; $i++ ) {
				$fat_ids[] = (int) $ids[ $i ];
			}
			$sid = (int) $ids[ $per ];
		}
		$fat = array();
		foreach ( $fat_ids as $fid ) {
			if ( $fid >= $nsect ) {
				throw new CX_Sidrena_Excel_Error( 'xls' );
			}
			foreach ( unpack( 'V' . ( $ss / 4 ), $this->sector( $d, $fid, $ss ) ) as $next ) {
				$fat[] = (int) $next;
			}
		}

		$ole = array(
			'd'      => $d,
			'ss'     => $ss,
			'nsect'  => $nsect,
			'fat'    => $fat,
			'minifat_start' => (int) $v['minifat'],
			'entries' => array(),
			'root'   => null,
		);

		$dir = $this->chain_bytes( $ole, (int) $v['dir'], null );
		for ( $p = 0; $p + 128 <= strlen( $dir ); $p += 128 ) {
			$nl   = unpack( 'v', substr( $dir, $p + 64, 2 ) )[1];
			$type = ord( $dir[ $p + 66 ] );
			if ( 0 === $type || $nl < 2 || $nl > 64 ) {
				continue;
			}
			$name = (string) $this->utf16( substr( $dir, $p, $nl - 2 ) );
			$se   = unpack( 'Vstart/Vsize', substr( $dir, $p + 116, 8 ) );
			$e    = array(
				'name'  => $name,
				'type'  => $type,
				'start' => (int) $se['start'],
				'size'  => (int) $se['size'],
			);
			if ( 5 === $type && null === $ole['root'] ) {
				$ole['root'] = $e;
			}
			$ole['entries'][] = $e;
		}

		return $ole;
	}

	/**
	 * One sector's bytes (sector 0 starts after the header sector).
	 *
	 * @param string $d   File bytes.
	 * @param int    $sid Sector id.
	 * @param int    $ss  Sector size.
	 * @return string
	 */
	private function sector( $d, $sid, $ss ) {
		$s = substr( $d, ( $sid + 1 ) * $ss, $ss );

		return str_pad( (string) $s, $ss, "\0" ); // the last sector may be short in the file
	}

	/**
	 * The bytes of a chain of sectors, cut to $size when given.
	 *
	 * @param array<string, mixed> $ole   OLE file.
	 * @param int                  $start First sector.
	 * @param int|null             $size  Stream size, or null (the whole chain).
	 * @return string
	 */
	private function chain_bytes( $ole, $start, $size ) {
		$out  = '';
		$sid  = $start;
		$seen = array();
		while ( 0xFFFFFFFE !== $sid ) {
			if ( $sid >= $ole['nsect'] || isset( $seen[ $sid ] ) || ! isset( $ole['fat'][ $sid ] ) ) {
				throw new CX_Sidrena_Excel_Error( 'xls' ); // outside the file, a loop, or a cut file
			}
			$seen[ $sid ] = true;
			$out         .= $this->sector( $ole['d'], $sid, $ole['ss'] );
			if ( null !== $size && strlen( $out ) >= $size ) {
				break;
			}
			$sid = $ole['fat'][ $sid ];
		}
		if ( null !== $size ) {
			if ( strlen( $out ) < $size ) {
				throw new CX_Sidrena_Excel_Error( 'xls' );
			}
			$out = substr( $out, 0, $size );
		}

		return $out;
	}

	/**
	 * A stream's bytes: from the FAT, or from the mini stream when it is smaller than 4096 bytes.
	 *
	 * @param array<string, mixed> $ole   OLE file.
	 * @param array<string, mixed> $entry Directory entry.
	 * @return string
	 */
	private function ole_stream( $ole, $entry ) {
		$size = (int) $entry['size'];
		if ( $size > strlen( $ole['d'] ) ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		if ( $size >= 4096 ) {
			return $this->chain_bytes( $ole, (int) $entry['start'], $size );
		}
		if ( null === $ole['root'] ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		$mini    = $this->chain_bytes( $ole, (int) $ole['root']['start'], (int) $ole['root']['size'] );
		$minifat = array();
		foreach ( str_split( $this->chain_bytes( $ole, $ole['minifat_start'], null ), 4 ) as $four ) {
			if ( 4 === strlen( $four ) ) {
				$minifat[] = unpack( 'V', $four )[1];
			}
		}
		$out  = '';
		$sid  = (int) $entry['start'];
		$seen = array();
		while ( strlen( $out ) < $size ) {
			if ( isset( $seen[ $sid ] ) || ! isset( $minifat[ $sid ] ) || ( $sid + 1 ) * 64 > strlen( $mini ) ) {
				throw new CX_Sidrena_Excel_Error( 'xls' );
			}
			$seen[ $sid ] = true;
			$out         .= substr( $mini, $sid * 64, 64 );
			$sid          = (int) $minifat[ $sid ];
		}

		return substr( $out, 0, $size );
	}

	/* ---------------------------------------------------------------------------------------------------------------
	 * .xls: BIFF records
	 * ------------------------------------------------------------------------------------------------------------- */

	/**
	 * The cells of the first worksheet of a BIFF5 or BIFF8 workbook stream.
	 *
	 * @param string $s        Workbook stream.
	 * @param int    $max_rows Rows with content wanted.
	 * @return array{rows: array<int, array<int, string>>, sheets: array{count: int, first: string}}
	 */
	private function read_biff( $s, $max_rows ) {
		$recs = $this->biff_records( $s, 0 );
		if ( empty( $recs ) || 0x0809 !== $recs[0][0] || strlen( $recs[0][1] ) < 4 ) {
			throw new CX_Sidrena_Excel_Error( 'xls' ); // BIFF2-4 (Excel 2-4) and anything else
		}
		$version = unpack( 'v', substr( $recs[0][1], 0, 2 ) )[1];
		if ( 0x0500 !== $version && 0x0600 !== $version ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		$biff8    = 0x0600 === $version;
		$codepage = $biff8 ? 1200 : 1250; // BIFF5 without CODEPAGE: Croatian Windows (cp1250, U2), as a CSV is read
		$sheets   = array();
		$sst      = array();
		for ( $i = 1, $n = count( $recs ); $i < $n; $i++ ) {
			list( $type, $b ) = $recs[ $i ];
			if ( 0x000A === $type ) {
				break; // EOF of the globals
			}
			switch ( $type ) {
				case 0x002F: // FILEPASS: the workbook is protected with a password
					throw new CX_Sidrena_Excel_Error( 'xls' );
				case 0x0042: // CODEPAGE
					if ( strlen( $b ) >= 2 ) {
						$codepage = unpack( 'v', $b )[1];
					}
					break;
				case 0x0022: // DATEMODE
					$this->date1904 = strlen( $b ) >= 2 && 1 === unpack( 'v', $b )[1];
					break;
				case 0x041E: // FORMAT
					if ( strlen( $b ) >= 3 ) {
						$id  = unpack( 'v', $b )[1];
						$pos = 2;
						$this->formats[ $id ] = $biff8 ? $this->biff8_string( $b, $pos, 2 ) : $this->biff5_string( $b, $pos, 1, $codepage );
					}
					break;
				case 0x00E0: // XF: its number format
					if ( strlen( $b ) >= 4 ) {
						$this->xf_format[] = unpack( 'v', substr( $b, 2, 2 ) )[1];
					}
					break;
				case 0x0085: // BOUNDSHEET: the sheets in the order of their tabs
					if ( strlen( $b ) >= 8 ) {
						$pos      = 6;
						$sheets[] = array(
							'pos'    => unpack( 'V', $b )[1],
							'hidden' => 0 !== ( ord( $b[4] ) & 3 ),
							'kind'   => ord( $b[5] ),
							'name'   => $biff8 ? $this->biff8_string( $b, $pos, 1 ) : $this->biff5_string( $b, $pos, 1, $codepage ),
						);
					}
					break;
				case 0x00FC: // SST (BIFF8): the shared strings, with the CONTINUE records after it
					$parts = array( $b );
					while ( $i + 1 < $n && 0x003C === $recs[ $i + 1 ][0] ) {
						$parts[] = $recs[ ++$i ][1];
					}
					$sst = $this->biff8_sst( $parts );
					break;
			}
		}

		$work  = array();
		$first = null;
		foreach ( $sheets as $sheet ) {
			if ( 0 === $sheet['kind'] ) {
				$work[] = $sheet;
				if ( null === $first && ! $sheet['hidden'] ) {
					$first = $sheet;
				}
			}
		}
		if ( null === $first ) {
			$first = isset( $work[0] ) ? $work[0] : null;
		}
		if ( null === $first ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}

		return array(
			'rows'   => $this->biff_sheet( $s, (int) $first['pos'], $biff8, $codepage, $sst, $max_rows ),
			'sheets' => array(
				'count' => count( $work ),
				'first' => (string) $first['name'],
			),
		);
	}

	/**
	 * The records of one substream (the globals, or one sheet) from its BOF to its EOF: [type, body]. A chart inside a
	 * sheet has its own BOF and EOF, so they are counted. A record past the end, or a stream that ends first, is damage.
	 *
	 * @param string $s    Stream.
	 * @param int    $from Offset of the BOF.
	 * @return array<int, array{0: int, 1: string}>
	 */
	private function biff_records( $s, $from ) {
		$out   = array();
		$len   = strlen( $s );
		$pos   = $from;
		$depth = 0;
		while ( $pos + 4 <= $len ) {
			$h    = unpack( 'vtype/vsize', substr( $s, $pos, 4 ) );
			$type = (int) $h['type'];
			$size = (int) $h['size'];
			if ( $pos + 4 + $size > $len ) {
				throw new CX_Sidrena_Excel_Error( 'xls' );
			}
			if ( in_array( $type, array( 0x0809, 0x0409, 0x0209, 0x0009 ), true ) ) {
				++$depth;
			}
			if ( $depth > 0 ) {
				$out[] = array( $type, (string) substr( $s, $pos + 4, $size ) );
			}
			$pos += 4 + $size;
			if ( 0x000A === $type && --$depth <= 0 ) {
				return $out;
			}
		}
		if ( empty( $out ) ) {
			return $out;
		}

		throw new CX_Sidrena_Excel_Error( 'xls' ); // no EOF: the stream was cut
	}

	/**
	 * The cells of one worksheet substream.
	 *
	 * @param string            $s        Workbook stream.
	 * @param int               $at       Offset of the sheet's BOF.
	 * @param bool              $biff8    BIFF8.
	 * @param int               $codepage Code page of BIFF5 text.
	 * @param array<int,string> $sst      Shared strings.
	 * @param int               $max_rows Rows with content wanted.
	 * @return array<int, array<int, string>>
	 */
	private function biff_sheet( $s, $at, $biff8, $codepage, $sst, $max_rows ) {
		if ( $at < 0 || $at + 4 > strlen( $s ) ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		$recs = $this->biff_records( $s, $at );
		if ( empty( $recs ) || 0x0809 !== $recs[0][0] ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		$rows    = array();
		$pending = null; // a formula whose text result is in the next STRING record
		$depth   = 0;
		foreach ( $recs as $rec ) {
			list( $type, $b ) = $rec;
			$len = strlen( $b );
			if ( 0x0809 === $type ) {
				++$depth;
			} elseif ( 0x000A === $type ) {
				--$depth;
			}
			if ( $depth > 1 ) {
				continue; // a chart's records inside the sheet
			}
			if ( $len < 6 && 0x0207 !== $type ) {
				continue;
			}
			switch ( $type ) {
				case 0x0204: // LABEL
				case 0x00D6: // RSTRING (text with formatting runs)
					$c   = unpack( 'vrow/vcol', $b );
					$pos = 6;
					$this->put( $rows, $c['row'], $c['col'], $biff8 ? $this->biff8_string( $b, $pos, 2 ) : $this->biff5_string( $b, $pos, 2, $codepage ) );
					break;
				case 0x00FD: // LABELSST
					if ( $len >= 10 ) {
						$c = unpack( 'vrow/vcol/vxf/Visst', $b );
						if ( ! isset( $sst[ $c['isst'] ] ) ) {
							throw new CX_Sidrena_Excel_Error( 'xls' );
						}
						$this->put( $rows, $c['row'], $c['col'], $sst[ $c['isst'] ] );
					}
					break;
				case 0x0203: // NUMBER
					if ( $len >= 14 ) {
						$c = unpack( 'vrow/vcol/vxf', $b );
						$this->put( $rows, $c['row'], $c['col'], $this->show_number( unpack( 'e', substr( $b, 6, 8 ) )[1], $c['xf'] ) );
					}
					break;
				case 0x027E: // RK
					if ( $len >= 10 ) {
						$c = unpack( 'vrow/vcol/vxf/Vrk', $b );
						$this->put( $rows, $c['row'], $c['col'], $this->show_number( $this->rk( (int) $c['rk'] ), $c['xf'] ) );
					}
					break;
				case 0x00BD: // MULRK
					$c = unpack( 'vrow/vcol', $b );
					for ( $k = 0, $count = (int) ( ( $len - 6 ) / 6 ); $k < $count; $k++ ) {
						$x = unpack( 'vxf/Vrk', substr( $b, 4 + 6 * $k, 6 ) );
						$this->put( $rows, $c['row'], $c['col'] + $k, $this->show_number( $this->rk( (int) $x['rk'] ), $x['xf'] ) );
					}
					break;
				case 0x0205: // BOOLERR
					if ( $len >= 8 ) {
						$c = unpack( 'vrow/vcol/vxf/Cvalue/Cerror', $b );
						$this->put( $rows, $c['row'], $c['col'], $c['error'] ? $this->error_text( $c['value'] ) : ( $c['value'] ? 'ISTINA' : 'LAŽ' ) );
					}
					break;
				case 0x0006: // FORMULA: its saved result, never calculated
					if ( $len >= 14 ) {
						$c = unpack( 'vrow/vcol/vxf', $b );
						if ( "\xFF\xFF" === substr( $b, 12, 2 ) ) {
							$kind = ord( $b[6] );
							if ( 0 === $kind ) {
								$pending = $c;
							} elseif ( 1 === $kind ) {
								$this->put( $rows, $c['row'], $c['col'], ord( $b[8] ) ? 'ISTINA' : 'LAŽ' );
							} elseif ( 2 === $kind ) {
								$this->put( $rows, $c['row'], $c['col'], $this->error_text( ord( $b[8] ) ) );
							}
						} else {
							$this->put( $rows, $c['row'], $c['col'], $this->show_number( unpack( 'e', substr( $b, 6, 8 ) )[1], $c['xf'] ) );
						}
					}
					break;
				case 0x0207: // STRING: the text result of the formula before it
					if ( null !== $pending ) {
						$pos = 0;
						$this->put( $rows, $pending['row'], $pending['col'], $biff8 ? $this->biff8_string( $b, $pos, 2 ) : $this->biff5_string( $b, $pos, 2, $codepage ) );
						$pending = null;
					}
					break;
			}
			if ( count( $rows ) > $max_rows ) {
				break;
			}
			if ( 0 === count( $rows ) % 256 ) {
				$this->room();
			}
		}

		$this->order_rows( $rows );

		return $rows;
	}

	/**
	 * Keep a cell's text (0-based row and column from the file, 1-based row in the grid).
	 *
	 * @param array<int, array<int, string>> $rows Grid.
	 * @param int                            $row  Row (0-based).
	 * @param int                            $col  Column (0-based).
	 * @param string                         $text Displayed text.
	 */
	private function put( &$rows, $row, $col, $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) || $col >= self::MAX_COLS || $col < 0 || $row < 0 ) {
			return;
		}
		$rows[ $row + 1 ][ (int) $col ] = $text;
	}

	/**
	 * Rows by number (columns stay as they came: their key is the column). Sorted in place and only when a file wrote its
	 * rows out of order: a copy of a 25 000-row sheet would cost tens of MB (128M on a shop, C195-big).
	 *
	 * @param array<int, array<int, string>> $rows Grid.
	 */
	private function order_rows( &$rows ) {
		$prev = -1;
		foreach ( array_keys( $rows ) as $n ) {
			if ( $n <= $prev ) {
				ksort( $rows, SORT_NUMERIC );
				return;
			}
			$prev = $n;
		}
	}

	/**
	 * An RK number (BIFF's packed number).
	 *
	 * @param int $v The 32 bits.
	 * @return float
	 */
	private function rk( $v ) {
		if ( $v & 2 ) {
			$n = ( $v >> 2 ) & 0x3FFFFFFF;
			if ( $n & 0x20000000 ) {
				$n -= 0x40000000;
			}
			$num = (float) $n;
		} else {
			$num = unpack( 'e', pack( 'V', 0 ) . pack( 'V', $v & 0xFFFFFFFC ) )[1];
		}

		return ( $v & 1 ) ? $num / 100 : $num;
	}

	/**
	 * A BIFF error value as Excel shows it.
	 *
	 * @param int $code Error code.
	 * @return string
	 */
	private function error_text( $code ) {
		$map = array(
			0x00 => '#NULL!',
			0x07 => '#DIV/0!',
			0x0F => '#VALUE!',
			0x17 => '#REF!',
			0x1D => '#NAME?',
			0x24 => '#NUM!',
			0x2A => '#N/A',
		);

		return isset( $map[ $code ] ) ? $map[ $code ] : '#N/A';
	}

	/**
	 * BIFF5 text: a byte or word length, then bytes in the workbook's code page.
	 *
	 * @param string $b        Record body.
	 * @param int    $pos      Offset (moved past the text).
	 * @param int    $lenbytes 1 or 2.
	 * @param int    $codepage Code page.
	 * @return string
	 */
	private function biff5_string( $b, &$pos, $lenbytes, $codepage ) {
		if ( $pos + $lenbytes > strlen( $b ) ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		$n    = 1 === $lenbytes ? ord( $b[ $pos ] ) : unpack( 'v', substr( $b, $pos, 2 ) )[1];
		$pos += $lenbytes;
		$raw  = (string) substr( $b, $pos, $n );
		$pos += $n;

		return $this->decode8( $raw, $codepage );
	}

	/**
	 * BIFF8 text (XLUnicodeString): length, a flags byte, then Latin-1 bytes or UTF-16; runs and phonetic data skipped.
	 *
	 * @param string $b        Record body.
	 * @param int    $pos      Offset (moved past the text).
	 * @param int    $lenbytes 1 or 2.
	 * @return string
	 */
	private function biff8_string( $b, &$pos, $lenbytes ) {
		if ( $pos + $lenbytes + 1 > strlen( $b ) ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		$n     = 1 === $lenbytes ? ord( $b[ $pos ] ) : unpack( 'v', substr( $b, $pos, 2 ) )[1];
		$pos  += $lenbytes;
		$flags = ord( $b[ $pos++ ] );
		$runs  = 0;
		$ext   = 0;
		if ( $flags & 8 ) {
			$runs = unpack( 'v', substr( $b, $pos, 2 ) )[1];
			$pos += 2;
		}
		if ( $flags & 4 ) {
			$ext  = unpack( 'V', substr( $b, $pos, 4 ) )[1];
			$pos += 4;
		}
		$wide = $flags & 1;
		$raw  = (string) substr( $b, $pos, $wide ? 2 * $n : $n );
		$pos += strlen( $raw ) + 4 * $runs + $ext;

		return $wide ? $this->utf16( $raw ) : $this->latin1( $raw );
	}

	/**
	 * The shared strings of BIFF8 (SST and its CONTINUE records). A string's characters may go on in the next record,
	 * which then starts with its own flags byte (wide or not); runs and phonetic data may also be split.
	 *
	 * @param array<int, string> $parts SST body, then the CONTINUE bodies.
	 * @return array<int, string>
	 */
	private function biff8_sst( $parts ) {
		$out = array();
		$pi  = 0;
		$cur = $parts[0];
		$pos = 8;
		if ( strlen( $cur ) < 8 ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		$unique = unpack( 'V', substr( $cur, 4, 4 ) )[1];
		$next   = function () use ( &$pi, &$cur, &$pos, $parts ) {
			++$pi;
			if ( ! isset( $parts[ $pi ] ) ) {
				throw new CX_Sidrena_Excel_Error( 'xls' );
			}
			$cur = $parts[ $pi ];
			$pos = 0;
		};
		$take   = function ( $n ) use ( &$cur, &$pos, $next ) {
			$got = '';
			while ( strlen( $got ) < $n ) {
				if ( $pos >= strlen( $cur ) ) {
					$next();
				}
				$piece = (string) substr( $cur, $pos, $n - strlen( $got ) );
				$got  .= $piece;
				$pos  += strlen( $piece );
			}

			return $got;
		};
		for ( $k = 0; $k < $unique; $k++ ) {
			if ( $pos >= strlen( $cur ) ) {
				$next();
			}
			$head  = $take( 3 );
			$n     = unpack( 'v', $head )[1];
			$flags = ord( $head[2] );
			$runs  = ( $flags & 8 ) ? unpack( 'v', $take( 2 ) )[1] : 0;
			$ext   = ( $flags & 4 ) ? unpack( 'V', $take( 4 ) )[1] : 0;
			$wide  = $flags & 1;
			$text  = '';
			$left  = $n;
			while ( $left > 0 ) {
				if ( $pos >= strlen( $cur ) ) {
					$next();
					$wide = ord( $cur[0] ) & 1; // the characters go on, with their own width
					$pos  = 1;
				}
				$avail = (int) ( ( strlen( $cur ) - $pos ) / ( $wide ? 2 : 1 ) );
				$count = min( $avail, $left );
				if ( $count < 1 ) {
					throw new CX_Sidrena_Excel_Error( 'xls' );
				}
				$raw   = (string) substr( $cur, $pos, $wide ? 2 * $count : $count );
				$text .= $wide ? $this->utf16( $raw ) : $this->latin1( $raw );
				$pos  += strlen( $raw );
				$left -= $count;
			}
			$skip = 4 * $runs + $ext;
			if ( $skip > 0 ) {
				$take( $skip );
			}
			$out[] = $text;
		}

		return $out;
	}

	/**
	 * 8-bit text in a code page into UTF-8.
	 *
	 * @param string $raw      Bytes.
	 * @param int    $codepage Windows code page (BIFF5 CODEPAGE).
	 * @return string
	 */
	private function decode8( $raw, $codepage ) {
		if ( '' === $raw || ! preg_match( '/[\x80-\xFF]/', $raw ) ) {
			return $raw;
		}
		$map = array(
			367   => 'ASCII',
			1250  => 'CP1250',
			1251  => 'CP1251',
			1252  => 'CP1252',
			1257  => 'CP1257',
			10000 => 'MACINTOSH',
			32768 => 'MACINTOSH',
			32769 => 'CP1252',
		);
		$enc = isset( $map[ $codepage ] ) ? $map[ $codepage ] : 'CP1250';
		if ( function_exists( 'iconv' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an iconv notice would break the AJAX JSON
			$out = @iconv( $enc, 'UTF-8//IGNORE', $raw );
			if ( is_string( $out ) ) {
				return $out;
			}
		}

		return $this->latin1( $raw );
	}

	/**
	 * Latin-1 bytes (BIFF8's compressed text) into UTF-8.
	 *
	 * @param string $raw Bytes.
	 * @return string
	 */
	private function latin1( $raw ) {
		if ( ! preg_match( '/[\x80-\xFF]/', $raw ) ) {
			return $raw;
		}
		$out = '';
		for ( $i = 0, $n = strlen( $raw ); $i < $n; $i++ ) {
			$out .= self::utf8_char( ord( $raw[ $i ] ) );
		}

		return $out;
	}

	/**
	 * UTF-16LE into UTF-8 (surrogate pairs joined, a lone one becomes U+FFFD).
	 *
	 * @param string $raw Bytes.
	 * @return string
	 */
	private function utf16( $raw ) {
		$out   = '';
		$units = array_values( (array) unpack( 'v*', strlen( $raw ) % 2 ? substr( $raw, 0, -1 ) : $raw ) );
		for ( $i = 0, $n = count( $units ); $i < $n; $i++ ) {
			$u = $units[ $i ];
			if ( $u >= 0xD800 && $u <= 0xDBFF && isset( $units[ $i + 1 ] ) && $units[ $i + 1 ] >= 0xDC00 && $units[ $i + 1 ] <= 0xDFFF ) {
				$u = 0x10000 + ( ( $u - 0xD800 ) << 10 ) + ( $units[ ++$i ] - 0xDC00 );
			} elseif ( $u >= 0xD800 && $u <= 0xDFFF ) {
				$u = 0xFFFD;
			}
			$out .= self::utf8_char( $u );
		}

		return $out;
	}

	/**
	 * One code point in UTF-8.
	 *
	 * @param int $cp Code point.
	 * @return string
	 */
	private static function utf8_char( $cp ) {
		if ( $cp < 0x80 ) {
			return chr( $cp );
		}
		if ( $cp < 0x800 ) {
			return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		if ( $cp < 0x10000 ) {
			return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}

		return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}

	/* ---------------------------------------------------------------------------------------------------------------
	 * .xlsx: zip and XML
	 * ------------------------------------------------------------------------------------------------------------- */

	/**
	 * A zip: an .xlsx is read, an .ods and anything else is refused with its own code.
	 *
	 * @param string $d        File bytes.
	 * @param int    $max_rows Rows with content wanted.
	 * @return array{rows: array<int, array<int, string>>, sheets: array{count: int, first: string}}
	 */
	private function read_zip( $d, $max_rows ) {
		$zip = $this->zip_index( $d );
		if ( isset( $zip['mimetype'] ) ) {
			$type = 0 === $zip['mimetype']['method'] ? (string) substr( $d, $this->zip_data_at( $d, $zip['mimetype'] ), 80 ) : '';
			if ( 0 === strpos( $type, 'application/vnd.oasis.opendocument.spreadsheet' ) ) {
				throw new CX_Sidrena_Excel_Error( 'ods' );
			}
			if ( 0 === strpos( $type, 'application/vnd.oasis.opendocument' ) ) {
				throw new CX_Sidrena_Excel_Error( 'other' );
			}
		}
		$book = '';
		foreach ( array_keys( $zip ) as $name ) {
			if ( preg_match( '#(^|/)workbook\.xml$#', $name ) ) {
				$book = $name;
				break;
			}
		}
		if ( '' === $book ) {
			throw new CX_Sidrena_Excel_Error( isset( $zip['content.xml'] ) && isset( $zip['meta.xml'] ) ? 'ods' : 'other' );
		}
		if ( ! self::xlsx_readable() ) {
			throw new CX_Sidrena_Excel_Error( 'xls' ); // this server can't read .xlsx (U5)
		}

		// The workbook: from the package's relationships, or where Excel puts it.
		foreach ( $this->rels( $d, $zip, '_rels/.rels', '' ) as $rel ) {
			if ( '/officedocument' === substr( strtolower( $rel['type'] ), -15 ) && isset( $zip[ $rel['target'] ] ) ) {
				$book = $rel['target'];
				break;
			}
		}
		$dir    = false !== strrpos( $book, '/' ) ? substr( $book, 0, strrpos( $book, '/' ) + 1 ) : '';
		$wbrels = $this->rels( $d, $zip, $dir . '_rels/' . basename( $book ) . '.rels', $dir );

		$sheets = array();
		$this->xml_run(
			$this->part( $d, $zip, $book, self::MAX_PART_BYTES ),
			function ( $name, $attrs ) use ( &$sheets ) {
				if ( 'workbookPr' === $name && isset( $attrs['date1904'] ) ) {
					$this->date1904 = in_array( strtolower( (string) $attrs['date1904'] ), array( '1', 'true' ), true );
				}
				if ( 'sheet' === $name ) {
					$sheets[] = array(
						'name'   => isset( $attrs['name'] ) ? (string) $attrs['name'] : '',
						'hidden' => isset( $attrs['state'] ) && 'visible' !== (string) $attrs['state'],
						'rid'    => isset( $attrs['id'] ) ? (string) $attrs['id'] : '',
					);
				}
			}
		);
		$first = null;
		foreach ( $sheets as $sheet ) {
			if ( ! $sheet['hidden'] ) {
				$first = $sheet;
				break;
			}
		}
		if ( null === $first && $sheets ) {
			$first = $sheets[0];
		}
		$target = '';
		$styles = '';
		$shared = '';
		foreach ( $wbrels as $id => $rel ) {
			$type = strtolower( $rel['type'] );
			if ( null !== $first && $id === $first['rid'] ) {
				$target = $rel['target'];
			}
			if ( '/styles' === substr( $type, -7 ) ) {
				$styles = $rel['target'];
			}
			if ( '/sharedstrings' === substr( $type, -14 ) ) {
				$shared = $rel['target'];
			}
		}
		if ( null === $first || '' === $target || ! isset( $zip[ $target ] ) ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}

		if ( '' !== $styles && isset( $zip[ $styles ] ) ) {
			$this->xlsx_styles( $this->part( $d, $zip, $styles, self::MAX_PART_BYTES ) );
		}
		$strings = ( '' !== $shared && isset( $zip[ $shared ] ) ) ? $this->xlsx_shared( $d, $zip, $shared ) : array();

		return array(
			'rows'   => $this->xlsx_sheet( $d, $zip, $target, $strings, $max_rows ),
			'sheets' => array(
				'count' => count( $sheets ),
				'first' => (string) $first['name'],
			),
		);
	}

	/**
	 * The zip's central directory: lower-case part name => entry. Zip64, a split archive, an encrypted entry or an
	 * unknown method can't be read here.
	 *
	 * @param string $d File bytes.
	 * @return array<string, array<string, int>>
	 */
	private function zip_index( $d ) {
		$len  = strlen( $d );
		$from = max( 0, $len - 65557 );
		$at   = strrpos( (string) substr( $d, $from ), "PK\x05\x06" );
		if ( false === $at || $from + $at + 22 > $len ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		$eocd = $from + $at;
		$e    = unpack( 'vdisk/vcddisk/vhere/ventries/Vsize/Voffset', substr( $d, $eocd + 4, 16 ) );
		if ( 0xFFFF === $e['entries'] || 0xFFFFFFFF === $e['size'] || 0xFFFFFFFF === $e['offset'] || 0 !== $e['disk'] || 0 !== $e['cddisk']
			|| ( $eocd >= 20 && "PK\x06\x07" === substr( $d, $eocd - 20, 4 ) ) ) {
			throw new CX_Sidrena_Excel_Error( 'xls' ); // zip64 or a split archive
		}
		$out = array();
		$p   = (int) $e['offset'];
		for ( $i = 0; $i < $e['entries']; $i++ ) {
			if ( $p + 46 > $len || "PK\x01\x02" !== substr( $d, $p, 4 ) ) {
				throw new CX_Sidrena_Excel_Error( 'xls' );
			}
			$c    = unpack( 'vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/vint/Vext/Vlho', substr( $d, $p + 8, 38 ) );
			$name = (string) substr( $d, $p + 46, $c['nlen'] );
			$xtra = (string) substr( $d, $p + 46 + $c['nlen'], $c['xlen'] );
			$p   += 46 + $c['nlen'] + $c['xlen'] + $c['clen'];
			if ( 0xFFFFFFFF === $c['csize'] || 0xFFFFFFFF === $c['usize'] || 0xFFFFFFFF === $c['lho'] || self::has_zip64( $xtra ) ) {
				throw new CX_Sidrena_Excel_Error( 'xls' ); // zip64 (no Excel writes it for a file that fits in 20 MB)
			}
			$key = strtolower( ltrim( str_replace( '\\', '/', $name ), '/' ) );
			if ( '' === $key || isset( $out[ $key ] ) ) {
				continue; // the first entry of a name counts
			}
			$out[ $key ] = array(
				'flags'  => (int) $c['flags'],
				'method' => (int) $c['method'],
				'csize'  => (int) $c['csize'],
				'usize'  => (int) $c['usize'],
				'lho'    => (int) $c['lho'],
			);
		}

		return $out;
	}

	/**
	 * Where an entry's data starts (after its local header).
	 *
	 * @param string             $d     File bytes.
	 * @param array<string, int> $entry Entry.
	 * @return int
	 */
	private function zip_data_at( $d, $entry ) {
		$lho = $entry['lho'];
		if ( $lho + 30 > strlen( $d ) || "PK\x03\x04" !== substr( $d, $lho, 4 ) ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		$l    = unpack( 'vnlen/vxlen', substr( $d, $lho + 26, 4 ) );
		$from = $lho + 30 + $l['nlen'] + $l['xlen'];
		if ( $from + $entry['csize'] > strlen( $d ) || self::has_zip64( (string) substr( $d, $lho + 30 + $l['nlen'], $l['xlen'] ) ) ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}

		return $from;
	}

	/**
	 * Whether a zip header's extra field has the zip64 block (id 0x0001): its sizes are not the ones read here.
	 *
	 * @param string $extra Extra field.
	 * @return bool
	 */
	private static function has_zip64( $extra ) {
		for ( $i = 0; $i + 4 <= strlen( $extra ); ) {
			$h = unpack( 'vid/vsize', substr( $extra, $i, 4 ) );
			if ( 0x0001 === $h['id'] ) {
				return true;
			}
			$i += 4 + $h['size'];
		}

		return false;
	}

	/**
	 * An entry's inflated bytes, piece by piece, to $sink; over $limit (or over the file's total) the file can't be read.
	 *
	 * @param string             $d     File bytes.
	 * @param array<string, int> $entry Entry.
	 * @param int                $limit Most inflated bytes.
	 * @param callable           $sink  Gets each piece.
	 */
	private function zip_each( $d, $entry, $limit, $sink ) {
		if ( $entry['flags'] & 1 ) {
			throw new CX_Sidrena_Excel_Error( 'xls' ); // encrypted
		}
		$from = $this->zip_data_at( $d, $entry );
		$size = $entry['csize'];
		$made = 0;
		$give = function ( $piece ) use ( &$made, $limit, $sink ) {
			$made           += strlen( $piece );
			$this->inflated += strlen( $piece );
			if ( $made > $limit || $this->inflated > self::MAX_TOTAL_BYTES ) {
				throw new CX_Sidrena_Excel_Error( 'xls' );
			}
			if ( '' !== $piece ) {
				$sink( $piece );
			}
		};
		if ( 0 === $entry['method'] ) {
			if ( $size !== $entry['usize'] ) {
				throw new CX_Sidrena_Excel_Error( 'xls' );
			}
			for ( $o = 0; $o < $size; $o += 65536 ) {
				$give( (string) substr( $d, $from + $o, min( 65536, $size - $o ) ) );
			}
			return;
		}
		if ( 8 !== $entry['method'] ) {
			throw new CX_Sidrena_Excel_Error( 'xls' ); // bzip2, LZMA and the rest
		}
		$ctx = inflate_init( ZLIB_ENCODING_RAW );
		if ( false === $ctx ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		// Stop at the end of the deflate stream: PHP resets the context on the next inflate_add() after it, so a last
		// call with ZLIB_FINISH would start a new stream and fail.
		$ended = false;
		for ( $o = 0; $o < $size && ! $ended; $o += self::INFLATE_STEP ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a zlib warning would break the AJAX JSON
			$out = @inflate_add( $ctx, (string) substr( $d, $from + $o, min( self::INFLATE_STEP, $size - $o ) ), ZLIB_SYNC_FLUSH );
			if ( false === $out ) {
				throw new CX_Sidrena_Excel_Error( 'xls' );
			}
			$give( $out );
			$ended = ZLIB_STREAM_END === inflate_get_status( $ctx );
		}
		if ( ! $ended ) {
			throw new CX_Sidrena_Excel_Error( 'xls' ); // cut short
		}
	}

	/**
	 * A small part as one string.
	 *
	 * @param string                             $d     File bytes.
	 * @param array<string, array<string, int>>  $zip   Index.
	 * @param string                             $name  Part.
	 * @param int                                $limit Most bytes.
	 * @return string
	 */
	private function part( $d, $zip, $name, $limit ) {
		if ( ! isset( $zip[ $name ] ) ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		$out = '';
		$this->zip_each(
			$d,
			$zip[ $name ],
			$limit,
			function ( $piece ) use ( &$out ) {
				$out .= $piece;
			}
		);

		return $out;
	}

	/**
	 * A relationships part: id => {type, target}, the target as a part name of the zip. A missing part = none.
	 *
	 * @param string                            $d    File bytes.
	 * @param array<string, array<string, int>> $zip  Index.
	 * @param string                            $name Part.
	 * @param string                            $base Folder the targets are relative to.
	 * @return array<string, array{type: string, target: string}>
	 */
	private function rels( $d, $zip, $name, $base ) {
		$name = strtolower( $name );
		if ( ! isset( $zip[ $name ] ) ) {
			return array();
		}
		$out = array();
		$this->xml_run(
			$this->part( $d, $zip, $name, self::MAX_PART_BYTES ),
			function ( $el, $attrs ) use ( &$out, $base ) {
				if ( 'Relationship' !== $el || ! isset( $attrs['Id'], $attrs['Target'] ) ) {
					return;
				}
				if ( isset( $attrs['TargetMode'] ) && 'external' === strtolower( (string) $attrs['TargetMode'] ) ) {
					return;
				}
				$out[ (string) $attrs['Id'] ] = array(
					'type'   => isset( $attrs['Type'] ) ? (string) $attrs['Type'] : '',
					'target' => self::part_name( (string) $attrs['Target'], $base ),
				);
			}
		);

		return $out;
	}

	/**
	 * A relationship target as a part name: absolute ("/xl/x.xml") or relative to the folder, ".." resolved, lower case.
	 *
	 * @param string $target Target.
	 * @param string $base   Folder ("xl/").
	 * @return string
	 */
	private static function part_name( $target, $base ) {
		$target = rawurldecode( str_replace( '\\', '/', $target ) );
		$path   = '/' === substr( $target, 0, 1 ) ? substr( $target, 1 ) : $base . $target;
		$out    = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '' === $seg || '.' === $seg ) {
				continue;
			}
			if ( '..' === $seg ) {
				array_pop( $out );
				continue;
			}
			$out[] = $seg;
		}

		return strtolower( implode( '/', $out ) );
	}

	/**
	 * Number formats and cell formats (styles.xml).
	 *
	 * @param string $xml Part.
	 */
	private function xlsx_styles( $xml ) {
		$in_xfs = false;
		$this->xml_run(
			$xml,
			function ( $el, $attrs, $path ) use ( &$in_xfs ) {
				if ( 'numFmt' === $el && isset( $attrs['numFmtId'], $attrs['formatCode'] ) ) {
					$this->formats[ (int) $attrs['numFmtId'] ] = (string) $attrs['formatCode'];
				}
				if ( 'xf' === $el && 'cellXfs' === $path ) {
					$this->xf_format[] = isset( $attrs['numFmtId'] ) ? (int) $attrs['numFmtId'] : 0;
				}
			}
		);
	}

	/**
	 * The shared strings: the text of each <si>, its runs joined, phonetic hints (<rPh>) left out.
	 *
	 * @param string                            $d    File bytes.
	 * @param array<string, array<string, int>> $zip  Index.
	 * @param string                            $name Part.
	 * @return array<int, string>
	 */
	private function xlsx_shared( $d, $zip, $name ) {
		$out   = array();
		$buf   = null;
		$depth = array();
		$x     = $this->xml_start(
			function ( $el ) use ( &$buf, &$depth ) {
				$depth[] = $el;
				if ( 'si' === $el ) {
					$buf = '';
				}
			},
			function ( $el ) use ( &$out, &$buf, &$depth ) {
				array_pop( $depth );
				if ( 'si' === $el ) {
					$out[] = self::unescape( (string) $buf );
					$buf   = null;
				}
			},
			function ( $text ) use ( &$buf, &$depth ) {
				if ( null !== $buf && 't' === end( $depth ) && ! in_array( 'rPh', $depth, true ) ) {
					$buf .= $text;
				}
			}
		);
		$this->zip_each(
			$d,
			$zip[ $name ],
			self::MAX_SHEET_BYTES,
			function ( $piece ) use ( &$x ) {
				$this->xml_feed( $x, $piece, false );
			}
		);
		$this->xml_feed( $x, '', true );

		return $out;
	}

	/**
	 * The cells of a sheet, read as it is inflated.
	 *
	 * @param string                            $d        File bytes.
	 * @param array<string, array<string, int>> $zip      Index.
	 * @param string                            $name     Part.
	 * @param array<int, string>                $strings  Shared strings.
	 * @param int                               $max_rows Rows with content wanted.
	 * @return array<int, array<int, string>>
	 */
	private function xlsx_sheet( $d, $zip, $name, $strings, $max_rows ) {
		$rows  = array();
		$row   = 0;
		$col   = -1;
		$cell  = null;
		$depth = array();
		$x     = $this->xml_start(
			function ( $el, $attrs ) use ( &$rows, &$row, &$col, &$cell, &$depth ) {
				$depth[] = $el;
				if ( 'row' === $el ) {
					$row = isset( $attrs['r'] ) && (int) $attrs['r'] > 0 ? (int) $attrs['r'] : $row + 1;
					$col = -1;
					if ( 0 === $row % 256 ) {
						$this->room();
					}
				} elseif ( 'c' === $el ) {
					$col  = isset( $attrs['r'] ) ? self::column( (string) $attrs['r'], $col + 1 ) : $col + 1;
					$cell = array(
						't' => isset( $attrs['t'] ) ? (string) $attrs['t'] : 'n',
						's' => isset( $attrs['s'] ) ? (int) $attrs['s'] : 0,
						'v' => '',
						'i' => '',
					);
				}
			},
			function ( $el ) use ( &$rows, &$row, &$col, &$cell, &$depth, $strings, $max_rows ) {
				array_pop( $depth );
				if ( 'c' === $el && null !== $cell ) {
					$text = $this->xlsx_cell( $cell, $strings );
					if ( '' !== trim( $text ) && $col >= 0 && $col < self::MAX_COLS && $row > 0 ) {
						$rows[ $row ][ $col ] = $text;
						if ( count( $rows ) > $max_rows ) {
							throw new CX_Sidrena_Excel_Stop();
						}

					}
					$cell = null;
				}
			},
			function ( $text ) use ( &$cell, &$depth ) {
				if ( null === $cell ) {
					return;
				}
				$top = end( $depth );
				if ( 'v' === $top ) {
					$cell['v'] .= $text;
				} elseif ( 't' === $top && in_array( 'is', $depth, true ) && ! in_array( 'rPh', $depth, true ) ) {
					$cell['i'] .= $text;
				}
			}
		);
		try {
			$this->zip_each(
				$d,
				$zip[ $name ],
				self::MAX_SHEET_BYTES,
				function ( $piece ) use ( &$x ) {
					$this->xml_feed( $x, $piece, false );
				}
			);
			$this->xml_feed( $x, '', true );
		} catch ( CX_Sidrena_Excel_Stop $stop ) {
			unset( $stop ); // enough rows: the rest of the sheet is not needed
		}

		$this->order_rows( $rows );

		return $rows;
	}

	/**
	 * One .xlsx cell as displayed.
	 *
	 * @param array<string, mixed> $cell Type, style, value, inline text.
	 * @param array<int, string>   $strings Shared strings.
	 * @return string
	 */
	private function xlsx_cell( $cell, $strings ) {
		$v = trim( (string) $cell['v'] );
		switch ( $cell['t'] ) {
			case 's':
				if ( '' === $v ) {
					return '';
				}
				if ( ! ctype_digit( $v ) || ! isset( $strings[ (int) $v ] ) ) {
					throw new CX_Sidrena_Excel_Error( 'xls' );
				}
				return $strings[ (int) $v ];
			case 'inlineStr':
				return self::unescape( (string) $cell['i'] );
			case 'str':
				return self::unescape( (string) $cell['v'] );
			case 'b':
				return '' === $v ? '' : ( '1' === $v || 'true' === strtolower( $v ) ? 'ISTINA' : 'LAŽ' );
			case 'e':
				return $v;
			case 'd':
				$serial = self::iso_serial( $v, $this->date1904 );
				return null === $serial ? $v : $this->show_number( $serial, $cell['s'] );
			default:
				if ( '' === $v ) {
					return '';
				}
				if ( ! is_numeric( $v ) ) {
					return $v;
				}
				return $this->show_number( (float) $v, $cell['s'] );
		}
	}

	/**
	 * Column index from a cell reference ("AA12" = 26); $fallback when there is none.
	 *
	 * @param string $ref      Reference.
	 * @param int    $fallback Index.
	 * @return int
	 */
	private static function column( $ref, $fallback ) {
		if ( ! preg_match( '/^\$?([A-Za-z]{1,3})/', $ref, $m ) ) {
			return $fallback;
		}
		$n = 0;
		foreach ( str_split( strtoupper( $m[1] ) ) as $ch ) {
			$n = $n * 26 + ( ord( $ch ) - 64 );
		}

		return $n - 1;
	}

	/**
	 * Excel's escapes in text: _xHHHH_ is that character (Excel writes a line break as _x000D_).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function unescape( $text ) {
		if ( false === strpos( $text, '_x' ) ) {
			return $text;
		}

		return (string) preg_replace_callback(
			'/_x([0-9A-Fa-f]{4})_/',
			function ( $m ) {
				return self::utf8_char( hexdec( $m[1] ) );
			},
			$text
		);
	}

	/**
	 * An ISO date ("2026-09-10T00:00:00", t="d") as Excel's day number.
	 *
	 * @param string $iso      Date.
	 * @param bool   $date1904 1904 days.
	 * @return float|null
	 */
	private static function iso_serial( $iso, $date1904 ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2}))?)?/', $iso, $m ) ) {
			return null;
		}
		$days = ( gmmktime( 0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] ) - gmmktime( 0, 0, 0, 12, 30, 1899 ) ) / 86400;
		if ( $date1904 ) {
			$days -= 1462;
		} elseif ( $days < 61 ) {
			--$days; // Excel's 29.2.1900. comes before 1.3.1900.
		}
		$secs = isset( $m[4] ) ? (int) $m[4] * 3600 + (int) $m[5] * 60 + ( isset( $m[6] ) ? (int) $m[6] : 0 ) : 0;

		return $days + $secs / 86400;
	}

	/* ---------------------------------------------------------------------------------------------------------------
	 * XML (ext/xml, fed in pieces)
	 * ------------------------------------------------------------------------------------------------------------- */

	/**
	 * Parse a whole small part: $on_start( local name, attributes by local name, parent local name ).
	 *
	 * @param string   $xml      Part.
	 * @param callable $on_start Start handler.
	 */
	private function xml_run( $xml, $on_start ) {
		$stack = array();
		$x     = $this->xml_start(
			function ( $el, $attrs ) use ( &$stack, $on_start ) {
				$on_start( $el, $attrs, (string) end( $stack ) );
				$stack[] = $el;
			},
			function () use ( &$stack ) {
				array_pop( $stack );
			},
			null
		);
		$this->xml_feed( $x, $xml, true );
	}

	/**
	 * A push parser. Names and attributes come without their namespace prefix (Strict OOXML and "x:"-prefixed files
	 * read the same). A document type declaration is never accepted: no entity of the file is ever expanded (XXE,
	 * "billion laughs", U4).
	 *
	 * @param callable      $start Start handler (local name, attributes).
	 * @param callable      $end   End handler (local name).
	 * @param callable|null $text  Text handler.
	 * @return array<string, mixed>
	 */
	private function xml_start( $start, $end, $text ) {
		$p = xml_parser_create( 'UTF-8' );
		xml_parser_set_option( $p, XML_OPTION_CASE_FOLDING, 0 );
		xml_parser_set_option( $p, XML_OPTION_SKIP_WHITE, 0 );
		xml_parser_set_option( $p, XML_OPTION_TARGET_ENCODING, 'UTF-8' );
		$local = function ( $name ) {
			$at = strrpos( $name, ':' );
			return false === $at ? $name : substr( $name, $at + 1 );
		};
		xml_set_element_handler(
			$p,
			function ( $parser, $name, $attrs ) use ( $start, $local ) {
				unset( $parser );
				$plain = array();
				foreach ( $attrs as $k => $val ) {
					$lk = $local( $k );
					if ( ! isset( $plain[ $lk ] ) ) {
						$plain[ $lk ] = $val;
					}
				}
				$start( $local( $name ), $plain );
			},
			function ( $parser, $name ) use ( $end, $local ) {
				unset( $parser );
				$end( $local( $name ) );
			}
		);
		if ( null !== $text ) {
			xml_set_character_data_handler(
				$p,
				function ( $parser, $data ) use ( $text ) {
					unset( $parser );
					$text( $data );
				}
			);
		}

		return array(
			'p'    => $p,
			'tail' => '',
		);
	}

	/**
	 * Give the parser a piece. A DOCTYPE or ENTITY declaration anywhere (also split between pieces) stops it.
	 *
	 * @param array<string, mixed> $x     Parser.
	 * @param string               $piece Bytes.
	 * @param bool                 $final Last piece.
	 */
	private function xml_feed( &$x, $piece, $final ) {
		$look = $x['tail'] . $piece;
		if ( false !== stripos( $look, '<!DOCTYPE' ) || false !== stripos( $look, '<!ENTITY' ) ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
		$x['tail'] = (string) substr( $look, -8 );
		if ( ! xml_parse( $x['p'], $piece, $final ) ) {
			throw new CX_Sidrena_Excel_Error( 'xls' );
		}
	}

	/* ---------------------------------------------------------------------------------------------------------------
	 * Number formats: the cell as displayed
	 * ------------------------------------------------------------------------------------------------------------- */

	/**
	 * A number of a cell with format (XF) $xf, as displayed.
	 *
	 * @param float $v  Value.
	 * @param int   $xf Cell format index.
	 * @return string
	 */
	public function show_number( $v, $xf ) {
		$id   = isset( $this->xf_format[ $xf ] ) ? (int) $this->xf_format[ $xf ] : 0;
		$code = isset( $this->formats[ $id ] ) ? $this->formats[ $id ] : self::builtin_format( $id );

		return self::format_number( (float) $v, $id, $code, $this->date1904 );
	}

	/**
	 * Excel's built-in formats that do not depend on the computer's language. 14 and 22 are the language's short date
	 * (and time), shown below as Croatian Excel shows them. The language's currency formats (5-8, 41-44) show the amount
	 * without a symbol. An id not known here is General.
	 *
	 * @param int $id Format id.
	 * @return string
	 */
	public static function builtin_format( $id ) {
		$map = array(
			0  => 'General',
			1  => '0',
			2  => '0.00',
			3  => '#,##0',
			4  => '#,##0.00',
			5  => '#,##0',
			6  => '#,##0',
			7  => '#,##0.00',
			8  => '#,##0.00',
			9  => '0%',
			10 => '0.00%',
			11 => '0.00E+00',
			12 => '# ?/?',
			13 => '# ??/??',
			14 => 'DATE14',
			15 => 'd-mmm-yy',
			16 => 'd-mmm',
			17 => 'mmm-yy',
			18 => 'h:mm AM/PM',
			19 => 'h:mm:ss AM/PM',
			20 => 'h:mm',
			21 => 'h:mm:ss',
			22 => 'DATE22',
			37 => '#,##0 ;(#,##0)',
			38 => '#,##0 ;(#,##0)',
			39 => '#,##0.00;(#,##0.00)',
			40 => '#,##0.00;(#,##0.00)',
			41 => '#,##0',
			42 => '#,##0',
			43 => '#,##0.00',
			44 => '#,##0.00',
			45 => 'mm:ss',
			46 => '[h]:mm:ss',
			47 => 'mmss.0',
			48 => '##0.0E+0',
			49 => '@',
		);

		return isset( $map[ $id ] ) ? $map[ $id ] : 'General';
	}

	/**
	 * A number through a format code, as Croatian Excel shows it.
	 *
	 * Dates: every format with a day, a month and a year shows "dd.mm.gggg." (and " hh:mm" when it has a time), the form
	 * Croatian Excel gives the built-in short date (S14: a cell of format 14 saved by the owner's Excel as CSV reads
	 * "23.09.2026.", Rook 4277) and the only one the import's date reader takes (CX_Sidrena_Catalog::ymd()). A time or a
	 * part of a date is shown by its format. Anything this does not know (fractions, E notation, conditions, names of
	 * months in a partial date, elapsed time) shows the number as General: every digit, never rounded to a format.
	 *
	 * @param float  $v        Value.
	 * @param int    $id       Format id.
	 * @param string $code     Format code.
	 * @param bool   $date1904 1904 days.
	 * @return string
	 */
	public static function format_number( $v, $id, $code, $date1904 ) {
		if ( 14 === $id || 'DATE14' === $code ) {
			$out = self::date_text( $v, $date1904, false );
			return null === $out ? self::general( $v ) : $out;
		}
		if ( 22 === $id || 'DATE22' === $code ) {
			$out = self::date_text( $v, $date1904, true );
			return null === $out ? self::general( $v ) : $out;
		}
		$sections = self::sections( (string) $code );
		$sec      = $sections[0];
		$sign     = $v < 0 ? '-' : '';
		$abs      = abs( $v );
		if ( $v < 0 && isset( $sections[1] ) && '' !== trim( $sections[1] ) ) {
			$sec  = $sections[1];
			$sign = '';
		} elseif ( 0.0 === (float) $v && isset( $sections[2] ) ) {
			$sec = $sections[2];
		}
		$tokens = self::tokens( $sec );
		if ( null === $tokens ) {
			return self::general( $v );
		}
		$kind = self::kind( $tokens );
		if ( 'date' === $kind ) {
			$out = self::date_tokens( $tokens, $v, $date1904 );
			return null === $out ? self::general( $v ) : $out;
		}
		if ( 'general' === $kind ) {
			$out = '';
			foreach ( $tokens as $t ) {
				$out .= 'G' === $t[0] ? self::general( $abs ) : ( 'L' === $t[0] ? $t[1] : '' );
			}
			return $sign . $out;
		}
		if ( 'number' === $kind ) {
			$out = self::number_tokens( $tokens, $abs );
			return null === $out ? self::general( $v ) : $sign . $out;
		}
		if ( 'text' === $kind ) {
			$out = '';
			foreach ( $tokens as $t ) {
				$out .= '@' === $t[0] ? self::general( $abs ) : ( 'L' === $t[0] ? $t[1] : '' );
			}
			return $sign . $out;
		}

		return self::general( $v ); // unknown
	}

	/**
	 * Sections of a format code split at ";" outside quotes, escapes and brackets.
	 *
	 * @param string $code Format code.
	 * @return array<int, string>
	 */
	private static function sections( $code ) {
		$out   = array();
		$cur   = '';
		$quote = false;
		$brack = false;
		for ( $i = 0, $n = strlen( $code ); $i < $n; $i++ ) {
			$ch = $code[ $i ];
			if ( '\\' === $ch && ! $quote && $i + 1 < $n ) {
				$cur .= $ch . $code[ ++$i ];
				continue;
			}
			if ( '"' === $ch && ! $brack ) {
				$quote = ! $quote;
			} elseif ( '[' === $ch && ! $quote ) {
				$brack = true;
			} elseif ( ']' === $ch && ! $quote ) {
				$brack = false;
			} elseif ( ';' === $ch && ! $quote && ! $brack ) {
				$out[] = $cur;
				$cur   = '';
				continue;
			}
			$cur .= $ch;
		}
		$out[] = $cur;

		return $out;
	}

	/**
	 * Tokens of one section: [type, text]. Types: L literal, G General, 0 digit placeholder, , comma, . point,
	 * % percent, @ text, D date/time part, X something this reader does not show (then the number is General). null =
	 * the whole section is unknown.
	 *
	 * @param string $sec Section.
	 * @return array<int, array{0: string, 1: string}>|null
	 */
	private static function tokens( $sec ) {
		$out = array();
		$n   = strlen( $sec );
		$i   = 0;
		while ( $i < $n ) {
			$ch = $sec[ $i ];
			if ( '"' === $ch ) {
				$end = strpos( $sec, '"', $i + 1 );
				$end = false === $end ? $n : $end;
				$out[] = array( 'L', (string) substr( $sec, $i + 1, $end - $i - 1 ) );
				$i     = $end + 1;
				continue;
			}
			if ( '\\' === $ch ) {
				$len   = $i + 1 < $n ? self::char_len( $sec, $i + 1 ) : 0;
				$out[] = array( 'L', (string) substr( $sec, $i + 1, $len ) );
				$i    += 1 + $len;
				continue;
			}
			if ( '_' === $ch ) {
				$out[] = array( 'L', ' ' );
				$i    += 1 + ( $i + 1 < $n ? self::char_len( $sec, $i + 1 ) : 0 );
				continue;
			}
			if ( '*' === $ch ) {
				$i += 1 + ( $i + 1 < $n ? self::char_len( $sec, $i + 1 ) : 0 ); // fill: nothing in a CSV-like text
				continue;
			}
			if ( '[' === $ch ) {
				$end = strpos( $sec, ']', $i );
				if ( false === $end ) {
					return null;
				}
				$in = (string) substr( $sec, $i + 1, $end - $i - 1 );
				$i  = $end + 1;
				if ( preg_match( '/^(Red|Black|Blue|Cyan|Green|Magenta|White|Yellow|Color\s*\d+)$/i', $in ) ) {
					continue;
				}
				if ( '$' === substr( $in, 0, 1 ) ) {
					$sym = substr( $in, 1 );
					$cut = strpos( (string) $sym, '-' );
					$sym = false === $cut ? $sym : substr( $sym, 0, $cut );
					if ( '' !== (string) $sym ) {
						$out[] = array( 'L', (string) $sym );
					}
					continue;
				}
				$out[] = array( 'X', $in ); // a condition, elapsed time, a calendar
				continue;
			}
			if ( 0 === strncasecmp( (string) substr( $sec, $i, 7 ), 'General', 7 ) ) {
				$out[] = array( 'G', 'General' );
				$i    += 7;
				continue;
			}
			if ( 0 === strncasecmp( (string) substr( $sec, $i, 5 ), 'AM/PM', 5 ) ) {
				$out[] = array( 'D', 'am/pm' );
				$i    += 5;
				continue;
			}
			if ( 0 === strncasecmp( (string) substr( $sec, $i, 3 ), 'A/P', 3 ) ) {
				$out[] = array( 'D', 'a/p' );
				$i    += 3;
				continue;
			}
			if ( false !== strpos( 'yYmMdDhHsS', $ch ) ) {
				$j = $i;
				while ( $j < $n && strtolower( $sec[ $j ] ) === strtolower( $ch ) ) {
					++$j;
				}
				$out[] = array( 'D', strtolower( (string) substr( $sec, $i, $j - $i ) ) );
				$i     = $j;
				continue;
			}
			if ( ( 'E' === $ch || 'e' === $ch ) && $i + 1 < $n && ( '+' === $sec[ $i + 1 ] || '-' === $sec[ $i + 1 ] ) ) {
				$out[] = array( 'X', 'E' );
				$i    += 2;
				continue;
			}
			if ( '0' === $ch || '#' === $ch || '?' === $ch ) {
				$out[] = array( '0', $ch );
				++$i;
				continue;
			}
			if ( ',' === $ch || '.' === $ch || '%' === $ch || '@' === $ch || '/' === $ch ) {
				$out[] = array( $ch, $ch );
				++$i;
				continue;
			}
			if ( ctype_digit( $ch ) ) {
				$out[] = array( 'X', $ch ); // a digit in a fraction's denominator ("# ?/8")
				++$i;
				continue;
			}
			$len   = self::char_len( $sec, $i );
			$out[] = array( 'L', (string) substr( $sec, $i, $len ) );
			$i    += $len;
		}

		return $out;
	}

	/**
	 * Bytes of the UTF-8 character at $i.
	 *
	 * @param string $s String.
	 * @param int    $i Offset.
	 * @return int
	 */
	private static function char_len( $s, $i ) {
		$o = ord( $s[ $i ] );

		return $o < 0x80 ? 1 : ( $o >= 0xF0 ? 4 : ( $o >= 0xE0 ? 3 : 2 ) );
	}

	/**
	 * What a section shows: 'date', 'number', 'general', 'text' or 'unknown'.
	 *
	 * @param array<int, array{0: string, 1: string}> $tokens Tokens.
	 * @return string
	 */
	private static function kind( $tokens ) {
		$has = array();
		foreach ( $tokens as $t ) {
			$has[ $t[0] ] = true;
		}
		if ( isset( $has['X'] ) ) {
			return 'unknown';
		}
		if ( isset( $has['D'] ) ) {
			return isset( $has['0'] ) || isset( $has['G'] ) || isset( $has['%'] ) ? 'unknown' : 'date';
		}
		if ( isset( $has['/'] ) ) {
			return 'unknown'; // a fraction
		}
		if ( isset( $has['G'] ) ) {
			return isset( $has['0'] ) ? 'unknown' : 'general';
		}
		if ( isset( $has['0'] ) ) {
			return 'number';
		}
		if ( isset( $has['@'] ) ) {
			return 'text';
		}

		return 'unknown'; // only literals: Excel would show the text, which would lose the number
	}

	/**
	 * A number through digit placeholders: "000" keeps leading zeros, "#,##0" groups thousands, ".00" fixes decimals,
	 * "%" multiplies by 100. Literals before and after the digits stay where they are; text between digit placeholders,
	 * a scaling comma and the like are not shown here (null: the number is shown as General).
	 *
	 * @param array<int, array{0: string, 1: string}> $tokens Tokens.
	 * @param float                                   $abs    Value without its sign.
	 * @return string|null
	 */
	private static function number_tokens( $tokens, $abs ) {
		$first = null;
		$last  = null;
		foreach ( $tokens as $k => $t ) {
			if ( '0' === $t[0] ) {
				if ( null === $first ) {
					$first = $k;
				}
				$last = $k;
			}
		}
		$int_min  = 0;
		$dec_min  = 0;
		$dec_max  = 0;
		$group    = false;
		$point    = false;
		$percent  = 0;
		$prefix   = '';
		$suffix   = '';
		foreach ( $tokens as $k => $t ) {
			if ( '%' === $t[0] ) {
				++$percent;
			}
			if ( $k < $first ) {
				$prefix .= 'L' === $t[0] || '%' === $t[0] ? $t[1] : '';
				continue;
			}
			if ( $k > $last ) {
				if ( '.' === $t[0] && ! $point ) {
					$point = true; // "0." : a point with no decimals after it
					continue;
				}
				if ( ',' === $t[0] ) {
					return null; // scaling by thousands
				}
				$suffix .= 'L' === $t[0] || '%' === $t[0] || '.' === $t[0] ? $t[1] : '';
				continue;
			}
			switch ( $t[0] ) {
				case '0':
					if ( $point ) {
						++$dec_max;
						if ( '0' === $t[1] ) {
							$dec_min = $dec_max;
						}
					} elseif ( '0' === $t[1] ) {
						++$int_min;
					}
					break;
				case ',':
					if ( $point ) {
						return null;
					}
					$group = true;
					break;
				case '.':
					if ( $point ) {
						return null;
					}
					$point = true;
					break;
				default:
					return null; // text or a sign between the digits ("000-000")
			}
		}
		list( $int, $frac ) = self::round_plain( self::plain( $abs * pow( 100, $percent ) ), $dec_max );
		$int                = ltrim( $int, '0' );
		while ( strlen( $frac ) > $dec_min && '0' === substr( $frac, -1 ) ) {
			$frac = substr( $frac, 0, -1 );
		}
		if ( strlen( $int ) < $int_min ) {
			$int = str_repeat( '0', $int_min - strlen( $int ) ) . $int;
		}
		if ( $group && strlen( $int ) > 3 ) {
			$int = strrev( implode( '.', str_split( strrev( $int ), 3 ) ) );
		}
		$num = $int . ( '' !== $frac ? ',' . $frac : '' );
		if ( '' === $num ) {
			$num = '0';
		}

		return $prefix . $num . $suffix;
	}

	/**
	 * General: every digit up to Excel's 15 significant ones, never in E notation, decimal comma.
	 *
	 * @param float $v Value.
	 * @return string
	 */
	public static function general( $v ) {
		if ( 0.0 === (float) $v ) {
			return '0';
		}

		return ( $v < 0 ? '-' : '' ) . str_replace( '.', ',', self::plain( abs( $v ) ) );
	}

	/**
	 * A number without its sign as plain decimal digits ("1234.5", "100000000000000000000", "0.00001"), from Excel's
	 * 15 significant digits; no E notation, no trailing zeros.
	 *
	 * @param float $x Value, 0 or more.
	 * @return string
	 */
	private static function plain( $x ) {
		$s = ltrim( strtoupper( sprintf( '%.15G', abs( $x ) ) ), '+' );
		if ( false !== strpos( $s, 'E' ) ) {
			list( $mant, $exp ) = explode( 'E', $s );
			$bits   = explode( '.', $mant );
			$digits = $bits[0] . ( isset( $bits[1] ) ? $bits[1] : '' );
			$at     = strlen( $bits[0] ) + (int) $exp;
			if ( $at <= 0 ) {
				$s = '0.' . str_repeat( '0', -$at ) . $digits;
			} elseif ( $at >= strlen( $digits ) ) {
				$s = $digits . str_repeat( '0', $at - strlen( $digits ) );
			} else {
				$s = substr( $digits, 0, $at ) . '.' . substr( $digits, $at );
			}
		}
		if ( false !== strpos( $s, '.' ) ) {
			$s = rtrim( rtrim( $s, '0' ), '.' );
		}

		return '' === $s ? '0' : $s;
	}

	/**
	 * Round plain decimal digits to $dec decimals, half away from zero, on the digits themselves: the same in every
	 * PHP version (round() changed in PHP 8.4), and 2.675 is 2,68 as in Excel.
	 *
	 * @param string $s   Plain digits ("2.675").
	 * @param int    $dec Decimals.
	 * @return array{0: string, 1: string} Integer digits, decimal digits.
	 */
	private static function round_plain( $s, $dec ) {
		$bits = explode( '.', $s, 2 );
		$int  = $bits[0];
		$frac = isset( $bits[1] ) ? $bits[1] : '';
		if ( strlen( $frac ) <= $dec ) {
			return array( $int, str_pad( $frac, $dec, '0' ) );
		}
		$keep = $int . substr( $frac, 0, $dec );
		if ( (int) $frac[ $dec ] >= 5 ) {
			$digits = str_split( $keep );
			for ( $k = count( $digits ) - 1; $k >= 0; $k-- ) {
				if ( '9' === $digits[ $k ] ) {
					$digits[ $k ] = '0';
					continue;
				}
				$digits[ $k ] = (string) ( (int) $digits[ $k ] + 1 );
				break;
			}
			$keep = ( $k < 0 ? '1' : '' ) . implode( '', $digits );
		}

		return array( (string) substr( $keep, 0, strlen( $keep ) - $dec ), $dec > 0 ? (string) substr( $keep, -$dec ) : '' );
	}

	/**
	 * Year, month, day, hour, minute, second of an Excel day number, or null outside Excel's dates.
	 *
	 * @param float $v        Day number.
	 * @param bool  $date1904 1904 days.
	 * @return array<int, int>|null
	 */
	private static function date_parts( $v, $date1904 ) {
		if ( $v < 0 || $v >= 2958466 ) {
			return null;
		}
		$days = (int) floor( $v );
		$secs = (int) round( ( $v - $days ) * 86400 );
		if ( $secs >= 86400 ) {
			++$days;
			$secs -= 86400;
		}
		if ( $date1904 ) {
			$ts = gmmktime( 0, 0, 0, 1, 1, 1904 ) + $days * 86400;
		} elseif ( 60 === $days ) {
			return array( 1900, 2, 29, intdiv( $secs, 3600 ), intdiv( $secs % 3600, 60 ), $secs % 60 ); // Excel's own day
		} else {
			$ts = gmmktime( 0, 0, 0, 12, 30, 1899 ) + ( $days < 60 ? $days + 1 : $days ) * 86400;
		}

		return array( (int) gmdate( 'Y', $ts ), (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ), intdiv( $secs, 3600 ), intdiv( $secs % 3600, 60 ), $secs % 60 );
	}

	/**
	 * A date as "dd.mm.gggg." (and " hh:mm").
	 *
	 * @param float $v        Day number.
	 * @param bool  $date1904 1904 days.
	 * @param bool  $time     With the time.
	 * @return string|null
	 */
	private static function date_text( $v, $date1904, $time ) {
		$p = self::date_parts( $v, $date1904 );
		if ( null === $p ) {
			return null;
		}

		return sprintf( '%02d.%02d.%04d.', $p[2], $p[1], $p[0] ) . ( $time ? sprintf( ' %02d:%02d', $p[3], $p[4] ) : '' );
	}

	/**
	 * A date or time format: a full date (day, month, year) as dd.mm.gggg. (date_text), the rest by its tokens.
	 *
	 * @param array<int, array{0: string, 1: string}> $tokens   Tokens.
	 * @param float                                   $v        Value.
	 * @param bool                                    $date1904 1904 days.
	 * @return string|null
	 */
	private static function date_tokens( $tokens, $v, $date1904 ) {
		// m after h, or before s, is minutes.
		$parts = array();
		foreach ( $tokens as $k => $t ) {
			if ( 'D' === $t[0] ) {
				$parts[] = $k;
			}
		}
		$minute = array();
		foreach ( $parts as $i => $k ) {
			if ( 'm' !== $tokens[ $k ][1][0] || strlen( $tokens[ $k ][1] ) > 2 ) {
				continue;
			}
			$prev = $i > 0 ? $tokens[ $parts[ $i - 1 ] ][1][0] : '';
			$next = isset( $parts[ $i + 1 ] ) ? $tokens[ $parts[ $i + 1 ] ][1][0] : '';
			if ( 'h' === $prev || 's' === $next ) {
				$minute[ $k ] = true;
			}
		}
		$has = array();
		foreach ( $parts as $k ) {
			$c = $tokens[ $k ][1][0];
			if ( 'm' === $c && isset( $minute[ $k ] ) ) {
				$c = 'n';
			}
			$has[ $c ] = true;
		}
		if ( isset( $has['y'], $has['m'], $has['d'] ) ) {
			return self::date_text( $v, $date1904, isset( $has['h'] ) );
		}
		$p = self::date_parts( $v, $date1904 );
		if ( null === $p ) {
			return null;
		}
		$ampm = isset( $has['a'] );
		$out  = '';
		foreach ( $tokens as $k => $t ) {
			if ( 'L' === $t[0] || '.' === $t[0] || ',' === $t[0] ) {
				$out .= $t[1];
				continue;
			}
			if ( '/' === $t[0] ) {
				$out .= '.';
				continue;
			}
			if ( 'D' !== $t[0] ) {
				return null;
			}
			$w = $t[1];
			switch ( $w[0] ) {
				case 'y':
					$out .= strlen( $w ) > 2 ? sprintf( '%04d', $p[0] ) : sprintf( '%02d', $p[0] % 100 );
					break;
				case 'd':
					if ( strlen( $w ) > 2 ) {
						return null; // names of days
					}
					$out .= 2 === strlen( $w ) ? sprintf( '%02d', $p[2] ) : (string) $p[2];
					break;
				case 'm':
					if ( isset( $minute[ $k ] ) ) {
						$out .= 2 === strlen( $w ) ? sprintf( '%02d', $p[4] ) : (string) $p[4];
					} elseif ( strlen( $w ) > 2 ) {
						return null; // names of months
					} else {
						$out .= 2 === strlen( $w ) ? sprintf( '%02d', $p[1] ) : (string) $p[1];
					}
					break;
				case 'h':
					$h    = $ampm ? ( 0 === $p[3] % 12 ? 12 : $p[3] % 12 ) : $p[3];
					$out .= strlen( $w ) > 1 ? sprintf( '%02d', $h ) : (string) $h;
					break;
				case 's':
					$out .= strlen( $w ) > 1 ? sprintf( '%02d', $p[5] ) : (string) $p[5];
					break;
				case 'a':
					$out .= $p[3] < 12 ? ( 'a/p' === $w ? 'A' : 'AM' ) : ( 'a/p' === $w ? 'P' : 'PM' );
					break;
				default:
					return null;
			}
		}

		return $out;
	}
}

/**
 * An Excel file that can't be read; the message is the code for the screen (xls, ods, other).
 */
class CX_Sidrena_Excel_Error extends Exception {
}

/**
 * Enough rows were read.
 */
class CX_Sidrena_Excel_Stop extends Exception {
}
