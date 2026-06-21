<?php

/**
 * Entry point when the web server document root is the project directory
 * instead of `public/`. Forwards to the real front controller.
 */
require __DIR__.'/public/index.php';
