<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require dirname(__DIR__,2).'/app/autoload.php';
echo "Toward Your Future v".KMM\Core\SiteState::displayVersion()."\n";
echo "Install state: ", KMM\Core\SiteState::state(),"\n";
echo "PHP: ",PHP_VERSION,"\n";
echo "PDO MySQL: ",(extension_loaded('pdo_mysql')?'available':'missing'),"\n";
echo "Argon2id: ",(defined('PASSWORD_ARGON2ID')?'available':'missing'),"\n";
echo "No passwords, tokens, database credentials or user data are printed.\n";
