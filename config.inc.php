<?php

/*
  ILO ACCESS CREDENTIALS
  --------------
  These are used to connect to the iLO
  interface and manage the fan speeds.
*/

$ILO_HOST = 'your-ilo-ip';  // Ex. 192.168.1.69
$ILO_USERNAME = 'your-ilo-username';  // Ex. Administrator
$ILO_PASSWORD = 'your-ilo-password';  // Ex. AdministratorPassword1234
$UNRAID_HOST='your-ip';
$UNRAID_API_KEY='your-readonly-api';


/*
  MISCELLANEOUS SETTINGS
  --------------
  These allows you to customize
  the behavior of the tool.
*/

// Minimum fan speed percentage, from 0% (DANGEROUS) to 100%
$MINIMUM_FAN_SPEED = 10;
$AUTO_DAEMON = true;

?>