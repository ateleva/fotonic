<?php
require __DIR__ . '/../lib/wp-bootstrap.php';

// A restore job belongs to the whole site, but its session answers to one person and one browser. Reviewer findings of
// phase 13: another admin (or the same admin without the cookie) must not end a running restore, a second backup must not
// close the session of a running restore or rewrite its plan, and the upload cleanup must not delete an archive that an
// open session reads. Only options are written (the job, one fake session, the registry); they are saved first and put
// back at the end. Skipped when a real restore is running.

$real = Fotonic_Restore_Job::job();
if ( null !== $real && in_array( $real['status'], array( 'running', 'paused', 'undoing', 'error', 'done' ), true ) ) {
	echo "skipped: a real restore is on this site\n";
	t_done();
	exit;
}
$sid    = bin2hex( random_bytes( 16 ) );
$prefix = Fotonic_Restore_Reader::OPTION_PREFIX;
t_preserve_options( array( Fotonic_Restore_Job::OPTION, Fotonic_Restore_Reader::OPTION_REGISTRY, $prefix . $sid ) );
register_shutdown_function( function () use ( $sid, $prefix ) {
	delete_option( $prefix . $sid );
} );

$up_id = bin2hex( random_bytes( 16 ) );
$path  = Fotonic_Restore_Upload::restore_dir() . '/up-' . $up_id . '.zip';
$other = (int) get_current_user_id() + 1000;
$make  = function ( int $user, int $age = 0 ) use ( $sid, $prefix, $path ) {
	update_option( $prefix . $sid, array( 'created' => time() - $age, 'user_id' => $user, 'wrap' => 'x', 'collections' => array(), 'path' => $path ), false );
	update_option( Fotonic_Restore_Reader::OPTION_REGISTRY, array( $sid => array( 'user' => $user, 'created' => time() - $age ) ), false );
};
$job = function ( string $status ) use ( $sid ) {
	update_option( Fotonic_Restore_Job::OPTION, array(
		'id' => 'job' . bin2hex( random_bytes( 4 ) ), 'status' => $status, 'sid' => $sid, 'updated' => time(), 'created' => time(),
		'total' => 10, 'done' => 1, 'scenario' => 'C', 'mode' => 'merge', 'step' => 'records', 'counts' => array(), 'report' => array(), 'safety' => '', 'error' => '',
	), false );
	return Fotonic_Restore_Job::job();
};

echo "another person's session: alive, not readable here\n";
$make( $other );
t_true( Fotonic_Restore_Reader::session_alive( $sid ), 'the session is alive for everyone' );
t_true( is_wp_error( Fotonic_Restore_Reader::work_dir( $sid ) ), 'but this person cannot use it' );
t_true( Fotonic_Restore_Reader::path_in_use( $path ), 'its archive is in use' );

echo "a running job survives a request that cannot see its session\n";
$j = $job( 'running' );
t_true( Fotonic_Restore_Job::is_blocking(), 'the job still blocks writes (and is not expired by a save of someone else)' );
t_equals( 'running', Fotonic_Restore_Job::job()['status'], 'the job is still running' );
$r = Fotonic_Restore_Job::run( $j['id'] );
t_true( is_wp_error( $r ) && 'ftnc_restore_other_browser' === $r->get_error_code(), 'run() says it runs elsewhere' );
t_equals( 'running', Fotonic_Restore_Job::job()['status'], 'and does not touch the job' );
t_true( Fotonic_Restore_Job::uses_session( $sid ), 'the running job uses its session' );

echo "a second backup cannot close the session of a running job, a preview cannot rewrite its plan\n";
$make( (int) get_current_user_id() );
$job( 'running' );
$o = Fotonic_Restore_Reader::open( $path, null );
t_true( is_wp_error( $o ) && 'ftnc_restore_in_use' === $o->get_error_code(), 'open() refuses while the job uses the session' );
t_true( null !== get_option( $prefix . $sid, null ), 'and the session is still there' );
$p = Fotonic_Restore_Preview::step( $sid, true );
t_true( is_wp_error( $p ) && 'ftnc_restore_in_use' === $p->get_error_code(), 'the preview refuses too' );
$job( 'error' );
t_true( Fotonic_Restore_Job::uses_session( $sid ), 'an error job waiting to be resumed still uses it' );
$job( 'done' );
t_true( ! Fotonic_Restore_Job::uses_session( $sid ), 'a finished job does not' );

