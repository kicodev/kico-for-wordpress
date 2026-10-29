<?php
/**
 * What one SQL statement changes, read from the statement itself with
 * phpMyAdmin's SQL parser (bundled under Kico\Vendor, see wordpress/sql-parser):
 * the rows an UPDATE or DELETE touches (as a SELECT on the same tables, joins
 * and condition), the keys an INSERT or REPLACE writes, or, for DDL and
 * statements the rows cannot be told from, the tables as a whole.
 *
 * This only plans; Kico_Snapshot runs the plan before the statement (and
 * after it, for the keys an INSERT made). Without the parser (a build without
 * vendor-scoped/, or no mbstring) plan() says so and the caller falls back to
 * whole tables.
 *
 * @package Kico
 */

defined( 'ABSPATH' ) || exit;

final class Kico_Sql {

	const NS = 'Kico\\Vendor\\PhpMyAdmin\\SqlParser\\';

	/** Functions whose value differs between the snapshot's SELECT and the statement. */
	const VOLATILE = '/\b(RAND|UUID|UUID_SHORT|SYSDATE|CONNECTION_ID|LAST_INSERT_ID|ROW_COUNT|FOUND_ROWS|GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK|SLEEP|BENCHMARK|LOAD_FILE|NEXTVAL|LASTVAL|SETVAL)\s*\(|@/i';

	private static $loaded = null;

	/** Loads the scoped parser; false when this build has none. */
	public static function available() {
		if ( null !== self::$loaded ) {
			return self::$loaded;
		}
		$src = dirname( __DIR__ ) . '/vendor-scoped/sql-parser/src/';
		if ( ! function_exists( 'mb_strlen' ) || ! file_exists( $src . 'Parser.php' ) ) {
			self::$loaded = false;
			return false;
		}
		if ( PHP_VERSION_ID < 80000 && ! interface_exists( 'Stringable', false ) ) {
			require_once __DIR__ . '/stringable-polyfill.php';
		}
		spl_autoload_register(
			function ( $class ) use ( $src ) {
				$prefix = self::NS;
				if ( 0 === strpos( $class, $prefix ) ) {
					$file = $src . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
					if ( file_exists( $file ) ) {
						require $file;
					}
				}
			}
		);
		self::$loaded = class_exists( self::NS . 'Parser' );
		return self::$loaded;
	}

	/** Whether a statement may change something (cheap; no parsing). */
	public static function is_write( $sql ) {
		$sql = preg_replace( '#^(\s*(/\*.*?\*/|--[^\n]*\n|\#[^\n]*\n))+#s', '', (string) $sql );
		return (bool) preg_match( '/^\s*\(?\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|RENAME|LOAD|WITH|CALL|HANDLER|IMPORT)\b/i', $sql );
	}

	private static function is( $o, $short ) {
		return is_object( $o ) && is_a( $o, self::NS . $short );
	}

	private static function build( $component ) {
		if ( null === $component || array() === $component ) {
			return '';
		}
		$class = is_array( $component ) ? get_class( reset( $component ) ) : get_class( $component );
		return trim( (string) $class::build( $component ) );
	}

	/** `name` for a table the statement names, or null when it is in another database or not a plain table. */
	private static function table_of( $expr ) {
		if ( ! is_object( $expr ) || empty( $expr->table ) || ! empty( $expr->subquery ) ) {
			return null;
		}
		if ( ! empty( $expr->database ) && defined( 'DB_NAME' ) && DB_NAME !== $expr->database ) {
			return null;
		}
		return (string) $expr->table;
	}

	private static function unquote( $name ) {
		return str_replace( '`', '', trim( (string) $name ) );
	}

