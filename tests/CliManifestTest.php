<?php
/**
 * Unit tests for FrankenPress\Cli\Snapshot\Manifest.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use FrankenPress\Cli\Snapshot\Manifest;
use PHPUnit\Framework\TestCase;

final class CliManifestTest extends TestCase {

	public function test_emits_flat_scalars(): void {
		$out = ( new Manifest(
			array(
				'schema'  => 'fp.snapshot/v4',
				'id'      => '01HXR2-architect-2',
				'created' => '2026-05-11T09:14:22Z',
			)
		) )->to_yaml();

		$expected = "schema: fp.snapshot/v4\nid: 01HXR2-architect-2\ncreated: \"2026-05-11T09:14:22Z\"\n";

		$this->assertSame( $expected, $out );
	}

	public function test_quotes_strings_that_could_be_misread(): void {
		$out = ( new Manifest(
			array(
				'looks_like_bool'  => 'true',
				'looks_like_int'   => '42',
				'looks_like_float' => '1.0',
				'looks_like_null'  => 'null',
				'normal_word'      => 'hello',
			)
		) )->to_yaml();

		$this->assertStringContainsString( "looks_like_bool: \"true\"\n", $out );
		$this->assertStringContainsString( "looks_like_int: \"42\"\n", $out );
		$this->assertStringContainsString( "looks_like_float: \"1.0\"\n", $out );
		$this->assertStringContainsString( "looks_like_null: \"null\"\n", $out );
		$this->assertStringContainsString( "normal_word: hello\n", $out );
	}

	public function test_preserves_actual_bools_and_ints(): void {
		$out = ( new Manifest(
			array(
				'flag'  => true,
				'count' => 42,
				'rate'  => 1.5,
				'empty' => null,
			)
		) )->to_yaml();

		$this->assertSame( "flag: true\ncount: 42\nrate: 1.5\nempty: null\n", $out );
	}

	public function test_emits_nested_mapping(): void {
		$out = ( new Manifest(
			array(
				'source' => array(
					'site' => 'sts',
					'env'  => 'local',
					'git'  => array(
						'site_repo' => 'github.com/EightOEight/sts@a1f7d3d',
					),
				),
			)
		) )->to_yaml();

		$expected = "source:\n  site: sts\n  env: local\n  git:\n    site_repo: \"github.com/EightOEight/sts@a1f7d3d\"\n";

		$this->assertSame( $expected, $out );
	}

	public function test_emits_sequence(): void {
		$out = ( new Manifest(
			array(
				'post_types' => array( 'wp_template', 'wp_template_part' ),
			)
		) )->to_yaml();

		$this->assertSame( "post_types:\n- wp_template\n- wp_template_part\n", $out );
	}

	public function test_emits_empty_sequence(): void {
		$out = ( new Manifest(
			array(
				'pending' => array(),
			)
		) )->to_yaml();

		// PHP's empty array() can't disambiguate empty list from empty
		// mapping; we emit `[]`, which is valid YAML for both.
		$this->assertSame( "pending: []\n", $out );
	}

	public function test_deterministic_for_identical_input(): void {
		$data = array(
			'schema'  => 'fp.snapshot/v4',
			'id'      => '01HXR2',
			'source'  => array(
				'site' => 'sts',
				'env'  => 'local',
				'git'  => array( 'site_repo' => 'github.com/x/y@abc' ),
			),
			'adapter' => 'fse',
		);

		$a = ( new Manifest( $data ) )->to_yaml();
		$b = ( new Manifest( $data ) )->to_yaml();

		$this->assertSame( $a, $b );
		$this->assertSame( hash( 'sha256', $a ), hash( 'sha256', $b ) );
	}

	public function test_quotes_strings_with_colons(): void {
		$out = ( new Manifest(
			array(
				'site_image' => 'ghcr.io/eightoeight/sts:v0.0.5',
			)
		) )->to_yaml();

		$this->assertStringContainsString(
			'site_image: "ghcr.io/eightoeight/sts:v0.0.5"',
			$out
		);
	}

	public function test_to_array_round_trips(): void {
		$data = array(
			'schema' => 'fp.snapshot/v4',
			'id'     => 'foo',
		);
		$this->assertSame( $data, ( new Manifest( $data ) )->to_array() );
	}
}
