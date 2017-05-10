# Petition XL Emailer

## About
**Still in development to make it re-usable. Currently has hardcoded values**

A Wordpress plugin that takes an Edmonton user's postal code, and finds their local MLA, MP and City Councillor.  Other Canadian postal codes will be stored, without finding their local representatives. User's information is later written to a spreadsheet with any others sharing the same representative and the dynamically generated .xlsx file is attached to an email sent to each representative.

Uses [OpenNorth's Represent API](https://represent.opennorth.ca/api/), [Google Maps Geocode API](https://developers.google.com/maps/documentation/geocoding/start), and [PHP XLSXWriter](https://github.com/mk-j/PHP_XLSXWriter).

Made to allow users to petition and endorse a specific cause via email.

## Configuration

Just install the plugin, activate and then insert the [show\_pxe\_form] shortcode where you want the form.

## On Submit Process
JS validation, AJAX call to the plugin passing the form data, server side validation and if the input is valid it will be stored.

## CRON Job Initiated Process
A wp-cron event will trigger once a week. This process will check if any new users have been added this week, if yes the petitioners for that district (old and new) will be taken from the table and written to a .xlsx file. An email with the attached file for that district will be sent to each representative. A "master" email is sent to the admin with ALL new xlsx files attached.

## Notes
I recommend editing your wp-config and disabling wp-cron and replacing this with a true CRON job on your server that will call wp-cron instead. Wp-cron requires an external trigger, such as someone visiting your site. A true CRON job is much more reliable.

### Outstanding Issues
#### True CRON Job & Email Plugin Dependency
There is an outstanding issue with true CRON job and wp-mail that causes SERVER_NAME, or the "from" domain on an email to be undefined when called through a CRON job.

https://wordpress.org/support/topic/system-cron-and-email-sending/

In order to remedy this a plugin that explicitly sets the mail address is necessary. Something like the wp-mailfrom-ii plugin will do if you want to use default wordpress mail, but I would recommend using WP Mail SMTP.  

WP mail default settings are sub optimal, a plugin such as WP Mail SMTP will help to set up mailing through your hosting allowing for branded emails to be sent with better encryption.

Follow the directions for WP MAIL SMTP, and emails sent through the plugin will use these settings for all wp_mail calls.

#### Permissions (write)
Another issue can be file permissions. XlsxWriter needs write permission to create the sheets.