	/**
	 * The plan for one statement:
	 * - kind "rows": targets [{table, from, where, order, limit, alias, set}] of an UPDATE or DELETE;
	 * - kind "insert": table, columns, rows (raw SQL value per column, null when not a literal),
	 *   replace, update (ON DUPLICATE KEY UPDATE), ignore;
	 * - kind "tables": tables to keep whole, and why;
	 * - kind "none": the statement changes no table (why);
	 * - kind "unknown": the parser could not tell (why); the caller falls back.
	 *
	 * @param string $sql One statement.
	 * @return array
	 */
	public static function plan( $sql ) {
		if ( ! self::available() ) {
			return array( 'kind' => 'unknown', 'why' => 'this build of the plugin has no SQL parser' );
		}
		$parser_class = self::NS . 'Parser';
		try {
			$parser = new $parser_class( (string) $sql );
		} catch ( Throwable $e ) {
			return array( 'kind' => 'unknown', 'why' => 'the SQL parser failed: ' . $e->getMessage() );
		}
		$statements = array_values( array_filter( $parser->statements ) );
		if ( $parser->errors || 1 !== count( $statements ) ) {
			$why = $parser->errors ? 'the SQL parser did not understand it (' . $parser->errors[0]->getMessage() . ')' : ( count( $statements ) ? 'it is more than one statement' : 'it is empty' );
			return array( 'kind' => 'unknown', 'why' => $why );
		}
		$st = $statements[0];

		// Text of the statement without its string literals, to look for volatile functions.
		$code = '';
		foreach ( $parser->list->tokens as $t ) {
			if ( $t && constant( self::NS . 'Token::TYPE_STRING' ) !== $t->type ) {
				$code .= $t->token;
			}
		}

		if ( self::is( $st, 'Statements\\UpdateStatement' ) ) {
			return self::plan_update( $st, $code );
		}
		if ( self::is( $st, 'Statements\\DeleteStatement' ) ) {
			return self::plan_delete( $st, $code );
		}
		if ( self::is( $st, 'Statements\\InsertStatement' ) || self::is( $st, 'Statements\\ReplaceStatement' ) ) {
			return self::plan_insert( $st, $code );
		}
		if ( self::is( $st, 'Statements\\CreateStatement' ) ) {
			$t = self::is( $st->name ?? null, 'Components\\Expression' ) ? self::table_of( $st->name ) : null;
			$is_table = $st->options && ( $st->options->has( 'TABLE' ) );
			$is_index = $st->options && $st->options->has( 'INDEX' );
			if ( $is_index ) {
				// CREATE INDEX ix ON t (...): the parser keeps "ON t" in the body.
				$t = preg_match( '/\bON\s+(?:`?[\w$]+`?\s*\.\s*)?`?([\w$]+)`?/i', (string) $sql, $m ) ? $m[1] : null;
			}
			if ( ( $is_table || $is_index ) && $t ) {
				return array( 'kind' => 'tables', 'tables' => array( $t ), 'why' => 'DDL' );
			}
			return array( 'kind' => 'none', 'why' => 'it creates no table (a view, routine, trigger, database or user is not in the snapshot)' );
		}
		if ( self::is( $st, 'Statements\\AlterStatement' ) ) {
			$t = self::table_of( $st->table ?? null );
			if ( $st->options && $st->options->has( 'TABLE' ) && $t ) {
				$tables = array( $t );
				foreach ( (array) $st->altered as $op ) {
					// ALTER TABLE a RENAME TO b: b as well.
					$to = $op->options && $op->options->has( 'RENAME' ) ? $op->options->has( 'TO' ) : false;
					if ( is_string( $to ) && '' !== $to ) {
						$tables[] = preg_replace( '/^.*\./', '', self::unquote( $to ) );
					}
				}
				return array( 'kind' => 'tables', 'tables' => array_values( array_unique( $tables ) ), 'why' => 'DDL' );
			}
			return array( 'kind' => 'unknown', 'why' => 'an ALTER of something other than a table' );
		}
		if ( self::is( $st, 'Statements\\DropStatement' ) ) {
			if ( $st->options && $st->options->has( 'TABLE' ) ) {
				$tables = array();
				foreach ( (array) $st->fields as $f ) {
					$t = self::table_of( $f );
					if ( $t ) {
						$tables[] = $t;
					}
				}
				return $tables ? array( 'kind' => 'tables', 'tables' => $tables, 'why' => 'DDL' ) : array( 'kind' => 'unknown', 'why' => 'no table named' );
			}
			if ( $st->options && $st->options->has( 'INDEX' ) && ! empty( $st->table ) && self::table_of( $st->table ) ) {
				return array( 'kind' => 'tables', 'tables' => array( self::table_of( $st->table ) ), 'why' => 'DDL' );
			}
			return array( 'kind' => 'none', 'why' => 'it drops no table' );
		}
		if ( self::is( $st, 'Statements\\TruncateStatement' ) ) {
			$t = self::table_of( $st->table ?? null );
			return $t ? array( 'kind' => 'tables', 'tables' => array( $t ), 'why' => 'TRUNCATE' ) : array( 'kind' => 'unknown', 'why' => 'no table named' );
		}
		if ( self::is( $st, 'Statements\\RenameStatement' ) ) {
			$tables = array();
			foreach ( (array) $st->renames as $r ) {
				foreach ( array( $r->old, $r->new ) as $e ) {
					$t = self::table_of( $e );
					if ( $t ) {
						$tables[] = $t;
					}
				}
			}
			return $tables ? array( 'kind' => 'tables', 'tables' => $tables, 'why' => 'DDL' ) : array( 'kind' => 'unknown', 'why' => 'no table named' );
		}
		if ( self::is( $st, 'Statements\\SelectStatement' ) || self::is( $st, 'Statements\\ShowStatement' ) || self::is( $st, 'Statements\\SetStatement' ) || self::is( $st, 'Statements\\TransactionStatement' ) || self::is( $st, 'Statements\\ExplainStatement' ) ) {
			return array( 'kind' => 'none', 'why' => 'it changes no data' );
		}
		return array( 'kind' => 'unknown', 'why' => 'a ' . preg_replace( '/^.*\\\\/', '', get_class( $st ) ) . ' is not read row by row' );
	}

