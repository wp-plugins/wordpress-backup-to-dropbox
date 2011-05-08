<?php
/*
Plugin Name: WordPress Backup to Dropbox
Plugin URI: http://www.mikeyd.com.au/wordpress-backup-to-dropbox/
Description: A plugin for WordPress that automatically creates a backup your blog and uploads it to Dropbox.
Version: 0.7.1
Author: Michael De Wildt
Author URI: http://www.mikeyd.com.au
License: Copyright 2011  Michael De Wildt  (email : michael.dewildt@gmail.com)

        This program is free software; you can redistribute it and/or modify
        it under the terms of the GNU General Public License, version 2, as
        published by the Free Software Foundation.

        This program is distributed in the hope that it will be useful,
        but WITHOUT ANY WARRANTY; without even the implied warranty of
        MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
        GNU General Public License for more details.

        You should have received a copy of the GNU General Public License
        along with this program; if not, write to the Free Software
        Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
include( 'class-dropbox-facade.php' );
include( 'class-wp-backup.php' );

define( 'BACKUP_TO_DROPBOX_VERSION', '0.7.1' );

//We need to set the PEAR_Includes folder in the path
ini_set( 'include_path', DEFAULT_INCLUDE_PATH . PATH_SEPARATOR  . dirname( __FILE__ ) . '/PEAR_Includes' );

/**
 * A wrapper function that adds an options page to setup Dropbox Backup
 * @return void
 */
function backup_to_dropbox_admin_menu() {
    add_options_page( 'Backup to Dropbox', 'Backup to Dropbox ', 8, 'backup-to-dropbox', 'backup_to_dropbox_admin_menu_contents' );
}

/**
 * A wrapper function that includes the backup to Dropbox options page
 * @return void
 */
function backup_to_dropbox_admin_menu_contents() {
    include( 'wp-backup-to-dropbox-options.php' );
}

/**
 * A wrapper function that executes the backup
 * @return void
 */
function execute_drobox_backup() {
	set_time_limit( 0 );
    $backup = new WP_Backup();
	$backup->set_history( WP_Backup::BACKUP_STATUS_STARTED );
    try {
        $dropbox = new Dropbox_Facade();
		if ( !$dropbox->is_authorized() ) {
			$backup->set_history( WP_Backup::BACKUP_STATUS_ERROR, "your Dropbox account is not authorized yet." );
			return;
		}
		
		$file = $backup->execute();
		if ( !$file ) {
			$backup->set_history( WP_Backup::BACKUP_STATUS_ERROR, __( 'error creating backup archive.' ) );
			return;
		}
		
		list( $dump_location, $dropbox_location, $keep_local, $count ) = $backup->get_options();
		$backup_file = ABSPATH . $dump_location . '/' . $file;

        $backup->set_history( WP_Backup::BACKUP_STATUS_UPLOADING );

        $memory_needed = round( ( filesize( $backup_file ) / 1048576 ) * 2.5 );
        $memory_limit = ( int )preg_replace( '/\D/', '', ini_get( 'memory_limit' ) );

        if ( $memory_needed > $memory_limit ) {
            if ( !ini_set( 'memory_limit', $memory_limit . 'M' ) ) {
                $backup->set_history( WP_Backup::BACKUP_STATUS_ERROR,
                                      sprintf( __( 'failed to set the php memory limit to %sM. More memory is required to upload this backup.'),
                                          $memory_needed ) );
                return;
            }
        }

        $dropbox->upload_backup( $dropbox_location . '/' . $file, $backup_file, $count );
		$dropbox->purge_backups( $dropbox_location, $count );
        
		$backup->set_history( WP_Backup::BACKUP_STATUS_SUCCESS );

		if ( $keep_local ) {
			$backup->purge_backups();
		} else {
			unlink( $backup_file );
		}

    } catch ( Exception $e ) {
        $backup->set_history( WP_Backup::BACKUP_STATUS_ERROR, "exception - " . $e->getMessage() );
    }
}

/**
 * Adds a set of custom intervals to the cron schedule list
 * @param  $schedules
 * @return array
 */
function backup_to_dropbox_cron_schedules( $schedules ) {
    $new_schedules = array(
		'daily' => array(
            'interval' => ( 86400 ),
            'display' => 'Weekly'
        ),
        'weekly' => array(
            'interval' => ( 604800 ),
            'display' => 'Weekly'
        ),
        'fortnightly' => array(
            'interval' => ( 1209600 ),
            'display' => 'Fortnightly'
        ),
        'monthly' => array(
            'interval' => ( 2419200 ),
            'display' => 'Once Every 4 weeks'
        ),
        'two_monthly' => array(
            'interval' => ( 4838400 ),
            'display' => 'Once Every 8 weeks'
        ),
        'three_monthly' => array(
            'interval' => ( 7257600 ),
            'display' => 'Once Every 12 weeks'
        ),
    );
    return array_merge( $schedules, $new_schedules );
}

//WordPress filters and actions
add_filter( 'cron_schedules', 'backup_to_dropbox_cron_schedules' );
add_action( 'execute_periodic_drobox_backup', 'execute_drobox_backup' );
add_action( 'execute_instant_drobox_backup', 'execute_drobox_backup' );
add_action( 'admin_menu', 'backup_to_dropbox_admin_menu' );
