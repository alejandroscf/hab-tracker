<?php
    global $secretKey;
    global $filePath;
    global $name;
    global $zoom;
    global $accuracy;
    global $height;
    global $width;
    #Change this! Just to prevent random people from being able to change your displayed location
    $secretKey = 'abcdefg';
    #used to round the lat/lon values so you aren't sharing your *exact* location
    $accuracy = 2; 
    $filePath = '/tmp/location';

    # Display options

    $name = 'Toby';
    #how far to zoom in on the map
    $zoom = "8";
    $height = "500";
    $width = "600";
?>
<html>
<body>
  <p>This is just a settings file. Nothing to see here. Move along now.</p>
  <p>You probably want the <a href="map.php">Map page</a></p>
</body>
</html>