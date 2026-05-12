<?php
/**
 * Snapshot manifest builder for `wp fp snapshot`.
 *
 * Builds the `fp.snapshot/v4` manifest as a nested array and emits it
 * as a stable, deterministic YAML document. "Deterministic" matters:
 * future phases cosign-sign the manifest content hash, so any
 * non-determinism (different key order, trailing whitespace, etc.)
 * breaks signature verification on the apply side. The hand-rolled
 * emitter here is intentionally minimal — no anchor/alias support, no
 * tag URIs, no folded scalars.
 *
 * Schema reference: workspace `.aidocs/fse-only-pivot.md` §"fp.snapshot/v3
 * shape".
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

final class Manifest {

	public const SCHEMA = 'fp.snapshot/v4';

	/**
	 * @param array<string, mixed> $data Top-level manifest keys, already populated
	 *                                   in the desired emission order.
	 */
	public function __construct( private array $data ) {}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * Emit the manifest as a YAML document (no leading `---`, single
	 * trailing newline). Deterministic: same input array → byte-identical
	 * output, every time.
	 */
	public function to_yaml(): string {
		return $this->emit_mapping( $this->data, 0 ) . '';
	}

	/**
	 * Emit an associative array as YAML mapping at the given indent
	 * level. Indent is in spaces (2 per level — the conventional YAML
	 * style and what gopkg.in/yaml.v3 emits by default).
	 *
	 * @param array<string, mixed> $map
	 */
	private function emit_mapping( array $map, int $indent ): string {
		$pad = str_repeat( ' ', $indent );
		$out = '';
		foreach ( $map as $key => $value ) {
			$key_str = $this->emit_key( (string) $key );
			if ( is_array( $value ) && $this->is_assoc( $value ) ) {
				if ( array() === $value ) {
					$out .= $pad . $key_str . ': {}' . "\n";
					continue;
				}
				$out .= $pad . $key_str . ':' . "\n";
				$out .= $this->emit_mapping( $value, $indent + 2 );
				continue;
			}
			if ( is_array( $value ) ) {
				if ( array() === $value ) {
					$out .= $pad . $key_str . ': []' . "\n";
					continue;
				}
				$out .= $pad . $key_str . ':' . "\n";
				foreach ( $value as $item ) {
					$out .= $pad . '- ' . $this->emit_scalar( $item ) . "\n";
				}
				continue;
			}
			$out .= $pad . $key_str . ': ' . $this->emit_scalar( $value ) . "\n";
		}
		return $out;
	}

	private function emit_key( string $key ): string {
		// All our keys are simple alphanumeric+underscore — no quoting
		// needed. Guard against accidental future keys that need it.
		if ( 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $key ) ) {
			return $key;
		}
		return $this->emit_quoted( $key );
	}

	/**
	 * @param mixed $value
	 */
	private function emit_scalar( $value ): string {
		if ( true === $value ) {
			return 'true';
		}
		if ( false === $value ) {
			return 'false';
		}
		if ( null === $value ) {
			return 'null';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		$str = (string) $value;
		if ( '' === $str ) {
			return '""';
		}
		// Quote if it looks like it could be reinterpreted (numbers,
		// booleans, special starters) or contains characters that need
		// escaping.
		if ( $this->scalar_needs_quoting( $str ) ) {
			return $this->emit_quoted( $str );
		}
		return $str;
	}

	private function scalar_needs_quoting( string $str ): bool {
		if ( 1 === preg_match( '/^(true|false|null|yes|no|on|off|~)$/i', $str ) ) {
			return true;
		}
		if ( 1 === preg_match( '/^[+-]?[0-9]+(\.[0-9]+)?$/', $str ) ) {
			return true;
		}
		// Leading/trailing whitespace, or any control / special YAML char.
		if ( $str !== trim( $str ) ) {
			return true;
		}
		if ( 1 === preg_match( '/[:#\\\\"\'\[\]\{\},&*!|>%@`\r\n\t]/', $str ) ) {
			return true;
		}
		// Leading character that YAML uses as indicator.
		$first = $str[0];
		if ( in_array( $first, array( '-', '?', ':', ',', '[', ']', '{', '}', '#', '&', '*', '!', '|', '>', '\'', '"', '%', '@', '`' ), true ) ) {
			return true;
		}
		return false;
	}

	private function emit_quoted( string $str ): string {
		// Double-quoted style for everything that needs quoting, with
		// the minimum-necessary escape set.
		$escaped = strtr(
			$str,
			array(
				'\\' => '\\\\',
				'"'  => '\\"',
				"\n" => '\\n',
				"\r" => '\\r',
				"\t" => '\\t',
			)
		);
		return '"' . $escaped . '"';
	}

	/**
	 * @param array<int|string, mixed> $arr
	 */
	private function is_assoc( array $arr ): bool {
		if ( array() === $arr ) {
			return false; // Treated as empty list rather than empty map by caller.
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}
}
