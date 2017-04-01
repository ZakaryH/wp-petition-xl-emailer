# Petition XL Emailer

## Configuration

Just install the plugin, activate and then insert the [show\_pxe\_form] shortcode where you want the form. All tables and CRON events will be automatically configured.

Still in development to make it usable for more than one case.
## Phase 1
A Wordpress plugin that takes a (Canadian) user's postal code, and finds their local MLA, MP and City Councillor. From that data an email is sent to each of the representatives' publicly listed email address with a default message of support and additional messages depending on the user's selection.

The data for the user and all their representatives is stored in a table.

## Phase 2
A wp-cron event is registered upon plugin activation which will trigger once a week. This process will check if any new users have been added this week, if a district has new users then the petitioners for that district (old and new) will be taken from the table and written to a .xlsx file. An email with the attached file for that district will be sent to each representative. A "master" email is sent to the admin with ALL new xlsx files attached.

## Notes
I recommend editing your wp-config and disabling wp-cron and replacing this with a true CRON job on your server that will call wp-cron instead. Wp-cron requires external action to be trigger, such as someone visiting your site. A true CRON job is much more reliable.

### Outstanding Issues

There is an outstanding issue with true CRON job and wp-mail that causes SERVER_NAME, or the "from" domain on an email to be undefined when called through a CRON job.
https://wordpress.org/support/topic/system-cron-and-email-sending/

In order to remedy this a plugin that explicitly sets the mail address is necessary. Something like the wp-mailfrom-ii plugin will do.

Another issue can be file permissions. XlsxWriter needs write permission.