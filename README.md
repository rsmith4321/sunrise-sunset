## Usage

The script generates an iCalendar feed when accessed via a URL containing specific parameters.

**URL Format Components:**

1.  **Base URL:** The location of your script on the server.
    `https://yourdomain.com/path/to/script_name.php`
2.  **Query Separator:** A question mark `?`.
3.  **Parameters:** Key-value pairs separated by ampersands `&`.
    *   `lat=LATITUDE` (e.g., `lat=33.6891`)
    *   `lon=LONGITUDE` (e.g., `lon=-78.8867`)
    *   `tz=TIMEZONE_ID` (e.g., `tz=America/New_York`)

**Constructing the Full URL:**

Combine the base URL and the required parameters. Ensure you use the correct values for `LATITUDE`, `LONGITUDE`, and `TIMEZONE_ID`.

**Required Parameters:**

*   `lat`: Latitude (-90 to 90).
*   `lon`: Longitude (-180 to 180).
*   `tz`: A valid PHP Timezone Identifier. See: [PHP Supported Timezones](https://www.php.net/manual/en/timezones.php)

**Example URLs:**

Carefully copy the *entire* line for the URL you need:

*   **Myrtle Beach, SC, USA:**
    ```
    https://yourdomain.com/path/to/script.php?lat=33.6891&lon=-78.8867&tz=America/New_York
    ```

*   **London, UK:**
    ```
    https://yourdomain.com/path/to/script.php?lat=51.5074&lon=-0.1278&tz=Europe/London
    ```

*   **Sydney, Australia:**
    ```
    https://yourdomain.com/path/to/script.php?lat=-33.8688&lon=151.2093&tz=Australia/Sydney
    ```

**Subscribing in Calendar Apps:**

1.  Construct the full URL for the desired location as shown in the examples.
2.  Open your calendar application (Fantastical, Google Calendar, Apple Calendar, etc.).
3.  Find the option to add a new calendar subscription (often called "Add Subscription Calendar", "Add Calendar by URL", "Add from URL", etc.).
4.  Paste the **complete URL** you constructed into the subscription field. Make sure there are no extra spaces or line breaks.
5.  Configure the calendar name, color, and refresh frequency (e.g., "Every Day" or "Every Week" is suitable).
6.  Save the subscription.

Your calendar application will now periodically fetch data from the URL, keeping the sunrise and sunset times updated.
