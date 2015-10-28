<?php
/* Provides functionality for recieving,  parsing and saving XML data from an Android Walking Tour app
   Author: o.saunders at me.com */

define("VERSION", "1.1b");

/* Function to calculate the total time for a walk */
function calculateWalkTime($firstTime, $lastTime)
{
    $timeElapsedInSeconds = $lastTime - $firstTime; // Difference between most recent timestamp and start in seconds
    $duration = $timeElapsedInSeconds / 3600; // Convert to hours
    $roundedDuration = round($duration, 4); // Round to four decimal places 

    return $roundedDuration; // Return the duration of the walk in hours
}

/* Function to convert a provided base64 encoded string into an image file */
function convertXMLImage($base64image, $outputFile)
{
    $fhandle = fopen("$outputFile", "wb"); // Open the output file
    fwrite($fhandle, base64_decode($base64image)); // Write the base64 string to the file
    fclose($fhandle); // Close the file
    chmod("$outputFile", 0755); // Set permissions on the new file
}

/* Simple function to calculate naive distances between two points in miles
 * Would be more accurate using the Google JavaScript API in the future
 */
function calculateDistanceInMiles($lat1, $lng1, $lat2, $lng2)
{
    $earthRadius = 3958.75; // Number assumed to be the radius of the Earth

    // Convert degrees to radians as required for the distance calculation
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    // Calculate the distance using a common algorithm for this purpose
    $a = sin($dLat / 2) * sin($dLat / 2) +
       cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
       sin($dLng / 2) * sin($dLng / 2);

    $c = 2 * atan2(sqrt($a),  sqrt(1 - $a));

    $distanceInMiles = $earthRadius * $c;

    return (double) $distanceInMiles;
}

require('database.php'); // Connect to the database

/* -------------
 * Read XML data
 * -------------
 */

/* Use $_POST to obtain XML data from the Android app HTTP request */
if (isset($_POST["data"]) and !empty($_POST["data"])) 
{
    // Save into a file for debugging purposes
    file_put_contents('data.xml', $_POST["data"]);
    // Load the XML file in for processing
    $walk = simplexml_load_file('data.xml');
    
    if (!$walk) // If there was an error reading this XML data 
    {
        file_put_contents('Error reading XML data!', 'XMLError.log');
        die ('Error: XML data was null');
    }
}

/* If running from the command line,  read in XML file provided */
elseif (isset ($argv[1]))
{
    $walk = simplexml_load_file($argv[1]);

    if (!$walk) 
    {
        die ('Error reading provided XML file');
    }
}

else
{
    echo "getData.php: " . constant("VERSION") . "<br><br>"; 
    die ('Error: No data received from Android application'); 
}  

/* ---------------- 
 * Process XML data
 * ----------------
 */

/* Save XML data to variables */
$title = $walk->title;
$shortDesc = $walk->short_description;
$longDesc = $walk->long_description;

$initialTimestamp = 0; // initialTimestamp is 0,  duration is end time - start time in hours

/* These variables are calculated later */
$hours = 0; 
$distance = 0;

/* -------------
 * Process Walks
 * -------------
 */

/* SQL Statement to insert a new walk into the 'walk' table */
$insertWalkSQL = "INSERT INTO walk (title,  shortDesc,  longDesc,  hours,  distance)
                   VALUES ('$title', '$shortDesc', '$longDesc', '$hours', '$distance')";

$result = mysqli_query($connection,  $insertWalkSQL); // Insert the walk into the database

if (!$result) // If there was an error inserting a walk 
{
    die ('Error: ' . mysqli_error($connection));
}

echo "Walk added\n";

$getWalkIDSQL = "SELECT LAST_INSERT_ID()"; // Keep track of the walkID

$getWalkID = mysqli_query($connection,  $getWalkIDSQL);

if ($getWalkID)
{
    $nrows = mysqli_num_rows($getWalkID);
    $row = mysqli_fetch_row($getWalkID);
    $walkID = $row[0]; // Store WalkID for later use
}

else
{
    die ('Error: ' . mysqli_error($connection));
}

/* ----------------- 
 * Process Locations
 * -----------------
 */

