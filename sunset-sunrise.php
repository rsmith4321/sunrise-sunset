<?php

// --- Configuration ---
$calculation_days = 365; // How many days into the future to calculate
$event_duration_minutes = 1; // Duration of the calendar event in minutes (for compatibility)
$prod_id = '-//Shoreline Web Designs//Sunrise Sunset Calendar V5//EN'; // Updated PRODID
$calendar_name = 'Sunrise/Sunset'; // Base name of the Calendar

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
    die("Error: Invalid 'tz' parameter. '{$timezone_identifier}' is not a recognized PHP timezone identifier. Check https://www.php.net/manual/en/timezones.php");
}

// --- Set Timezone and Get Timezone Object ---
try {
    $tz = new DateTimeZone($timezone_identifier);
    date_default_timezone_set($timezone_identifier); // Set for local date() formatting
} catch (Exception $e) {
    header("HTTP/1.1 500 Internal Server Error");
    die("Error: Could not process timezone identifier '{$timezone_identifier}'. " . $e->getMessage());
}

// --- Helper Functions ---

// Helper function to escape ICS strings
function escapeICS($string) {
    return preg_replace('/([\,;])/','\\\$1', str_replace("\n", "\\n", $string));
}

// Helper function to format seconds offset to HHMM or (+/-)HHMMSS for ICS
function formatOffset($offsetSeconds) {
    $sign = ($offsetSeconds < 0) ? '-' : '+';
    $absOffset = abs($offsetSeconds);
    $hours = str_pad(floor($absOffset / 3600), 2, '0', STR_PAD_LEFT);
    $minutes = str_pad(floor(($absOffset % 3600) / 60), 2, '0', STR_PAD_LEFT);
    $seconds = str_pad($absOffset % 60, 2, '0', STR_PAD_LEFT);
    // Return HHMM format which is widely compatible, add SS if needed
    return $sign . $hours . $minutes . ($seconds === '00' ? '' : $seconds);
}