	/** Every table expression of the statement's FROM part, by alias (or name). */
	private static function aliases( array $tables, $joins ) {
		$map = array();
		$all = $tables;
		foreach ( (array) $joins as $j ) {
			$all[] = $j->expr;
		}
		foreach ( $all as $e ) {
			$t = self::table_of( $e );
			if ( null === $t ) {
				return null;
			}
			$map[ $e->alias ? self::unquote( $e->alias ) : $t ] = $t;
		}
		return $map;
	}

	private static function from_sql( array $tables, $joins ) {
		return self::build( $tables ) . ( $joins ? ' ' . self::build( $joins ) : '' );
	}

	private static function plan_update( $st, $code ) {
		$tables = (array) $st->tables;
		$map    = self::aliases( $tables, $st->join );
		if ( null === $map || ! $map ) {
			return array( 'kind' => 'unknown', 'why' => 'the tables could not be told (a subquery or another database)' );
		}
		$where = self::build( $st->where );
		if ( preg_match( self::VOLATILE, $code ) ) {
			return array( 'kind' => 'tables', 'tables' => array_values( array_unique( $map ) ), 'why' => 'its condition is not the same twice (RAND, UUID, a variable, ...)' );
		}
		if ( ! empty( $st->limit ) && empty( $st->order ) ) {
			return array( 'kind' => 'tables', 'tables' => array_values( array_unique( $map ) ), 'why' => 'LIMIT without ORDER BY picks rows the snapshot cannot repeat' );
		}
		// Which tables the SET changes: a qualified column names its table;
		// an unqualified one is the only table's, else the caller looks it up.
		$targets = array();
		foreach ( (array) $st->set as $op ) {
			$parts = explode( '.', self::unquote( $op->column ) );
			$col   = array_pop( $parts );
			$alias = $parts ? array_pop( $parts ) : null;
			if ( null === $alias && 1 === count( $map ) ) {
				$alias = key( $map );
			}
			if ( null !== $alias && ! isset( $map[ $alias ] ) ) {
				return array( 'kind' => 'unknown', 'why' => "the SET names $alias, which is not one of its tables" );
			}
			$key = null === $alias ? '?' : $alias;
			$targets[ $key ]['alias']   = $alias;
			$targets[ $key ]['table']   = null === $alias ? null : $map[ $alias ];
			$targets[ $key ]['columns'][] = $col;
		}
		$out = array();
		foreach ( $targets as $t ) {
			$out[] = array(
				'table'   => $t['table'],
				'alias'   => $t['alias'],
				'columns' => $t['columns'],
				'from'    => self::from_sql( $tables, $st->join ),
				'where'   => $where,
				'order'   => self::build( $st->order ),
				'limit'   => self::build( $st->limit ),
				'single'  => 1 === count( $map ),
			);
		}
		return array( 'kind' => 'rows', 'op' => 'UPDATE', 'targets' => $out, 'aliases' => $map );
	}

