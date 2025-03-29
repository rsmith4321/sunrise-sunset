<?php

// --- Configuration ---
$calculation_days = 365; // How many days into the future to calculate
$event_duration_minutes = 1; // Duration of the calendar event in minutes (for compatibility)
$prod_id = '-//My Website//Sunrise Sunset Calendar V2//EN'; // ICS Product ID
$calendar_name = 'Sunrise/Sunset Times'; // Base name of the Calendar

// --- Get Input Parameters ---
$lat = isset($_GET['lat']) ? filter_var($_GET['lat'], FILTER_VALIDATE_FLOAT) : null;
$lon = isset($_GET['lon']) ? filter_var($_GET['lon'], FILTER_VALIDATE_FLOAT) : null;
$timezone_identifier = isset($_GET['tz']) ? trim($_GET['tz']) : null;

// --- Validate Input ---
if ($lat === null || $lat === false || $lat < -90 || $lat > 90) {
	header("HTTP/1.1 400 Bad Request");
	die("Error: Invalid or missing 'lat' parameter. Must be a number between -90 and 90.");
}

if ($lon === null || $lon === false || $lon < -180 || $lon > 180) {
	header("HTTP/1.1 400 Bad Request");
	die("Error: Invalid or missing 'lon' parameter. Must be a number between -180 and 180.");
}

if (empty($timezone_identifier)) {
	header("HTTP/1.1 400 Bad Request");
	die("Error: Missing 'tz' parameter. Please provide a valid PHP timezone identifier (e.g., America/New_York, Europe/London).");
}

// Validate the provided timezone identifier
if (!in_array($timezone_identifier, timezone_identifiers_list())) {
	header("HTTP/1.1 400 Bad Request");
	// You could potentially link to a list of valid identifiers here if desired
	die("Error: Invalid 'tz' parameter. '{$timezone_identifier}' is not a recognized PHP timezone identifier.");
}

// --- Set Timezone for Calculations and Formatting ---
// Set the default timezone for date functions *within this script*
// This ensures date() formats timestamps correctly for the target location
try {
	$tz = new DateTimeZone($timezone_identifier);
	date_default_timezone_set($timezone_identifier);
} catch (Exception $e) {
	// This catch is less likely now due to the in_array check, but good practice
	header("HTTP/1.1 500 Internal Server Error");
	die("Error: Could not process timezone identifier '{$timezone_identifier}'. " . $e->getMessage());
}

// --- iCalendar Generation ---

// Helper function to escape ICS strings
function escapeICS($string) {
	return preg_replace('/([\,;])/','\\\$1', str_replace("\n", "\\n", $string));
}

// Standard Zenith Angles
define('SUNFUNCS_ZENITH_OFFICIAL', 90.833333); // 90 degrees 50 minutes

// Prepare Calendar Metadata
$calendar_description = "Sunrise/Sunset times calculated for Lat: {$lat}, Lon: {$lon} in the {$timezone_identifier} timezone.";
$calendar_name_location = escapeICS($calendar_name . " ({$lat}, {$lon}, {$timezone_identifier})");

// Start ICS output
$ics_content = [];
$ics_content[] = 'BEGIN:VCALENDAR';
$ics_content[] = 'VERSION:2.0';
$ics_content[] = 'PRODID:' . $prod_id;
$ics_content[] = 'CALSCALE:GREGORIAN';
$ics_content[] = 'X-WR-CALNAME:' . $calendar_name_location;
$ics_content[] = 'X-WR-TIMEZONE:' . $timezone_identifier; // Declare the primary timezone
$ics_content[] = 'X-WR-CALDESC:' . escapeICS($calendar_description);

// Loop through the date range
$start_date = new DateTime('now', $tz); // Start from today in the target timezone
$current_timestamp = $start_date->getTimestamp();