foreach ($walk->locations->children() as $location)
{
    $locID = $location->location_ID+1; // Locations begin from ID 1
    $lat = $location->location_lat;
    $lng = $location->location_lng;
    $timestamp = $location->location_timestamp;

    /* FIXME: Hack because the Android app has a tendency to send timestamps with 3 extra zeroes */
    if (strlen($timestamp) > 10) // If the timestamp is incorrect...
    {
        $timestamp = substr($timestamp, 10); // Only use the first 10 digits
    }

    /* If this is the first location on the walk,  set the start time */
    if ($initialTimestamp == 0) 
    {
        $initialTimestamp = $timestamp;
    }
    
    /* SQL Statement to insert a new location into the 'location' table */
    $insertLocationSQL = "INSERT INTO location (walkId, locationId, latitude, longitude, timestamp, isPlace)
                          VALUES ('$walkID', '$locID', '$lat', '$lng', '$timestamp', '0')";
    
    /* Only insert if the coordinates are not both zero values */
    if ($lat != 0 and $lng != 0)
    {
        mysqli_query($connection, $insertLocationSQL);
        echo "Location " . "$locID" . " added to walk with ID " . "$walkID" . "\n"; 
    }

    /* --------------
     * Process Places
     * --------------
     */

    /* If a location has a name associated with it,  i.e. is not autogenerated... */
    if ($location->location_name != "null") // If the location is NOT autogenerated
    {
        /* SQL Statement to insert a new place into the 'placedescriptions' table */
        $insertPlaceSQL = "INSERT INTO placedescriptions (locationId,  walkId,  description, name)
       VALUES ('$locID', '$walkID', '$location->location_description', '$location->location_name')";

        $result = mysqli_query($connection, $insertPlaceSQL); // Insert new place into database

        if (!$result) // If there was an error inserting a new place
        {
            die('Error: ' . mysqli_error($connection));
        }

        /* Set the isPlace boolean in the location table if a location was explicitly added
         * 0 = Autogenerated 
         * 1 = User created  
         */
        $updateIsPlaceSQL = "UPDATE location SET isPlace=1 WHERE locationId=$locID";
        $result = mysqli_query($connection,  $updateIsPlaceSQL); 

        if(!$result) // If there was an error setting this boolean value
        {
            die ('Error: ' . mysqli_error($connection));
        }

        /* SQL statement to get the last inserted placeID */
        $getPlaceIDSQL = "SELECT LAST_INSERT_ID()"; // Keep track of the placeID

        /* Get reference to placeID here for use with photousage table */
        $getPlaceID = mysqli_query($connection,  $getWalkIDSQL);

        if ($getPlaceID) // If a place ID was succesfully retrieved
        {
            $nrows = mysqli_num_rows($getPlaceID);
            $row = mysqli_fetch_row($getPlaceID);
            $placeID = $row[0]; // Store PlaceID in variable for later use
        }
    }  

    /* --------------
     * Process Photos
     * --------------
     */
    foreach ($location->location_photos->children() as $photo) // For each 'location_photo' tag
    {
        if ($photo != '' and $photo != ' ' and $photo != null) // IF a photo is provided in base64
        {
            /* Write base64 encoded image out to file 
             * Write filename to photoName in DB
             * Use placeId for identification (linked to placeId in 'placedescription' table) 
             */
            $imageFilename = tempnam("./images/walks", "IMG"); // Generate unique filename
            $imageFilename .= ".jpg"; // Append .jpg extension

            /* This function takes the base64 string and writes it out to $imageFilename */
            convertXMLImage($photo, $imageFilename); 

            /* Convert to relative filename for the database */
            $relativeFilename = "." .
            "/" . "images/walks/" .
            basename($imageFilename);

            /* SQL Statement to insert a photo name and placeId into the 'photousage' table */
            $insertPhotoSQL = "INSERT INTO photousage (placeId, photoName)
                               VALUES ('$placeID', '$relativeFilename')";

            $result = mysqli_query($connection, $insertPhotoSQL); // Insert photo info into DB
        }
    } 
} 

/* -----------------------------
 * Calculate total Walk duration 
 * -----------------------------
 */

/* Function calculates walk duration from the first location to the most recent (last) location */ 
$durationInHours = calculateWalkTime($initialTimestamp, $timestamp);

/* SQL Statement to update duration for the current walk in the 'walk' table */
$updateWalkDurationSQL = "UPDATE walk SET hours=$durationInHours WHERE walkId=$walkID";

$result = mysqli_query($connection,  $updateWalkDurationSQL); // Update the walk duration

if (!$result) // If there was an error updating the walk duration
{
    die('Error: ' . mysqli_error($connection));
}

/* ---------------------------------------------------------------- 
 * Distance handling for Walks:
 * The following code calculates the distance between each location
 * to come up with an approximate final distance travelled
 * ----------------------------------------------------------------
 */

/* Variables set to 0 initially */
$numLocations = 0;
$totalDistance = 0; 

/* SQL statement to get all locations from the database */
$getAllLocationsSQL = "SELECT * FROM location WHERE walkId=$walkID";

$allLocations = mysqli_query($connection, $getAllLocationsSQL); // Get list of locations

if (!$allLocations) // If there was an error receiving the list of locations
{
    die('Error: ' . mysqli_error($connection));
}

/* While there are locations remaining... */ 
while ($aLocation = mysqli_fetch_assoc($allLocations))
{
     /* Take each location and put the GPS coordinates for them into 
      * A two-dimensional associative array
      */
     $coordinates[$numLocations]['lat'] = $aLocation['latitude'];
     $coordinates[$numLocations]['lng'] = $aLocation['longitude'];
     $numLocations++; // Increment number of locations
}

/* Iterate over all locations,  calculate distance from one point to the next and add to total */
for ($i = 0; $i < $numLocations-1; $i++) // -1 as Array is zero-indexed
{
        $totalDistance = (double) $totalDistance + 
        calculateDistanceInMiles($coordinates[$i]['lat'],  $coordinates[$i]['lng'], 
                                 $coordinates[($i+1)]['lat'],  $coordinates[($i+1)]['lng']);
}

$totalDistance = round($totalDistance, 3); // Round distance to 3 places (e.g. 1.245 miles)

/* SQL Statement to update the distance in the walk table */
$updateWalkDistanceSQL = "UPDATE walk SET distance=$totalDistance WHERE walkId=$walkID";

$result = mysqli_query($connection, $updateWalkDistanceSQL); // Update the distance

/* --------
 * Clean up 
 * --------
 */
    
mysqli_close($connection); // Close the database connection

?>

