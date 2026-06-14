<?php
echo 'phar.readonly: ' . ini_get( 'phar.readonly' ) . PHP_EOL;
@ini_set( 'phar.readonly', 0 );
echo 'after ini_set: ' . ini_get( 'phar.readonly' ) . PHP_EOL;
echo 'exec: ' . ( \TheExporter\Runtime::exec_available() ? 'yes' : 'no' ) . PHP_EOL;
echo 'tar: ' . ( \TheExporter\Runtime::command_exists( 'tar' ) ? 'yes' : 'no' ) . PHP_EOL;

$d = sys_get_temp_dir() . '/te-phar-test';
wp_mkdir_p( $d . '/segments' );
$tar = $d . '/segments/_t.tar';
@unlink( $tar );

try {
	$t = new PharData( $tar );
	$t->addFromString( 'hello.txt', 'hello' );
	$t->compress( Phar::GZ );
	echo "phar write: ok\n";
} catch ( Exception $e ) {
	echo 'phar write: fail - ' . $e->getMessage() . PHP_EOL;
}
