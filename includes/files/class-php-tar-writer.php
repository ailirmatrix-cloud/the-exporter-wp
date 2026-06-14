<?php
/**
 * Pure PHP streaming tar writer (fallback when shell tar and Phar unavailable).
 *
 * @package TheExporter
 */

namespace TheExporter\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Class PhpTarWriter
 */
class PhpTarWriter {

	/**
	 * Write a tar archive from file list.
	 *
	 * @param array  $batch      Files with path key.
	 * @param string $source_dir Source base directory.
	 * @param string $output     Output tar path.
	 * @param array  $options    on_heartbeat.
	 * @return bool
	 */
	public static function write_tar( array $batch, $source_dir, $output, array $options = array() ) {
		$fh = fopen( $output, 'wb' );
		if ( ! $fh ) {
			return false;
		}

		$added = 0;
		$total = count( $batch );
		foreach ( $batch as $file ) {
			$rel     = $file['path'];
			$full_fp = trailingslashit( $source_dir ) . $rel;
			if ( ! is_file( $full_fp ) ) {
				continue;
			}
			self::write_file_entry( $fh, $rel, $full_fp );
			$added++;
			if ( 0 === $added % 10 && ! empty( $options['on_write_progress'] ) && is_callable( $options['on_write_progress'] ) ) {
				call_user_func( $options['on_write_progress'], 'compressing', $added, $total );
			}
			if ( 0 === $added % 25 && ! empty( $options['on_heartbeat'] ) && is_callable( $options['on_heartbeat'] ) ) {
				call_user_func( $options['on_heartbeat'] );
			}
		}

		self::write_end_blocks( $fh );
		fclose( $fh );
		return $added > 0;
	}

	/**
	 * Gzip a file in place (replaces source with .gz).
	 *
	 * @param string $source Source file.
	 * @param string $dest   Destination .gz path.
	 * @param int    $level  Gzip level 0-9.
	 * @return bool
	 */
	public static function gzip_file( $source, $dest, $level = 1 ) {
		if ( self::gzip_file_stream( $source, $dest, $level ) ) {
			return true;
		}
		$data = file_get_contents( $source );
		if ( false === $data ) {
			return false;
		}
		$gz = gzencode( $data, max( 0, min( 9, (int) $level ) ) );
		if ( false === $gz ) {
			return false;
		}
		return false !== file_put_contents( $dest, $gz );
	}

	/**
	 * Stream gzip without loading entire source into memory.
	 *
	 * @param string $source Source file.
	 * @param string $dest   Destination .gz path.
	 * @param int    $level  Gzip level 0-9.
	 * @return bool
	 */
	public static function gzip_file_stream( $source, $dest, $level = 1 ) {
		$src = fopen( $source, 'rb' );
		if ( ! $src ) {
			return false;
		}
		$level = max( 0, min( 9, (int) $level ) );
		$dst   = gzopen( $dest, 'wb' . $level );
		if ( ! $dst ) {
			fclose( $src );
			return false;
		}
		while ( ! feof( $src ) ) {
			$chunk = fread( $src, 65536 );
			if ( false === $chunk ) {
				break;
			}
			gzwrite( $dst, $chunk );
		}
		fclose( $src );
		gzclose( $dst );
		return file_exists( $dest ) && filesize( $dest ) > 0;
	}

	/**
	 * Write one file entry to tar stream.
	 *
	 * @param resource $fh      Tar file handle.
	 * @param string   $rel     Relative path in archive.
	 * @param string   $full_fp Full filesystem path.
	 */
	private static function write_file_entry( $fh, $rel, $full_fp ) {
		$rel  = str_replace( '\\', '/', $rel );
		$size = filesize( $full_fp );
		$mtime = filemtime( $full_fp );

		$header = self::build_header( $rel, $size, $mtime, '0' );
		fwrite( $fh, $header );

		$src = fopen( $full_fp, 'rb' );
		if ( $src ) {
			stream_copy_to_stream( $src, $fh );
			fclose( $src );
		}

		$pad = ( 512 - ( $size % 512 ) ) % 512;
		if ( $pad > 0 ) {
			fwrite( $fh, str_repeat( "\0", $pad ) );
		}
	}

	/**
	 * Build 512-byte USTAR header block.
	 *
	 * @param string $name  Path in archive.
	 * @param int    $size  File size.
	 * @param int    $mtime Modification time.
	 * @param string $typeflag Type flag.
	 * @return string
	 */
	private static function build_header( $name, $size, $mtime, $typeflag = '0' ) {
		$name = substr( $name, 0, 100 );
		$header = pack( 'a100', $name );
		$header .= pack( 'a8', '0000644' );
		$header .= pack( 'a8', '0000000' );
		$header .= pack( 'a8', '0000000' );
		$header .= pack( 'a12', sprintf( '%011o', $size ) );
		$header .= pack( 'a12', sprintf( '%011o', $mtime ) );
		$header .= pack( 'a8', '        ' );
		$header .= pack( 'a1', $typeflag );
		$header .= pack( 'a100', '' );
		$header .= pack( 'a6', 'ustar' );
		$header .= pack( 'a2', '00' );
		$header .= pack( 'a32', '' );
		$header .= pack( 'a32', '' );
		$header .= pack( 'a8', '' );
		$header .= pack( 'a8', '' );
		$header .= pack( 'a155', '' );

		$header = str_pad( $header, 512, "\0" );

		$checksum = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$checksum += ord( $header[ $i ] );
		}
		$sum = sprintf( '%06o', $checksum );
		$header = substr_replace( $header, $sum, 148, 6 );
		$header[ 155 ] = "\0";

		return $header;
	}

	/**
	 * Write tar end-of-archive blocks.
	 *
	 * @param resource $fh File handle.
	 */
	private static function write_end_blocks( $fh ) {
		fwrite( $fh, str_repeat( "\0", 1024 ) );
	}
}
