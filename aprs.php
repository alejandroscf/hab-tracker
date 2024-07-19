<?php
   include "settings.php";
   $info['indicativo'] = $_GET['indicativo'];
   $json = file_get_contents('https://api.aprs.fi/api/get?name=' . $info['indicativo'] . '&what=loc&apikey=' . $AprsFiKey . '&format=json');
echo $json;
   //$obj = json_decode($json);
   //var_dump($obj);