// --- VTIMEZONE Generation ---
// Generates a basic VTIMEZONE component for the specified timezone ID
// based on transitions within the relevant period (current year +/- buffer).
// NOTE: This generates a simplified VTIMEZONE without complex RRULEs,
// focusing on providing the necessary STANDARD/DAYLIGHT blocks for compatibility.
function generateVTimezone($tzId) {
    try {
        $timezone = new DateTimeZone($tzId);
        // Get transitions for a period around the relevant dates (e.g., this year and next)
        // Use fixed timestamps far enough apart to likely capture at least one full DST cycle if applicable
        $start = new DateTime('now', $timezone);
        $year = (int)$start->format('Y');
        $transitions = $timezone->getTransitions(strtotime(($year - 1).'-01-01'), strtotime(($year + 1).'-12-31'));

        if (count($transitions) <= 1) { // Timezone might not have DST or changes
             $transition = $transitions[0] ?? $timezone->getTransitions(time(), time())[0]; // Get current rules
             if (!$transition) return ''; // Cannot determine rules

             $vtz = [];
             $vtz[] = 'BEGIN:VTIMEZONE';
             $vtz[] = 'TZID:' . $tzId;
             $vtz[] = 'BEGIN:STANDARD';
             $vtz[] = 'DTSTART:' . date('Ymd\THis', $transition['ts']); // Use first known transition time
             $vtz[] = 'TZOFFSETFROM:' . formatOffset($transition['offset']);
             $vtz[] = 'TZOFFSETTO:' . formatOffset($transition['offset']);
             $vtz[] = 'TZNAME:' . $transition['abbr'];
             $vtz[] = 'END:STANDARD';
             $vtz[] = 'END:VTIMEZONE';
             return implode("\r\n", $vtz);
        }

        // Find the latest STANDARD and DAYLIGHT definitions from the transitions
        $std = null;
        $dst = null;

        // Iterate backwards to find the most recent rules first
        for ($i = count($transitions) - 1; $i >= 0; $i--) {
            $t = $transitions[$i];
             // Determine the 'from' offset (offset of the *previous* transition)
             $fromOffset = ($i > 0) ? $transitions[$i-1]['offset'] : $t['offset']; // Default to current if it's the first

            if ($t['isdst']) {
                if ($dst === null) { // Found latest DST rule
                    $dst = $t;
                    $dst['offsetfrom'] = $fromOffset;
                }
            } else {
                if ($std === null) { // Found latest STD rule
                    $std = $t;
                    $std['offsetfrom'] = $fromOffset;
                }
            }
            // Stop if we've found both most recent types
            if ($std !== null && $dst !== null) break;
        }
         // Handle cases where only one type exists (no DST or always DST?)
         if ($std === null && $dst !== null) $std = $dst; // Treat DST as STANDARD if no STD found
         if ($dst === null && $std !== null) $dst = $std; // Treat STD as DAYLIGHT if no DST found
         if ($std === null && $dst === null) return ''; // Still couldn't find rules

        // Use the transition *before* the found rule for DTSTART if possible
        // to better represent when the rule *became* active.
        // Use a fallback fixed date like 1970 if needed.
        $stdDtStart = date('Ymd\THis', ($std['ts'] > 0) ? $std['ts'] : 0);
        $dstDtStart = date('Ymd\THis', ($dst['ts'] > 0) ? $dst['ts'] : 0);

        // Ensure DTSTART is not in the future relative to other rules if possible
        // This part is tricky without full RRULEs, aiming for basic compatibility.

        $vtz = [];
        $vtz[] = 'BEGIN:VTIMEZONE';
        $vtz[] = 'TZID:' . $tzId;
        //$vtz[] = 'X-LIC-LOCATION:' . $tzId; // Optional

        // STANDARD Component
        if ($std) {
             $vtz[] = 'BEGIN:STANDARD';
             // DTSTART: Use a relevant past date/time when this rule likely first applied.
             // Using the transition timestamp itself is a simpler approach.
             $vtz[] = 'DTSTART:' . date('Ymd\THis', $std['ts'] ?: strtotime('1970-01-01 00:00:00', 0));
             $vtz[] = 'TZOFFSETFROM:' . formatOffset($std['offsetfrom']);
             $vtz[] = 'TZOFFSETTO:' . formatOffset($std['offset']);
             $vtz[] = 'TZNAME:' . $std['abbr'];
             $vtz[] = 'END:STANDARD';
        }

        // DAYLIGHT Component
        if ($dst && $dst['isdst']) { // Only add DAYLIGHT if DST actually occurs
             $vtz[] = 'BEGIN:DAYLIGHT';
             $vtz[] = 'DTSTART:' . date('Ymd\THis', $dst['ts'] ?: strtotime('1970-03-08 02:00:00', 0)); // Example DST start
             $vtz[] = 'TZOFFSETFROM:' . formatOffset($dst['offsetfrom']);
             $vtz[] = 'TZOFFSETTO:' . formatOffset($dst['offset']);
             $vtz[] = 'TZNAME:' . $dst['abbr'];
             $vtz[] = 'END:DAYLIGHT';
        }

        $vtz[] = 'END:VTIMEZONE';
        return implode("\r\n", $vtz);

    } catch (Exception $e) {
        // Log error if needed
        error_log("Error generating VTIMEZONE for $tzId: " . $e->getMessage());
        return ''; // Return empty string on error
    }
}


// --- iCalendar Generation ---

// Standard Zenith Angles
define('SUNFUNCS_ZENITH_OFFICIAL', 90.833333); // 90 degrees 50 minutes

// Prepare Calendar Metadata
$calendar_description = "Sunrise/Sunset times for Lat: {$lat}, Lon: {$lon} ({$timezone_identifier}).";
$calendar_name_location = escapeICS($calendar_name . " (" . round($lat,2) . ", " . round($lon,2) . ")");

// Start ICS output
$ics_content = [];
$ics_content[] = 'BEGIN:VCALENDAR';
$ics_content[] = 'VERSION:2.0';
$ics_content[] = 'PRODID:' . $prod_id;
$ics_content[] = 'CALSCALE:GREGORIAN';

// *** Add the VTIMEZONE component ***
$vtimezone_block = generateVTimezone($timezone_identifier);
if (!empty($vtimezone_block)) {
    $ics_content[] = $vtimezone_block;
} else {
    // Log or handle the error - VTIMEZONE generation failed
    // As a fallback, we might omit TZID from events, but that deviates from the original request.
    // For now, we'll proceed, but validation might still complain if the block is missing.
     error_log("Warning: VTIMEZONE block could not be generated for {$timezone_identifier}. ICS feed might be invalid for strict clients.");
}