echo "the job lock: pause, resume and dismiss wait for a running batch, undo does not\n";
$make( (int) get_current_user_id() );
$dir = Fotonic_Restore_Upload::restore_dir() . '/s-' . $sid;
wp_mkdir_p( $dir );
t_equals( $dir, Fotonic_Restore_Reader::shared_dir( $sid ), 'the folder of a live session is found by anyone' );
$j    = $job( 'running' );
$held = fopen( $dir . '/job.lock', 'c' );
flock( $held, LOCK_EX ); // A batch is writing.
$t0 = microtime( true );
$r  = Fotonic_Restore_Job::undo( $j['id'] );
t_true( is_wp_error( $r ) && 'ftnc_restore_busy' === $r->get_error_code(), 'undo says busy while a batch holds the lock' );
t_true( microtime( true ) - $t0 < 2, 'and does not wait for it' );
$r = Fotonic_Restore_Job::pause( $j['id'] );
t_true( is_wp_error( $r ) && 'ftnc_restore_busy' === $r->get_error_code(), 'pause says busy when the batch does not end' );
t_equals( 'running', Fotonic_Restore_Job::job()['status'], 'and leaves the job as it was' );
flock( $held, LOCK_UN ); // The batch ends.
$r = Fotonic_Restore_Job::pause( $j['id'] );
t_true( ! is_wp_error( $r ), 'pause works once the lock is free' );
t_equals( 'paused', Fotonic_Restore_Job::job()['status'], 'the job is paused' );
$r = Fotonic_Restore_Job::resume( $j['id'] );
t_true( ! is_wp_error( $r ) && 'running' === Fotonic_Restore_Job::job()['status'], 'resume works' );
fclose( $held );
delete_option( $prefix . $sid );
t_equals( '', Fotonic_Restore_Reader::shared_dir( $sid ), 'a session that is gone has no folder to lock' );

echo "locking everywhere closes the sessions nothing is writing from\n";
$make( (int) get_current_user_id() );
wp_mkdir_p( $dir );
$job( 'paused' );
t_equals( 0, Fotonic_Restore_Reader::close_unused(), 'a session a paused job needs stays' );
t_true( null !== get_option( $prefix . $sid, null ), 'and is still there' );
$job( 'done' );
t_equals( 1, Fotonic_Restore_Reader::close_unused(), 'a session no job needs is closed' );
t_true( null === get_option( $prefix . $sid, null ) && ! is_dir( $dir ), 'its key and folder are gone' );

echo "an archive older than the upload limit stays while a session reads it\n";
$ok = wp_mkdir_p( Fotonic_Restore_Upload::restore_dir() );
t_true( $ok, 'the restore folder exists' );
file_put_contents( $path, 'PK' );
$meta_file = Fotonic_Restore_Upload::restore_dir() . '/up-' . $up_id . '.json';
file_put_contents( $meta_file, wp_json_encode( array( 'id' => $up_id, 'created' => time() - Fotonic_Restore_Upload::TTL - 60, 'size' => 2, 'total' => 1, 'got' => '1', 'state' => 'done' ) ) );
$make( (int) get_current_user_id() );
Fotonic_Restore_Upload::cleanup();
t_true( file_exists( $path ), 'the zip is kept while a session reads it' );
delete_option( $prefix . $sid );
update_option( Fotonic_Restore_Reader::OPTION_REGISTRY, array(), false );
Fotonic_Restore_Upload::cleanup();
t_true( ! file_exists( $path ), 'and removed once nothing reads it' );
@unlink( $path );
@unlink( $meta_file );

echo "a session that is really gone ends the job\n";
$job( 'running' );
delete_option( $prefix . $sid );
t_true( ! Fotonic_Restore_Job::is_blocking(), 'no session: the job stops blocking' );
t_equals( 'expired', Fotonic_Restore_Job::job()['status'], 'and becomes expired' );

t_done();
