<?php
/**
 * The file recorder: while code Kico runs (php_eval, abilities_run, and
 * WP-CLI through kico-cli-recorder.php) changes files, it stands in for
 * PHP's file:// stream wrapper and copies a file's old content into the
 * operation's snapshot before its first write, unlink, rename, mkdir, rmdir,
 * touch or chmod. Everything else passes straight to the real wrapper.
 *
 * What PHP's stream layer never sees (a child process, an extension writing
 * on its own, such as ZipArchive or Imagick) the before/after manifest in
 * Kico_Snapshot finds instead.
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors, WordPress.NamingConventions

final class Kico_Recorder {

	/** @var resource|null The stream context PHP sets on wrapper instances. */
	public $context;

	/** @var resource|null */
	private $handle;

	/** @var resource|null */
	private $dir;

	/** @var Kico_Snapshot|null */
	private static $snapshot = null;

	/** Folders whose old state is kept another way (an upgrader's zip) or that are not worth keeping (caches, temp). */
	private static $skip = array();

	private static $depth = 0;

	public static function active() {
		return null !== self::$snapshot;
	}

	public static function start( Kico_Snapshot $s ) {
		if ( self::$snapshot ) {
			return true;
		}
		self::$skip = array();
		foreach ( array( WP_CONTENT_DIR . '/cache', WP_CONTENT_DIR . '/upgrade', WP_CONTENT_DIR . '/upgrade-temp-backup', get_temp_dir(), sys_get_temp_dir() ) as $d ) {
			self::skip( $d );
		}
		if ( ! @stream_wrapper_unregister( 'file' ) ) {
			return false;
		}
		if ( ! stream_wrapper_register( 'file', __CLASS__ ) ) {
			stream_wrapper_restore( 'file' );
			return false;
		}
		self::$snapshot = $s;
		return true;
	}

	public static function stop() {
		if ( self::$snapshot ) {
			self::$snapshot = null;
			@stream_wrapper_restore( 'file' );
		}
	}

	/** Changes under this folder are not copied by the recorder. */
	public static function skip( $dir ) {
		$dir = rtrim( wp_normalize_path( (string) $dir ), '/' );
		if ( '' !== $dir ) {
			self::$skip[ $dir . '/' ] = true;
		}
	}

	public static function skipped( $path ) {
		foreach ( self::$skip as $d => $_ ) {
			if ( 0 === strpos( $path . '/', $d ) ) {
				return true;
			}
		}
		return false;
	}

	/** Runs $fn with the real file wrapper in place. */
	private static function real( callable $fn ) {
		if ( 0 === self::$depth++ ) {
			@stream_wrapper_restore( 'file' );
		}
		try {
			return $fn();
		} finally {
			if ( 0 === --self::$depth && self::$snapshot ) {
				@stream_wrapper_unregister( 'file' );
				stream_wrapper_register( 'file', __CLASS__ );
			}
		}
	}

	private static function abs( $path ) {
		$path = (string) $path;
		if ( 0 === stripos( $path, 'file://' ) ) {
			$path = substr( $path, 7 );
		}
		if ( '' === $path || ( '/' !== $path[0] && ! preg_match( '#^[A-Za-z]:[\\\\/]#', $path ) ) ) {
			$path = getcwd() . '/' . $path;
		}
		$path  = wp_normalize_path( $path );
		$parts = array();
		foreach ( explode( '/', $path ) as $i => $seg ) {
			if ( '..' === $seg ) {
				array_pop( $parts );
			} elseif ( '.' !== $seg && ( '' !== $seg || 0 === $i ) ) {
				$parts[] = $seg;
			}
		}
		return implode( '/', $parts );
	}

	/** Keeps the old state of a path before its first change. */
	private static function before( $path ) {
		$s = self::$snapshot;
		if ( ! $s ) {
			return;
		}
		$path = self::abs( $path );
		if ( self::skipped( $path ) || $s->has_file( $path ) ) {
			return;
		}
		self::real(
			function () use ( $s, $path ) {
				try {
					$s->file( $path );
				} catch ( Throwable $e ) {
					$s->notes[] = 'The recorder could not keep ' . $path . ': ' . $e->getMessage();
				}
			}
		);
	}

	// --- the wrapper -------------------------------------------------------------------------------

	public function stream_open( $path, $mode, $options, &$opened_path ) {
		if ( strpbrk( $mode, 'waxc+' ) ) {
			self::before( $path );
		}
		$ctx = $this->context;
		$use = (bool) ( $options & STREAM_USE_PATH );
		$h   = self::real(
			function () use ( $path, $mode, $use, $ctx ) {
				return $ctx ? fopen( $path, $mode, $use, $ctx ) : fopen( $path, $mode, $use );
			}
		);
		if ( false === $h ) {
			return false;
		}
		$this->handle = $h;
		if ( $options & STREAM_USE_PATH ) {
			$meta        = stream_get_meta_data( $h );
			$opened_path = $meta['uri'] ?? $path;
		}
		return true;
	}

	public function stream_read( $count ) {
		return fread( $this->handle, $count );
	}

	public function stream_write( $data ) {
		return fwrite( $this->handle, $data );
	}

	public function stream_close() {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
		}
	}

	public function stream_eof() {
		return feof( $this->handle );
	}

	public function stream_flush() {
		return fflush( $this->handle );
	}

	public function stream_seek( $offset, $whence = SEEK_SET ) {
		return 0 === fseek( $this->handle, $offset, $whence );
	}

	public function stream_tell() {
		return ftell( $this->handle );
	}

	public function stream_stat() {
		return fstat( $this->handle );
	}

	public function stream_truncate( $size ) {
		return ftruncate( $this->handle, $size );
	}

	public function stream_lock( $operation ) {
		return $operation ? flock( $this->handle, $operation ) : true;
	}

	public function stream_cast( $as ) {
		return $this->handle;
	}

	public function stream_set_option( $option, $arg1, $arg2 ) {
		switch ( $option ) {
			case STREAM_OPTION_BLOCKING:
				return stream_set_blocking( $this->handle, (bool) $arg1 );
			case STREAM_OPTION_READ_TIMEOUT:
				return stream_set_timeout( $this->handle, (int) $arg1, (int) $arg2 );
			case STREAM_OPTION_WRITE_BUFFER:
				return 0 === stream_set_write_buffer( $this->handle, (int) $arg2 );
			case 3: // STREAM_OPTION_READ_BUFFER
				return function_exists( 'stream_set_read_buffer' ) && 0 === stream_set_read_buffer( $this->handle, (int) $arg2 );
		}
		return false;
	}

	public function stream_metadata( $path, $option, $value ) {
		self::before( $path );
		return (bool) self::real(
			function () use ( $path, $option, $value ) {
				switch ( $option ) {
					case STREAM_META_TOUCH:
						return empty( $value ) ? touch( $path ) : touch( $path, (int) $value[0], (int) ( $value[1] ?? $value[0] ) );
					case STREAM_META_OWNER_NAME:
					case STREAM_META_OWNER:
						return chown( $path, $value );
					case STREAM_META_GROUP_NAME:
					case STREAM_META_GROUP:
						return chgrp( $path, $value );
					case STREAM_META_ACCESS:
						return chmod( $path, $value );
				}
				return false;
			}
		);
	}

	public function unlink( $path ) {
		self::before( $path );
		$ctx = $this->context;
		return self::real(
			function () use ( $path, $ctx ) {
				return $ctx ? unlink( $path, $ctx ) : unlink( $path );
			}
		);
	}

	public function rename( $from, $to ) {
		self::before( $from );
		self::before( $to );
		$ctx = $this->context;
		return self::real(
			function () use ( $from, $to, $ctx ) {
				return $ctx ? rename( $from, $to, $ctx ) : rename( $from, $to );
			}
		);
	}

	public function mkdir( $path, $mode, $options ) {
		$recursive = (bool) ( $options & STREAM_MKDIR_RECURSIVE );
		// The topmost folder this creates is what a rollback removes.
		$top = self::abs( $path );
		if ( $recursive ) {
			$top = self::real(
				function () use ( $top ) {
					$t = $top;
					while ( ! file_exists( dirname( $t ) ) && dirname( $t ) !== $t ) {
						$t = dirname( $t );
					}
					return $t;
				}
			);
		}
		self::before( $top );
		$ctx = $this->context;
		return self::real(
			function () use ( $path, $mode, $recursive, $ctx ) {
				return $ctx ? mkdir( $path, $mode, $recursive, $ctx ) : mkdir( $path, $mode, $recursive );
			}
		);
	}

	public function rmdir( $path, $options ) {
		self::before( $path );
		$ctx = $this->context;
		return self::real(
			function () use ( $path, $ctx ) {
				return $ctx ? rmdir( $path, $ctx ) : rmdir( $path );
			}
		);
	}

	public function url_stat( $path, $flags ) {
		return self::real(
			function () use ( $path, $flags ) {
				$quiet = (bool) ( $flags & STREAM_URL_STAT_QUIET );
				if ( $flags & STREAM_URL_STAT_LINK ) {
					return $quiet ? @lstat( $path ) : lstat( $path );
				}
				if ( $quiet && ! file_exists( $path ) ) {
					return false;
				}
				return $quiet ? @stat( $path ) : stat( $path );
			}
		);
	}

	public function dir_opendir( $path, $options ) {
		$ctx = $this->context;
		$d   = self::real(
			function () use ( $path, $ctx ) {
				return $ctx ? opendir( $path, $ctx ) : opendir( $path );
			}
		);
		if ( false === $d ) {
			return false;
		}
		$this->dir = $d;
		return true;
	}

	public function dir_readdir() {
		return readdir( $this->dir );
	}

	public function dir_rewinddir() {
		rewinddir( $this->dir );
		return true;
	}

	public function dir_closedir() {
		closedir( $this->dir );
		return true;
	}
}
