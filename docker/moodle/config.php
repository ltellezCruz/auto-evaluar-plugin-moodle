<?php  // Moodle configuration file (Docker). Todo sale de variables de entorno.

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = getenv('MOODLE_DB_HOST') ?: 'db';
$CFG->dbname    = getenv('MOODLE_DB_NAME') ?: 'moodle';
$CFG->dbuser    = getenv('MOODLE_DB_USER') ?: 'moodle';
$CFG->dbpass    = getenv('MOODLE_DB_PASSWORD') ?: '';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = [
    'dbpersist'   => 0,
    'dbport'      => '',
    'dbsocket'    => '',
    'dbcollation' => 'utf8mb4_unicode_ci',
];

$CFG->wwwroot   = getenv('MOODLE_WWWROOT') ?: 'http://localhost:8080';
$CFG->dataroot  = '/var/www/moodledata';
$CFG->admin     = 'admin';
$CFG->directorypermissions = 02777;

// Detrás de un proxy HTTPS (por ejemplo ngrok apuntando al puerto publicado).
if (getenv('MOODLE_SSLPROXY')) {
    $CFG->sslproxy = true;
}

require_once(__DIR__ . '/lib/setup.php');