// Add other calendar properties AFTER VTIMEZONE
$ics_content[] = 'X-WR-CALNAME:' . $calendar_name_location;
$ics_content[] = 'X-WR-TIMEZONE:' . $timezone_identifier; // Still useful for clients
$ics_content[] = 'X-WR-CALDESC:' . escapeICS($calendar_description);

// Loop through the date range
$start_date = new DateTime('now', $tz); // Start from today in the target timezone
$current_timestamp = $start_date->getTimestamp();

for ($i = 0; $i < $calculation_days; $i++) {
    // Calculate sunrise/sunset timestamps (UTC)
    $noon_timestamp = strtotime(date('Y-m-d', $current_timestamp) . ' 12:00:00');

    $sunrise_ts = date_sunrise(
        $noon_timestamp, SUNFUNCS_RET_TIMESTAMP, $lat, $lon, SUNFUNCS_ZENITH_OFFICIAL, 0
    );

    $sunset_ts = date_sunset(
        $noon_timestamp, SUNFUNCS_RET_TIMESTAMP, $lat, $lon, SUNFUNCS_ZENITH_OFFICIAL, 0
    );

    $day_str = date('Ymd', $current_timestamp);

    // Create Sunrise Event if valid
    if ($sunrise_ts !== false) {
        $dtstart_local = date('Ymd\THis', $sunrise_ts); // ICS requires this format for DTSTART
        $event_uid = 'sunrise-' . $day_str . '-' . $lat . '-' . $lon . '@ryansmithphotography.com'; // Use your domain

        $ics_content[] = 'BEGIN:VEVENT';
        $ics_content[] = 'UID:' . $event_uid;
        $ics_content[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z'); // Creation timestamp (UTC)
        // Use DTSTART with TZID only if VTIMEZONE was successfully generated
        if (!empty($vtimezone_block)) {
            $ics_content[] = 'DTSTART;TZID=' . $timezone_identifier . ':' . $dtstart_local; // Local time with TZID
        } else {
             // Fallback: Output as UTC time if VTIMEZONE failed
             $ics_content[] = 'DTSTART:' . gmdate('Ymd\THis\Z', $sunrise_ts);
        }
        $ics_content[] = 'DURATION:PT' . $event_duration_minutes . 'M'; // Specify duration
        $ics_content[] = 'SUMMARY:Sunrise'; // Simplified summary
        $ics_content[] = 'END:VEVENT';
    }

    // Create Sunset Event if valid
    if ($sunset_ts !== false) {
        $dtstart_local = date('Ymd\THis', $sunset_ts); // ICS requires this format for DTSTART
        $event_uid = 'sunset-' . $day_str . '-' . $lat . '-' . $lon . '@ryansmithphotography.com'; // Use your domain

        $ics_content[] = 'BEGIN:VEVENT';
        $ics_content[] = 'UID:' . $event_uid;
        $ics_content[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z'); // Creation timestamp (UTC)
        // Use DTSTART with TZID only if VTIMEZONE was successfully generated
        if (!empty($vtimezone_block)) {
            $ics_content[] = 'DTSTART;TZID=' . $timezone_identifier . ':' . $dtstart_local; // Local time with TZID
         } else {
             // Fallback: Output as UTC time if VTIMEZONE failed
             $ics_content[] = 'DTSTART:' . gmdate('Ymd\THis\Z', $sunset_ts);
        }
        $ics_content[] = 'DURATION:PT' . $event_duration_minutes . 'M'; // Specify duration
        $ics_content[] = 'SUMMARY:Sunset'; // Simplified summary
        $ics_content[] = 'END:VEVENT';
    }

    // Move to the next day
    $current_timestamp = strtotime('+1 day', $current_timestamp);
}

// End ICS output
$ics_content[] = 'END:VCALENDAR';

// --- Output ICS File ---
// Keep location info in filename for clarity when managing downloaded files
$filename = "sunrise_sunset_" . str_replace('/', '_', $timezone_identifier) . "_" . round($lat, 2) . "_" . round($lon, 2) . ".ics";
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache'); // Prevent caching
header('Expires: 0');      // Prevent caching

echo implode("\r\n", $ics_content); // Use CRLF line endings as per RFC 5545

?>