	private static function plan_delete( $st, $code ) {
		$from = (array) $st->from;
		if ( ! empty( $st->using ) ) {
			return array( 'kind' => 'unknown', 'why' => 'DELETE ... USING is not read row by row' );
		}
		$map = self::aliases( $from, $st->join );
		if ( null === $map || ! $map ) {
			return array( 'kind' => 'unknown', 'why' => 'the tables could not be told (a subquery or another database)' );
		}
		if ( preg_match( self::VOLATILE, $code ) ) {
			return array( 'kind' => 'tables', 'tables' => array_values( array_unique( $map ) ), 'why' => 'its condition is not the same twice (RAND, UUID, a variable, ...)' );
		}
		if ( ! empty( $st->limit ) && empty( $st->order ) ) {
			return array( 'kind' => 'tables', 'tables' => array_values( array_unique( $map ) ), 'why' => 'LIMIT without ORDER BY picks rows the snapshot cannot repeat' );
		}
		// DELETE a, b FROM ...: the tables named before FROM; else the one table.
		$aliases = array();
		if ( ! empty( $st->columns ) ) {
			foreach ( (array) $st->columns as $c ) {
				$name = self::unquote( preg_replace( '/\.\*$/', '', (string) ( $c->expr ?? $c->table ?? '' ) ) );
				$name = preg_replace( '/^.*\./', '', $name );
				if ( ! isset( $map[ $name ] ) ) {
					return array( 'kind' => 'unknown', 'why' => "it deletes from $name, which is not one of its tables" );
				}
				$aliases[] = $name;
			}
		} elseif ( 1 === count( $map ) ) {
			$aliases[] = key( $map );
		} else {
			return array( 'kind' => 'unknown', 'why' => 'it does not say which tables it deletes from' );
		}
		$out = array();
		foreach ( array_unique( $aliases ) as $a ) {
			$out[] = array(
				'table'  => $map[ $a ],
				'alias'  => $a,
				'from'   => self::from_sql( $from, $st->join ),
				'where'  => self::build( $st->where ),
				'order'  => self::build( $st->order ),
				'limit'  => self::build( $st->limit ),
				'single' => 1 === count( $map ),
			);
		}
		return array( 'kind' => 'rows', 'op' => 'DELETE', 'targets' => $out, 'aliases' => $map );
	}

	/** A raw SQL value that means the same in a SELECT as in the statement. */
	private static function literal( $raw ) {
		$raw = trim( (string) $raw );
		return (bool) preg_match( '/^(NULL|TRUE|FALSE|[-+]?\d+(\.\d+)?([eE][-+]?\d+)?|0x[0-9a-fA-F]+|[xX]\'[0-9a-fA-F]*\'|(_[a-z0-9]+\s*)?\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*")$/s', $raw );
	}

	private static function plan_insert( $st, $code ) {
		$replace = self::is( $st, 'Statements\\ReplaceStatement' );
		$into    = $st->into ?? null;
		$table   = $into ? self::table_of( $into->dest ) : null;
		if ( null === $table ) {
			return array( 'kind' => 'unknown', 'why' => 'the table could not be told' );
		}
		$base = array(
			'kind'    => 'insert',
			'table'   => $table,
			'replace' => $replace,
			'update'  => ! empty( $st->onDuplicateSet ),
			'ignore'  => $st->options && $st->options->has( 'IGNORE' ),
		);
		if ( ! empty( $st->select ) ) {
			return array( 'kind' => 'tables', 'tables' => array( $table ), 'why' => 'INSERT ... SELECT writes rows that cannot be told in advance' );
		}
		$columns = array();
		$rows    = array();
		if ( ! empty( $st->set ) ) {
			$row = array();
			foreach ( (array) $st->set as $op ) {
				$col       = preg_replace( '/^.*\./', '', self::unquote( $op->column ) );
				$columns[] = $col;
				$row[]     = self::literal( $op->value ) && ! preg_match( self::VOLATILE, $op->value ) ? trim( $op->value ) : null;
			}
			$rows[] = $row;
		} else {
			$columns = array_map( array( __CLASS__, 'unquote' ), (array) ( $into->columns ?? array() ) );
			if ( ! $columns ) {
				return array( 'kind' => 'tables', 'tables' => array( $table ), 'why' => 'INSERT without a column list' ) + $base;
			}
			foreach ( (array) $st->values as $arr ) {
				$row = array();
				foreach ( (array) $arr->raw as $raw ) {
					$row[] = self::literal( $raw ) ? trim( $raw ) : null;
				}
				if ( count( $row ) !== count( $columns ) ) {
					return array( 'kind' => 'unknown', 'why' => 'a VALUES row has the wrong number of values' );
				}
				$rows[] = $row;
			}
		}
		return $base + array( 'columns' => $columns, 'rows' => $rows );
	}
}
