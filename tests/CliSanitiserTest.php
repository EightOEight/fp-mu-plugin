<?php
/**
 * Unit tests for FrankenPress\Cli\Snapshot\Sanitiser.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use FrankenPress\Cli\Snapshot\Sanitiser;
use PHPUnit\Framework\TestCase;

final class CliSanitiserTest extends TestCase {

	private Sanitiser $sanitiser;

	protected function setUp(): void {
		parent::setUp();
		$this->sanitiser = new Sanitiser();
	}

	/**
	 * @dataProvider provide_dropped_lines
	 */
	public function test_drops_lines( string $line ): void {
		$this->assertNull( $this->sanitiser->sanitise( $line ) );
	}

	public static function provide_dropped_lines(): array {
		return array(
			'transient option'               => array(
				"INSERT INTO `wp_options` VALUES (42,'_transient_foo','bar','yes');",
			),
			'site transient option'          => array(
				"INSERT INTO `wp_options` VALUES (43,'_site_transient_update_plugins','a:0:{}','no');",
			),
			'cron option'                    => array(
				"INSERT INTO `wp_options` VALUES (1,'cron','a:1:{i:1234567890;a:0:{}}','yes');",
			),
			'bedrock autoloader cache'       => array(
				"INSERT INTO `wp_options` VALUES (99,'bedrock_autoloader','a:2:{}','yes');",
			),
			'session_tokens usermeta'        => array(
				"INSERT INTO `wp_usermeta` VALUES (10,1,'session_tokens','a:1:{}');",
			),
			'wp_user-settings usermeta'      => array(
				"INSERT INTO `wp_usermeta` VALUES (11,1,'wp_user-settings','editor=tinymce');",
			),
			'actionscheduler_actions insert' => array(
				"INSERT INTO `wp_actionscheduler_actions` VALUES (1,'pending');",
			),
			'actionscheduler_logs insert'    => array(
				"INSERT INTO `wp_actionscheduler_logs` VALUES (1,42,'created');",
			),
			'custom prefix transient'        => array(
				"INSERT INTO `sts_options` VALUES (7,'_transient_doing_cron','1234567890.123','yes');",
			),
			'custom prefix actionscheduler'  => array(
				"INSERT INTO `sts_actionscheduler_claims` VALUES (1,'x');",
			),
		);
	}

	/**
	 * @dataProvider provide_kept_lines
	 */
	public function test_keeps_lines_unchanged( string $line ): void {
		$this->assertSame( $line, $this->sanitiser->sanitise( $line ) );
	}

	public static function provide_kept_lines(): array {
		return array(
			'normal option'                   => array(
				"INSERT INTO `wp_options` VALUES (1,'siteurl','http://localhost:8080','yes');",
			),
			'normal usermeta'                 => array(
				"INSERT INTO `wp_usermeta` VALUES (1,1,'nickname','admin');",
			),
			'normal post insert'              => array(
				"INSERT INTO `wp_posts` VALUES (1,1,'2026-05-11 00:00:00','...');",
			),
			'create table statement'          => array(
				'CREATE TABLE `wp_options` (id bigint, option_name varchar(255));',
			),
			'drop table statement'            => array(
				'DROP TABLE IF EXISTS `wp_options`;',
			),
			'comment line'                    => array(
				'-- MySQL dump 10.13  Distrib 8.0.32',
			),
			'lock tables'                     => array(
				'LOCK TABLES `wp_options` WRITE;',
			),
			'option with apostrophe in value' => array(
				"INSERT INTO `wp_options` VALUES (5,'blogname','It\\'s a Site','yes');",
			),
		);
	}

	public function test_redacts_user_password_preserves_row(): void {
		$input = "INSERT INTO `wp_users` VALUES (1,'admin','\$P\$BabcdefgHASH1234567890','admin','admin@example.com','','2026-05-11 00:00:00','',0,'admin');";

		$out = $this->sanitiser->sanitise( $input );

		$this->assertNotNull( $out );
		$this->assertStringNotContainsString( '$P$Babcdef', $out );
		$this->assertStringContainsString( Sanitiser::REDACTED_PASSWORD, $out );
		// Other columns preserved verbatim.
		$this->assertStringContainsString( "'admin'", $out );
		$this->assertStringContainsString( "'admin@example.com'", $out );
		$this->assertStringContainsString( "'2026-05-11 00:00:00'", $out );
	}

	public function test_redacts_user_password_with_custom_prefix(): void {
		$input = "INSERT INTO `sts_users` VALUES (1,'admin','\$bcrypt\$realhashvaluehere','admin','x@y.com','','2026-05-11 00:00:00','',0,'admin');";

		$out = $this->sanitiser->sanitise( $input );

		$this->assertNotNull( $out );
		$this->assertStringNotContainsString( 'realhashvaluehere', $out );
		$this->assertStringContainsString( Sanitiser::REDACTED_PASSWORD, $out );
	}

	public function test_options_table_match_does_not_falsely_trigger_on_users_table(): void {
		// The 'users' regex contains the literal substring "options"-style
		// suffix matching; this guards against accidental coupling. A
		// `wp_useroptions` table (hypothetical) should NOT be treated as
		// the options table for transient-stripping purposes.
		$line = "INSERT INTO `wp_useroptions` VALUES (1,'_transient_doing_cron','x','yes');";

		// We expect the sanitiser to pass it through unchanged — it's a
		// table we don't have rules for.
		$out = $this->sanitiser->sanitise( $line );

		$this->assertSame( $line, $out );
	}
}
