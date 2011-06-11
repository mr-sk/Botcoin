#!/usr/bin/php
<?php
/**
 *  This is the bootstrapping code for the bot. Start in a screen to keep it running
 *  for extended periods of time.
 *
 * @author      mr-sk aka sk <sk@mr-sk.com>
 * @package     Botcoin
 * @copyright   None yo, just keep this header in place!
 * @example     ./run.php >> /dev/null
 *
 * @see config.ini
 * @see Botcoin.php
 */
require_once('config.ini');
require_once('Botcoin.php');

$p = new Botcoin(parse_ini_file('config.ini'));
$p->run();