for ($i = 0; $i < $calculation_days; $i++) {
	// Calculate sunrise/sunset timestamps (UTC)
	// Use noon of the current day to avoid DST change issues near midnight
	// strtotime uses the default timezone set above, which is correct here
	$noon_timestamp = strtotime(date('Y-m-d', $current_timestamp) . ' 12:00:00');

	$sunrise_ts = date_sunrise(
		$noon_timestamp,          // Timestamp for the day
		SUNFUNCS_RET_TIMESTAMP, // Return format: timestamp
		$lat,                   // Latitude
		$lon,                   // Longitude
		SUNFUNCS_ZENITH_OFFICIAL, // Zenith angle
		0                       // UTC offset - use 0 here, timezone handled separately
	);

	$sunset_ts = date_sunset(
		$noon_timestamp,           // Timestamp for the day
		SUNFUNCS_RET_TIMESTAMP,  // Return format: timestamp
		$lat,                    // Latitude
		$lon,                    // Longitude
		SUNFUNCS_ZENITH_OFFICIAL,  // Zenith angle
		0                        // UTC offset - use 0 here, timezone handled separately
	);

	// Get the date string for the current day in YYYYMMDD format
	$day_str = date('Ymd', $current_timestamp);

	// Create Sunrise Event if valid
	if ($sunrise_ts !== false) {
		$dtstart_local = date('Ymd\THis', $sunrise_ts); // Format timestamp into Local Time string
		$event_uid = 'sunrise-' . $day_str . '-' . $lat . '-' . $lon . '@mysuncal.com'; // Unique ID

		$ics_content[] = 'BEGIN:VEVENT';
		$ics_content[] = 'UID:' . $event_uid;
		$ics_content[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z'); // Creation timestamp (UTC)
		// DTSTART includes TZID for local time representation using the specified timezone
		$ics_content[] = 'DTSTART;TZID=' . $timezone_identifier . ':' . $dtstart_local;
		// Using DURATION is generally preferred for fixed, short events
		$ics_content[] = 'DURATION:PT' . $event_duration_minutes . 'M';
		$ics_content[] = 'SUMMARY:Sunrise (' . date('H:i', $sunrise_ts) . ')'; // Add time to summary
		$ics_content[] = 'DESCRIPTION:Sunrise at Lat ' . $lat . ', Lon ' . $lon . ' (' . $timezone_identifier . ')';
		$ics_content[] = 'GEO:' . $lat . ';' . $lon; // Optional Geo coordinates
		$ics_content[] = 'END:VEVENT';
	}

	// Create Sunset Event if valid
	if ($sunset_ts !== false) {
		$dtstart_local = date('Ymd\THis', $sunset_ts); // Format timestamp into Local Time string
		$event_uid = 'sunset-' . $day_str . '-' . $lat . '-' . $lon . '@mysuncal.com'; // Unique ID

		$ics_content[] = 'BEGIN:VEVENT';
		$ics_content[] = 'UID:' . $event_uid;
		$ics_content[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z'); // Creation timestamp (UTC)
		// DTSTART includes TZID for local time representation using the specified timezone
		$ics_content[] = 'DTSTART;TZID=' . $timezone_identifier . ':' . $dtstart_local;
		// Using DURATION
		$ics_content[] = 'DURATION:PT' . $event_duration_minutes . 'M';
		$ics_content[] = 'SUMMARY:Sunset (' . date('H:i', $sunset_ts) . ')'; // Add time to summary
		$ics_content[] = 'DESCRIPTION:Sunset at Lat ' . $lat . ', Lon ' . $lon . ' (' . $timezone_identifier . ')';
		$ics_content[] = 'GEO:' . $lat . ';' . $lon; // Optional Geo coordinates
		$ics_content[] = 'END:VEVENT';
	}

	// Move to the next day
	$current_timestamp = strtotime('+1 day', $current_timestamp);
}

// End ICS output
$ics_content[] = 'END:VCALENDAR';

// --- Output ICS File ---
$filename = "sunrise_sunset_" . str_replace('/', '_', $timezone_identifier) . "_" . round($lat, 2) . "_" . round($lon, 2) . ".ics";
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache'); // Prevent caching
header('Expires: 0');      // Prevent caching

echo implode("\r\n", $ics_content); // Use CRLF line endings as per RFC 5545

?>
