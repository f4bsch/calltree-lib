<?php
set_include_path(get_include_path().PATH_SEPARATOR. dirname(__FILE__).'/');
spl_autoload_extensions(".php"); // comma-separated list
spl_autoload_register();