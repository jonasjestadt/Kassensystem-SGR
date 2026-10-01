<?php
require __DIR__ . '/../app/bootstrap.php';
redirect(needs_setup() ? 'setup.php' : (current_user() ? 'kasse.php' : 'login.php'));
