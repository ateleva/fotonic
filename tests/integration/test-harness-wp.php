<?php
require __DIR__ . '/../lib/wp-bootstrap.php';
t_true( defined( 'FOTONIC_VERSION' ), 'WordPress and the free plugin are loaded' );
t_true( current_user_can( 'manage_options' ), 'tests run as an administrator' );
t_done();
