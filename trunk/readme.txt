=== WordPress Backup to Dropbox ===
Contributors: michael.dewildt
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=38SEXDYP28CFA
Tags: backup, dropbox
Requires at least: 3.0
Tested up to: 3.1.2
Stable tag: trunk

A plugin for WordPress that automatically creates a backup your blog and uploads it to Dropbox.

== Description ==

WordPress Backup to Dropbox has been created to give you piece of mind that your blog is backed up on a regular basis.

Just choose a day, time and how often you wish yor backup to be performed and kick back and wait for a zipped archive
of your websites files and its database to be dropped in your Dropbox!

Other settings include the ability to set where you want your backups stored within Dropbox and on your server, whether
you want to keep a copy of the backup on your server, and how many backups you wish to hold onto at any one time.

The plugin uses [OAuth](http://en.wikipedia.org/wiki/OAuth) so your Dropbox account details are not stored for the
plugin to gain access.

Once installed, the authorization process is pretty easy -

1. The plugin will ask you to authorize the plugin with Dropbox.

2. A new window open where Dropbox will ask you to authenticate in order allow this plugin access to your Dropbox.

3. Once you have granted access to the plugin click continue to setup your backup

Minimum Requirements -

1. PHP 5.2 or higher with zip support

2. [A Dropbox account](https://www.dropbox.com/referrals/NTM1NTcwNjc5)

For more information, news and updates please visit my blog - http://www.mikeyd.com.au/wordpress-backup-to-dropbox/

You can pull the source from my BitBucket account - https://bitbucket.org/michaeldewildt/wordpress-backup-to-dropbox

If you notice any bugs or want to request a feature please do so on BitBucket - https://bitbucket.org/michaeldewildt/wordpress-backup-to-dropbox/issues/

== Installation ==

1. Upload the contents of `wordpress-dropbox-backup.zip` to the `/wp-content/plugins/` directory or use WordPress' built-in plugin upload tool
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Authorize the plugin with Dropbox by following the instructions in the settings page found under Settings->Backup to Dropbox

== Frequently Asked Questions ==

= How do I get a free Dropbox account? =

Browse to http://db.tt/szCyl7o and create a free account.

= Why doesn't my backup execute at the time I set? =

The backup is executed using WordPress' scheduling system that, unlike a cron job, kicks of tasks the next time your
blog is accessed after the scheduled time.

== Screenshots ==

1. The WordPress Backup to Dropbox options page

== Changelog ==

= 0.7 =
* Added feature #4: Backup now button
* Fixed issue #2: Allow legitimately empty tables in backup
* Fixed some minor look and feel issues
* Added logo artwork, default i18n POT file and a daily schedule interval

= 0.6 =
* Initial stable release

== Upgrade Notice ==

* This version fixes several bugs and adds a few nice features so updating is highly recommended
